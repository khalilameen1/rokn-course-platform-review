<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StorePurchaseProviderGateway;
use App\Data\VerifiedStorePurchase;
use App\Exceptions\StorePurchaseVerificationException;
use App\Models\StorePurchase;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Google\Client as GoogleClient;
use Google\Service\AndroidPublisher;
use Google\Service\AndroidPublisher\ProductOfferDetails;
use Google\Service\AndroidPublisher\ProductPurchaseV2;
use Google\Service\AndroidPublisher\PurchaseStateContext;
use GuzzleHttp\Client as HttpClient;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class LiveStorePurchaseProviderGateway implements StorePurchaseProviderGateway
{
    public function verify(
        string $provider,
        string $productId,
        string $purchaseToken,
        ?string $transactionId,
        string $expectedAccountBinding
    ): VerifiedStorePurchase {
        return match ($provider) {
            StorePurchase::PROVIDER_GOOGLE => $this->verifyGoogle(
                $productId,
                $purchaseToken,
                $expectedAccountBinding
            ),
            StorePurchase::PROVIDER_APPLE => $this->verifyApple(
                $productId,
                $purchaseToken,
                $transactionId,
                $expectedAccountBinding
            ),
            default => throw new StorePurchaseVerificationException('unsupported_store_provider'),
        };
    }

    private function verifyGoogle(
        string $productId,
        string $purchaseToken,
        ?string $expectedAccountBinding
    ): VerifiedStorePurchase {
        try {
            $service = $this->googlePublisher();
            $packageName = trim((string) config('store_billing.google.package_name'));
            if ($packageName === '') {
                throw new \RuntimeException('Google Play package name is not configured.');
            }
            $purchase = $service->purchases_productsv2->getproductpurchasev2(
                $packageName,
                $purchaseToken
            );
        } catch (StorePurchaseVerificationException $exception) {
            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            throw new StorePurchaseVerificationException(
                'google_verification_unavailable',
                'تعذّر التحقق من عملية الشراء الآن',
                503
            );
        }

        return $this->verifiedGooglePurchase($purchase, $productId, $purchaseToken, $expectedAccountBinding);
    }

    private function verifiedGooglePurchase(
        ProductPurchaseV2 $purchase,
        string $productId,
        string $purchaseToken,
        ?string $expectedAccountBinding
    ): VerifiedStorePurchase {
        $state = $purchase->getPurchaseStateContext()?->getPurchaseState();
        if ($state !== PurchaseStateContext::PURCHASE_STATE_PURCHASED) {
            throw new StorePurchaseVerificationException(
                $state === PurchaseStateContext::PURCHASE_STATE_PENDING
                    ? 'store_purchase_pending'
                    : ($state === PurchaseStateContext::PURCHASE_STATE_CANCELLED
                        ? 'store_purchase_cancelled' : 'store_purchase_not_completed'),
                $state === PurchaseStateContext::PURCHASE_STATE_PENDING
                    ? 'عملية الدفع ما زالت قيد التأكيد.'
                    : 'عملية الشراء غير مكتملة.'
            );
        }

        $lineItems = $purchase->getProductLineItem() ?: [];
        $verifiedProductIds = array_values(array_filter(array_map(
            static fn ($item): string => trim((string) $item->getProductId()),
            $lineItems
        )));
        if (!in_array($productId, $verifiedProductIds, true)) {
            throw new StorePurchaseVerificationException('store_product_mismatch');
        }
        // These coin contracts fulfill exactly one consumable per token.
        // Multi-quantity must remain disabled in Play Console.
        $quantity = count($lineItems) === 1
            ? (int) ($lineItems[0]->getProductOfferDetails()?->getQuantity() ?? 1)
            : 0;
        if ($quantity !== 1) {
            throw new StorePurchaseVerificationException('store_purchase_quantity_unsupported');
        }

        $account = trim((string) $purchase->getObfuscatedExternalAccountId());
        if ($account === '' || ($expectedAccountBinding !== null && !hash_equals($expectedAccountBinding, $account))) {
            throw new StorePurchaseVerificationException('store_account_mismatch');
        }

        $orderId = trim((string) $purchase->getOrderId());
        $externalId = $orderId !== '' ? $orderId : hash('sha256', $purchaseToken);
        $testPurchase = $purchase->getTestPurchaseContext() !== null;

        return new VerifiedStorePurchase(
            provider: StorePurchase::PROVIDER_GOOGLE,
            productId: $productId,
            externalTransactionId: $externalId,
            environment: $testPurchase ? 'test' : 'production',
            auditPayload: [
                'order_id' => $orderId !== '' ? $orderId : null,
                'region_code' => $purchase->getRegionCode(),
                'purchase_completed_at' => $purchase->getPurchaseCompletionTime(),
                'acknowledgement_state' => $purchase->getAcknowledgementState(),
                'test_purchase' => $testPurchase,
                'quantity' => $quantity,
                'consumption_state' => $lineItems[0]->getProductOfferDetails()?->getConsumptionState(),
            ],
            quantity: $quantity,
            accountBinding: $account
        );
    }

    public function verifyGoogleNotification(string $productId, string $purchaseToken): VerifiedStorePurchase
    {
        return $this->verifyGoogle($productId, $purchaseToken, null);
    }

    public function consumeGoogle(string $productId, string $purchaseToken, string $expectedAccountBinding): void
    {
        $verified = $this->verifyGoogle($productId, $purchaseToken, $expectedAccountBinding);
        if (($verified->auditPayload['consumption_state'] ?? null)
            === ProductOfferDetails::CONSUMPTION_STATE_CONSUMPTION_STATE_CONSUMED) {
            return;
        }

        try {
            $this->googlePublisher()->purchases_products->consume(
                (string) config('store_billing.google.package_name'),
                $productId,
                $purchaseToken
            );
        } catch (\Throwable) {
            // A device/worker can consume concurrently or a successful response
            // can be lost. Only provider state, never an error string, proves
            // that this retry is complete.
            $verified = $this->verifyGoogle($productId, $purchaseToken, $expectedAccountBinding);
            if (($verified->auditPayload['consumption_state'] ?? null)
                === ProductOfferDetails::CONSUMPTION_STATE_CONSUMPTION_STATE_CONSUMED) {
                return;
            }
            throw new StorePurchaseVerificationException(
                'google_consumption_unavailable',
                'Google Play consumption will be retried.',
                503
            );
        }
    }

    private function googlePublisher(): AndroidPublisher
    {
        $client = new GoogleClient();
        $client->setAuthConfig($this->googleCredentials());
        $client->setScopes([AndroidPublisher::ANDROIDPUBLISHER]);
        $client->setHttpClient(new HttpClient([
            'connect_timeout' => (float) config('store_billing.connect_timeout_seconds', 3),
            'timeout' => (float) config('store_billing.timeout_seconds', 12),
        ]));

        return new AndroidPublisher($client);
    }

    private function verifyApple(
        string $productId,
        string $purchaseToken,
        ?string $transactionId,
        string $expectedAccountBinding
    ): VerifiedStorePurchase {
        $transactionId = trim((string) $transactionId);
        if ($transactionId === '') {
            throw new StorePurchaseVerificationException('apple_transaction_id_required');
        }

        $authToken = $this->appleServerToken();
        $response = $this->appleRequest(
            'https://api.storekit.itunes.apple.com',
            $transactionId,
            $authToken
        );
        $environment = 'production';
        if ((int) $response->json('errorCode') === 4040010) {
            $environment = 'sandbox';
            $response = $this->appleRequest(
                'https://api.storekit-sandbox.itunes.apple.com',
                $transactionId,
                $authToken
            );
        }
        if (!$response->successful()) {
            throw new StorePurchaseVerificationException(
                $response->serverError()
                    ? 'apple_verification_unavailable'
                    : 'store_purchase_not_found',
                $response->serverError()
                    ? 'تعذّر التحقق من عملية الشراء الآن'
                    : 'لم تكتمل عملية الشراء',
                $response->serverError() ? 503 : 422
            );
        }

        $signedTransaction = trim((string) $response->json('signedTransactionInfo'));
        if ($signedTransaction === '') {
            throw new StorePurchaseVerificationException('apple_signed_transaction_missing');
        }
        $claims = $this->verifiedAppleClaims($signedTransaction);

        if (
            !hash_equals((string) config('store_billing.apple.bundle_id'), (string) ($claims['bundleId'] ?? ''))
            || !hash_equals($productId, (string) ($claims['productId'] ?? ''))
            || !hash_equals($transactionId, (string) ($claims['transactionId'] ?? ''))
        ) {
            throw new StorePurchaseVerificationException('store_product_mismatch');
        }
        if (
            !isset($claims['appAccountToken'])
            || !hash_equals(strtolower($expectedAccountBinding), strtolower((string) $claims['appAccountToken']))
        ) {
            throw new StorePurchaseVerificationException('store_account_mismatch');
        }
        if (isset($claims['revocationDate']) || (string) ($claims['type'] ?? '') !== 'Consumable') {
            throw new StorePurchaseVerificationException('store_purchase_not_entitled');
        }
        if ((int) ($claims['quantity'] ?? 1) !== 1) {
            throw new StorePurchaseVerificationException('store_purchase_quantity_unsupported');
        }
        if (strtolower((string) ($claims['environment'] ?? '')) !== $environment) {
            throw new StorePurchaseVerificationException('store_purchase_environment_invalid');
        }

        $currency = strtoupper((string) ($claims['currency'] ?? ''));
        $price = isset($claims['price']) && is_numeric($claims['price'])
            ? ((float) $claims['price']) / 1000
            : null;

        return new VerifiedStorePurchase(
            provider: StorePurchase::PROVIDER_APPLE,
            productId: $productId,
            externalTransactionId: $transactionId,
            environment: strtolower((string) ($claims['environment'] ?? $environment)),
            currency: preg_match('/^[A-Z]{3}$/', $currency) ? $currency : null,
            grossAmount: $price,
            auditPayload: [
                'original_transaction_id' => $claims['originalTransactionId'] ?? null,
                'purchase_date' => $claims['purchaseDate'] ?? null,
                'storefront' => $claims['storefront'] ?? null,
                'quantity' => $claims['quantity'] ?? 1,
            ],
            quantity: (int) ($claims['quantity'] ?? 1),
            accountBinding: (string) $claims['appAccountToken']
        );
    }

    /** @return array<string, mixed> */
    private function googleCredentials(): array
    {
        $encoded = trim((string) config('store_billing.google.credentials_base64'));
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);
            $credentials = is_string($decoded) ? json_decode($decoded, true) : null;
            if (is_array($credentials)) {
                return $credentials;
            }
        }

        $file = trim((string) config('store_billing.google.credentials_file'));
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $credentials = json_decode((string) file_get_contents($file), true);
            if (is_array($credentials)) {
                return $credentials;
            }
        }

        throw new \RuntimeException('Google Play service account credentials are missing.');
    }

    private function appleServerToken(): string
    {
        $issuer = trim((string) config('store_billing.apple.issuer_id'));
        $keyId = trim((string) config('store_billing.apple.key_id'));
        $bundleId = trim((string) config('store_billing.apple.bundle_id'));
        $privateKey = $this->applePrivateKey();
        if ($issuer === '' || $keyId === '' || $bundleId === '') {
            throw new StorePurchaseVerificationException(
                'apple_verification_not_configured',
                'تعذّر التحقق من عملية الشراء الآن',
                503
            );
        }

        $issuedAt = time();

        return JWT::encode([
            'iss' => $issuer,
            'iat' => $issuedAt,
            'exp' => $issuedAt + 600,
            'aud' => 'appstoreconnect-v1',
            'bid' => $bundleId,
        ], $privateKey, 'ES256', $keyId, ['typ' => 'JWT']);
    }

    private function applePrivateKey(): string
    {
        $encoded = trim((string) config('store_billing.apple.private_key_base64'));
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);
            if (is_string($decoded) && str_contains($decoded, 'PRIVATE KEY')) {
                return $decoded;
            }
        }

        $file = trim((string) config('store_billing.apple.private_key_file'));
        if ($file !== '' && is_file($file) && is_readable($file)) {
            return (string) file_get_contents($file);
        }

        throw new StorePurchaseVerificationException(
            'apple_verification_not_configured',
            'تعذّر التحقق من عملية الشراء الآن',
            503
        );
    }

    private function appleRequest(string $baseUrl, string $transactionId, string $token): Response
    {
        return Http::baseUrl($baseUrl)
            ->withToken($token)
            ->acceptJson()
            ->connectTimeout((float) config('store_billing.connect_timeout_seconds', 3))
            ->timeout((float) config('store_billing.timeout_seconds', 12))
            ->get('/inApps/v1/transactions/' . rawurlencode($transactionId));
    }

    /** @return array<string, mixed> */
    private function verifiedAppleClaims(string $jws): array
    {
        $segments = explode('.', $jws);
        if (count($segments) !== 3) {
            throw new StorePurchaseVerificationException('apple_transaction_signature_invalid');
        }
        $header = json_decode($this->base64UrlDecode($segments[0]), true);
        $chain = is_array($header) && is_array($header['x5c'] ?? null) ? $header['x5c'] : [];
        if (($header['alg'] ?? null) !== 'ES256' || count($chain) < 2) {
            throw new StorePurchaseVerificationException('apple_transaction_signature_invalid');
        }

        $certificates = array_map(function ($value): string {
            $der = base64_decode((string) $value, true);
            if (!is_string($der)) {
                throw new StorePurchaseVerificationException('apple_transaction_signature_invalid');
            }
            return "-----BEGIN CERTIFICATE-----\n"
                . chunk_split(base64_encode($der), 64, "\n")
                . "-----END CERTIFICATE-----\n";
        }, $chain);

        $trustedRoots = (array) config('store_billing.apple.root_certificate_sha256', []);
        $rootDer = base64_decode((string) end($chain), true);
        $rootFingerprint = is_string($rootDer) ? hash('sha256', $rootDer) : '';
        if ($rootFingerprint === '' || !in_array($rootFingerprint, $trustedRoots, true)) {
            throw new StorePurchaseVerificationException(
                'apple_certificate_root_untrusted',
                'تعذّر التحقق من عملية الشراء',
                503
            );
        }

        foreach ($certificates as $index => $certificate) {
            $certificateDetails = openssl_x509_parse($certificate);
            $now = time();
            if (
                !is_array($certificateDetails)
                || (int) ($certificateDetails['validFrom_time_t'] ?? PHP_INT_MAX) > $now
                || (int) ($certificateDetails['validTo_time_t'] ?? 0) < $now
            ) {
                throw new StorePurchaseVerificationException('apple_certificate_expired_or_not_yet_valid');
            }

            $issuer = $certificates[$index + 1] ?? $certificate;
            $issuerKey = openssl_pkey_get_public($issuer);
            if ($issuerKey === false || openssl_x509_verify($certificate, $issuerKey) !== 1) {
                throw new StorePurchaseVerificationException('apple_transaction_signature_invalid');
            }
        }

        try {
            $claims = (array) JWT::decode($jws, new Key($certificates[0], 'ES256'));
        } catch (\Throwable $exception) {
            report($exception);
            throw new StorePurchaseVerificationException('apple_transaction_signature_invalid');
        }

        return $claims;
    }

    /** @return array<string, mixed> */
    public function verifyAppleSignedPayload(string $jws): array
    {
        return $this->verifiedAppleClaims($jws);
    }

    private function base64UrlDecode(string $value): string
    {
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
        if (!is_string($decoded)) {
            throw new StorePurchaseVerificationException('apple_transaction_signature_invalid');
        }

        return $decoded;
    }
}
