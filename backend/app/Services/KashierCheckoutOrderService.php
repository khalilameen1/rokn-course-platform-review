<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseCheckout;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class KashierCheckoutOrderService
{
    public function __construct(private PackageChannelPricingService $pricing)
    {
    }

    /**
     * @return array{order: Order, reused: bool, closed: ?string}
     */
    public function beginCheckout(
        User $user,
        Package $package,
        string $clientRequestKey,
        ?float $expectedAmount = null,
        ?int $expectedCoins = null,
        ?string $courseCheckoutId = null
    ): array
    {
        return DB::transaction(function () use (
            $user,
            $package,
            $clientRequestKey,
            $expectedAmount,
            $expectedCoins,
            $courseCheckoutId
        ): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            // The package is catalogue input, not the learner's financial
            // aggregate. Locking it serialized every buyer of the same popular
            // package. Published store coin terms are immutable and direct
            // price/coin facts are copied into the order below, so a consistent
            // transaction read is sufficient here.
            $package = Package::query()->findOrFail($package->id);
            $courseCheckout = $courseCheckoutId === null ? null : CourseCheckout::query()
                ->where('user_id', $user->id)->where('public_id', $courseCheckoutId)
                ->lockForUpdate()->firstOrFail();
            if ($courseCheckout && ($courseCheckout->channel !== 'direct'
                || !$courseCheckout->authorized_at
                || (int) $courseCheckout->package_id !== (int) $package->id)) {
                throw new \DomainException('checkout_funding_mismatch');
            }

            $existing = null;
            if ($clientRequestKey !== '') {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('checkout_request_key', $clientRequestKey)
                    ->first();

                if (
                    $existing
                    && (
                        (int) $existing->package_id !== (int) $package->id
                        || $existing->payment_method !== Order::PAYMENT_METHOD_KASHIER
                    )
                ) {
                    throw new \UnexpectedValueException(
                        'Checkout idempotency key was reused for another package.'
                    );
                }
            } else {
                $existing = Order::query()
                    ->where('user_id', $user->id)
                    ->where('package_id', $package->id)
                    ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                    ->where('status', Order::STATUS_PENDING)
                    ->where('created_at', '>=', now()->subMinutes(10))
                    ->where(function ($query): void {
                        $query->whereNull('checkout_expires_at')
                            ->orWhere('checkout_expires_at', '>', now());
                    })
                    ->latest('id')
                    ->first();
            }

            if ($existing) {
                if ($courseCheckout && (int) $courseCheckout->funding_order_id !== (int) $existing->id) {
                    throw new \DomainException('checkout_funding_mismatch');
                }
                if (
                    ($expectedAmount !== null
                        && (int) round((float) $existing->final_amount * 100)
                            !== (int) round($expectedAmount * 100))
                    || ($expectedCoins !== null
                        && (int) $existing->package_coins !== $expectedCoins)
                ) {
                    throw new \UnexpectedValueException(
                        'Checkout idempotency key was replayed with different package terms.'
                    );
                }
                if ($existing->isCheckoutExpired()) {
                    return [
                        'order' => $existing,
                        'reused' => true,
                        'closed' => 'expired',
                    ];
                }

                if ($existing->status !== Order::STATUS_PENDING) {
                    return [
                        'order' => $existing,
                        'reused' => true,
                        'closed' => 'closed',
                    ];
                }

                return ['order' => $existing, 'reused' => true, 'closed' => null];
            }

            if ($courseCheckout && ($courseCheckout->status !== 'pending_payment'
                || $courseCheckout->quoteHasExpired() || $courseCheckout->funding_order_id)) {
                throw new \DomainException('checkout_funding_mismatch');
            }

            if (
                !$package->is_active
                || !$package->direct_enabled
                || (float) $package->price <= 0
                || (int) $package->coins <= 0
            ) {
                throw new \UnexpectedValueException(
                    'This package is not available for checkout.'
                );
            }

            $otherPendingCheckout = Order::query()
                ->where('user_id', $user->id)
                ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                ->where('status', Order::STATUS_PENDING)
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if ($otherPendingCheckout) {
                throw new \UnexpectedValueException(
                    'A previous payment is still pending confirmation.'
                );
            }

            // Course funding is read only from the authorized server snapshot.
            // Neither client expectations nor a changed catalogue can set the
            // amount or turn a course shortfall into a full package purchase.
            $funding = $courseCheckout?->terms['selected_package'] ?? null;
            $baseAmount = (float) ($funding['price'] ?? $package->price);
            $finalAmount = (float) ($funding['direct_price'] ?? $this->pricing->directPrice($package));
            $coins = (int) ($funding['coins'] ?? $package->coins);
            if ($coins < 1 || $baseAmount <= 0 || $finalAmount <= 0
                || ($courseCheckout && (int) ($funding['id'] ?? 0) !== (int) $package->id)) {
                throw new \DomainException('checkout_funding_mismatch');
            }
            if (
                ($expectedAmount !== null
                    && (int) round($finalAmount * 100) !== (int) round($expectedAmount * 100))
                || ($expectedCoins !== null && $coins !== $expectedCoins)
            ) {
                throw new \UnexpectedValueException(
                    'Package terms changed before checkout.'
                );
            }
            $order = Order::create([
                'user_id' => $user->id,
                'package_id' => $package->id,
                'package_coins' => $coins,
                'payment_method' => Order::PAYMENT_METHOD_KASHIER,
                'order_ref' => 'PKG-' . strtoupper(str_replace('-', '', (string) Str::uuid())),
                'checkout_request_key' => $clientRequestKey !== ''
                    ? $clientRequestKey
                    : 'server-' . (string) Str::uuid(),
                'checkout_expires_at' => now()->addMinutes(Order::KASHIER_CHECKOUT_TTL_MINUTES),
                'amount' => $baseAmount,
                'discount_amount' => round($baseAmount - $finalAmount, 2),
                'final_amount' => $finalAmount,
                'status' => Order::STATUS_PENDING,
                'financial_status' => Order::FINANCIAL_PENDING,
                'is_premium_user' => $user->isPremiumUser(),
                'notes' => $courseCheckout ? 'Course funding '.$courseCheckout->public_id : null,
            ]);

            return ['order' => $order, 'reused' => false, 'closed' => null];
        }, 3);
    }
}
