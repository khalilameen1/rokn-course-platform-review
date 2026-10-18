<?php

declare(strict_types=1);

namespace App\Listeners;

use App\Events\CourseCompleted;
use App\Models\Course;
use App\Models\User;
use App\Services\LearningRewardService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

final class AwardCourseCompletionReward implements ShouldQueue
{
    use InteractsWithQueue;

    public bool $afterCommit = true;

    public int $tries = 3;

    public int $timeout = 30;

    public bool $failOnTimeout = true;

    public array $backoff = [10, 60, 180];

    public function __construct(
        private readonly LearningRewardService $rewards
    ) {}

    public function handle(CourseCompleted $event): void
    {
        $user = User::query()->find($event->resolvedUserId());
        $course = Course::withTrashed()->find($event->resolvedCourseId());
        if (! $user || ! $course) {
            return;
        }

        $this->rewards->awardCourseCompletion(
            $user,
            $course,
            $event->rewardContract
        );
    }
}
