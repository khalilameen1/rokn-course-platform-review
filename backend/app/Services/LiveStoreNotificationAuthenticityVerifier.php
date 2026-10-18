<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StoreNotificationAuthenticityVerifier;
use App\Exceptions\StorePurchaseVerificationException;
use Google\Client as GoogleClient;

final readonly class LiveStoreNotificationAuthenticityVerifier implements StoreNotificationAuthenticityVerifier
{
    public function __construct(private LiveStorePurchaseProviderGateway $storeGateway) {}

    public function verifyGooglePushToken(string $token): array
    {
        $audience = trim((string) config('store_billing.google.rtdn_audience'));
        $expectedEmail = strtolower(trim((string) config('store_billing.google.rtdn_service_account_email')));
        if ($audience === '' || $expectedEmail === '') {
            throw new StorePurchaseVerificationException(
                'google_rtdn_not_configured',
                'Google Play notifications are not configured.',
                503
            );
        }

        try {
            $client = new GoogleClient(['client_id' => $audience]);
            $claims = $client->verifyIdToken($token);
        } catch (\Throwable $exception) {
            report($exception);
            $claims = false;
        }

        if (! is_array($claims)) {
            throw new StorePurchaseVerificationException(
                'google_rtdn_identity_invalid',
                'Google Play notification identity is invalid.',
                401
            );
        }

        $issuer = (string) ($claims['iss'] ?? '');
        $email = strtolower((string) ($claims['email'] ?? ''));
        $verified = filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOL);
        if (
            ! in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true)
            || ! $verified
            || ! hash_equals($expectedEmail, $email)
            || ! hash_equals($audience, (string) ($claims['aud'] ?? ''))
        ) {
            throw new StorePurchaseVerificationException(
                'google_rtdn_identity_mismatch',
                'Google Play notification identity is invalid.',
                401
            );
        }

        return $claims;
    }

    public function verifyAppleSignedPayload(string $signedPayload): array
    {
        return $this->storeGateway->verifyAppleSignedPayload($signedPayload);
    }
}
