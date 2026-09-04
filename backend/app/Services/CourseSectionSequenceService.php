<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseModule;
use Illuminate\Support\Collection;

final class CourseSectionSequenceService
{
    private const LEARNING_TYPES = ['lesson', 'project'];

    /**
     * The published learner map is the ordered module graph. A second flat
     * Course::sections graph can drift from it and must not be presented as a
     * parallel source of lock or progress state.
     */
    public function fromModules(Collection $modules): Collection
    {
        return $modules
            ->sortBy([
                ['order', 'asc'],
                ['id', 'asc'],
            ])
            ->flatMap(fn ($module): Collection => $module->relationLoaded('sections')
                ? $module->sections->sortBy([
                    ['order', 'asc'],
                    ['id', 'asc'],
                ])->values()
                : collect())
            ->values();
    }

    /** Only reels and crossing projects take part in course progression. */
    public function learning(Collection $sections): Collection
    {
        return $this->ordered(
            $sections->filter(
                fn ($section): bool => in_array($section->getSectionType(), self::LEARNING_TYPES, true)
            )
        );
    }

    /**
     * Order a multi-course read with one module-order query.
     *
     * @return Collection<int,Collection<int,mixed>> keyed by course id
     */
    public function learningByCourse(Collection $sections): Collection
    {
        $learning = $sections->filter(
            fn ($section): bool => in_array(
                $section->getSectionType(),
                self::LEARNING_TYPES,
                true
            )
        );
        $moduleOrders = CourseModule::query()
            ->whereIn(
                'id',
                $learning->pluck('module_id')->filter()->map(
                    fn ($id): int => (int) $id
                )->unique()
            )
            ->pluck('order', 'id');

        return $learning
            ->groupBy('course_id')
            ->map(fn (Collection $courseSections): Collection =>
                $this->orderedWithModuleOrders($courseSections, $moduleOrders)
            );
    }

    /**
     * Section order is local to a module, so module order always comes first.
     */
    public function ordered(Collection $sections): Collection
    {
        $moduleIds = $sections->pluck('module_id')->filter()->map(fn ($id): int => (int) $id)->unique();
        if ($sections->contains(fn ($section): bool => $section->module_id === null)) {
            throw new \LogicException('Every learning section must belong to a course module.');
        }

        $moduleOrders = CourseModule::query()
            ->whereIn('id', $moduleIds)
            ->pluck('order', 'id');
        return $this->orderedWithModuleOrders($sections, $moduleOrders);
    }

    private function orderedWithModuleOrders(
        Collection $sections,
        Collection $moduleOrders
    ): Collection {
        if ($moduleOrders->count() !== $sections->pluck('module_id')->unique()->count()) {
            throw new \LogicException('A learning section references a missing course module.');
        }

        return $sections->sortBy(function ($section) use ($moduleOrders): string {
            $moduleOrder = (int) $moduleOrders->get((int) $section->module_id);

            return sprintf(
                '%010d:%010d:%010d',
                $moduleOrder,
                (int) $section->order,
                (int) $section->id
            );
        })->values();
    }
}
