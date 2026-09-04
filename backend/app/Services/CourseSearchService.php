<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class CourseSearchService
{
    public function __construct(
        private ArabicSearchNormalizer $normalizer,
        private CourseCatalogueQueryService $catalogue,
        private CourseDurationService $duration
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function results(array $filters): LengthAwarePaginator
    {
        $key = 'course-search:v5:' . hash('sha256', (string) json_encode([
            'revision' => $this->catalogue->revision(),
            'q' => $this->normalizer->normalize((string) ($filters['q'] ?? '')),
            'page' => (int) ($filters['page'] ?? 1),
            'per_page' => (int) ($filters['per_page'] ?? 12),
            'classification_id' => $filters['classification_id'] ?? null,
            'course_type' => $filters['course_type'] ?? null,
        ]));
        $build = fn (): LengthAwarePaginator => $this->buildResults($filters);

        return $this->catalogue->rememberPage($key, $build, 120);
    }

    /** @param array<string, mixed> $filters */
    private function buildResults(array $filters): LengthAwarePaginator
    {
        $query = $this->catalogue
            ->applyPublicContract(Course::query())
            ->when(
                $filters['classification_id'] ?? null,
                function (Builder $courses, $classificationId): void {
                    $courses->whereHas(
                        'classifications',
                        fn (Builder $classifications) => $classifications->whereKey($classificationId)
                    );
                }
            )
            ->when(
                $filters['course_type'] ?? null,
                fn (Builder $courses, $type) => $courses->where('course_type', $type)
            );

        $results = $this->catalogue->orderForDiscovery(
            $this->catalogue->applySearch($query, (string) $filters['q'])
        )
            ->paginate(
                (int) ($filters['per_page'] ?? 12),
                ['*'],
                'page',
                (int) ($filters['page'] ?? 1)
            );
        $this->duration->attachMany($results->getCollection());

        return $results;
    }
}
