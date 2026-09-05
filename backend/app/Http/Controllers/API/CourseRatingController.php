<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Requests\API\CourseRatingDeleteRequest;
use App\Http\Requests\API\CourseRatingRequest;
use App\Models\Course;
use App\Models\CourseRating;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\CourseRatingEligibilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

final class CourseRatingController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly CourseRatingEligibilityService $eligibility
    ) {
    }

    public function store(CourseRatingRequest $request, int|string $courseId): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $course = Course::query()->findOrFail($courseId);

        $expectedVersion = $request->integer('version');
        $nextRating = $request->integer('rating');
        $nextComment = $request->input('comment');

        $result = DB::transaction(function () use (
            $user,
            $course,
            $expectedVersion,
            $nextRating,
            $nextComment
        ): array {
            // User-bound enrollment, code redemption and certificate writes
            // take the learner before the course. Keep that global order here
            // while also locking the publication aggregate before authorising.
            $lockedUser = User::query()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            $lockedCourse = Course::query()->whereKey($course->id)
                ->lockForUpdate()->firstOrFail();
            $eligibility = $this->eligibility->for($lockedUser, $lockedCourse);
            if (!$eligibility['can_rate']) {
                return [
                    'conflict' => false,
                    'denied' => $eligibility['reason'],
                    'rating' => null,
                ];
            }

            /** @var CourseRating|null $rating */
            $rating = CourseRating::withTrashed()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (!$rating) {
                if ($expectedVersion !== 0) {
                    return ['conflict' => true, 'denied' => null, 'rating' => null];
                }

                // The user row above serializes first writes for this learner.
                // Use the model write so catalogue aggregates are invalidated;
                // a query-builder insert followed by an unchanged save emits
                // no creation event and leaves the first rating invisible in
                // cached course cards.
                $rating = new CourseRating();
                $rating->forceFill([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'rating' => $nextRating,
                    'comment' => $nextComment,
                    'version' => 1,
                ]);
                $rating->save();

                return ['conflict' => false, 'denied' => null, 'rating' => $rating];
            }

            $sameValue = !$rating->trashed()
                && (int) $rating->rating === $nextRating
                && ($rating->comment !== null ? (string) $rating->comment : null) === $nextComment;
            if ((int) $rating->version !== $expectedVersion) {
                // A transport retry after a committed response is success,
                // while a genuinely different edit from another device is a conflict.
                return ['conflict' => !$sameValue, 'denied' => null, 'rating' => $rating];
            }

            if ($sameValue) {
                return ['conflict' => false, 'denied' => null, 'rating' => $rating];
            }

            $rating->forceFill([
                'rating' => $nextRating,
                'comment' => $nextComment,
                'version' => (int) $rating->version + 1,
            ]);
            if ($rating->trashed()) {
                $rating->restore();
            } else {
                $rating->save();
            }

            return ['conflict' => false, 'denied' => null, 'rating' => $rating];
        }, 3);

        if ($result['denied'] !== null) {
            $message = $result['denied'] === 'watch_required'
                ? 'شاهد مقطعًا كاملًا قبل التقييم'
                : 'التقييم متاح لطلاب الكورس';

            return $this->responses->error($message, 403, [
                'code' => strtoupper((string) $result['denied']),
            ]);
        }

        if ($result['conflict']) {
            return $this->responses->error(
                "تغيّر تقييمك من جهاز آخر\nحدّث الكورس ثم حاول مرة أخرى",
                409,
                $this->payload($course, $result['rating'])
            );
        }

        return $this->responses->success(
            $this->payload($course, $result['rating']),
            'تم حفظ تقييمك'
        );
    }

    public function destroy(
        CourseRatingDeleteRequest $request,
        int|string $courseId
    ): JsonResponse {
        /** @var User $user */
        $user = auth('api')->user();
        $course = Course::query()->findOrFail($courseId);
        $expectedVersion = $request->integer('version');

        $result = DB::transaction(function () use ($user, $course, $expectedVersion): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            /** @var CourseRating|null $rating */
            $rating = CourseRating::withTrashed()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (!$rating || $rating->trashed()) {
                return ['conflict' => false, 'rating' => $rating];
            }
            if ((int) $rating->version !== $expectedVersion) {
                return ['conflict' => true, 'rating' => $rating];
            }

            $rating->forceFill(['version' => (int) $rating->version + 1])->save();
            $rating->delete();

            return ['conflict' => false, 'rating' => $rating];
        }, 3);

        if ($result['conflict']) {
            return $this->responses->error(
                "تغيّر تقييمك من جهاز آخر\nحدّث الكورس ثم حاول مرة أخرى",
                409,
                $this->payload($course, $result['rating'])
            );
        }

        return $this->responses->success(
            $this->payload($course, null, (int) ($result['rating']?->version ?? 0)),
            'تم حذف تقييمك'
        );
    }

    /** @return array<string, int|float|string|null> */
    private function payload(
        Course $course,
        ?CourseRating $rating,
        ?int $version = null
    ): array {
        $aggregate = CourseRating::query()
            ->where('course_id', $course->id)
            ->whereBetween('rating', [1, 5])
            ->selectRaw('COUNT(*) AS ratings_count, AVG(rating) AS average_rating')
            ->first();
        $count = (int) ($aggregate?->ratings_count ?? 0);

        return [
            'rating' => $rating && !$rating->trashed() ? (int) $rating->rating : null,
            'comment' => $rating && !$rating->trashed() ? $rating->comment : null,
            'version' => $version ?? (int) ($rating?->version ?? 0),
            'average_rating' => $count > 0
                ? round((float) $aggregate->average_rating, 1)
                : null,
            'ratings_count' => $count,
        ];
    }
}
