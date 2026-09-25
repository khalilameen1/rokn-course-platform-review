<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\User;
use App\Support\TeacherEditorVersion;
use Closure;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

/** Teacher/profile writes and image ownership, independent of dashboard rendering. */
final class AdminTeacherAuthoringService
{
    public function __construct(
        private readonly StoredFileUploadService $uploads,
        private readonly StoredFileDeletionService $cleanup
    ) {
    }

    /**
     * @param array<string, mixed> $payload Validated profile and authorized credential fields only.
     * @param Closure(User):void $completeIntent Runs with the profile write in one transaction.
     */
    public function create(array $payload, bool $active, string $requestId, ?UploadedFile $image, Closure $completeIntent): User
    {
        $identity = $image === null ? null
            : 'admin-teacher|'.strtolower($requestId).'|'.hash_file('sha256', $image->getRealPath());
        $imagePath = $this->stageImage($image, $identity);
        try {
            return DB::transaction(function () use ($payload, $active, $requestId, $imagePath, $completeIntent): User {
                $teacher = User::withTrashed()->where('authoring_request_id', $requestId)
                    ->lockForUpdate()->first();
                if (!$teacher) {
                    $teacher = new User();
                    $teacher->fill($this->withHashedPassword($payload));
                    // Guarded role and request identity are assigned by the command,
                    // not mass-assigned from a form. Persist the profile in one insert.
                    $teacher->forceFill([
                        'role' => 'teacher', 'active' => $active, 'authoring_request_id' => $requestId,
                    ])->save();
                }
                if ($imagePath) {
                    $teacher->allPhotos()->firstOrCreate(['type' => 'featured'], ['path' => $imagePath]);
                }
                $completeIntent($teacher);

                return $teacher;
            }, 3);
        } catch (\Throwable $exception) {
            if ($imagePath) $this->cleanup->deleteOrQueue('public', $imagePath);
            throw $exception;
        }
    }

    /** @param array<string, mixed> $payload Validated profile and authorized credential fields only. */
    public function update(int $teacherId, array $payload, bool $active, string $editorVersion, ?UploadedFile $image): void
    {
        $payload = $this->withHashedPassword($payload);
        $imagePath = $this->stageImage($image);
        try {
            DB::transaction(function () use ($teacherId, $payload, $active, $editorVersion, $imagePath): void {
                $teacher = User::query()->whereKey($teacherId)->where('role', 'teacher')
                    ->lockForUpdate()->firstOrFail();
                if (!hash_equals(TeacherEditorVersion::for($teacher), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => "عدّل شخص آخر بيانات المحاضر\nأعد تحميل الصفحة قبل الحفظ",
                    ]);
                }
                if ($teacher->active && !$active) {
                    $this->assertCanDeactivate($teacher);
                }
                $teacher->update($payload);
                $teacher->forceFill(['active' => $active])->save();
                if ($imagePath) {
                    $oldPhotos = $teacher->allPhotos()->where('type', 'featured')->lockForUpdate()->get();
                    $teacher->allPhotos()->create(['path' => $imagePath, 'type' => 'featured']);
                    // Photo deletion reserves reference-aware cleanup in this transaction.
                    $oldPhotos->each->delete();
                }
            }, 3);
        } catch (\Throwable $exception) {
            if ($imagePath) $this->cleanup->deleteOrQueue('public', $imagePath);
            throw $exception;
        }
    }

    /** Returns false when courses must be reassigned before deleting this profile. */
    public function delete(int $teacherId): bool
    {
        return DB::transaction(function () use ($teacherId): bool {
            $teacher = User::query()->where('role', 'teacher')->whereKey($teacherId)->lockForUpdate()->firstOrFail();
            if ($teacher->teachingCourses()->exists()) return false;
            $teacher->delete();

            return true;
        }, 3);
    }

    public function toggleActive(int $teacherId, bool $expectedActive): User
    {
        return DB::transaction(function () use ($teacherId, $expectedActive): User {
            $teacher = User::query()->where('role', 'teacher')->whereKey($teacherId)->lockForUpdate()->firstOrFail();
            if ((bool) $teacher->active !== $expectedActive) {
                throw ValidationException::withMessages([
                    'expected_active' => "تغيّرت حالة المحاضر بالفعل\nأعد تحميل الصفحة",
                ]);
            }
            if ($teacher->active) $this->assertCanDeactivate($teacher);
            $teacher->forceFill(['active' => !$teacher->active])->save();

            return $teacher;
        }, 3);
    }

    private function stageImage(?UploadedFile $image, ?string $identity = null): ?string
    {
        if ($image === null) return null;
        $path = $this->uploads->storeTrackedUpload($image, 'users', 'public', 60, $identity);
        if ($path === '') throw new \RuntimeException('Teacher image storage failed');

        return $path;
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withHashedPassword(array $payload): array
    {
        if (isset($payload['password'])) {
            $payload['password'] = Hash::make((string) $payload['password']);
        }

        return $payload;
    }

    private function assertCanDeactivate(User $teacher): void
    {
        $publishedCourses = Course::query()
            ->where('is_coming_soon', false)
            ->where(function ($courses) use ($teacher): void {
                $courses->where('teacher_id', $teacher->id)
                    ->orWhereHas('teachers', fn ($teachers) => $teachers->whereKey($teacher->id));
            })
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
        foreach ($publishedCourses as $course) {
            $hasOtherActiveTeacher = $course->teachers()
                ->where('users.id', '<>', $teacher->id)
                ->where('users.active', true)
                ->exists();
            if (!$hasOtherActiveTeacher && (int) $course->teacher_id !== (int) $teacher->id) {
                $hasOtherActiveTeacher = User::query()
                    ->whereKey($course->teacher_id)
                    ->whereIn('role', ['teacher', 'admin'])
                    ->where('active', true)
                    ->exists();
            }
            if (!$hasOtherActiveTeacher) {
                throw ValidationException::withMessages([
                    'active' => "اربط الكورس «{$course->name_ar}» بمحاضر نشط آخر قبل تعطيل هذا المحاضر",
                ]);
            }
        }
    }
}
