<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseCatalogueQueryService;
use App\Services\CourseCompletionService;
use App\Services\CourseLeaderboardService;
use App\Services\CoursePresentationService;
use App\Services\CourseReadService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CourseController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly CourseCatalogueQueryService $catalogueQueries,
        private readonly CourseCompletionService $completion,
        private readonly CourseLeaderboardService $leaderboard,
        private readonly CourseReadService $courseReads,
        private readonly CoursePresentationService $coursePresentation
    ) {
    }

    public function listCourses(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
            'grade_id' => 'nullable|integer',
            'type' => 'nullable|string|max:50',
            'course_type' => 'nullable|string|max:50',
            'search' => 'nullable|string|max:120',
            'catalogue_revision' => 'nullable|integer|min:1',
        ]);

        try {
            $snapshot = $this->catalogueQueries->readStablePage(
                max(1, (int) ($filters['page'] ?? 1)),
                isset($filters['catalogue_revision'])
                    ? (int) $filters['catalogue_revision']
                    : null,
                fn () => $this->catalogueQueries->mobileCatalogue($filters)
            );
            if ($snapshot['changed']) {
                return $this->catalogueChanged($snapshot['revision']);
            }
            $courses = $snapshot['data'];
            $payload = $this->coursePresentation->mobileCataloguePayload($courses);
            $payload['catalogue_revision'] = $snapshot['revision'];

            return $this->responses->success(
                $payload,
                'تم تحميل الكورسات'
            );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل الكورسات', 500);
        }
    }

    private function catalogueChanged(int $revision): JsonResponse
    {
        return $this->responses->error(
            "تغيّرت قائمة الكورسات\nنحدّثها الآن",
            409,
            ['catalogue_revision' => $revision],
            ['code' => 'catalogue_changed']
        );
    }

    public function getCourseProgress(Request $request, int|string $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $read = $this->courseReads->progressCourse(
                (int) $user->id,
                (int) $courseId
            );

            if (!$read['enrollment']) {
                return $this->responses->error(
                    'هذا الكورس غير متاح لحسابك',
                    403
                );
            }

            return $this->responses->success(
                $this->coursePresentation->progressPayload(
                    $read['course'],
                    $read['enrollment'],
                    $read['access_type'],
                    (int) $user->id
                ),
                'تم تحميل تقدمك في الكورس'
            );
        } catch (ModelNotFoundException $e) {
            return $this->responses->error('الكورس غير متاح', 404);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل تقدم الكورس', 500);
        }
    }

    public function viewCourseDetails(Request $request, int|string $courseId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $normalizedCourseId = (int) $courseId;
            $snapshot = $this->courseReads->readStablePublishedPayload(
                $normalizedCourseId,
                function () use ($normalizedCourseId, $user, $request): array {
                    $read = $this->courseReads->detailedCourse($normalizedCourseId, $user);

                    return $this->coursePresentation->detailedCourse(
                        $read['course'],
                        $user,
                        $read['entitlement'],
                        $read['enrollment']
                    )->resolve($request);
                }
            );
            if (!$snapshot['changed']) {
                return $this->responses->success(
                    $snapshot['data'],
                    'تم تحميل تفاصيل الكورس'
                );
            }

            return $this->responses->error(
                "تغيّرت نسخة الكورس\nنحدّثها الآن",
                409,
                ['published_revision' => $snapshot['revision']],
                ['code' => 'course_revision_changed']
            );
        } catch (ModelNotFoundException $e) {
            return $this->responses->error('الكورس غير متاح', 404);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل الكورس', 500);
        }
    }

    public function markSectionComplete(
        Request $request,
        int|string $courseId,
        int|string $sectionId
    ): JsonResponse {
        try {
            /** @var User|null $user */
            $user = auth('api')->user();
            if (!$user) {
                return $this->responses->error('سجّل الدخول أولًا', 401);
            }

            $result = $this->completion->complete(
                $user,
                (int) $courseId,
                (int) $sectionId
            );
            $additional = isset($result['code'])
                ? ['code' => $result['code']]
                : [];

            return $result['success']
                ? $this->responses->success(
                    $result['data'],
                    $result['message'],
                    $result['status'],
                    $additional
                )
                : $this->responses->error(
                    $result['message'],
                    $result['status'],
                    $result['data'],
                    $additional
                );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر حفظ تقدمك', 500);
        }
    }

    public function getBestStudents(int|string $courseId): JsonResponse
    {
        try {
            $result = $this->leaderboard->forCourse((int) $courseId);

            return $this->responses->success($result['data'], $result['message']);
        } catch (ModelNotFoundException $e) {
            return $this->responses->error(
                'الكورس غير متاح',
                404,
                null,
                ['error' => 'الكورس غير متاح']
            );
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);

            return $this->responses->error('تعذّر تحميل قائمة الطلاب', 500);
        }
    }
}
