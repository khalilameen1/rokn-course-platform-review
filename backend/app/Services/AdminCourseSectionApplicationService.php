<?php

declare(strict_types=1);

namespace App\Services;

use App\Data\CourseSectionEdit;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/** One transaction owns section content, placement, media leases, version and create receipt. */
final readonly class AdminCourseSectionApplicationService
{
    public function __construct(
        private CourseAuthoringConcurrencyService $authoring,
        private CourseSectionOrderingService $ordering,
        private CourseSectionContentService $content,
        private CourseSectionMediaService $media,
        private CourseAuthoringDeletionService $deletion,
        private AdminCourseOutlinePresenter $outline
    ) {
    }

    /** @param Closure(Course,CourseSection,array<string,mixed>):void $completeIntent @return array<string,mixed> */
    public function store(Course $course, CourseSectionEdit $edit, ?User $actor, Closure $completeIntent): array
    {
        $this->assertDraft($course);
        $stage = null;
        try {
            $stage = $this->media->stage($edit, $actor, $course, null, null);
            [$payload, $lesson] = DB::transaction(function () use ($course, $edit, $stage, $completeIntent): array {
                $lockedCourse = $this->authoring->lockExpected($course, $edit->expectedVersion);
                $this->assertDraft($lockedCourse);
                $maxOrder = $lockedCourse->sections()->where('module_id', $edit->moduleId)->max('order') ?? 0;
                $order = $edit->order ?? (int) $maxOrder + 1;
                $this->media->attach($stage);
                $sectionable = $this->content->create($edit, $lockedCourse, $stage);
                $section = CourseSection::query()->create([
                    'title_ar' => $edit->titleAr, 'title_en' => $edit->titleEn,
                    'course_id' => $lockedCourse->id, 'order' => $order,
                    'sectionable_type' => $this->content->modelClass($edit->type),
                    'sectionable_id' => $sectionable->id, 'module_id' => $edit->moduleId,
                    'section_type' => $edit->type,
                ]);
                $this->ordering->place($lockedCourse, $section, null, (int) $order);
                $version = $this->authoring->advance($lockedCourse);
                $payload = [
                    'success' => true, 'message' => 'تم إضافة القسم بنجاح',
                    'section' => $this->outline->section($lockedCourse, $section),
                    'authoring_version' => $version,
                ];
                $completeIntent($lockedCourse, $section, $payload);

                return [$payload, $sectionable instanceof Lesson ? $sectionable : null];
            });
            // From here on the durable owner/receipt committed. Never retire its
            // media as failed staging if a post-commit diagnostic is unavailable.
            $stage = null;
            if ($lesson !== null) $this->media->probe($lesson);

            return $payload;
        } catch (Throwable $error) {
            if ($stage !== null) $this->media->rollback($stage, 'section_create_rollback');
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function update(Course $course, CourseSection $section, CourseSectionEdit $edit, ?User $actor): array
    {
        $this->assertDraft($course);
        $this->assertBelongsToCourse($course, $section);
        $previous = $section->sectionable;
        $oldLesson = $section->getSectionType() === 'lesson' && $previous instanceof Lesson ? $previous : null;
        $stage = null;
        try {
            $stage = $this->media->stage($edit, $actor, $course, $section, $oldLesson);
            [$payload, $lesson] = DB::transaction(function () use ($course, $section, $edit, $stage): array {
                $lockedCourse = $this->authoring->lockExpected($course, $edit->expectedVersion);
                $this->assertDraft($lockedCourse);
                $lockedSection = $this->lockedSection($lockedCourse, (int) $section->id);
                $order = $edit->order ?? (int) $lockedSection->order;
                $this->media->attach($stage);
                $sectionable = $this->content->update($edit, $lockedCourse, $lockedSection, $stage);
                $previousModuleId = $lockedSection->module_id;
                $lockedSection->update([
                    'title_ar' => $edit->titleAr, 'title_en' => $edit->titleEn, 'order' => $order,
                    'sectionable_type' => $this->content->modelClass($edit->type),
                    'sectionable_id' => $sectionable->id, 'module_id' => $edit->moduleId,
                    'section_type' => $edit->type,
                ]);
                // A type change replaces the morph target. Do not render the
                // previously loaded/deleted content from Eloquent's relation cache.
                $lockedSection->unsetRelation('sectionable');
                $this->ordering->place($lockedCourse, $lockedSection, $previousModuleId, (int) $order);
                $this->media->retireReplaced($stage, $edit->type);
                $version = $this->authoring->advance($lockedCourse);
                $payload = [
                    'success' => true, 'message' => 'تم تحديث القسم بنجاح',
                    'section' => $this->outline->section($lockedCourse, $lockedSection),
                    'authoring_version' => $version,
                ];
                $probe = $sectionable instanceof Lesson && ($stage->videoChanged || $stage->thumbnailChanged);

                return [$payload, $probe ? $sectionable : null];
            });
            $stage = null;
            if ($lesson !== null) $this->media->probe($lesson);

            return $payload;
        } catch (Throwable $error) {
            if ($stage !== null) $this->media->rollback($stage, 'section_update_rollback');
            throw $error;
        }
    }

    /** @return array<string,mixed> */
    public function delete(Course $course, CourseSection $section, int $expectedVersion): array
    {
        $this->assertBelongsToCourse($course, $section);
        $this->assertDraft($course);

        return DB::transaction(function () use ($course, $section, $expectedVersion): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $lockedSection = $this->lockedSection($lockedCourse, (int) $section->id);
            $moduleId = (int) $lockedSection->module_id;
            $this->deletion->deleteSection($lockedSection);
            $this->ordering->normalizeModule($lockedCourse, $moduleId);
            $version = $this->authoring->advance($lockedCourse);

            return [
                'success' => true, 'message' => 'تم حذف المحتوى',
                'deleted_section_id' => (int) $section->id, 'authoring_version' => $version,
            ];
        });
    }

    /** @param array<int,array{id:int,order:int,module_id?:?int}> $sections @return array<string,mixed> */
    public function reorder(Course $course, array $sections, int $expectedVersion): array
    {
        $this->assertDraft($course);

        return DB::transaction(function () use ($course, $sections, $expectedVersion): array {
            $lockedCourse = $this->authoring->lockExpected($course, $expectedVersion);
            $this->assertDraft($lockedCourse);
            $this->ordering->apply($lockedCourse, $sections);
            $version = $this->authoring->advance($lockedCourse);

            return [
                'success' => true, 'authoring_version' => $version,
                'modules' => $this->outline->graph($lockedCourse->fresh())['modules'],
            ];
        });
    }

    public function assertDraft(Course $course): void
    {
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => ['حوّل الكورس إلى مسودة قبل تغيير بنية المحتوى أو الفيديو ثم أعد نشره بعد الفحص'],
            ]);
        }
    }

    public function assertBelongsToCourse(Course $course, CourseSection $section): void
    {
        if ((int) $section->course_id !== (int) $course->id) {
            throw (new \Illuminate\Database\Eloquent\ModelNotFoundException())->setModel(CourseSection::class, [$section->id]);
        }
    }

    private function lockedSection(Course $course, int $id): CourseSection
    {
        return CourseSection::query()->whereKey($id)->where('course_id', $course->id)->lockForUpdate()->firstOrFail();
    }
}
