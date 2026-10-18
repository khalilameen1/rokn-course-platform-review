<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;

final class WebhookDestinationPolicy
{
    /**
     * Resolve immediately before sending and return a cURL pin. Rejecting the
     * complete DNS answer (not only the first address) prevents mixed public /
     * private answers from becoming an SSRF bypass.
     *
     * @return array{url: string, host: string, port: int, address: string}
     */
    public function resolve(string $candidate): array
    {
        $url = SafeExternalUrl::sanitize($candidate);
        if ($url === null) {
            throw new InvalidArgumentException('Webhook URL must be a valid HTTPS URL.');
        }

        $parts = parse_url($url);
        $host = strtolower(rtrim(trim((string) ($parts['host'] ?? ''), '[]'), '.'));
        $port = (int) ($parts['port'] ?? 443);
        if (
            $host === ''
            || $port < 1
            || $port > 65535
            || preg_match('/^[a-z0-9.-]+$/i', $host) !== 1
            || $this->isReservedName($host)
        ) {
            throw new InvalidArgumentException('Webhook host is not a public Internet destination.');
        }

        $addresses = filter_var($host, FILTER_VALIDATE_IP)
            ? [$host]
            : $this->dnsAddresses($host);
        if ($addresses === []) {
            throw new InvalidArgumentException('Webhook host did not resolve.');
        }

        foreach ($addresses as $address) {
            if (! $this->isPublicAddress($address)) {
                throw new InvalidArgumentException('Webhook host resolved to a private or reserved address.');
            }
        }

        sort($addresses, SORT_STRING);

        return [
            'url' => $url,
            'host' => $host,
            'port' => $port,
            'address' => $addresses[0],
        ];
    }

    private function isReservedName(string $host): bool
    {
        if (! str_contains($host, '.')) {
            return true;
        }

        foreach (['localhost', '.localhost', '.local', '.internal', '.home', '.test', '.invalid'] as $suffix) {
            if ($host === ltrim($suffix, '.') || str_ends_with($host, $suffix)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private function dnsAddresses(string $host): array
    {
        $addresses = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                $address = $record['ip'] ?? $record['ipv6'] ?? null;
                if (is_string($address) && $address !== '') {
                    $addresses[] = $address;
                }
            }
        }

        // Some locked-down runtimes disable dns_get_record but still provide
        // the system IPv4 resolver. Never fall back after receiving a partial
        // DNS answer because that would drop AAAA safety checks.
        if ($addresses === []) {
            $ipv4 = @gethostbynamel($host);
            if (is_array($ipv4)) {
                $addresses = array_merge($addresses, $ipv4);
            }
        }

        return array_values(array_unique($addresses));
    }

    private function isPublicAddress(string $address): bool
    {
        return filter_var(
            $address,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) !== false;
    }
}
