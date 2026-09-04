<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class CourseSectionOrderingService
{
    /**
     * Put one newly-created or moved section at the requested module position.
     * The caller owns the surrounding course lock and transaction.
     */
    public function place(
        Course $course,
        CourseSection $section,
        int|string|null $previousModuleId,
        int $requestedOrder
    ): void {
        $moduleId = (int) $section->module_id;
        if ($moduleId < 1) {
            throw ValidationException::withMessages([
                'module_id' => 'يجب أن يبقى كل مقطع أو مشروع داخل وحدة',
            ]);
        }

        if ($previousModuleId && (int) $previousModuleId !== $moduleId) {
            $this->normalizeModule($course, (int) $previousModuleId);
        }

        $siblings = $this->moduleSections($course, $moduleId)
            ->reject(fn (CourseSection $candidate): bool => $candidate->is($section));
        $lessons = $siblings
            ->reject(fn (CourseSection $candidate): bool => $candidate->isProject())
            ->values();
        $projects = $siblings
            ->filter(fn (CourseSection $candidate): bool => $candidate->isProject())
            ->values();

        if ($section->isProject()) {
            if ($projects->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'module_id' => 'هذه الوحدة لها مشروع عبور بالفعل',
                ]);
            }
            $ordered = $lessons->concat($projects)->push($section);
        } else {
            $index = min($lessons->count(), max(0, $requestedOrder - 1));
            $lessons->splice($index, 0, [$section]);
            $ordered = $lessons->concat($projects);
        }

        $this->writeSequentialOrders($ordered);
    }

    /**
     * Apply the moderator's layout while preserving the learner contract:
     * every learning item belongs to a module and a crossing project is last.
     *
     * @param  array<int, array{id: int|string, order: int|string, module_id?: int|string|null}>  $requestedSections
     */
    public function apply(Course $course, array $requestedSections): void
    {
        $requestedModules = collect($requestedSections)
            ->filter(fn (array $section): bool => array_key_exists('module_id', $section))
            ->mapWithKeys(fn (array $section): array => [
                (int) $section['id'] => $section['module_id'] === null
                    ? null
                    : (int) $section['module_id'],
            ]);

        $learningSections = CourseSection::query()
            ->where('course_id', $course->id)
            ->whereIn('sectionable_type', [Lesson::class, Project::class])
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'module_id', 'section_type', 'sectionable_type']);

        $layout = $learningSections->map(fn (CourseSection $section): array => [
            'id' => (int) $section->id,
            'is_project' => $section->getSectionType() === 'project',
            'module_id' => $requestedModules->has($section->id)
                ? $requestedModules->get($section->id)
                : $section->module_id,
        ]);

        if ($layout->contains(fn (array $section): bool =>
            $requestedModules->has($section['id']) && $section['module_id'] === null
        )) {
            throw ValidationException::withMessages([
                'sections' => 'يجب أن يبقى كل مقطع أو مشروع داخل وحدة',
            ]);
        }

        if ($layout->where('is_project', true)
            ->groupBy('module_id')
            ->contains(fn ($projects): bool => $projects->count() > 1)) {
            throw ValidationException::withMessages([
                'sections' => 'يمكن لكل وحدة أن تحتوي مشروع عبور واحدًا فقط',
            ]);
        }

        $requestedIds = collect($requestedSections)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $currentModules = $learningSections
            ->whereIn('id', $requestedIds)
            ->pluck('module_id');

        foreach ($requestedSections as $section) {
            $values = ['order' => (int) $section['order']];
            if (array_key_exists('module_id', $section)) {
                $values['module_id'] = $section['module_id'];
            }

            CourseSection::query()
                ->whereKey((int) $section['id'])
                ->where('course_id', $course->id)
                ->update($values);
        }

        $currentModules
            ->merge($requestedModules->values())
            ->filter()
            ->map(fn ($moduleId): int => (int) $moduleId)
            ->unique()
            ->each(fn (int $moduleId) => $this->normalizeModule($course, $moduleId));
    }

    public function normalizeModule(Course $course, int|string|null $moduleId): void
    {
        if (!$moduleId) {
            return;
        }

        $sections = $this->moduleSections($course, (int) $moduleId)
            ->sortBy(fn (CourseSection $section): int => $section->isProject() ? 1 : 0)
            ->values();

        $this->writeSequentialOrders($sections);
    }

    /** @return Collection<int, CourseSection> */
    private function moduleSections(Course $course, int $moduleId): Collection
    {
        return CourseSection::query()
            ->where('course_id', $course->id)
            ->where('module_id', $moduleId)
            ->orderBy('order')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /** @param iterable<int, CourseSection> $sections */
    private function writeSequentialOrders(iterable $sections): void
    {
        foreach ($sections as $index => $section) {
            $order = $index + 1;
            if ((int) $section->order !== $order) {
                $section->updateQuietly(['order' => $order]);
            }
        }
    }
}
