<?php

declare(strict_types=1);

namespace App\Services;

use InvalidArgumentException;
use RuntimeException;

/**
 * Minimal first-party adapter for Kashier's hosted payment page contract.
 *
 * This replaces the unversioned third-party wrapper while intentionally
 * preserving the public methods used by PaymentController and its tests.
 */
final class KashierService
{
    private string $mode;

    /** @var array{base_url?: string, api_key?: string, secret_key?: string, mid?: string} */
    private array $config;

    public function __construct()
    {
        $this->mode = strtolower(trim((string) config('kashier.mode')));
        $configured = config("kashier.{$this->mode}", []);
        $this->config = is_array($configured) ? $configured : [];
    }

    public function generateOrderHash(
        string $orderId,
        string $amount,
        string $currency,
        ?string $customerReference = null
    ): string {
        $this->assertConfigured();
        $this->assertSafeScalar('order ID', $orderId);
        $this->assertSafeScalar('amount', $amount);
        $this->assertSafeScalar('currency', $currency);
        if ($customerReference !== null) {
            $this->assertSafeScalar('customer reference', $customerReference);
        }

        $path = sprintf(
            '/?payment=%s.%s.%s.%s',
            $this->getMid(),
            $orderId,
            $amount,
            strtoupper($currency)
        );
        if ($customerReference !== null && $customerReference !== '') {
            $path .= '.'.$customerReference;
        }

        return hash_hmac('sha256', $path, (string) $this->config['api_key']);
    }

    public function validateSignature(array $queryParams): bool
    {
        $provided = strtolower(trim((string) ($queryParams['signature'] ?? '')));
        $secret = (string) ($this->config['api_key'] ?? '');
        if ($secret === '' || preg_match('/^[a-f0-9]{64}$/', $provided) !== 1) {
            return false;
        }

        // The order hash handed to the learner is the same HMAC over
        // "/?payment=MID.order.amount.currency", which is itself a key=value
        // string. Requiring plain field names and the redirect's own
        // merchantOrderId stops that hash from being replayed as a callback
        // signature. Crediting still waits for Kashier's order API.
        if (! isset($queryParams['merchantOrderId'])) {
            return false;
        }

        $parts = [];
        foreach ($queryParams as $key => $value) {
            if ($key === 'signature' || $key === 'mode') {
                continue;
            }
            if (
                preg_match('/\A[A-Za-z][A-Za-z0-9_]*\z/D', (string) $key) !== 1
                || (! is_scalar($value) && $value !== null)
            ) {
                return false;
            }
            $parts[] = (string) $key.'='.(string) $value;
        }

        $expected = hash_hmac('sha256', implode('&', $parts), $secret);

        return hash_equals($expected, $provided);
    }

    public function getHppUrl(
        string $orderId,
        string $amount,
        string $currency,
        string $callbackUrl,
        string $allowedMethods = 'card,wallet,bank_installments'
    ): string {
        $this->assertConfigured();
        if (filter_var($callbackUrl, FILTER_VALIDATE_URL) === false) {
            throw new InvalidArgumentException('Kashier callback URL must be absolute.');
        }

        $query = http_build_query([
            'merchantId' => $this->getMid(),
            'orderId' => $orderId,
            'mode' => $this->mode,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'hash' => $this->generateOrderHash($orderId, $amount, $currency),
            'merchantRedirect' => $callbackUrl,
            'allowedMethods' => $allowedMethods,
            'display' => 'en',
        ], '', '&', PHP_QUERY_RFC3986);

        return rtrim($this->getBaseUrl(), '?&').'?'.$query;
    }

    /** @return array{base_url?: string, api_key?: string, secret_key?: string, mid?: string} */
    public function getConfig(): array
    {
        return $this->config;
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function getMid(): string
    {
        return (string) ($this->config['mid'] ?? '');
    }

    public function getBaseUrl(): string
    {
        return (string) ($this->config['base_url'] ?? '');
    }

    private function assertConfigured(): void
    {
        if (! in_array($this->mode, ['test', 'live'], true)) {
            throw new RuntimeException('Kashier mode must be explicitly set to test or live.');
        }
        foreach (['base_url', 'api_key', 'mid'] as $key) {
            if (trim((string) ($this->config[$key] ?? '')) === '') {
                throw new RuntimeException("Kashier {$key} is not configured.");
            }
        }
    }

    private function assertSafeScalar(string $label, string $value): void
    {
        if ($value === '' || str_contains($value, '.')) {
            // Amount is the only protocol segment where a decimal point is
            // expected. Other dots change the signed path structure.
            if ($label !== 'amount' || preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
                throw new InvalidArgumentException("Invalid Kashier {$label}.");
            }
        }
    }
}
