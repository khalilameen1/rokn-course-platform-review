<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;

final readonly class AdminCourseOutlinePresenter
{
    public function __construct(private BunnyService $bunny)
    {
    }

    /** @return array<string, mixed> */
    public function graph(Course $course): array
    {
        $course->loadMissing([
            'modules' => fn ($modules) => $modules
                ->with(['sections' => fn ($sections) => $sections
                    ->with('sectionable')
                    ->orderBy('order')
                    ->orderBy('id')])
                ->orderBy('order')
                ->orderBy('id'),
        ]);

        return [
            'course_id' => (int) $course->id,
            'authoring_version' => (int) $course->authoring_version,
            'module_store_url' => route('admin.courses.modules.store', $course),
            'section_store_url' => route('admin.courses.sections.store', $course),
            'module_reorder_url' => route('admin.courses.modules.reorder', $course),
            'section_reorder_url' => route('admin.courses.sections.reorder', $course),
            'modules' => $course->modules
                ->sortBy([['order', 'asc'], ['id', 'asc']])
                ->map(fn (CourseModule $module): array => $this->module($course, $module))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function module(Course $course, CourseModule $module): array
    {
        $module->loadMissing([
            'sections' => fn ($sections) => $sections
                ->with('sectionable')
                ->orderBy('order')
                ->orderBy('id'),
        ]);

        return [
            'id' => (int) $module->id,
            'title' => (string) ($module->title_ar ?: $module->title_en),
            'title_ar' => $module->title_ar,
            'title_en' => $module->title_en,
            'order' => (int) $module->order,
            'update_url' => route('admin.courses.modules.update', [$course, $module]),
            'delete_url' => route('admin.courses.modules.destroy', [$course, $module]),
            'sections' => $module->sections
                ->sortBy([['order', 'asc'], ['id', 'asc']])
                ->map(fn (CourseSection $section): array => $this->section($course, $section))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function section(Course $course, CourseSection $section): array
    {
        $section->loadMissing('sectionable');
        $content = $section->sectionable;
        $lesson = $content instanceof Lesson ? $content : null;
        $project = $content instanceof Project ? $content : null;
        $thumbnailPath = trim((string) $lesson?->thumbnail_path);

        return [
            'id' => (int) $section->id,
            'module_id' => $section->module_id === null ? null : (int) $section->module_id,
            'type' => $section->getSectionType(),
            'title' => (string) ($section->title_ar ?: $section->title_en),
            'title_ar' => $section->title_ar,
            'title_en' => $section->title_en,
            'order' => (int) $section->order,
            'lesson_description_ar' => $lesson?->description_ar,
            'lesson_description_en' => $lesson?->description_en,
            'lesson_duration_minutes' => $lesson?->duration_minutes === null
                ? null
                : (int) $lesson->duration_minutes,
            'is_opened' => (bool) ($lesson?->is_opened ?? false),
            'has_video' => trim((string) $lesson?->bunny_video_id) !== '',
            'has_thumbnail' => $thumbnailPath !== '',
            'thumbnail_url' => $thumbnailPath === ''
                ? null
                : $this->bunny->generateBunnySignedUrl($thumbnailPath),
            'project_requirements_ar' => $project?->requirements_text_ar,
            'project_requirements_en' => $project?->requirements_text_en,
            'project_submission_types' => $this->projectSubmissionTypes($project),
            'is_graduation_project' => (bool) ($project?->is_graduation_project ?? false),
            'update_url' => route('admin.courses.sections.update', [$course, $section]),
            'delete_url' => route('admin.courses.sections.destroy', [$course, $section]),
        ];
    }

    /** @return list<string> */
    private function projectSubmissionTypes(?Project $project): array
    {
        if (!$project) {
            return [];
        }

        $storedMimes = $project->submission_allowed_mime_types;

        return collect((array) config('projects.submission_types', []))
            ->filter(function (array $definition, string $type) use ($project, $storedMimes): bool {
                if ($type === 'text') {
                    return (bool) $project->submission_text_enabled;
                }
                if ($storedMimes === null) {
                    return true;
                }

                return collect((array) $storedMimes)
                    ->intersect((array) ($definition['mime_types'] ?? []))
                    ->isNotEmpty();
            })
            ->keys()
            ->values()
            ->all();
    }
}
