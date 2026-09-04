<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Log;

final readonly class KashierCallbackSignatureService
{
    public function __construct(private KashierConfigurationService $configuration)
    {
    }

    /**
     * @param list<mixed> $headerCandidates
     * @param array<string,mixed> $params
     */
    public function validate(
        array $headerCandidates,
        string $rawBody,
        KashierService $kashier,
        array &$params
    ): bool {
        try {
            $secret = $this->configuration->get()['api_key'];
        } catch (\RuntimeException $exception) {
            Log::error('Kashier signature verification is not configured', [
                'exception' => $exception::class,
            ]);

            return false;
        }

        $signatureSource = isset($params['data']) && is_array($params['data'])
            ? $params['data']
            : $params;
        if (isset($params['data']) && is_array($params['data'])) {
            $params = array_merge($params, $params['data']);
        }

        $candidates = array_values(array_filter([
            ...$headerCandidates,
            $params['kashierSignature'] ?? null,
            $params['signature'] ?? null,
            $params['hash'] ?? null,
            $signatureSource['kashierSignature'] ?? null,
            $signatureSource['hash'] ?? null,
            $signatureSource['signature'] ?? null,
        ], static fn ($candidate): bool => is_scalar($candidate) && (string) $candidate !== ''));

        if (!empty($params['signatureKeys']) && is_array($params['signatureKeys'])) {
            $queryString = $this->signatureKeysQuery($params['signatureKeys'], $signatureSource);
            if ($queryString !== null) {
                $expected = hash_hmac('sha256', $queryString, $secret, false);
                foreach ($candidates as $candidate) {
                    if (hash_equals($expected, (string) $candidate)) {
                        return true;
                    }
                }
            }
            Log::warning('Kashier webhook signature mismatch (signatureKeys check failed)', [
                'mode' => config('kashier.mode'),
            ]);
        }

        foreach ($candidates as $candidate) {
            if ($rawBody !== '' && hash_equals(
                hash_hmac('sha256', $rawBody, $secret, false),
                (string) $candidate
            )) {
                return true;
            }
        }

        if (!empty($params['signature'])) {
            return $this->validateFlat($params, $secret);
        }

        return $kashier->validateSignature($this->flatten($params));
    }

    private function signatureKeysQuery(array $signatureKeys, array $source): ?string
    {
        $pairs = [];
        foreach ($signatureKeys as $key) {
            if (
                !is_string($key)
                || $key === ''
                || !array_key_exists($key, $source)
                || (!is_scalar($source[$key]) && $source[$key] !== null)
            ) {
                return null;
            }
            $value = $source[$key];
            $pairs[] = rawurlencode($key) . '=' . rawurlencode($value === null ? '' : (string) $value);
        }

        return implode('&', $pairs);
    }

    /** @return array<string,scalar|null> */
    private function flatten(array $params): array
    {
        $flat = [];
        foreach ($params as $key => $value) {
            if (!is_array($value) && !is_object($value)) {
                $flat[$key] = $value;
            }
        }

        return $flat;
    }

    private function validateFlat(array $params, string $secret): bool
    {
        $provided = $params['signature'] ?? '';
        if ($provided === '' || $provided === null) {
            return false;
        }

        $excluded = ['signature', 'mode', 'hash', 'event', 'data', 'signatureKeys'];
        $query = '';
        foreach ($params as $key => $value) {
            if (in_array($key, $excluded, true) || is_array($value) || is_object($value)) {
                continue;
            }
            $query .= "&{$key}={$value}";
        }

        return hash_equals(
            hash_hmac('sha256', ltrim($query, '&'), $secret, false),
            (string) $provided
        );
    }
}
