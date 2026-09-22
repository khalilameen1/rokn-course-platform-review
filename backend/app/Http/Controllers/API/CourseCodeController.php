<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\CourseCode;
use App\Models\Lesson;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use App\Services\CourseChatAccessService;
use App\Support\UnicodeText;

final class CourseCodeController extends Controller
{
    public function __construct(private readonly CourseChatAccessService $courseAccess)
    {
    }

    /**
     * Redeem a course code
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function redeem(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50',
            'course_id' => 'nullable|integer|exists:courses,id',
        ], [
            'code.required' => 'الكود مطلوب',
            'code.string' => 'الكود يجب أن يكون نص',
            'code.max' => 'الكود يجب أن يكون أقل من 50 حرف'
        ]);

        try {
            $code = CourseCode::where('code', UnicodeText::identifier($request->code))->first();
            if (!$code) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'الكود غير صحيح',
                    'data' => null,
                ], 404);
            }

            $user = Auth::guard('api')->user();

            $expectedCourseId = $request->integer('course_id');
            $codeCourseId = $code->getCourseIdForEnrollment();
            if ($expectedCourseId && (int) $codeCourseId !== $expectedCourseId) {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'code' => 'course_code_course_mismatch',
                    'message' => 'هذا الكود مخصص لكورس آخر ولم يتم استخدامه',
                    'data' => [
                        'expected_course_id' => $expectedCourseId,
                        'code_course_id' => $codeCourseId,
                        'course' => $code->course ? [
                            'id' => $code->course->id,
                            'name' => $code->course->name_ar,
                        ] : null,
                    ],
                ], 409);
            }
            if ($code->type !== 'course') {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'legacy_partial_code_retired',
                    'message' => 'هذا النوع القديم من الأكواد لم يعد متاحًا',
                    'data' => null,
                ], 410);
            }
            if (!$code->targetsPublishedCourse()) {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'code' => 'course_not_available',
                    'message' => 'هذا الكورس غير متاح للفتح الآن',
                    'data' => null,
                ], 409);
            }

            if ($this->courseAccess->hasLearningAccess((int) $user->id, (int) $codeCourseId)) {
                $entitlement = $this->courseAccess->entitlementFor(
                    (int) $user->id,
                    (int) $codeCourseId
                );
                return response()->json([
                    'status' => 200,
                    'success' => true,
                    'message' => 'الكورس مفتوح بالفعل على حسابك',
                    'data' => [
                        'code' => $code->code,
                        'type' => $code->type,
                        'access_type' => $entitlement['access_type'],
                        'learning_access' => $entitlement['has_learning_access'],
                        'chat_available' => $entitlement['chat_available'],
                        'certificate_available' => $entitlement['certificate_available'],
                        'projects_available' => $entitlement['projects_available'],
                        'already_enrolled' => true,
                        'course' => $code->course ? [
                            'id' => $code->course->id,
                            'name' => $code->course->name_ar,
                        ] : null,
                    ],
                ]);
            }

            if (!$code->canBeUsedByUser($user->id)) {
                $grantAlreadyClaimed = $code->hasReachedInstitutionalGrantLimit($user->id);
                if ($grantAlreadyClaimed) {
                    return response()->json([
                        'status' => 409,
                        'success' => false,
                        'code' => 'grant_already_claimed',
                        'message' => 'استخدمت منحتك التعليمية في كورس آخر بالفعل',
                        'data' => null,
                    ], 409);
                }

                if (!$code->isEligibleForUser($user->id)) {
                    $message = 'هذا الكود متاح لبريد الجهة التعليمية المحددة فقط';
                } elseif ($code->is_expired) {
                    $message = 'الكود منتهي الصلاحية';
                } elseif ($code->is_not_yet_active) {
                    $message = 'الكود لم يبدأ بعد';
                } elseif (!$code->is_active) {
                    $message = 'الكود معطل';
                } elseif ($code->used_count >= $code->max_uses) {
                    $message = 'تم استنفاذ جميع مرات الاستخدام للكود';
                } else {
                    $message = 'لقد استخدمت هذا الكود من قبل';
                }

                return response()->json([
                    'status' => 400,
                    'success' => false,
                    'code' => 'course_code_unavailable',
                    'message' => $message,
                    'data' => null,
                ], 400);
            }
            // Use the code
            if ($code->useForUser($user->id)) {
                $entitlement = $this->courseAccess->entitlementFor(
                    (int) $user->id,
                    (int) $codeCourseId
                );
                $response = [
                    'status' => 200,
                    'success' => true,
                    'message' => 'تم تفعيل المنحة',
                    'data' => [
                        'code' => $code->code,
                        'type' => $code->type,
                        'access_type' => $entitlement['access_type'],
                        'learning_access' => true,
                        'chat_available' => $entitlement['chat_available'],
                        'certificate_available' => $entitlement['certificate_available'],
                        'projects_available' => $entitlement['projects_available'],
                        'target_content_name' => $code->target_content_name,
                    ]
                ];

                // Add specific content data based on type
                switch ($code->type) {
                    case 'course':
                        if ($code->course) {
                            $response['data']['course'] = [
                                'id' => $code->course->id,
                                'name' => $code->course->name_ar,
                                'description' => $code->course->description_ar
                            ];
                        }
                        break;
                    case 'lesson':
                        if ($code->lesson) {
                            $response['data']['lesson'] = [
                                'id' => $code->lesson->id,
                                'title' => $code->lesson->title,
                                'description' => $code->lesson->description
                            ];
                        }
                        break;
                    case 'multiple_lessons':
                        if ($code->course) {
                            $response['data']['course'] = [
                                'id' => $code->course->id,
                                'name' => $code->course->name_ar
                            ];
                        }
                        $response['data']['lessons'] = $code->getLessonsCollection()->map(function($lesson) {
                            return [
                                'id' => $lesson->id,
                                'title' => $lesson->title
                            ];
                        });
                        break;
                }

                return response()->json($response);
            }

            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'حدث خطأ أثناء تفعيل الكود',
                'data' => null,
            ], 500);

        } catch (\Illuminate\Database\QueryException $e) {
            if ((string) $e->getCode() === '23000') {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'code' => 'grant_already_claimed',
                    'message' => 'منحتك التعليمية مستخدمة بالفعل في كورس آخر',
                    'data' => null,
                ], 409);
            }
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'حدث خطأ في الخادم',
                'data' => null,
            ], 500);
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

    /**
     * Check if a code is valid (without using it)
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function check(Request $request): JsonResponse
    {
        $request->validate([
            'code' => 'required|string|max:50'
        ], [
            'code.required' => 'الكود مطلوب',
            'code.string' => 'الكود يجب أن يكون نص',
            'code.max' => 'الكود يجب أن يكون أقل من 50 حرف'
        ]);

        try {
            $code = CourseCode::where('code', UnicodeText::identifier($request->code))->first();

            if (!$code) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'الكود غير صحيح',
                    'data' => null,
                ], 404);
            }
            if ($code->type !== 'course') {
                return response()->json([
                    'status' => 410,
                    'success' => false,
                    'code' => 'legacy_partial_code_retired',
                    'message' => 'هذا النوع القديم من الأكواد لم يعد متاحًا',
                    'data' => null,
                ], 410);
            }

            $user = Auth::guard('api')->user();
            $canUse = $code->canBeUsedByUser($user->id);

            $response = [
                'status' => 200,
                'success' => true,
                'message' => 'تم التحقق من الكود بنجاح',
                'data' => [
                    'code' => $code->code,
                    'name' => $code->name,
                    'type' => $code->type,
                    'target_content_name' => $code->target_content_name,
                    'is_valid' => $code->isValid(),
                    'can_use' => $canUse,
                    'start_date' => $code->start_date ? $code->start_date->format('Y-m-d H:i:s') : null,
                    'expiry_date' => $code->expiry_date ? $code->expiry_date->format('Y-m-d H:i:s') : null,
                    'is_expired' => $code->is_expired,
                    'is_not_yet_active' => $code->is_not_yet_active
                ]
            ];

            if (!$canUse) {
                if (!$code->targetsPublishedCourse()) {
                    $response['data']['error_message'] = 'هذا الكورس غير متاح للفتح الآن';
                } elseif ($code->hasReachedInstitutionalGrantLimit($user->id)) {
                    $response['data']['error_message'] = 'استخدمت منحتك التعليمية في كورس آخر بالفعل';
                } elseif (!$code->isEligibleForUser($user->id)) {
                    $response['data']['error_message'] = 'هذا الكود متاح لبريد الجهة التعليمية المحددة فقط';
                } elseif ($code->is_expired) {
                    $response['data']['error_message'] = 'الكود منتهي الصلاحية';
                } elseif ($code->is_not_yet_active) {
                    $response['data']['error_message'] = 'الكود لم يبدأ بعد';
                } elseif (!$code->is_active) {
                    $response['data']['error_message'] = 'الكود معطل';
                } elseif ($code->used_count >= $code->max_uses) {
                    $response['data']['error_message'] = 'تم استنفاذ جميع مرات الاستخدام للكود';
                } else {
                    $response['data']['error_message'] = 'لقد استخدمت هذا الكود من قبل';
                }
            }

            return response()->json($response);

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

