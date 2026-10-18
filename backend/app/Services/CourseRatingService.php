<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseRating;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of a learner's course rating. Each write is versioned: a
 * transport retry of a committed edit is success, a different edit from
 * another device is a conflict. Lock order is the learner, then the course,
 * then the rating row.
 */
final readonly class CourseRatingService
{
    public const SAVED = 'saved';

    public const CONFLICT = 'conflict';

    public const DENIED = 'denied';

    public function __construct(private CourseRatingEligibilityService $eligibility) {}

    /**
     * @return array{state: string, reason: string|null, data: array<string, int|float|string|null>|null}
     */
    public function rate(
        User $user,
        Course $course,
        int $expectedVersion,
        int $nextRating,
        ?string $nextComment
    ): array {
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
            // The exclusive course lock also serializes two learners' first
            // ratings, whose locking reads of absent rows would otherwise share
            // one InnoDB gap lock and deadlock on insert.
            $lockedUser = User::query()->whereKey($user->id)
                ->lockForUpdate()->firstOrFail();
            $lockedCourse = Course::query()->whereKey($course->id)
                ->lockForUpdate()->firstOrFail();
            $eligibility = $this->eligibility->for($lockedUser, $lockedCourse);
            if (! $eligibility['can_rate']) {
                return ['state' => self::DENIED, 'reason' => $eligibility['reason'], 'rating' => null];
            }

            /** @var CourseRating|null $rating */
            $rating = CourseRating::withTrashed()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (! $rating) {
                if ($expectedVersion !== 0) {
                    return ['state' => self::CONFLICT, 'reason' => null, 'rating' => null];
                }

                // The user row above serializes first writes for this learner.
                // Use the model write so catalogue aggregates are invalidated;
                // a query-builder insert followed by an unchanged save emits
                // no creation event and leaves the first rating invisible in
                // cached course cards.
                $rating = new CourseRating;
                $rating->forceFill([
                    'user_id' => $user->id,
                    'course_id' => $course->id,
                    'rating' => $nextRating,
                    'comment' => $nextComment,
                    'version' => 1,
                ]);
                $rating->save();

                return ['state' => self::SAVED, 'reason' => null, 'rating' => $rating];
            }

            $sameValue = ! $rating->trashed()
                && (int) $rating->rating === $nextRating
                && ($rating->comment !== null ? (string) $rating->comment : null) === $nextComment;
            if ((int) $rating->version !== $expectedVersion) {
                // A transport retry after a committed response is success,
                // while a genuinely different edit from another device is a conflict.
                return [
                    'state' => $sameValue ? self::SAVED : self::CONFLICT,
                    'reason' => null,
                    'rating' => $rating,
                ];
            }

            if ($sameValue) {
                return ['state' => self::SAVED, 'reason' => null, 'rating' => $rating];
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

            return ['state' => self::SAVED, 'reason' => null, 'rating' => $rating];
        }, 3);

        return [
            'state' => $result['state'],
            'reason' => $result['reason'],
            'data' => $result['state'] === self::DENIED
                ? null
                : $this->summary($course, $result['rating']),
        ];
    }

    /**
     * @return array{state: string, data: array<string, int|float|string|null>}
     */
    public function remove(User $user, Course $course, int $expectedVersion): array
    {
        $result = DB::transaction(function () use ($user, $course, $expectedVersion): array {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            /** @var CourseRating|null $rating */
            $rating = CourseRating::withTrashed()
                ->where('user_id', $user->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->first();

            if (! $rating || $rating->trashed()) {
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
            return ['state' => self::CONFLICT, 'data' => $this->summary($course, $result['rating'])];
        }

        return [
            'state' => self::SAVED,
            'data' => $this->summary($course, null, (int) ($result['rating']->version ?? 0)),
        ];
    }

    /** @return array<string, int|float|string|null> */
    private function summary(
        Course $course,
        ?CourseRating $rating,
        ?int $version = null
    ): array {
        $aggregate = CourseRating::query()
            ->where('course_id', $course->id)
            ->whereBetween('rating', [1, 5])
            ->selectRaw('COUNT(*) AS ratings_count, AVG(rating) AS average_rating')
            ->toBase()
            ->first();
        $count = (int) ($aggregate->ratings_count ?? 0);

        return [
            'rating' => $rating && ! $rating->trashed() ? (int) $rating->rating : null,
            'comment' => $rating && ! $rating->trashed() ? $rating->comment : null,
            'version' => $version ?? (int) ($rating->version ?? 0),
            'average_rating' => $count > 0
                ? round((float) $aggregate->average_rating, 1)
                : null,
            'ratings_count' => $count,
        ];
    }
}
