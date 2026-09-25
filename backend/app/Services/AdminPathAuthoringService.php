<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Path;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class AdminPathAuthoringService
{
    public function __construct(private AdminPathReadService $read)
    {
    }

    /** @param array<string,mixed> $data @param Closure(Path):void $complete */
    public function create(array $data, Closure $complete): Path
    {
        return DB::transaction(function () use ($data, $complete): Path {
            $selected = $this->courseIds($data);
            $courses = $this->read->courses()->whereKey($selected)->orderBy('id')->lockForUpdate()->get();
            $this->assertSelectable($selected, $courses);
            $path = Path::query()->create($this->titles($data));
            $path->interests()->sync($data['interest_ids'] ?? []);
            foreach ($courses as $course) $course->update(['path_id' => $path->id]);
            $complete($path);

            return $path;
        }, 3);
    }

    /** @param array<string,mixed> $data */
    public function update(int $id, array $data, string $expected): void
    {
        DB::transaction(function () use ($id, $data, $expected): void {
            $selected = $this->courseIds($data);
            // Publication locks the canonical course before its other owners.
            // Do not reverse that order by taking the Path first.
            $courses = $this->read->courses()
                ->where(fn ($query) => $query->where('path_id', $id)->orWhereIn('id', $selected))
                ->orderBy('id')->lockForUpdate()->get();
            $path = Path::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertSelectable($selected, $courses);
            if (!hash_equals($this->read->editorVersion($path), $expected)) {
                throw ValidationException::withMessages([
                    'editor_version' => "عدّل شخص آخر هذا المسار\nأعد تحميل الصفحة قبل الحفظ",
                ]);
            }
            $path->update($this->titles($data));
            $path->interests()->sync($data['interest_ids'] ?? []);
            foreach ($courses as $course) {
                $target = in_array((int) $course->id, $selected, true) ? $path->id : null;
                if ((int) ($course->path_id ?? 0) !== (int) ($target ?? 0)) {
                    $course->update(['path_id' => $target]);
                }
            }
        }, 3);
    }

    /** Keep the existing deletion guard, including references held by retained drafts. */
    public function deleteIfUnused(int $id): bool
    {
        return DB::transaction(function () use ($id): bool {
            $path = Path::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            if ($path->courses()->exists()) return false;
            $path->interests()->detach();
            $path->delete();

            return true;
        }, 3);
    }

    /** @param array<string,mixed> $data @return list<int> */
    private function courseIds(array $data): array
    {
        return collect($data['course_ids'] ?? [])->map(fn ($id): int => (int) $id)
            ->unique()->sort()->values()->all();
    }

    /** @param list<int> $selected @param Collection<int,Course> $courses */
    private function assertSelectable(array $selected, Collection $courses): void
    {
        if (array_diff($selected, $courses->modelKeys()) !== []) {
            throw ValidationException::withMessages([
                'course_ids' => ['اختر الكورسات الأصلية المتاحة فقط وليس نسخ التعديل أو الكورسات المحذوفة'],
            ]);
        }
    }

    /** @param array<string,mixed> $data @return array{title_ar:string,title_en:string} */
    private function titles(array $data): array
    {
        return ['title_ar' => $data['title_ar'], 'title_en' => $data['title_en']];
    }
}
