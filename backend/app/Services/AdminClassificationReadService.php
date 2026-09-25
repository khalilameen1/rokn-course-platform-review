<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class AdminClassificationReadService
{
    /** @return Collection<int, Classification> */
    public function rows(): Collection
    {
        return Classification::query()
            ->withCount(['courses as home_courses_count' => fn ($courses) => $this
                ->onlyCanonicalCourses($courses)->where('is_catalog_visible', true)])
            ->orderBy('home_order')->orderBy('name_ar')->get();
    }

    /** @return Builder<Course> */
    public function selectableCourses(): Builder
    {
        return $this->onlyCanonicalCourses(Course::query())->where('is_catalog_visible', true);
    }

    public function editorVersion(Classification $classification): string
    {
        return hash('sha256', json_encode([
            $classification->name_ar,
            $classification->name_en,
            (bool) $classification->show_on_home,
            (int) $classification->home_order,
            $this->canonicalCourseIds($classification),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** @return Collection<int, Course> */
    public function homeCourseOptions(): Collection
    {
        return $this->selectableCourses()
            ->select(['id', 'name_ar', 'name_en', 'home_sort_order', 'is_coming_soon'])
            ->orderBy('home_sort_order')
            ->orderBy('name_ar')
            ->orderBy('id')
            ->get();
    }

    /** @return array<int, int> */
    public function canonicalCourseIds(Classification $classification): array
    {
        return $this->onlyCanonicalCourses($classification->courses())
            ->pluck('courses.id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /** @return array<int, int> */
    public function visibleCanonicalCourseIds(Classification $classification): array
    {
        return $this->onlyCanonicalCourses($classification->courses())
            ->where('is_catalog_visible', true)
            ->pluck('courses.id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();
    }

    /**
     * Limit home curation to real course identities. Working drafts and retained
     * archives deliberately keep their own classification snapshots for the
     * staged three-way merge and must never appear as extra home-row courses.
     */
    private function onlyCanonicalCourses($courses)
    {
        return $courses->whereNotIn(
            'courses.id',
            CourseAuthoringRevision::query()->select('revision_course_id')
        );
    }

}
