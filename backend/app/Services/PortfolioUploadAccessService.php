<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseEnrollment;
use App\Models\User;
use Illuminate\Http\Exceptions\HttpResponseException;

/** Portfolio storage follows purchased certificate rights, not course completion. */
final readonly class PortfolioUploadAccessService
{
    public const DENIED_CODE = 'PORTFOLIO_CERTIFICATE_SUBSCRIPTION_REQUIRED';
    public const SUBSCRIBE_MESSAGE = 'اشترك في كورس باشتراك يشمل شهادة';
    public const UPGRADE_MESSAGE = 'قم بترقية اشتراكك إلى اشتراك يشمل شهادة';

    public function __construct(
        private CourseEntitlementService $courseAccess,
        private FinancialEntitlementHoldReadService $holds,
    ) {}

    public function allows(User $user): bool
    {
        return $this->decision($user)['can_upload'];
    }

    /** @return array{can_upload:bool,has_subscription:bool,message:?string} */
    public function decision(User $user): array
    {
        // Captured enrollment terms survive catalogue edits and curriculum drafts.
        // Theory courses qualify too; no project or issued certificate is required.
        $enrollments = CourseEnrollment::query()->where('user_id', $user->id)->active()
            ->with(['order.courseCode', 'accessPlanOrder', 'accessPlan'])
            ->get()->filter(fn (CourseEnrollment $enrollment): bool =>
                (!$enrollment->order_id || $enrollment->order?->isFinanciallyEffective())
                && (!$enrollment->access_plan_order_id || $enrollment->accessPlanOrder?->isFinanciallyEffective())
                && !$this->holds->enrollmentHasActiveHold($enrollment, ['course', 'plan'])
            );
        $allowed = $enrollments->contains(fn (CourseEnrollment $enrollment): bool =>
            $this->courseAccess->enrollmentHasCertificateAccess($enrollment)
        );

        return [
            'can_upload' => $allowed,
            'has_subscription' => $enrollments->isNotEmpty(),
            'message' => $allowed ? null : ($enrollments->isNotEmpty()
                ? self::UPGRADE_MESSAGE : self::SUBSCRIBE_MESSAGE),
        ];
    }

    public function assertAllowed(User $user): void
    {
        $decision = $this->decision($user);
        if (!$decision['can_upload']) {
            throw new HttpResponseException(response()->json([
                'status' => 403,
                'success' => false,
                'code' => self::DENIED_CODE,
                'message' => $decision['message'],
                'has_subscription' => $decision['has_subscription'],
                'data' => null,
            ], 403));
        }
    }
}
