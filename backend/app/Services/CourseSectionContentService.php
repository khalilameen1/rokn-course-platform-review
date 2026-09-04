<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Project;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use UnexpectedValueException;

final readonly class CourseSectionContentService
{
    public function __construct(
        private CourseSectionTypeChangeGuard $typeChangeGuard
    ) {
    }

    public function create(
        Request $request,
        Course $course,
        int $order,
        ?string $videoGuid,
        ?string $thumbnailPath
    ): Model {
        return match ((string) $request->input('section_type')) {
            'lesson' => $this->createLesson(
                $request,
                $course,
                $order,
                $videoGuid,
                $thumbnailPath
            ),
            'project' => Project::query()->create($this->projectData($request)),
            default => throw new UnexpectedValueException('Unsupported course section type.'),
        };
    }

    public function update(
        Request $request,
        Course $course,
        CourseSection $section,
        int $order,
        ?string $newVideoGuid,
        ?string $newThumbnailPath,
        ?string $existingVideoGuid,
        ?string $existingThumbnailPath
    ): Model {
        $newType = (string) $request->input('section_type');
        $oldType = $section->getSectionType();
        $content = $section->sectionable;

        if ($oldType !== $newType && $content) {
            $this->typeChangeGuard->assertAllowed($section, $content);
            $content->delete();
            $content = null;
        }

        return match ($newType) {
            'lesson' => $this->updateLesson(
                $request,
                $course,
                $order,
                $oldType === 'lesson' && $content instanceof Lesson ? $content : null,
                $newVideoGuid ?: $existingVideoGuid,
                $newThumbnailPath ?: $existingThumbnailPath,
                $newVideoGuid !== null
            ),
            'project' => $this->updateProject(
                $request,
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

    private function createLesson(
        Request $request,
        Course $course,
        int $order,
        ?string $videoGuid,
        ?string $thumbnailPath
    ): Lesson {
        $lesson = Lesson::query()->create(
            $this->lessonData($request, $course, $order, $videoGuid, $thumbnailPath)
        );
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id,
        ] + LessonMediaState::resetForGeneration((string) $videoGuid));

        return $lesson;
    }

    private function updateLesson(
        Request $request,
        Course $course,
        int $order,
        ?Lesson $lesson,
        ?string $videoGuid,
        ?string $thumbnailPath,
        bool $videoChanged
    ): Lesson {
        $data = $this->lessonData($request, $course, $order, $videoGuid, $thumbnailPath);
        if ($lesson) {
            $lesson->update($data);
        } else {
            $lesson = Lesson::query()->create($data);
        }

        if ($videoChanged || !$lesson->mediaState()->exists()) {
            LessonMediaState::query()->updateOrCreate(
                ['lesson_id' => $lesson->id],
                LessonMediaState::resetForGeneration((string) $videoGuid)
            );
        }

        return $lesson;
    }

    /** @return array<string, mixed> */
    private function lessonData(
        Request $request,
        Course $course,
        int $order,
        ?string $videoGuid,
        ?string $thumbnailPath
    ): array {
        return [
            'title_ar' => $request->input('title_ar'),
            'title_en' => $request->input('title_en'),
            'description_ar' => $request->input('lesson_description_ar', ''),
            'description_en' => $request->input('lesson_description_en', ''),
            'video_link' => null,
            'video_source_type' => 'bunny',
            'bunny_video_id' => $videoGuid,
            'thumbnail_path' => $thumbnailPath,
            'list_id' => $course->id,
            'is_opened' => $request->boolean('is_opened'),
            'duration_minutes' => $request->input('lesson_duration_minutes'),
        ];
    }

    private function updateProject(Request $request, ?Project $project): Project
    {
        $data = $this->projectData($request);
        if ($project) {
            $project->update($data);
            return $project;
        }

        return Project::query()->create($data);
    }

    /** @return array<string, mixed> */
    private function projectData(Request $request): array
    {
        $submissionTypes = (array) config('projects.submission_types', []);
        $selectedTypes = collect((array) $request->input('project_submission_types', []))
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

        return [
            'requirements_text_ar' => $request->input('project_requirements_ar'),
            'requirements_text_en' => $request->input('project_requirements_en'),
            'is_graduation_project' => $request->boolean('is_graduation_project'),
            'submission_text_enabled' => $selectedTypes->contains('text'),
            'submission_allowed_mime_types' => $allowedMimeTypes,
        ];
    }
}
