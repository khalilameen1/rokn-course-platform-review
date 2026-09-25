<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\CourseCodeUnavailable;
use App\Http\Controllers\Controller;
use App\Models\CourseCode;
use App\Models\Lesson;
use App\Services\CourseCodeEligibilityService;
use App\Services\CourseCodeRedemptionService;
use App\Services\CourseEntitlementService;
use App\Support\CourseCodeRejection;
use App\Support\UnicodeText;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class CourseCodeController extends Controller
{
    public function __construct(
        private readonly CourseEntitlementService $courseAccess,
        private readonly CourseCodeEligibilityService $eligibility,
        private readonly CourseCodeRedemptionService $redemptions
    ) {
    }

    public function redeem(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50',
            'course_id' => 'nullable|integer|exists:courses,id',
        ], [
            'code.required' => 'الكود مطلوب',
            'code.string' => 'الكود يجب أن يكون نص',
            'code.max' => 'الكود يجب أن يكون أقل من 50 حرف',
        ]);
        $expectedCourseId = $request->filled('course_id') ? $request->integer('course_id') : null;

        try {
            $user = Auth::guard('api')->user();
            $result = $this->redemptions->redeem(
                user: $user,
                rawCode: (string) $request->input('code'),
                expectedCourseId: $expectedCourseId,
                requestIp: $request->ip(),
                userAgent: $request->userAgent()
            );
            $code = $result->courseCode;
            $entitlement = $this->courseAccess->entitlementFor((int) $user->id, (int) $code->course_id);
            $data = [
                'code' => $code->code,
                'type' => $code->type,
                'access_type' => $entitlement['access_type'],
                'learning_access' => $entitlement['has_learning_access'],
                'chat_available' => $entitlement['chat_available'],
                'certificate_available' => $entitlement['certificate_available'],
                'projects_available' => $entitlement['projects_available'],
            ];
            if ($result->alreadyEnrolled) {
                $data['already_enrolled'] = true;
                $data['course'] = $code->course ? [
                    'id' => $code->course->id, 'name' => $code->course->name_ar,
                ] : null;
            } else {
                $data['target_content_name'] = $code->target_content_name;
                $data['course'] = [
                    'id' => $code->course->id,
                    'name' => $code->course->name_ar,
                    'description' => $code->course->description_ar,
                ];
            }

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => $result->alreadyEnrolled ? 'الكورس مفتوح بالفعل على حسابك' : 'تم تفعيل المنحة',
                'data' => $data,
            ]);
        } catch (CourseCodeUnavailable $exception) {
            return $this->rejectionResponse($exception, $expectedCourseId);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);
            return response()->json([
                'status' => 500, 'success' => false, 'message' => 'حدث خطأ في الخادم', 'data' => null,
            ], 500);
        }
    }

    /** Preview never consumes the code or creates a financial receipt. */
    public function check(Request $request): JsonResponse
    {
        $request->validate(['code' => 'required|string|max:50'], [
            'code.required' => 'الكود مطلوب',
            'code.string' => 'الكود يجب أن يكون نص',
            'code.max' => 'الكود يجب أن يكون أقل من 50 حرف',
        ]);

        try {
            $code = CourseCode::query()->where('code', UnicodeText::identifier($request->input('code')))->first();
            if (!$code) {
                throw new CourseCodeUnavailable(CourseCodeRejection::NOT_FOUND);
            }
            if ($code->type !== 'course') {
                throw new CourseCodeUnavailable(CourseCodeRejection::LEGACY_RETIRED, $code);
            }
            $rejection = $this->eligibility->rejectionFor($code, Auth::guard('api')->user());
            $data = [
                'code' => $code->code,
                'name' => $code->name,
                'type' => $code->type,
                'target_content_name' => $code->target_content_name,
                'is_valid' => $code->isValid(),
                'can_use' => $rejection === null,
                'start_date' => $code->start_date?->format('Y-m-d H:i:s'),
                'expiry_date' => $code->expiry_date?->format('Y-m-d H:i:s'),
                'is_expired' => $code->is_expired,
                'is_not_yet_active' => $code->is_not_yet_active,
            ];
            if ($rejection) {
                $data['error_message'] = $rejection->message();
            }

            return response()->json([
                'status' => 200, 'success' => true, 'message' => 'تم التحقق من الكود بنجاح', 'data' => $data,
            ]);
        } catch (CourseCodeUnavailable $exception) {
            return $this->rejectionResponse($exception);
        } catch (\Throwable $exception) {
            $this->rethrowExpectedRequestException($exception);
            report($exception);
            return response()->json([
                'status' => 500, 'success' => false, 'message' => 'حدث خطأ في الخادم', 'data' => null,
            ], 500);
        }
    }

    private function rejectionResponse(CourseCodeUnavailable $exception, ?int $expectedCourseId = null): JsonResponse
    {
        $reason = $exception->reason;
        $status = match ($reason) {
            CourseCodeRejection::NOT_FOUND => 404,
            CourseCodeRejection::LEGACY_RETIRED => 410,
            CourseCodeRejection::COURSE_MISMATCH,
            CourseCodeRejection::COURSE_UNAVAILABLE,
            CourseCodeRejection::GRANT_ALREADY_CLAIMED => 409,
            default => 400,
        };
        $publicCode = match ($reason) {
            CourseCodeRejection::NOT_FOUND => null,
            CourseCodeRejection::LEGACY_RETIRED => 'legacy_partial_code_retired',
            CourseCodeRejection::COURSE_MISMATCH => 'course_code_course_mismatch',
            CourseCodeRejection::COURSE_UNAVAILABLE => 'course_not_available',
            CourseCodeRejection::GRANT_ALREADY_CLAIMED => 'grant_already_claimed',
            default => 'course_code_unavailable',
        };
        $data = null;
        if ($reason === CourseCodeRejection::COURSE_MISMATCH) {
            $code = $exception->courseCode;
            $data = [
                'expected_course_id' => $expectedCourseId,
                'code_course_id' => $code->targetCourseId(),
                'course' => $code->course ? ['id' => $code->course->id, 'name' => $code->course->name_ar] : null,
            ];
        }
        $response = ['status' => $status, 'success' => false, 'message' => $reason->message(), 'data' => $data];
        if ($publicCode !== null) {
            $response['code'] = $publicCode;
        }

        return response()->json($response, $status);
    }

    /**
     * Get user's redeemed codes
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function myCodes(): JsonResponse
    {
        try {
            $user = Auth::guard('api')->user();

            $codes = CourseCode::whereHas('usages', function($query) use ($user) {
                $query->where('user_id', $user->id);
            })->with(['course', 'lesson', 'usages' => function($query) use ($user) {
                $query->where('user_id', $user->id);
            }])->get();

            $lessonIds = $codes
                ->where('type', 'multiple_lessons')
                ->flatMap(static fn (CourseCode $code): array => array_map(
                    'intval',
                    is_array($code->lesson_ids) ? $code->lesson_ids : []
                ))
                ->filter()
                ->unique()
                ->values();
            $lessons = $lessonIds->isEmpty()
                ? collect()
                : Lesson::query()->whereIn('id', $lessonIds)->get()->keyBy('id');

            $formattedCodes = $codes->map(function (CourseCode $code) use ($lessons, $user) {
                $isGrant = $code->isInstitutionalGrant();
                $isCurrentCourseCode = $code->type === 'course' && $code->course;
                $entitlement = $isCurrentCourseCode
                    ? $this->courseAccess->entitlementFor(
                        (int) $user->id,
                        (int) $code->course->id
                    )
                    : null;

                return [
                    'code' => $code->code,
                    'name' => $code->name,
                    'type' => $code->type,
                    'target_content_name' => $code->target_content_name,
                    'access_type' => $entitlement['access_type']
                        ?? ($isGrant ? 'scholarship' : 'course_code'),
                    'is_grant' => $isGrant,
                    // Usage history is not an entitlement. Legacy partial codes,
                    // expired enrolments and financially held purchases must not
                    // be presented as active access merely because a code was
                    // redeemed in the past.
                    'learning_access' => (bool) ($entitlement['has_learning_access'] ?? false),
                    'chat_available' => (bool) ($entitlement['chat_available'] ?? false),
                    'certificate_available' => (bool) ($entitlement['certificate_available'] ?? false),
                    'used_at' => $code->usages->first()->used_at->format('Y-m-d H:i:s'),
                    'course' => $code->course ? [
                        'id' => $code->course->id,
                        'name' => $code->course->name_ar
                    ] : null,
                    'lesson' => $code->lesson ? [
                        'id' => $code->lesson->id,
                        'title' => $code->lesson->title
                    ] : null,
                    'lessons' => $code->type === 'multiple_lessons'
                        ? collect($code->lesson_ids)
                            ->map(static fn ($lessonId) => $lessons->get((int) $lessonId))
                            ->filter()
                            ->map(static fn (Lesson $lesson): array => [
                                'id' => $lesson->id,
                                'title' => $lesson->title,
                            ])
                            ->values()
                        : null,
                ];
            });

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم استرجاع الأكواد بنجاح',
                'data' => $formattedCodes,
            ]);

        } catch (\Throwable $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'حدث خطأ في الخادم',
                'data' => null,
            ], 500);
        }
    }
}

