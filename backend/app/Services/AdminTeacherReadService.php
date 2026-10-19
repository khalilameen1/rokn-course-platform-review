<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\User;
use App\Support\TeacherEditorVersion;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

/** Instructor read projections; credential visibility is decided by the caller. */
final class AdminTeacherReadService
{
    /** @param array<string, mixed> $queryParameters */
    public function listing(string $search, bool $includeCredentials, array $queryParameters): LengthAwarePaginator
    {
        $teachers = $this->listQuery($includeCredentials);
        if ($search !== '') {
            $teachers->where(function (Builder $query) use ($search, $includeCredentials): void {
                $query->where('name_ar', 'LIKE', "%{$search}%")
                    ->orWhere('name_en', 'LIKE', "%{$search}%");
                if ($includeCredentials) {
                    $query->orWhere('email', 'LIKE', "%{$search}%")
                        ->orWhere('phone', 'LIKE', "%{$search}%");
                }
            });
        }

        return $teachers->latest()->latest('id')->paginate(10)->appends($queryParameters);
    }

    /** @return Builder<User> */
    public function listQuery(bool $includeCredentials): Builder
    {
        return $this->profiles($includeCredentials)->withCount([
            'teachingCourses' => fn (Builder $courses) => $this->onlyCanonicalCourses($courses),
        ]);
    }

    /** @return array{teacher:User,courses:LengthAwarePaginator} */
    public function detail(int $id, bool $includeCredentials, bool $includeEnrollmentCounts): array
    {
        $teacher = $this->profiles($includeCredentials)->findOrFail($id);
        $courses = $this->profileCourses($teacher->teachingCourses()->getQuery(), $includeEnrollmentCounts);

        return ['teacher' => $teacher, 'courses' => $courses->orderByDesc('courses.id')->paginate(10)];
    }

    /** @param Builder<Course> $courses @return Builder<Course> */
    public function profileCourses(Builder $courses, bool $includeEnrollmentCounts): Builder
    {
        $this->onlyCanonicalCourses($courses);
        if ($includeEnrollmentCounts) {
            $courses->withCount('activeEnrollments');
        }

        return $courses;
    }

    /** @return array{teacher:User,editorVersion:string} */
    public function editor(int $id): array
    {
        $teacher = User::query()->where('role', 'teacher')->with('photo')->findOrFail($id);

        return ['teacher' => $teacher, 'editorVersion' => TeacherEditorVersion::for($teacher)];
    }

    public function existingTeacherId(int $id): int
    {
        return (int) User::query()->where('role', 'teacher')->findOrFail($id, ['id'])->id;
    }

    public function createIntentTeacherId(mixed $requestId): ?int
    {
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            return null;
        }
        $id = User::withTrashed()->where('authoring_request_id', $requestId)->value('id');

        return $id === null ? null : (int) $id;
    }

    /** @return Builder<User> */
    private function profiles(bool $includeCredentials): Builder
    {
        $columns = [
            'id', 'name_ar', 'name_en', 'job_title', 'bio_ar', 'bio_en',
            'profile_image', 'active', 'role', 'created_at',
        ];
        if ($includeCredentials) {
            array_push($columns, 'email', 'phone');
        }

        return User::query()->select($columns)->where('role', 'teacher')->with('photo');
    }

    /** @param Builder<Course> $courses @return Builder<Course> */
    private function onlyCanonicalCourses(Builder $courses): Builder
    {
        return $courses->whereNotIn('courses.id', CourseAuthoringRevision::query()->select('revision_course_id'));
    }
}
