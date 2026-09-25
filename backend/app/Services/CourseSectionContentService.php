<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\CourseSectionEdit;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use UnexpectedValueException;

/** Persists validated content intent inside the caller's section transaction. */
final readonly class CourseSectionContentService
{
    public function __construct(private CourseSectionTypeChangeGuard $typeChangeGuard)
    {
    }

    public function create(CourseSectionEdit $edit, Course $course, CourseSectionMediaStage $media): Model
    {
        return match ($edit->type) {
            'lesson' => $this->saveLesson($edit, $course, null, $media),
            'project' => Project::query()->create($this->projectData($edit)),
            default => throw new UnexpectedValueException('Unsupported course section type.'),
        };
    }

    public function update(
        CourseSectionEdit $edit,
        Course $course,
        CourseSection $section,
        CourseSectionMediaStage $media
    ): Model {
        $oldType = $section->getSectionType();
        $content = $section->sectionable;

        if ($oldType !== $edit->type && $content) {
            $this->typeChangeGuard->assertAllowed($section, $content);
            $content->delete();
            $content = null;
        }

        return match ($edit->type) {
            'lesson' => $this->saveLesson(
                $edit,
                $course,
                $oldType === 'lesson' && $content instanceof Lesson ? $content : null,
                $media
            ),
            'project' => $this->saveProject(
                $edit,
                $oldType === 'project' && $content instanceof Project ? $content : null
            ),
            default => throw new UnexpectedValueException('Unsupported course section type.'),
        };
    }

    public function modelClass(string $sectionType): string
    {
        return match ($sectionType) {
            'lesson' => Lesson::class,
            'project' => Project::class,
            default => throw new UnexpectedValueException('Unsupported course section type.'),
        };
    }

    private function saveLesson(
        CourseSectionEdit $edit,
        Course $course,
        ?Lesson $lesson,
        CourseSectionMediaStage $media
    ): Lesson {
        $videoGuid = $media->videoGuid ?: $media->previousVideoGuid();
        $thumbnailPath = $media->thumbnailPath ?: $media->previousThumbnailPath();
        $changes = $lesson ? $edit->lessonChanges : array_replace([
            'description_ar' => '', 'description_en' => '',
            'duration_minutes' => null, 'is_opened' => false,
        ], $edit->lessonChanges);
        $data = array_merge($changes, [
            'title_ar' => $edit->titleAr,
            'title_en' => $edit->titleEn,
            'video_link' => null,
            'video_source_type' => 'bunny',
            'bunny_video_id' => $videoGuid,
            'thumbnail_path' => $thumbnailPath,
            'list_id' => $course->id,
        ]);
        if ($lesson) {
            $lesson->update($data);
        } else {
            $lesson = Lesson::query()->create($data);
        }

        if ($media->videoChanged || !$lesson->mediaState()->exists()) {
            LessonMediaState::query()->updateOrCreate(
                ['lesson_id' => $lesson->id],
                LessonMediaState::resetForGeneration((string) $videoGuid)
            );
        }

        return $lesson;
    }

    private function saveProject(CourseSectionEdit $edit, ?Project $project): Project
    {
        $data = $this->projectData($edit, $project);
        if ($project) {
            $project->update($data);
            return $project;
        }

        return Project::query()->create($data);
    }

    /** @return array<string,mixed> */
    private function projectData(CourseSectionEdit $edit, ?Project $existing = null): array
    {
        $data = $existing ? $edit->projectChanges : array_replace([
            'requirements_text_ar' => null, 'requirements_text_en' => null,
            'is_graduation_project' => false,
        ], $edit->projectChanges);
        if ($existing && $edit->projectSubmissionTypes === null) {
            return $data;
        }

        $submissionTypes = (array) config('projects.submission_types', []);
        $selectedTypes = collect($edit->projectSubmissionTypes ?? [])
            ->map(static fn ($type): string => trim((string) $type))
            ->filter()
            ->unique()
            ->values();
        $allowedMimeTypes = $selectedTypes
            ->flatMap(static fn (string $type): array =>
                (array) ($submissionTypes[$type]['mime_types'] ?? [])
            )
            ->map(static fn ($mime): string => strtolower(trim((string) $mime)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $data + [
            'submission_text_enabled' => $selectedTypes->contains('text'),
            'submission_allowed_mime_types' => $allowedMimeTypes,
        ];
    }
}
