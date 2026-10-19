<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Path;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

/** The path editor works with logical courses, never revision implementation rows. */
final readonly class AdminPathReadService
{
    public function __construct(private AdminContentInventoryReadService $inventory) {}

    /** @param array<string, mixed> $queryParameters */
    public function listing(string $search, array $queryParameters): LengthAwarePaginator
    {
        $paths = Path::query()->with('interests')->withCount([
            'courses' => fn (Builder $courses) => $courses->whereNotIn(
                'courses.id', CourseAuthoringRevision::query()->select('revision_course_id')
            ),
        ]);
        if ($search !== '') {
            $paths->where(function (Builder $query) use ($search): void {
                $query->where('title_ar', 'LIKE', "%{$search}%")
                    ->orWhere('title_en', 'LIKE', "%{$search}%");
            });
        }

        return $paths->latest()->latest('id')->paginate(10)->appends($queryParameters);
    }

    /** @return Builder<Course> */
    public function courses(): Builder
    {
        return $this->inventory->courses();
    }

    /** @return array<string,mixed> */
    public function form(?int $pathId = null): array
    {
        $data = [
            'interests' => Classification::all(),
            'courses' => $this->courses()->orderBy('name_ar')->orderBy('id')->get(),
        ];
        if ($pathId !== null) {
            $path = Path::query()->with('interests')->findOrFail($pathId);
            $path->setRelation('courses', $data['courses']->where('path_id', $pathId)->values());
            $data += ['path' => $path, 'editorVersion' => $this->editorVersion($path)];
        }

        return $data;
    }

    public function editorVersion(Path $path): string
    {
        $path->loadMissing('interests:id');

        return hash('sha256', json_encode([
            $path->title_ar,
            $path->title_en,
            collect($path->interests->modelKeys())->map(fn ($id): int => (int) $id)->sort()->values()->all(),
            $this->courses()->orderBy('id')->get(['id', 'path_id'])
                ->map(fn (Course $course): array => [(int) $course->id, (int) ($course->path_id ?? 0)])->all(),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
