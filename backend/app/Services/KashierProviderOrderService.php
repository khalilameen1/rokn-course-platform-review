<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final readonly class KashierProviderOrderService
{
    public function __construct(private KashierConfigurationService $configuration)
    {
    }

    public function isValidReference(mixed $orderRef): bool
    {
        return is_string($orderRef)
            && preg_match('/^PKG-[A-Z0-9-]{8,64}$/i', $orderRef) === 1;
    }

    /** @return array<string,mixed>|null */
    public function fetch(string $orderRef): ?array
    {
        if (!$this->isValidReference($orderRef)) {
            Log::warning('Kashier order verification rejected an invalid reference', [
                'order_ref_fingerprint' => hash('sha256', $orderRef),
            ]);

            return null;
        }

        try {
            $configuration = $this->configuration->get();
        } catch (\RuntimeException $exception) {
            Log::error('Kashier order verification is not configured', [
                'order_ref' => $orderRef,
                'exception' => $exception::class,
            ]);

            return null;
        }

        $apiHost = $configuration['mode'] === 'live'
            ? 'https://api.kashier.io'
            : 'https://test-api.kashier.io';

        try {
            $response = Http::withHeaders([
                'Authorization' => $configuration['secret_key'],
            ])->connectTimeout(5)
                ->timeout(10)
                ->get("{$apiHost}/payments/orders/" . rawurlencode($orderRef));

            if ($response->status() === 404) {
                return [
                    'response' => [
                        'status' => 'NOT_FOUND',
                        'merchantOrderId' => $orderRef,
                    ],
                ];
            }

            if (!$response->successful()) {
                Log::warning('Kashier order verification API failed', [
                    'order_ref' => $orderRef,
                    'status' => $response->status(),
                ]);

                return null;
            }

            $payload = $response->json();
            if (!is_array($payload)) {
                Log::warning('Kashier order verification returned an invalid payload', [
                    'order_ref' => $orderRef,
                ]);

                return null;
            }

            return $payload;
        } catch (\Throwable $exception) {
            Log::error('Kashier order verification API error', [
                'order_ref' => $orderRef,
                'exception' => $exception::class,
            ]);

            return null;
        }
    }
}
