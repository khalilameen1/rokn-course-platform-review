<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\FinancialEntitlementHold;
use App\Models\Order;
use Illuminate\Support\Collection;

/**
 * Read immutable purchase ancestry, not current catalog prices. Medusa's
 * payment/order separation is an architecture reference, not transplanted
 * gateway code. Eloquent is reused; Rokn's coin-funded upgrade rules stay here.
 * Callers hold the learner lock when using this view to change entitlements.
 */
final readonly class CoursePlanLineageService
{
    /** @return Collection<int, Order> oldest purchase first, keyed by order id */
    public function forEnrollment(CourseEnrollment $enrollment, ?FinancialEntitlementHold $restoring = null): Collection
    {
        $orders = Order::query()->where('user_id', $enrollment->user_id)
            ->where('course_id', $enrollment->course_id)->get()->keyBy('id');
        $base = (int) $enrollment->order_id;
        $current = (int) ($enrollment->access_plan_order_id ?: $base);
        $lineage = $this->trace($orders, $current, $base);
        if ($lineage->isEmpty()) {
            return $lineage;
        }

        $holds = FinancialEntitlementHold::query()
            ->where('enrollment_id', $enrollment->id)
            ->where('entitlement_scope', 'plan')
            ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)->get();
        if ($restoring) {
            $holds->push($restoring);
        }
        // After a second reversal the current plan can be the common root of
        // two branches. Prefer the most recently purchased tip, not whichever
        // older hold the database happens to return first. Upgrade orders are
        // created under the learner lock and their ancestry is immutable.
        foreach ($holds->sortByDesc(fn (FinancialEntitlementHold $hold): int => (int) ($hold->plan_restore_order_id ?: $hold->course_order_id)) as $hold) {
            if ($hold->enrollment_order_id && (int) $hold->enrollment_order_id !== $base) {
                continue;
            }
            $candidate = $this->trace($orders, (int) ($hold->plan_restore_order_id ?: $hold->course_order_id), $base);
            // A separate repurchase/upgrade branch must never be overwritten
            // when an older financial review is resolved.
            if ($candidate->has((int) $lineage->last()->id)) {
                $lineage = $candidate;
            }
        }

        return $lineage;
    }

    /** @param Collection<int, Order> $lineage */
    public function effectiveOrder(Collection $lineage): ?Order
    {
        $blocked = FinancialEntitlementHold::query()
            ->whereIn('course_order_id', $lineage->keys())
            ->where('entitlement_scope', 'plan')
            ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
            ->pluck('course_order_id')->flip();
        $effective = null;
        foreach ($lineage as $order) {
            if ($blocked->has((int) $order->id)) {
                break;
            }
            $effective = $order;
        }

        return $effective;
    }

    /** @param Collection<int, Order> $orders
     * @return Collection<int, Order>
     */
    private function trace(Collection $orders, int $tip, int $base): Collection
    {
        $chain = collect();
        while ($tip > 0 && ! $chain->has($tip)) {
            $order = $orders->get($tip);
            if (! $order) {
                break;
            }
            $chain->put($tip, $order);
            if ($tip === $base) {
                return $chain->reverse();
            }
            $tip = (int) $order->parent_order_id;
        }

        return collect();
    }
}
