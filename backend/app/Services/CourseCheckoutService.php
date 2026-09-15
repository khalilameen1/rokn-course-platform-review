<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseCheckout;
use App\Models\CourseEnrollment;
use App\Models\Order;
use App\Models\StorePurchase;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable consent; existing wallet/order actions remain the only debit authority. */
final readonly class CourseCheckoutService
{
    public function __construct(private CourseCheckoutQuoteService $quotes, private WalletService $wallet,
        private CoursePlanUpgradeAction $upgrades, private CoursePurchaseAction $purchases) {}

    public function create(User $user, array $input): array
    {
        return DB::transaction(function () use ($user, $input): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $terms = $this->quotes->calculate($user, $input);
            $checkout = CourseCheckout::query()->create([
                'public_id' => (string) Str::uuid(), 'user_id' => $user->id,
                'course_id' => $terms['course_id'], 'channel' => $terms['channel'],
                'package_id' => $terms['selected_package']['id'] ?? null,
                'terms' => $terms, 'terms_hash' => $this->quotes->commercialHash($terms),
                'expires_at' => now()->addMinutes(15), 'status' => 'quoted',
            ]);
            return $this->payload($checkout);
        }, 3);
    }

    /** GET is strictly read-only. Explicit POST resume owns any fulfillment. */
    public function show(User $user, string $id): array
    {
        return $this->payload(CourseCheckout::query()->where('user_id', $user->id)->where('public_id', $id)->firstOrFail());
    }

    public function latest(User $user, int $courseId): ?array
    {
        $checkout = CourseCheckout::query()->where('user_id', $user->id)->where('course_id', $courseId)
            ->where(function ($query): void {
                $query->whereIn('status', ['completed', 'reconfirm_required'])
                    ->orWhere(fn ($pending) => $pending->where('status', 'pending_payment')->where('expires_at', '>', now()));
            })
            ->orderByRaw("CASE WHEN status = 'pending_payment' THEN 0 ELSE 1 END")
            ->latest('id')->first();
        return $checkout ? $this->payload($checkout) : null;
    }

    public function authorize(User $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $checkout = $this->locked($user, $id);
            if ($checkout->status !== 'quoted') return $this->fulfillLocked($user, $checkout);
            if ($checkout->expires_at->isPast()) return $this->stop($checkout, 'expired', 'checkout_expired');
            if (!$this->stillValid($user, $checkout, true)) return $this->stop($checkout, 'reconfirm_required', 'checkout_terms_changed');
            if (CourseCheckout::query()->where('user_id', $user->id)->where('status', 'pending_payment')
                ->where('expires_at', '>', now())->where('id', '!=', $checkout->id)->exists()) {
                throw new \DomainException('checkout_already_pending');
            }
            if ($checkout->terms['deficit'] > 0 && !$checkout->package_id) throw new \DomainException('checkout_package_required');
            $checkout->forceFill(['status' => 'pending_payment', 'authorized_at' => now()])->save();
            return $this->fulfillLocked($user, $checkout);
        }, 3);
    }

    public function resume(User $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            return $this->fulfillLocked($user, $this->locked($user, $id));
        }, 3);
    }

    public function cancel(User $user, string $id): array
    {
        return DB::transaction(function () use ($user, $id): array {
            User::query()->lockForUpdate()->findOrFail($user->id);
            $checkout = $this->locked($user, $id);
            if (in_array($checkout->status, ['quoted', 'pending_payment'], true)) return $this->stop($checkout, 'cancelled', 'checkout_cancelled');
            return $this->payload($checkout);
        }, 3);
    }

    /** Only an explicitly linked, owner-verified receipt may fund this authorization. */
    public function bindStorePurchase(User $user, string $id, StorePurchase $purchase): array
    {
        return DB::transaction(function () use ($user, $id, $purchase): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $checkout = $this->locked($user, $id);
            if ((int) $purchase->user_id !== (int) $user->id || $purchase->provider !== $checkout->channel
                || (int) $purchase->package_id !== (int) $checkout->package_id) throw new \DomainException('checkout_funding_mismatch');
            $profile = data_get($purchase->provider_payload, 'checkout_profile_id');
            if (is_string($profile) && $profile !== '' && !hash_equals($id, $profile)) throw new \DomainException('checkout_funding_mismatch');
            if (in_array($checkout->status, ['completed', 'cancelled', 'expired', 'reconfirm_required'], true)) return $this->payload($checkout);
            $audit = $purchase->provider_payload ?? [];
            $occurred = null;
            try {
                if ($purchase->provider === 'google' && !empty($audit['purchase_completed_at'])) $occurred = CarbonImmutable::parse($audit['purchase_completed_at']);
                elseif ($purchase->provider === 'apple' && is_numeric($audit['purchase_date'] ?? null)) $occurred = CarbonImmutable::createFromTimestampMs((int) $audit['purchase_date']);
            } catch (\Throwable) { /* Without trusted purchase time, credit only; never auto-spend. */ }
            if (!$checkout->authorized_at || !$occurred || $occurred->lt($checkout->authorized_at)) return $this->stop($checkout, 'reconfirm_required', 'checkout_receipt_not_bound');
            $this->attachFunding($checkout, $purchase->order()->firstOrFail());
            return $this->fulfillLocked($user, $checkout);
        }, 3);
    }

    /** Direct gateway order is bound before returning its checkout URL. */
    public function bindFundingOrder(User $user, string $id, Order $order): array
    {
        return DB::transaction(function () use ($user, $id, $order): array {
            $user = User::query()->lockForUpdate()->findOrFail($user->id);
            $checkout = $this->locked($user, $id);
            if ($checkout->funding_order_id) {
                // A lost direct-checkout response can be retried after approval,
                // expiry or cancellation. Only this exact issued order may replay.
                $this->attachFunding($checkout, $order);
                return $this->fulfillLocked($user, $checkout);
            }
            if ($checkout->channel !== 'direct' || $checkout->status !== 'pending_payment'
                || !$checkout->authorized_at || $order->created_at->lt($checkout->authorized_at)
                || $checkout->expires_at->isPast()) throw new \DomainException('checkout_funding_mismatch');
            $this->attachFunding($checkout, $order);
            return $this->fulfillLocked($user, $checkout);
        }, 3);
    }

    public function resumeFundingOrder(Order $order): void
    {
        $checkout = CourseCheckout::query()->where('funding_order_id', $order->id)->where('status', 'pending_payment')->first();
        if (!$checkout) return;
        $user = User::query()->find($checkout->user_id);
        if ($user) $this->resume($user, $checkout->public_id);
    }

    private function attachFunding(CourseCheckout $checkout, Order $order): void
    {
        $package = $checkout->terms['selected_package'] ?? null;
        if ((int) $order->user_id !== (int) $checkout->user_id
            || $order->payment_method !== match ($checkout->channel) {
                'google' => Order::PAYMENT_METHOD_GOOGLE_PLAY,
                'apple' => Order::PAYMENT_METHOD_APP_STORE,
                default => Order::PAYMENT_METHOD_KASHIER,
            }
            || (int) $order->package_id !== (int) $checkout->package_id
            || (int) $order->package_coins !== (int) ($package['coins'] ?? 0)
            || ($checkout->funding_order_id && (int) $checkout->funding_order_id !== (int) $order->id)
            || CourseCheckout::query()->where('funding_order_id', $order->id)->where('id', '!=', $checkout->id)->exists()) throw new \DomainException('checkout_funding_mismatch');
        if (!$checkout->funding_order_id) $checkout->forceFill(['funding_order_id' => $order->id])->save();
    }

    private function fulfillLocked(User $user, CourseCheckout $checkout): array
    {
        if (!$user->active) return $this->stop($checkout, 'reconfirm_required', 'account_inactive');
        if ($checkout->status === 'completed') {
            $order = Order::query()->find($checkout->course_order_id);
            if (!$order || !$order->isFinanciallyEffective()) return $this->stop($checkout, 'reconfirm_required', 'course_access_under_review');
            return $this->payload($checkout);
        }
        if (!in_array($checkout->status, ['quoted', 'pending_payment'], true)) return $this->payload($checkout);
        if ($checkout->expires_at->isPast()) return $this->stop($checkout, 'expired', 'checkout_expired');
        if ($checkout->status !== 'pending_payment') return $this->payload($checkout);
        if ($checkout->package_id) {
            if (!$checkout->funding_order_id) return $this->payload($checkout);
            $funding = Order::query()->findOrFail($checkout->funding_order_id);
            if (!$funding->isFinanciallyEffective()) {
                if ($funding->status === Order::STATUS_PENDING && $funding->financial_status === Order::FINANCIAL_PENDING) return $this->payload($checkout);
                return $this->stop($checkout, 'reconfirm_required', 'checkout_funding_not_effective');
            }
        }
        if (!$this->stillValid($user, $checkout, false)) return $this->stop($checkout, 'reconfirm_required', 'checkout_terms_changed');
        $terms = $checkout->terms;
        $balances = $this->wallet->balances($user->fresh());
        if ($balances['paid'] < $terms['allocation']['paid_coins'] || $balances['reward'] < $terms['allocation']['reward_coins']) return $this->stop($checkout, 'reconfirm_required', 'checkout_balance_changed');
        try {
            $course = Course::query()->findOrFail($checkout->course_id);
            $key = 'course-checkout:'.$checkout->public_id;
            $result = $terms['mode'] === 'upgrade'
                ? $this->upgrades->execute($user, $course, $terms['access_plan_code'], $key, $terms['final_price'], $terms['course_revision'])
                : $this->purchases->execute($user, $course, $terms['access_plan_code'], $key, $terms['final_price'], $terms['course_revision'], $terms['coupon_code'], $terms['allocation']['reward_coins']);
            if (!empty($result['access_changed']) || empty($result['order'])) return $this->stop($checkout, 'reconfirm_required', 'course_access_changed');
            $checkout->forceFill(['course_order_id' => $result['order']->id, 'status' => 'completed', 'completed_at' => now(), 'error_code' => null])->save();
        } catch (\DomainException $exception) {
            return $this->stop($checkout, 'reconfirm_required', $exception->getMessage());
        } catch (\Illuminate\Validation\ValidationException) {
            return $this->stop($checkout, 'reconfirm_required', 'course_terms_changed');
        }
        return $this->payload($checkout);
    }

    private function stillValid(User $user, CourseCheckout $checkout, bool $checkBalances): bool
    {
        try {
            $fresh = $this->quotes->calculate($user, $checkout->terms, $checkBalances);
            if (!hash_equals($checkout->terms_hash, $this->quotes->commercialHash($fresh))) return false;
            $saved = $checkout->terms;
            if ($fresh['promotion']['remaining'] - $fresh['discount_amount'] < $saved['allocation']['reward_coins']) return false;
            return !$checkBalances || ($fresh['purchased_balance'] === $saved['purchased_balance'] && $fresh['reward_balance'] === $saved['reward_balance']);
        } catch (\DomainException|\Illuminate\Validation\ValidationException|\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return false;
        }
    }

    private function locked(User $user, string $id): CourseCheckout
    {
        return CourseCheckout::query()->where('user_id', $user->id)->where('public_id', $id)->lockForUpdate()->firstOrFail();
    }

    private function stop(CourseCheckout $checkout, string $status, string $code): array
    {
        $checkout->forceFill(['status' => $status, 'error_code' => $code])->save();
        return $this->payload($checkout);
    }

    private function payload(CourseCheckout $checkout): array
    {
        $terms = $checkout->terms;
        unset($terms['plan_contract'], $terms['enrollment_order_id']);
        return array_merge($terms, ['id' => $checkout->public_id, 'status' => $checkout->status,
            'expires_at' => $checkout->expires_at->toIso8601String(), 'error_code' => $checkout->error_code,
            'purchase' => $checkout->course_order_id ? ['order_id' => (int) $checkout->course_order_id,
                'enrollment_id' => CourseEnrollment::query()->where('user_id', $checkout->user_id)->where('course_id', $checkout->course_id)->value('id')] : null]);
    }
}
