<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;

final readonly class CourseAuthoringDeletionService
{
    public function __construct(
        private CourseSectionMediaService $media,
        private ProjectSubmissionFileRetentionService $projectFiles
    ) {
    }

    public function deleteSection(CourseSection $section): void
    {
        $content = $section->sectionable()->lockForUpdate()->first();
        if ($content instanceof Project) {
            $this->projectFiles->purgeForDeletedProject($content);
        }

        $section->delete();
        if ($content) {
            $content->delete();
        }
        $this->media->retireDeleted($content instanceof Lesson ? $content : null);
    }

    /** @return list<int> */
    public function deleteModule(CourseModule $module): array
    {
        $sections = $module->sections()
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        $sectionIds = $sections->modelKeys();

        foreach ($sections as $section) {
            $this->deleteSection($section);
        }
        $module->delete();

        return array_map(static fn ($id): int => (int) $id, $sectionIds);
    }
}
