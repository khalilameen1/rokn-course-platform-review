<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseSection;
use App\Models\PlaybackSession;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\StudentSectionProgress;
use App\Models\WatchingLog;
use Illuminate\Validation\ValidationException;

final class CourseSectionTypeChangeGuard
{
    public function assertAllowed(CourseSection $section, object $content): void
    {
        $hasActivity = StudentSectionProgress::query()
            ->where('course_section_id', $section->id)->exists()
            || WatchingLog::query()->where('course_section_id', $section->id)->exists()
            || PlaybackSession::query()->where('course_section_id', $section->id)->exists();

        if ($content instanceof Project) {
            $hasActivity = $hasActivity
                || ProjectSubmission::query()->where('project_id', $content->id)->exists()
                || PortfolioItem::query()->where('source_project_id', $content->id)->exists();
        }

        if ($hasActivity) {
            throw ValidationException::withMessages([
                'section_type' => [
                    "هذا المحتوى مرتبط بتقدم طلاب محفوظ\nيمكنك تعديله أو حذفه لكن لا تغيّر نوعه",
                ],
            ]);
        }
    }
}
