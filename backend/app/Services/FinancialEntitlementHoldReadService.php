<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\FinancialEntitlementHold;
use App\Support\FinancialProvenanceSchema;
use Illuminate\Database\Eloquent\Builder;

/** Reads holds against the purchased course/plan, without credit/reversal/budget services. */
final readonly class FinancialEntitlementHoldReadService
{
    /** @param list<string> $scopes */
    public function enrollmentHasActiveHold(
        CourseEnrollment $enrollment,
        array $scopes = ['course']
    ): bool
    {
        if (!FinancialProvenanceSchema::available() || !$enrollment->order_id) {
            return false;
        }
        $hasCourseScope = in_array('course', $scopes, true);
        $planScopes = array_values(array_intersect($scopes, ['chat', 'plan']));
        $planOrderId = (int) ($enrollment->access_plan_order_id ?: $enrollment->order_id);
        if (!$hasCourseScope && ($planScopes === [] || $planOrderId <= 0)) {
            return false;
        }

        return FinancialEntitlementHold::query()
            ->where('user_id', $enrollment->user_id)
            ->where('course_id', $enrollment->course_id)
            ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
            ->whereIn('entitlement_scope', $scopes)
            ->where(function (Builder $orders) use (
                $enrollment,
                $hasCourseScope,
                $planScopes,
                $planOrderId
            ): void {
                if ($hasCourseScope) {
                    $orders->where(function (Builder $course) use ($enrollment): void {
                        $course->where('entitlement_scope', 'course')
                            ->where('course_order_id', $enrollment->order_id);
                    });
                }

                if ($planScopes !== [] && $planOrderId > 0) {
                    $method = $hasCourseScope ? 'orWhere' : 'where';
                    $orders->{$method}(function (Builder $plan) use ($planOrderId, $planScopes): void {
                        $plan->whereIn('entitlement_scope', $planScopes)
                            ->where('course_order_id', $planOrderId);
                    });
                }
            })
            ->exists();
    }
}
