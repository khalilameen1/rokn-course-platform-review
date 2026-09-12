<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StoreNotificationAuthenticityVerifier;
use App\Exceptions\StorePurchaseVerificationException;
use App\Models\Order;
use App\Models\StoreNotificationEvent;
use App\Models\StorePurchase;
use Illuminate\Support\Facades\Log;

final readonly class StoreServerNotificationService
{
    public function __construct(
        private StoreNotificationAuthenticityVerifier $authenticity,
        private OrderLifecycleService $orders,
        private StorePurchaseService $purchases
    ) {
    }

    /** @return array{status:string,event_id:string} */
    public function handleGoogle(array $envelope, string $authorizationToken): array
    {
        $this->authenticity->verifyGooglePushToken($authorizationToken);

        $message = is_array($envelope['message'] ?? null) ? $envelope['message'] : [];
        $eventId = trim((string) ($message['messageId'] ?? $message['message_id'] ?? ''));
        $encoded = (string) ($message['data'] ?? '');
        $decoded = base64_decode($encoded, true);
        $payload = is_string($decoded) ? json_decode($decoded, true) : null;
        if ($eventId === '' || !is_array($payload)) {
            throw new StorePurchaseVerificationException(
                'google_rtdn_payload_invalid',
                'Google Play notification payload is invalid.'
            );
        }

        $packageName = trim((string) ($payload['packageName'] ?? ''));
        if (!hash_equals((string) config('store_billing.google.package_name'), $packageName)) {
            throw new StorePurchaseVerificationException(
                'google_rtdn_package_mismatch',
                'Google Play notification package is invalid.',
                401
            );
        }

        $voided = is_array($payload['voidedPurchaseNotification'] ?? null)
            ? $payload['voidedPurchaseNotification']
            : null;
        $oneTime = is_array($payload['oneTimeProductNotification'] ?? null)
            ? $payload['oneTimeProductNotification']
            : null;
        $eventType = $voided
            ? 'voided_purchase'
            : ($oneTime ? 'one_time_product_' . (string) ($oneTime['notificationType'] ?? 'unknown') : 'other');
        $purchaseToken = trim((string) (($voided ?? $oneTime)['purchaseToken'] ?? ''));
        $safePayload = [
            'package_name' => $packageName,
            'event_time_millis' => $payload['eventTimeMillis'] ?? null,
            'purchase_token_sha256' => $purchaseToken !== '' ? hash('sha256', $purchaseToken) : null,
            'order_id' => $voided['orderId'] ?? null,
            'product_type' => $voided['productType'] ?? null,
            'refund_type' => $voided['refundType'] ?? null,
            'product_id' => $oneTime['sku'] ?? null,
            'notification_type' => $oneTime['notificationType'] ?? null,
        ];
        $event = $this->receiveEvent(
            StorePurchase::PROVIDER_GOOGLE,
            $eventId,
            $eventType,
            hash('sha256', $decoded),
            $safePayload
        );
        if ($this->finished($event)) {
            return ['status' => $event->status, 'event_id' => $eventId];
        }

        if ($oneTime && !$voided && in_array((int) ($oneTime['notificationType'] ?? 0), [1, 2], true)) {
            return $this->handleGoogleOneTime($event, $oneTime, $purchaseToken);
        }
        if (!$voided) {
            return $this->finish($event, StoreNotificationEvent::STATUS_IGNORED);
        }
        if ((int) ($voided['productType'] ?? 0) !== 2 || $purchaseToken === '') {
            return $this->review($event, 'unsupported_google_voided_purchase');
        }

        $purchase = StorePurchase::query()
            ->where('provider', StorePurchase::PROVIDER_GOOGLE)
            ->where(function ($query) use ($purchaseToken, $voided): void {
                $query->where('purchase_token_hash', hash('sha256', $purchaseToken));
                $orderId = trim((string) ($voided['orderId'] ?? ''));
                if ($orderId !== '') {
                    $query->orWhere('external_transaction_id', $orderId);
                }
            })
            ->with('order')
            ->first();
        if (!$purchase?->order) {
            return $this->review($event, 'store_purchase_not_found');
        }

        $reason = (int) ($voided['refundType'] ?? 1) === 2
            ? 'Google Play quantity-based refund'
            : 'Google Play voided purchase';
        $this->orders->registerReversal(
            $purchase->order,
            Order::FINANCIAL_REFUNDED,
            $reason,
            'store-notification:google:' . $eventId,
            null,
            Order::PAYMENT_METHOD_GOOGLE_PLAY,
            $eventId,
            $safePayload
        );
        $purchase->forceFill(['status' => 'refunded'])->save();

        return $this->finish($event, StoreNotificationEvent::STATUS_PROCESSED);
    }

    private function handleGoogleOneTime(StoreNotificationEvent $event, array $notification, string $purchaseToken): array
    {
        $productId = trim((string) ($notification['sku'] ?? ''));
        if ($productId === '' || $purchaseToken === '') {
            return $this->review($event, 'google_rtdn_purchase_incomplete');
        }
        try {
            // RTDN is a prompt to query current provider state, not proof of
            // payment. Account lookup uses only the verified opaque binding.
            $this->purchases->recoverGooglePurchase($productId, $purchaseToken);
        } catch (StorePurchaseVerificationException $exception) {
            if ($exception->errorCode === 'store_purchase_cancelled') {
                $purchaseQuery = StorePurchase::query()
                    ->where('provider', StorePurchase::PROVIDER_GOOGLE)
                    ->where('product_id', $productId)
                    ->where('purchase_token_hash', hash('sha256', $purchaseToken))
                    ->with('order');
                $purchase = $purchaseQuery->first();
                if (!$purchase?->order) {
                    // A device may already hold an older PURCHASED snapshot.
                    // Persist the provider-confirmed cancellation so its later
                    // credit reconciles it, even if this RTDN is acknowledged.
                    $result = $this->review($event, 'store_purchase_cancelled_before_receipt');
                    // Close the race where credit committed/reconciled between
                    // the first lookup and persisting the cancellation marker.
                    $purchase = $purchaseQuery->first();
                    if (!$purchase?->order) return $result;
                }
                if ($purchase?->order) {
                    $this->orders->registerReversal(
                        $purchase->order,
                        Order::FINANCIAL_REFUNDED,
                        'Google Play cancelled purchase',
                        'store-notification:google:' . $event->event_id,
                        null,
                        Order::PAYMENT_METHOD_GOOGLE_PLAY,
                        $event->event_id,
                        $event->payload
                    );
                    $purchase->forceFill(['status' => 'refunded'])->save();
                }

                return $this->finish($event, StoreNotificationEvent::STATUS_PROCESSED);
            }
            if ($exception->errorCode === 'store_purchase_pending') {
                // A notification can precede provider API propagation. Ask
                // Pub/Sub to retry; pending payments never receive coins.
                throw new StorePurchaseVerificationException('store_purchase_pending', 'Purchase confirmation is pending.', 503);
            }
            if ($exception->httpStatus >= 500) throw $exception;

            return $this->review($event, $exception->errorCode);
        }

        return $this->finish($event, StoreNotificationEvent::STATUS_PROCESSED);
    }

    /** @return array{status:string,event_id:string} */
    public function handleApple(string $signedPayload): array
    {
        $outer = $this->authenticity->verifyAppleSignedPayload($signedPayload);
        $eventId = trim((string) ($outer['notificationUUID'] ?? ''));
        $eventType = strtoupper(trim((string) ($outer['notificationType'] ?? '')));
        $data = is_array($outer['data'] ?? null) ? $outer['data'] : [];
        if ($eventId === '' || $eventType === '') {
            throw new StorePurchaseVerificationException('apple_notification_payload_invalid');
        }
        if (!hash_equals((string) config('store_billing.apple.bundle_id'), (string) ($data['bundleId'] ?? ''))) {
            throw new StorePurchaseVerificationException(
                'apple_notification_bundle_mismatch',
                'App Store notification bundle is invalid.',
                401
            );
        }

        $transactionClaims = [];
        $signedTransaction = trim((string) ($data['signedTransactionInfo'] ?? ''));
        if ($signedTransaction !== '') {
            $transactionClaims = $this->authenticity->verifyAppleSignedPayload($signedTransaction);
        }
        $transactionId = trim((string) ($transactionClaims['transactionId'] ?? ''));
        $safePayload = [
            'notification_type' => $eventType,
            'signed_date' => $outer['signedDate'] ?? null,
            'subtype' => $outer['subtype'] ?? null,
            'environment' => $data['environment'] ?? null,
            'bundle_id' => $data['bundleId'] ?? null,
            'transaction_id' => $transactionId ?: null,
            'original_transaction_id' => $transactionClaims['originalTransactionId'] ?? null,
            'product_id' => $transactionClaims['productId'] ?? null,
            'transaction_type' => $transactionClaims['type'] ?? null,
            'revocation_date' => $transactionClaims['revocationDate'] ?? null,
            'revocation_reason' => $transactionClaims['revocationReason'] ?? null,
        ];
        $event = $this->receiveEvent(
            StorePurchase::PROVIDER_APPLE,
            $eventId,
            strtolower($eventType),
            hash('sha256', $signedPayload),
            $safePayload
        );
        if ($this->finished($event)) {
            return ['status' => $event->status, 'event_id' => $eventId];
        }

        if ($eventType === 'CONSUMPTION_REQUEST') {
            // Apple requires separate, explicit customer consent before the
            // server may share consumption data. Keep the request visible for
            // operations without silently sending personal usage details.
            return $this->review($event, 'apple_consumption_consent_required');
        }
        if (!in_array($eventType, ['REFUND', 'REFUND_REVERSED'], true)) {
            return $this->finish($event, StoreNotificationEvent::STATUS_IGNORED);
        }
        if ($transactionId === '' || (string) ($transactionClaims['type'] ?? '') !== 'Consumable') {
            return $this->review($event, 'apple_transaction_missing_or_unsupported');
        }

        $purchase = StorePurchase::query()
            ->where('provider', StorePurchase::PROVIDER_APPLE)
            ->where(function ($query) use ($transactionClaims, $transactionId): void {
                $query->where('external_transaction_id', $transactionId);
                $original = trim((string) ($transactionClaims['originalTransactionId'] ?? ''));
                if ($original !== '') {
                    $query->orWhere('external_transaction_id', $original);
                }
            })
            ->with('order')
            ->first();
        if (!$purchase?->order) {
            return $this->review($event, 'store_purchase_not_found');
        }
        if (!hash_equals((string) $purchase->product_id, (string) ($transactionClaims['productId'] ?? ''))) {
            return $this->review($event, 'store_product_mismatch');
        }
        if (strtolower((string) ($data['environment'] ?? '')) !== strtolower((string) $purchase->environment)) {
            return $this->review($event, 'store_purchase_environment_invalid');
        }
        $this->purchases->reconcileAppleNotifications($purchase);

        return ['status' => $event->fresh()->status, 'event_id' => $eventId];
    }

    private function receiveEvent(
        string $provider,
        string $eventId,
        string $eventType,
        string $payloadHash,
        array $payload
    ): StoreNotificationEvent {
        $event = StoreNotificationEvent::query()->firstOrCreate(
            ['provider' => $provider, 'event_id' => $eventId],
            [
                'event_type' => $eventType,
                'status' => StoreNotificationEvent::STATUS_RECEIVED,
                'payload_sha256' => $payloadHash,
                'payload' => $payload,
                'received_at' => now(),
            ]
        );
        if (!hash_equals((string) $event->payload_sha256, $payloadHash)) {
            throw new StorePurchaseVerificationException(
                'store_notification_event_conflict',
                'Store notification identity was reused.',
                409
            );
        }

        return $event;
    }

    private function finished(StoreNotificationEvent $event): bool
    {
        return in_array($event->status, [
            StoreNotificationEvent::STATUS_PROCESSED,
            StoreNotificationEvent::STATUS_IGNORED,
        ], true);
    }

    /** @return array{status:string,event_id:string} */
    private function finish(StoreNotificationEvent $event, string $status): array
    {
        $event->forceFill([
            'status' => $status,
            'error_code' => null,
            'processed_at' => now(),
        ])->save();

        return ['status' => $status, 'event_id' => $event->event_id];
    }

    /** @return array{status:string,event_id:string} */
    private function review(StoreNotificationEvent $event, string $errorCode): array
    {
        $event->forceFill([
            'status' => StoreNotificationEvent::STATUS_REVIEW_REQUIRED,
            'error_code' => $errorCode,
            'processed_at' => now(),
        ])->save();
        Log::warning('Store notification requires review', [
            'provider' => $event->provider,
            'event_id' => $event->event_id,
            'event_type' => $event->event_type,
            'error_code' => $errorCode,
        ]);

        return ['status' => $event->status, 'event_id' => $event->event_id];
    }
}
