<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseModule;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminCourseModuleApplicationService
{
    public function __construct(
        private readonly CourseAuthoringConcurrencyService $authoring,
        private readonly CourseAuthoringDeletionService $deletion,
        private readonly CourseModuleOrderingService $ordering,
        private readonly AdminCourseOutlinePresenter $outline
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param Closure(Course, CourseModule, array<string, mixed>): void $completeIntent
     * @return array<string, mixed>
     */
    public function store(
        Course $course,
        array $data,
        int $expectedVersion,
        Closure $completeIntent
    ): array {
        $this->assertDraft($course);

        return DB::transaction(function () use (
            $course,
            $data,
            $expectedVersion,
            $completeIntent
        ): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $requestedOrder = array_key_exists('order', $data) && $data['order'] !== null
                ? (int) $data['order']
                : $lockedCourse->modules()->count() + 1;
            $module = CourseModule::query()->create([
                'course_id' => $lockedCourse->id,
                'title_ar' => $data['title_ar'],
                'title_en' => $data['title_en'] ?? null,
                'order' => $requestedOrder,
            ]);
            $this->ordering->place($lockedCourse, $module, $requestedOrder);
            $version = $this->authoring->advance($lockedCourse);
            $payload = [
                'success' => true,
                'message' => 'تمت إضافة الوحدة',
                'module' => $this->outline->module($lockedCourse, $module->fresh()),
                'authoring_version' => $version,
            ];
            $completeIntent($lockedCourse, $module, $payload);

            return $payload;
        }, 3);
    }

    /** @param array<string, mixed> $data @return array<string, mixed> */
    public function update(
        Course $course,
        CourseModule $module,
        array $data,
        int $expectedVersion
    ): array {
        $this->assertBelongsToCourse($course, $module);
        $this->assertDraft($course);

        return DB::transaction(function () use (
            $course,
            $module,
            $data,
            $expectedVersion
        ): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $lockedModule = CourseModule::query()
                ->whereKey($module->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();
            $requestedOrder = array_key_exists('order', $data) && $data['order'] !== null
                ? (int) $data['order']
                : (int) $lockedModule->order;
            $lockedModule->update([
                'title_ar' => $data['title_ar'],
                'title_en' => $data['title_en'] ?? null,
                'order' => $requestedOrder,
            ]);
            $this->ordering->place($lockedCourse, $lockedModule, $requestedOrder);
            $version = $this->authoring->advance($lockedCourse);

            return [
                'success' => true,
                'message' => 'تم تحديث الوحدة',
                'module' => $this->outline->module($lockedCourse, $lockedModule->fresh()),
                'authoring_version' => $version,
            ];
        }, 3);
    }

    /** @return array<string, mixed> */
    public function destroy(
        Course $course,
        CourseModule $module,
        int $expectedVersion
    ): array {
        $this->assertBelongsToCourse($course, $module);
        $this->assertDraft($course);

        return DB::transaction(function () use ($course, $module, $expectedVersion): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $lockedModule = CourseModule::query()
                ->whereKey($module->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();
            $deletedSectionIds = $this->deletion->deleteModule($lockedModule);
            $this->ordering->normalize($lockedCourse);
            $version = $this->authoring->advance($lockedCourse);

            return [
                'success' => true,
                'message' => 'تم حذف الوحدة',
                'deleted_module_id' => (int) $module->id,
                'section_ids' => $deletedSectionIds,
                'authoring_version' => $version,
            ];
        }, 3);
    }

    /** @param list<array{id:int, order:int}> $modules @return array<string, mixed> */
    public function reorder(Course $course, array $modules, int $expectedVersion): array
    {
        $this->assertDraft($course);

        return DB::transaction(function () use ($course, $modules, $expectedVersion): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $lockedIds = CourseModule::query()
                ->where('course_id', $course->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values();
            $submittedIds = collect($modules)
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->sort()
                ->values();
            if ($lockedIds->all() !== $submittedIds->all()) {
                throw ValidationException::withMessages([
                    'modules' => 'تغيّرت قائمة الوحدات منذ بدء السحب\nحدّث الصفحة ثم أعد الترتيب',
                ])->status(409);
            }

            foreach ($modules as $module) {
                CourseModule::query()
                    ->whereKey($module['id'])
                    ->where('course_id', $course->id)
                    ->update(['order' => $module['order']]);
            }
            $this->ordering->normalize($lockedCourse);
            $version = $this->authoring->advance($lockedCourse);

            return [
                'success' => true,
                'authoring_version' => $version,
                'modules' => $this->outline->graph($lockedCourse->fresh())['modules'],
            ];
        }, 3);
    }

    private function assertBelongsToCourse(Course $course, CourseModule $module): void
    {
        abort_unless((int) $module->course_id === (int) $course->id, 404);
    }

    private function assertDraft(Course $course): void
    {
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => [
                    'حوّل الكورس إلى مسودة قبل إضافة وحدة ثم أعد نشره بعد اكتمال محتواها',
                ],
            ]);
        }
    }
}
