<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Course;
use App\Services\CoursePlanAttachmentGrantService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class GrantCourseAttachmentsToEnrollments implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public bool $afterCommit = true;
    public int $tries = 5;
    public array $backoff = [15, 60, 300, 900];

    public function __construct(
        public readonly int $courseId,
        public readonly int $publishedRevision,
        public readonly bool $chat,
        public readonly bool $project
    ) {}

    public function uniqueId(): string
    {
        return implode(':', [$this->courseId, $this->publishedRevision, (int) $this->chat, (int) $this->project]);
    }

    public function handle(CoursePlanAttachmentGrantService $grants): void
    {
        $course = Course::query()->find($this->courseId);
        if (!$course || (int) $course->last_published_authoring_version < $this->publishedRevision) return;
        $grants->grantAttachmentsToCurrentEnrollments($course, $this->chat, $this->project);
    }
}
