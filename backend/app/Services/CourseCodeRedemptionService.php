<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCodeUnavailable;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseEnrollment;
use App\Models\CourseGrantClaim;
use App\Models\User;
use App\Support\CourseCodeRedemptionResult;
use App\Support\CourseCodeRejection;
use App\Support\PrivacyFingerprint;
use App\Support\StudentNotificationIntent;
use App\Support\UnicodeText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class CourseCodeRedemptionService
{
    public function __construct(
        private readonly CourseCodeEligibilityService $eligibility,
        private readonly CourseCodeReceiptService $receipts,
        private readonly CourseEntitlementService $access,
        private readonly StudentNotificationService $notifications
    ) {
    }

    public function redeem(
        User $user,
        string $rawCode,
        ?int $expectedCourseId = null,
        ?string $requestIp = null,
        ?string $userAgent = null
    ): CourseCodeRedemptionResult {
        $identifier = UnicodeText::identifier($rawCode);
        $code = null;
        try {
            return DB::transaction(function () use ($user, $identifier, $expectedCourseId, $requestIp, $userAgent, &$code): CourseCodeRedemptionResult {
                // Preserve the existing aggregate order: learner, code, course.
                // All preview decisions are repeated against these current rows.
                $learner = User::query()->lockForUpdate()->findOrFail($user->id);
                $code = CourseCode::query()->where('code', $identifier)->lockForUpdate()->first();
                if (!$code) {
                    throw new CourseCodeUnavailable(CourseCodeRejection::NOT_FOUND);
                }
                $courseId = $code->targetCourseId();
                if ($expectedCourseId && $courseId !== $expectedCourseId) {
                    throw new CourseCodeUnavailable(CourseCodeRejection::COURSE_MISMATCH, $code);
                }
                if ($code->type !== 'course') {
                    throw new CourseCodeUnavailable(CourseCodeRejection::LEGACY_RETIRED, $code);
                }
                $course = Course::query()->lockForUpdate()->find($courseId);
                if (!$this->eligibility->courseAvailable($code, $course)) {
                    throw new CourseCodeUnavailable(CourseCodeRejection::COURSE_UNAVAILABLE, $code);
                }
                $code->setRelation('course', $course);
                if ($this->access->hasLearningAccess((int) $learner->id, (int) $course->id)) {
                    // An existing paid plan must never be replaced by a free code.
                    // A repeated successful request consumes neither quota nor a claim.
                    return new CourseCodeRedemptionResult($code, alreadyEnrolled: true);
                }
                if ($rejection = $this->eligibility->rejectionFor($code, $learner, $course)) {
                    throw new CourseCodeUnavailable($rejection, $code);
                }

                $usage = $code->usages()->create([
                    'user_id' => $learner->id, 'used_at' => now(),
                    'ip_address' => PrivacyFingerprint::make($requestIp),
                    'user_agent' => PrivacyFingerprint::make($userAgent),
                ]);
                if ($code->isInstitutionalGrant()) {
                    CourseGrantClaim::query()->create([
                        'user_id' => $learner->id,
                        'normalized_email_hash' => CourseGrantClaim::emailHash($learner->email),
                        'email_hint' => CourseGrantClaim::emailHint($learner->email),
                        'course_code_id' => $code->id, 'course_code_usage_id' => $usage->id,
                        'course_id' => $course->id, 'status' => CourseGrantClaim::STATUS_ACTIVE,
                        'claimed_at' => now(),
                    ]);
                }
                $code->increment('used_count');
                $order = $this->receipts->ensureWithinRedemption($learner, $code, $course);
                $enrollment = CourseEnrollment::query()->where('user_id', $learner->id)
                    ->where('course_id', $course->id)->first();
                if ($enrollment) {
                    // A new valid code may re-source an ineffective old purchase.
                    // Completion facts stay intact; paid capabilities do not carry over.
                    $enrollment->forceFill([
                        'order_id' => $order->id, 'access_plan_order_id' => null,
                        'access_plan_id' => null, 'access_plan_snapshot' => null,
                        'is_active' => true, 'access_granted_at' => now(), 'expires_at' => null,
                    ])->save();
                } else {
                    CourseEnrollment::query()->create([
                        'user_id' => $learner->id, 'course_id' => $course->id, 'order_id' => $order->id,
                        'enrolled_at' => now(), 'is_active' => true, 'access_granted_at' => now(),
                    ]);
                    $this->notifyEnrollment($learner, $code, $course, (int) $order->id);
                }

                return new CourseCodeRedemptionResult($code, alreadyEnrolled: false);
            }, 3);
        } catch (UniqueConstraintViolationException $exception) {
            // Only a durable, competing acquisition claim is a grant conflict.
            // A bill/order/FK failure must not masquerade as "already claimed".
            if ($code && $this->eligibility->hasReachedGrantLimit($code, $user)) {
                throw new CourseCodeUnavailable(CourseCodeRejection::GRANT_ALREADY_CLAIMED, $code);
            }
            throw $exception;
        }
    }

    private function notifyEnrollment(User $user, CourseCode $code, Course $course, int $orderId): void
    {
        $grant = $code->isInstitutionalGrant();
        $this->notifications->notifyUser($user, new StudentNotificationIntent(
            notificationType: $grant
                ? StudentNotificationService::TYPE_INSTITUTIONAL_GRANT
                : StudentNotificationService::TYPE_COURSE_ENROLLED,
            titleAr: $grant ? 'تم تفعيل منحتك' : 'الكورس أصبح لك',
            titleEn: $grant ? 'Your grant is active' : 'Course access active',
            messageAr: $grant ? "مشاهدة الكورس مجانًا\nابدأ عندما يناسبك" : $course->name_ar . "\nابدأ أو أكمل من مكانك",
            messageEn: $grant ? 'Watch the complete course for free whenever you are ready.'
                : 'You can start ' . $course->name_en . ' and resume at any time.',
            link: '/course/' . $course->id,
            notifiableType: Course::class, notifiableId: (int) $course->id,
            deliveryKey: 'course-enrolled:order:' . $orderId,
            templateVariables: ['course' => (string) ($course->name_ar ?: $course->name_en)]
        ));
    }
}
