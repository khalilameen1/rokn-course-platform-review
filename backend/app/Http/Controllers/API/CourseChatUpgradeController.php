<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CourseCheckoutRejected;
use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CoursePlanUpgradeQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Quotes the upgrade of an existing enrolment to its next paid service plan
 * for the shipped upgrade offer sheet. Purchasing an upgrade goes through
 * course-checkouts (mode=upgrade) and CoursePlanUpgradeAction.
 */
final class CourseChatUpgradeController extends Controller
{
    public function __construct(
        private readonly CoursePlanUpgradeQuoteService $upgrades,
        private readonly ApiResponseService $responses
    ) {}

    public function quote(Request $request, Course $course): JsonResponse
    {
        $validated = $request->validate([
            'target_plan_code' => 'nullable|string|in:guided,mentor',
            'required_feature' => 'nullable|string|in:chat,project_discussion',
        ]);
        /** @var User $user */
        $user = auth('api')->user();

        try {
            $quote = $this->upgrades->quote(
                $user,
                $course,
                isset($validated['target_plan_code']) ? (string) $validated['target_plan_code'] : null,
                $validated['required_feature'] ?? null
            );
        } catch (\DomainException $exception) {
            [$code, $message, $status] = match (CourseCheckoutRejected::codeFor($exception)) {
                CourseCheckoutRejected::COURSE_ACCESS_REQUIRED => [
                    CourseCheckoutRejected::COURSE_ACCESS_REQUIRED, 'افتح الكورس أولًا قبل الترقية', 403,
                ],
                CourseCheckoutRejected::FULL_TRACK_UPGRADE_PAID_FLOOR_UNFUNDED => [
                    CourseCheckoutRejected::FULL_TRACK_UPGRADE_PAID_FLOOR_UNFUNDED, 'الترقية غير متاحة بالسعر الحالي', 409,
                ],
                CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_PRICED => [
                    CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_PRICED, 'لم يحدد سعر الترقية لهذا الكورس', 409,
                ],
                default => [
                    CourseCheckoutRejected::FULL_TRACK_UPGRADE_NOT_AVAILABLE, 'الترقية غير متاحة لهذه الفئة', 409,
                ],
            };

            return $this->responses->error($message, $status, additional: ['code' => $code]);
        }

        if ($quote['already_upgraded']) {
            return $this->responses->success(
                $quote['data'],
                'الفئة المختارة مفعّلة بالفعل',
                additional: ['code' => 'full_track_already_available']
            );
        }

        return $this->responses->success($quote['data'], 'تم تحميل فرق الترقية');
    }
}
