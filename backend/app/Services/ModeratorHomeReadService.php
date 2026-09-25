<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;

/** Content-only dashboard projection; never resolves financial reporting services. */
final readonly class ModeratorHomeReadService
{
    public function __construct(
        private AdminContentInventoryReadService $inventory,
        private CoursePublishingService $publishing
    ) {
    }

    /** @param array<string,mixed> $queryParameters @return array<string,mixed> */
    public function read(array $queryParameters = [], int $page = 1): array
    {
        $courses = $this->inventory->courses()
            ->with([
                'photo',
                'teachers:id,name,name_ar,name_en,profile_image',
                'classifications:id,name_ar,name_en',
            ])
            ->withCount(['modules', 'sections'])
            ->latest('updated_at')
            ->latest('id')
            ->paginate(12, ['*'], 'page', $page)
            ->appends($queryParameters);
        $activeRevisions = CourseAuthoringRevision::query()
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->whereIn('canonical_course_id', $courses->getCollection()->modelKeys())
            ->get(['canonical_course_id', 'revision_course_id'])
            ->keyBy('canonical_course_id');
        $workingDrafts = Course::query()
            ->with([
                'photo',
                'teachers:id,name,name_ar,name_en,profile_image',
                'classifications:id,name_ar,name_en',
            ])
            ->withCount(['modules', 'sections'])
            ->whereIn('id', $activeRevisions->pluck('revision_course_id'))
            ->get()
            ->keyBy('id');
        $courses->setCollection($courses->getCollection()->map(function (Course $canonical) use (
            $activeRevisions,
            $workingDrafts
        ): Course {
            $revision = $activeRevisions->get((int) $canonical->id);

            return $revision
                ? ($workingDrafts->get((int) $revision->revision_course_id) ?: $canonical)
                : $canonical;
        }));
        $publishingAudits = $courses->getCollection()->mapWithKeys(
            fn (Course $course): array => [$course->id => $this->publishing->auditCatalogCard($course)]
        );
        $contentSummary = $this->inventory->summary();

        return compact('courses', 'publishingAudits', 'contentSummary');
    }
}
