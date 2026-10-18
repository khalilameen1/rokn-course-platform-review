<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseRatingDeleteRequest;
use App\Http\Requests\API\CourseRatingRequest;
use App\Models\Course;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseRatingService;
use Illuminate\Http\JsonResponse;

final class CourseRatingController extends Controller
{
    private const CONFLICT_MESSAGE = "تغيّر تقييمك من جهاز آخر\nحدّث الكورس ثم حاول مرة أخرى";

    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly CourseRatingService $ratings
    ) {}

    public function store(CourseRatingRequest $request, int|string $courseId): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $result = $this->ratings->rate(
            $user,
            Course::query()->findOrFail($courseId),
            $request->integer('version'),
            $request->integer('rating'),
            $request->input('comment')
        );

        return match ($result['state']) {
            CourseRatingService::DENIED => $this->responses->error(
                $result['reason'] === 'watch_required'
                    ? 'شاهد مقطعًا كاملًا قبل التقييم'
                    : 'التقييم متاح لطلاب الكورس',
                403,
                ['code' => strtoupper((string) $result['reason'])]
            ),
            CourseRatingService::CONFLICT => $this->responses->error(self::CONFLICT_MESSAGE, 409, $result['data']),
            default => $this->responses->success($result['data'], 'تم حفظ تقييمك'),
        };
    }

    public function destroy(
        CourseRatingDeleteRequest $request,
        int|string $courseId
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();
        $result = $this->ratings->remove(
            $user,
            Course::query()->findOrFail($courseId),
            $request->integer('version')
        );

        return $result['state'] === CourseRatingService::CONFLICT
            ? $this->responses->error(self::CONFLICT_MESSAGE, 409, $result['data'])
            : $this->responses->success($result['data'], 'تم حذف تقييمك');
    }
}
