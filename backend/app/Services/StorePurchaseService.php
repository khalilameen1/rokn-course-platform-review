<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\StorePurchaseProviderGateway;
use App\Data\VerifiedStorePurchase;
use App\Exceptions\StorePurchaseVerificationException;
use App\Models\Order;
use App\Models\Package;
use App\Models\StoreNotificationEvent;
use App\Models\StorePurchase;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class StorePurchaseService
{
    public function __construct(
        private StorePurchaseProviderGateway $gateway,
        private StoreBillingAccountIdentity $identities,
        private OrderLifecycleService $orders,
        private WalletQueryService $wallet,
        private StorePurchaseFinalizationService $finalization
    ) {
    }

    /** @return array<string, mixed> */
    public function verifyAndCredit(
        User $user,
        string $provider,
        string $productId,
        string $purchaseToken,
        ?string $transactionId
    ): array {
        $tokenHash = hash('sha256', $purchaseToken);

        $existing = StorePurchase::query()
            ->with('package')
            ->where('provider', $provider)
            ->where('purchase_token_hash', $tokenHash)
            ->first();
        if ($existing) {
            if (!$existing->package) {
                throw new StorePurchaseVerificationException(
                    'store_purchase_receipt_incomplete',
                    'تعذّر مطابقة عملية الشراء بالباقة',
                    409
                );
            }

            // Restore/finalization belongs to the immutable issued receipt.
            // A package may be retired after payment without making that paid
            // receipt impossible to recover on a new device.
            return $this->replay($existing, $user, $existing->package, $productId);
        }

        // Product ids and their coin value form the immutable fulfilment
        // contract. Availability flags only control whether a new sheet may
        // be opened; they must not invalidate a receipt already paid in the
        // provider UI.
        $package = $this->packageContractForProduct($provider, $productId);
        $contractCoins = (int) $package->coins;

        $binding = $provider === StorePurchase::PROVIDER_GOOGLE
            ? $this->identities->google($user)
            : $this->identities->apple($user);
        $verified = $this->gateway->verify(
            $provider,
            $productId,
            $purchaseToken,
            $transactionId,
            $binding
        );
        return $this->creditVerified($user, $package, $purchaseToken, $verified, $provider, $productId, $binding, $contractCoins);
    }

    /** Recover a completed purchase even when its device never returns to the app. */
    public function recoverGooglePurchase(string $productId, string $purchaseToken): array
    {
        $package = $this->packageContractForProduct(StorePurchase::PROVIDER_GOOGLE, $productId);
        $contractCoins = (int) $package->coins;
        $verified = $this->gateway->verifyGoogleNotification($productId, $purchaseToken);
        $binding = (string) $verified->accountBinding;
        $user = $binding !== '' ? $this->identities->googleUser($binding) : null;
        if (!$user || $user->trashed()) {
            throw new StorePurchaseVerificationException('store_account_not_recoverable');
        }

        return $this->creditVerified(
            $user, $package, $purchaseToken, $verified,
            StorePurchase::PROVIDER_GOOGLE, $productId, $binding, $contractCoins
        );
    }

    /** Only the provider gateway supplies the environment, quantity and account evidence. */
    private function creditVerified(
        User $user,
        Package $package,
        string $purchaseToken,
        VerifiedStorePurchase $verified,
        string $provider,
        string $productId,
        string $binding,
        int $contractCoins
    ): array {
        if (
            !hash_equals($provider, $verified->provider)
            || !hash_equals($productId, $verified->productId)
        ) {
            throw new StorePurchaseVerificationException('store_verification_contract_mismatch');
        }
        $environment = strtolower(trim($verified->environment));
        $allowedEnvironments = $provider === StorePurchase::PROVIDER_GOOGLE
            ? ['production', 'test'] : ['production', 'sandbox'];
        if (!in_array($environment, $allowedEnvironments, true)) {
            throw new StorePurchaseVerificationException('store_purchase_environment_invalid');
        }
        if ($verified->quantity !== 1) {
            throw new StorePurchaseVerificationException('store_purchase_quantity_unsupported');
        }
        if ($verified->accountBinding !== null && !hash_equals(strtolower($binding), strtolower($verified->accountBinding))) {
            throw new StorePurchaseVerificationException('store_account_mismatch');
        }
        $tokenHash = hash('sha256', $purchaseToken);

        $alreadyProcessed = false;
        try {
            $storePurchase = DB::transaction(function () use (
                $user,
                $package,
                $provider,
                $productId,
                $purchaseToken,
                $tokenHash,
                $verified,
                $contractCoins,
                $binding,
                $environment,
                &$alreadyProcessed
            ): StorePurchase {
                /** @var Package $lockedPackage */
                // Store product id and coin quantity are immutable after the
                // contract is issued. A row lock here only made independent
                // receipt verifications for the same SKU wait on each other.
                $lockedPackage = Package::query()->findOrFail($package->id);
                $providerProductColumn = $provider === StorePurchase::PROVIDER_GOOGLE
                    ? 'google_product_id'
                    : 'apple_product_id';
                if (
                    !hash_equals((string) $lockedPackage->{$providerProductColumn}, $productId)
                    || $contractCoins <= 0
                    || (int) $lockedPackage->coins !== $contractCoins
                ) {
                    throw new StorePurchaseVerificationException(
                        'store_product_catalog_changed',
                        "تغيّرت تفاصيل الباقة\nراجعها ثم حاول مرة أخرى",
                        409
                    );
                }
                $package = $lockedPackage;

                $existing = StorePurchase::query()
                    ->where('provider', $provider)
                    ->where(function ($query) use ($tokenHash, $verified): void {
                        $query->where('purchase_token_hash', $tokenHash)
                            ->orWhere('external_transaction_id', $verified->externalTransactionId);
                    })
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    $this->assertReplayMatches($existing, $user, $package, $productId);
                    $alreadyProcessed = true;
                    return $existing;
                }

                $catalogAmount = (float) $package->price;
                $isTest = in_array($environment, ['test', 'sandbox'], true);
                $gatewayGross = $isTest ? 0.0 : $verified->grossAmount;
                $gatewayCurrency = $verified->currency;
                $transactionKey = $provider . ':' . $verified->externalTransactionId;

                $order = Order::query()->create([
                    'user_id' => $user->id,
                    'course_id' => null,
                    'package_id' => $package->id,
                    'package_coins' => (int) $package->coins,
                    'payment_method' => $provider === StorePurchase::PROVIDER_GOOGLE
                        ? Order::PAYMENT_METHOD_GOOGLE_PLAY
                        : Order::PAYMENT_METHOD_APP_STORE,
                    'order_ref' => strtoupper($provider) . '-' . Str::orderedUuid(),
                    'transaction_id' => $transactionKey,
                    // Test coins can exercise real entitlements/AI but never
                    // represent cash, including downstream lot attribution.
                    'amount' => $isTest ? 0 : $catalogAmount,
                    'discount_amount' => 0,
                    'final_amount' => $isTest ? 0 : $catalogAmount,
                    'gateway_gross_amount' => $gatewayGross,
                    'gateway_fee_amount' => $isTest ? 0 : null,
                    'gateway_net_amount' => $isTest ? 0 : null,
                    'gateway_currency' => $gatewayCurrency,
                    'gateway_settlement_status' => $isTest
                        ? 'test_purchase'
                        : ($verified->grossAmount === null ? 'catalog_estimate' : 'provider_verified'),
                    'total_coins' => (int) $package->coins,
                    'status' => Order::STATUS_PENDING,
                    'financial_status' => Order::FINANCIAL_PENDING,
                    'is_premium_user' => false,
                    'payment_gateway_response' => [
                        'provider' => $provider,
                        'product_id' => $productId,
                        'environment' => $environment,
                        'verification' => $verified->auditPayload,
                    ],
                ]);

                $purchase = StorePurchase::query()->create([
                    'public_id' => (string) Str::uuid(),
                    'user_id' => $user->id,
                    'package_id' => $package->id,
                    'order_id' => $order->id,
                    'provider' => $provider,
                    'product_id' => $productId,
                    'external_transaction_id' => $verified->externalTransactionId,
                    'purchase_token_hash' => $tokenHash,
                    'purchase_token' => $purchaseToken,
                    'environment' => $environment,
                    'status' => 'verified',
                    'provider_payload' => array_merge($verified->auditPayload, ['account_binding' => $binding]),
                    'verified_at' => now(),
                ]);

                $approved = $this->orders->approve($order, null, null, true);
                $purchase->forceFill([
                    'status' => $approved->status === Order::STATUS_APPROVED
                        ? 'credited'
                        : 'review_required',
                ])->save();

                return $purchase->fresh(['order']);
            }, 3);
        } catch (QueryException $exception) {
            // Two devices can submit the same store receipt before either sees
            // the other's row. The database uniqueness constraint is the final
            // arbiter; the losing request becomes a normal idempotent replay.
            if (!in_array((string) $exception->getCode(), ['23000', '23505'], true)) {
                throw $exception;
            }
            $storePurchase = StorePurchase::query()
                ->where('provider', $provider)
                ->where(function ($query) use ($tokenHash, $verified): void {
                    $query->where('purchase_token_hash', $tokenHash)
                        ->orWhere('external_transaction_id', $verified->externalTransactionId);
                })
                ->with('order')
                ->first();
            if (!$storePurchase) throw $exception;
            $this->assertReplayMatches($storePurchase, $user, $package, $productId);
            $alreadyProcessed = true;
        }

        if ($alreadyProcessed) {
            return $this->replay($storePurchase, $user, $package, $productId);
        }
        $this->reconcilePendingStoreNotifications($storePurchase);
        $this->finalizeAfterCommit($storePurchase);

        return $this->result(
            $storePurchase->fresh(['order']),
            $user,
            $alreadyProcessed
        );
    }

    /** @return array<string, mixed> */
    private function replay(
        StorePurchase $purchase,
        User $user,
        Package $package,
        string $productId
    ): array {
        $this->assertReplayMatches($purchase, $user, $package, $productId);
        $order = $purchase->order;
        if (!$order) {
            throw new StorePurchaseVerificationException(
                'store_purchase_receipt_incomplete',
                'تعذّر مطابقة عملية الشراء بالطلب',
                409
            );
        }

        // A replay may finish the one pending fulfilment created by the same
        // verified receipt. It must never turn a cancelled/rejected purchase,
        // or a refund/chargeback under review, back into settled money.
        if (
            $order->status === Order::STATUS_PENDING
            && $order->financial_status === Order::FINANCIAL_PENDING
            && !$order->reversed_at
        ) {
            $this->orders->approve($purchase->order, null, null, true);
            $purchase->forceFill(['status' => 'credited'])->save();
        }
        $this->reconcilePendingStoreNotifications($purchase);
        $this->finalizeAfterCommit($purchase);

        return $this->result($purchase->fresh(['order']), $user, true);
    }

    private function finalizeAfterCommit(StorePurchase $purchase): void
    {
        // Includes outer caller transactions. A rolled-back credit must never
        // consume a paid token. The persisted unfinalized row is the retry queue.
        DB::afterCommit(fn () => $this->finalization->attempt((int) $purchase->id));
    }

    /**
     * Refund notifications can beat the device receipt to our database. They
     * were already authenticated at ingestion, so once the matching purchase
     * exists we apply them in financial order instead of leaving a brief
     * credit-without-charge window for manual cleanup.
     */
    private function reconcilePendingStoreNotifications(StorePurchase $purchase): void
    {
        if ($purchase->provider === StorePurchase::PROVIDER_APPLE) {
            $this->reconcileAppleNotifications($purchase);
            return;
        }
        $query = StoreNotificationEvent::query()
            ->where('provider', $purchase->provider)
            ->where('status', StoreNotificationEvent::STATUS_REVIEW_REQUIRED)
            ->where(function ($events): void {
                $events->where(function ($voided): void {
                    $voided->where('event_type', 'voided_purchase')
                        ->where('error_code', 'store_purchase_not_found');
                })->orWhere(function ($cancelled): void {
                    $cancelled->whereIn('event_type', ['one_time_product_1', 'one_time_product_2'])
                        ->where('error_code', 'store_purchase_cancelled_before_receipt');
                });
            });

        $query->where(function ($events) use ($purchase): void {
            $events->where('payload->purchase_token_sha256', $purchase->purchase_token_hash)
                ->orWhere('payload->order_id', $purchase->external_transaction_id);
        });

        $events = $query->get();
        foreach ($events as $event) {
            $payload = is_array($event->payload) ? $event->payload : [];
            $this->orders->registerReversal(
                $purchase->order,
                Order::FINANCIAL_REFUNDED,
                $event->event_type !== 'voided_purchase'
                    ? 'Google Play cancelled purchase'
                    : ((int) ($payload['refund_type'] ?? 1) === 2
                        ? 'Google Play quantity-based refund'
                        : 'Google Play voided purchase'),
                'store-notification:google:' . $event->event_id,
                null,
                Order::PAYMENT_METHOD_GOOGLE_PLAY,
                $event->event_id,
                $payload
            );
            $purchase->forceFill(['status' => 'refunded'])->save();

            $event->forceFill([
                'status' => StoreNotificationEvent::STATUS_PROCESSED,
                'error_code' => null,
                'processed_at' => now(),
            ])->save();
        }
    }

    /** Apply the newest authenticated Apple snapshot, never network arrival order. */
    public function reconcileAppleNotifications(StorePurchase $purchase): void
    {
        if ($purchase->provider !== StorePurchase::PROVIDER_APPLE) return;

        DB::transaction(function () use ($purchase): void {
            User::withTrashed()->lockForUpdate()->findOrFail($purchase->user_id);
            $order = Order::query()->lockForUpdate()->findOrFail($purchase->order_id);
            $events = StoreNotificationEvent::query()
                ->where('provider', StorePurchase::PROVIDER_APPLE)
                ->whereIn('event_type', ['refund', 'refund_reversed'])
                ->where('payload->product_id', $purchase->product_id)
                ->where(function ($query) use ($purchase): void {
                    $query->where('payload->transaction_id', $purchase->external_transaction_id)
                        ->orWhere('payload->original_transaction_id', $purchase->external_transaction_id);
                })->lockForUpdate()->get()->filter(function (StoreNotificationEvent $event) use ($purchase): bool {
                    $payload = (array) $event->payload;
                    return strtolower((string) ($payload['environment'] ?? '')) === strtolower($purchase->environment)
                        && (string) ($payload['bundle_id'] ?? '') === (string) config('store_billing.apple.bundle_id')
                        && (!isset($payload['transaction_type']) || $payload['transaction_type'] === 'Consumable');
                });
            if ($events->isEmpty()) return;

            // Apple documents signedDate as the state snapshot timestamp and
            // explicitly requires using the newest one for a transaction.
            // Legacy/ambiguous evidence stays visible for financial review.
            $invalidDate = $events->contains(fn (StoreNotificationEvent $event): bool =>
                !is_numeric(data_get($event->payload, 'signed_date')) || (int) data_get($event->payload, 'signed_date') <= 0
            );
            $latest = $events->sortByDesc(fn (StoreNotificationEvent $event): int => (int) data_get($event->payload, 'signed_date'))->first();
            $sameTimeTypes = $events->filter(fn (StoreNotificationEvent $event): bool =>
                data_get($event->payload, 'signed_date') == data_get($latest->payload, 'signed_date')
            )->pluck('event_type')->unique();
            if ($invalidDate || $sameTimeTypes->count() > 1) {
                foreach ($events->whereNotIn('status', ['processed', 'ignored']) as $event) {
                    $event->forceFill(['status' => 'review_required', 'error_code' => 'apple_notification_chronology_ambiguous', 'processed_at' => now()])->save();
                }
                return;
            }

            if ($latest->status !== StoreNotificationEvent::STATUS_PROCESSED) {
                if ($latest->event_type === 'refund') {
                    $this->orders->registerReversal(
                        $order, Order::FINANCIAL_REFUNDED, 'App Store refund',
                        'store-notification:apple:' . $latest->event_id,
                        null, Order::PAYMENT_METHOD_APP_STORE, $latest->event_id, (array) $latest->payload
                    );
                    $purchase->forceFill(['status' => 'refunded'])->save();
                } elseif ($order->financial_status === Order::FINANCIAL_REVIEW_REQUIRED && $order->reversed_at) {
                    $this->orders->resolveFinancialReview(
                        $order, 'repaid', 'store-notification:apple:' . $latest->event_id,
                        null, 'App Store reversed a prior refund.'
                    );
                    $purchase->forceFill(['status' => 'credited'])->save();
                } elseif (!$order->isFinanciallyEffective()) {
                    $latest->forceFill(['status' => 'review_required', 'error_code' => 'refund_reversal_requires_manual_review', 'processed_at' => now()])->save();
                    return;
                }
                $latest->forceFill(['status' => 'processed', 'error_code' => null, 'processed_at' => now()])->save();
            }
            foreach ($events->where('id', '!=', $latest->id)->whereNotIn('status', ['processed', 'ignored']) as $event) {
                $event->forceFill(['status' => 'ignored', 'error_code' => null, 'processed_at' => now()])->save();
            }
        }, 3);
    }

    private function assertReplayMatches(
        StorePurchase $purchase,
        User $user,
        Package $package,
        string $productId
    ): void {
        if (
            (int) $purchase->user_id !== (int) $user->id
            || (int) $purchase->package_id !== (int) $package->id
            || !hash_equals((string) $purchase->product_id, $productId)
        ) {
            throw new StorePurchaseVerificationException(
                'store_purchase_already_claimed',
                'عملية الشراء مرتبطة بحساب آخر',
                409
            );
        }
    }

    private function packageContractForProduct(string $provider, string $productId): Package
    {
        $idColumn = match ($provider) {
            StorePurchase::PROVIDER_GOOGLE => 'google_product_id',
            StorePurchase::PROVIDER_APPLE => 'apple_product_id',
            default => throw new StorePurchaseVerificationException('unsupported_store_provider'),
        };
        $package = Package::query()
            ->where($idColumn, $productId)
            ->where('coins', '>', 0)
            ->first();
        if (!$package) {
            throw new StorePurchaseVerificationException(
                'store_product_not_configured',
                'باقة الشحن غير متاحة الآن'
            );
        }

        return $package;
    }

    /** @return array<string, mixed> */
    private function result(
        StorePurchase $purchase,
        User $user,
        bool $alreadyProcessed
    ): array {
        $order = $purchase->relationLoaded('order')
            ? $purchase->order
            : $purchase->order()->first();
        $isSettled = $order
            && $order->status === Order::STATUS_APPROVED
            && $order->financial_status === Order::FINANCIAL_SETTLED
            && !$order->reversed_at;
        $creditedCoins = $isSettled
            ? max(0, (int) ($order->package_coins ?? 0))
            : 0;
        if ($creditedCoins === 0 && $isSettled) {
            $creditedCoins = max(0, (int) $order->paidCreditLot()->value('original_amount'));
        }

        return [
            'purchase_id' => $purchase->public_id,
            'provider' => $purchase->provider,
            'product_id' => $purchase->product_id,
            'environment' => $purchase->environment,
            // The issued order is the purchase receipt. Package rows remain
            // editable catalogue data and must never rewrite a past credit.
            'coins_added' => $creditedCoins,
            'credited' => $isSettled,
            'financial_status' => $order?->financial_status,
            'already_processed' => $alreadyProcessed,
            'finalize_transaction' => true,
            'store_finalized' => $purchase->finalized_at !== null,
            'wallet' => $this->wallet->summary($user),
        ];
    }
}
