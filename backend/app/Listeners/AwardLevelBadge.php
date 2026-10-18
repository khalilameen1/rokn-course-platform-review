<?php

namespace App\Listeners;

use App\Events\CourseCompleted;
use App\Models\Course;
use App\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;

class AwardLevelBadge implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public array $backoff = [10, 60, 180];

    /**
     * Handle the event.
     */
    public function handle(CourseCompleted $event): void
    {
        $user = User::query()->find($event->resolvedUserId());
        $course = Course::query()->find($event->resolvedCourseId());
        if (! $user || ! $course) {
            return;
        }

        // Badges are an explicit opt-in for professional/freelance courses only.
        // Religious and language courses never award career badges by accident.
        if (
            ! $course->level_id
            || ! $course->awards_badge
            || ! in_array($course->badge_track, ['professional', 'freelance'], true)
        ) {
            return;
        }

        // Serialize awards per learner. Unlike insertOrIgnore(), this only
        // treats the exact existing award as an idempotent replay; schema,
        // connection and foreign-key failures remain visible to the queue and
        // are retried/reported instead of being silently swallowed.
        DB::transaction(function () use ($user, $course): void {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $alreadyAwarded = DB::table('user_level')
                ->where('user_id', $user->id)
                ->where('level_id', $course->level_id)
                ->where('course_id', $course->id)
                ->exists();

            if ($alreadyAwarded) {
                return;
            }

            DB::table('user_level')->insert([
                'user_id' => $user->id,
                'level_id' => $course->level_id,
                'course_id' => $course->id,
                'earned_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }
}
