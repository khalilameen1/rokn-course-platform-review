<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCourseOutlinePresenter;
use App\Services\CourseAuthoringDeletionService;
use App\Services\CourseSectionInput;
use App\Services\CourseSectionOrderingService;
use App\Services\CourseSectionContentService;
use App\Services\CourseSectionMediaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CourseSectionController extends Controller
{
    public function __construct(
        private readonly CourseAuthoringConcurrencyService $authoring,
        private readonly AdminAuthoringCreateIntentService $createIntents,
        private readonly CourseSectionInput $input,
        private readonly CourseSectionOrderingService $ordering,
        private readonly CourseSectionContentService $content,
        private readonly CourseSectionMediaService $media,
        private readonly CourseAuthoringDeletionService $deletion,
        private readonly AdminCourseOutlinePresenter $outline
    ) {
    }

    /**
     * Show the form for creating a new section
     */
    public function create(Course $course)
    {
        return $this->authoringRedirect($course);
    }

    /**
     * Resolve an uncertain section create without resending its multipart
     * body. File inputs remain in the browser while the server confirms
     * whether the original request committed.
     */
    public function createIntentReceipt(Request $request, Course $course, string $intent)
    {
        $this->assertDraftForStagedAuthoring($course);
        $receipt = $this->createIntents->resourceReceipt(
            $request,
            $intent,
            'admin.courses.sections.store',
            ['course' => $course],
            CourseSection::class
        );
        $course->refresh();

        if ($receipt['state'] !== 'completed') {
            return response()->json([
                'state' => $receipt['state'],
                'authoring_version' => (int) $course->authoring_version,
            ])->header('Cache-Control', 'no-store');
        }

        $original = data_get($receipt, 'payload.section');
        $receiptAuthoringVersion = (int) data_get($receipt, 'payload.authoring_version', 0);
        $section = $course->sections()->with('sectionable')->find($receipt['resource_id']);
        $sameCommittedResource = $section
            && is_array($original)
            && $receiptAuthoringVersion > 0
            && $receiptAuthoringVersion <= (int) $course->authoring_version
            && (int) ($original['id'] ?? 0) === (int) $section->id
            && (int) ($original['module_id'] ?? 0) === (int) $section->module_id
            && (string) ($original['type'] ?? '') === $section->getSectionType();

        if (!$sameCommittedResource) {
            return response()->json([
                'state' => 'superseded',
                'authoring_version' => (int) $course->authoring_version,
            ])->header('Cache-Control', 'no-store');
        }

        return response()->json([
            'state' => 'completed',
            'success' => true,
            'section' => $this->outline->section($course, $section),
            'receipt_authoring_version' => $receiptAuthoringVersion,
            'authoring_version' => (int) $course->authoring_version,
        ])->header('Cache-Control', 'no-store');
    }

    /**
     * Store a newly created section
     */
    public function store(Request $request, Course $course)
    {
        $this->assertDraftForStagedAuthoring($course);
        $this->input->validate($request, $course, null, true);
        $stage = null;
        $transactionStarted = false;

        try {
            $stage = $this->media->stage($request, $course, null, null);

            DB::beginTransaction();
            $transactionStarted = true;
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);

            $moduleId = $request->integer('module_id');
            $moduleMaxOrder = $lockedCourse->sections()
                ->where('module_id', $moduleId)
                ->max('order') ?? 0;
            $order = $request->filled('order')
                ? $request->integer('order')
                : (int) $moduleMaxOrder + 1;

            $this->media->attach($stage);

            $sectionable = $this->content->create(
                $request,
                $course,
                (int) $order,
                $stage->videoGuid,
                $stage->thumbnailPath
            );

            $sectionData = [
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'course_id' => $course->id,
                'order' => $order,
                'sectionable_type' => $this->content->modelClass((string) $request->section_type),
                'sectionable_id' => $sectionable->id,
                'module_id' => $moduleId,
                'section_type' => $request->section_type,
            ];
            $section = CourseSection::create($sectionData);
            $this->ordering->place($lockedCourse, $section, null, (int) $order);
            $authoringVersion = $this->authoring->advance($lockedCourse);
            $sectionPayload = $this->outline->section($lockedCourse, $section);

            // Publish the resource and the exact browser/API receipt in the
            // same transaction. A killed worker after commit can replay it
            // without allocating or uploading a second section.
            if ($request->expectsJson()) {
                $this->createIntents->completeJson(
                    $request,
                    [
                        'success' => true,
                        'message' => 'تم إضافة القسم بنجاح',
                        'section' => $sectionPayload,
                        'authoring_version' => $authoringVersion,
                    ],
                    200,
                    CourseSection::class,
                    $section->id
                );
            } else {
                $this->createIntents->completeRedirect(
                    $request,
                    route('admin.courses.show', $course),
                    302,
                    CourseSection::class,
                    $section->id
                );
            }

            DB::commit();
            $transactionStarted = false;
            $stage = null;
            if ($sectionable instanceof Lesson) {
                $this->media->probe($sectionable);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم إضافة القسم بنجاح',
                    'section' => $sectionPayload,
                    'authoring_version' => $authoringVersion,
                ]);
            }

            return $this->authoringRedirect($course)
                ->with('success', 'تم إضافة القسم بنجاح');
        } catch (Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }
            if ($stage) {
                $this->media->rollback($stage, 'section_create_rollback');
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'تعذر إضافة القسم الآن'
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'حدث خطأ أثناء إضافة القسم')
                ->withInput();
        }
    }

    /**
     * Show the form for editing a section
     */
    public function edit(Course $course, CourseSection $section)
    {
        $this->ensureSectionBelongsToCourse($course, $section);

        return $this->authoringRedirect($course);
    }

    /**
     * Update the specified section
     */
    public function update(Request $request, Course $course, CourseSection $section)
    {
        $this->assertDraftForStagedAuthoring($course);
        $this->ensureSectionBelongsToCourse($course, $section);
        $oldSectionType = $section->getSectionType();
        $oldSectionable = $section->sectionable;
        $oldLesson = $oldSectionType === 'lesson' && $oldSectionable instanceof Lesson
            ? $oldSectionable
            : null;
        $oldVideoGuid = trim((string) $oldLesson?->bunny_video_id) ?: null;
        $oldThumbnailPath = trim((string) $oldLesson?->thumbnail_path) ?: null;
        $this->input->validate($request, $course, $section, !$oldVideoGuid);
        $stage = null;
        $transactionStarted = false;

        try {
            $stage = $this->media->stage($request, $course, $section, $oldLesson);

            DB::beginTransaction();
            $transactionStarted = true;
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);
            $section = CourseSection::query()
                ->whereKey($section->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();

            $order = $request->input('order', $section->order);
            $sectionType = $request->section_type;
            $this->media->attach($stage);

            $sectionable = $this->content->update(
                $request,
                $course,
                $section,
                (int) $order,
                $stage->videoGuid,
                $stage->thumbnailPath,
                $oldVideoGuid,
                $oldThumbnailPath
            );

            // Update the section
            $previousModuleId = $section->module_id;
            $section->update([
                'title_ar' => $request->title_ar,
                'title_en' => $request->title_en,
                'order' => $order,
                'sectionable_type' => $this->content->modelClass($sectionType),
                'sectionable_id' => $sectionable->id,
                'module_id' => $request->module_id,
                'section_type' => $sectionType,
            ]);
            $this->ordering->place(
                $lockedCourse,
                $section,
                $previousModuleId,
                (int) $order
            );
            $this->media->retireReplaced($stage, $sectionType);
            $authoringVersion = $this->authoring->advance($lockedCourse);
            $sectionPayload = $this->outline->section($lockedCourse, $section);

            DB::commit();
            $transactionStarted = false;

            $shouldProbe = $sectionable instanceof Lesson
                && ($stage->videoChanged || $stage->thumbnailChanged);
            $stage = null;
            if ($shouldProbe) {
                $this->media->probe($sectionable);
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => 'تم تحديث القسم بنجاح',
                    'section' => $sectionPayload,
                    'authoring_version' => $authoringVersion,
                ]);
            }

            return $this->authoringRedirect($course)
                ->with('success', 'تم تحديث القسم بنجاح');
        } catch (Throwable $e) {
            if ($transactionStarted) {
                DB::rollBack();
            }
            if ($stage) {
                $this->media->rollback($stage, 'section_update_rollback');
            }
            if ($e instanceof ValidationException) {
                throw $e;
            }
            report($e);
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'تعذر تحديث القسم الآن'
                ], 500);
            }

            return redirect()->back()
                ->with('error', 'تعذر تحديث القسم الآن')
                ->withInput();
        }
    }

    /**
     * Remove the specified section
     */
    public function destroy(Request $request, Course $course, CourseSection $section)
    {
        $this->ensureSectionBelongsToCourse($course, $section);
        $this->assertDraftForStagedAuthoring($course);
        $request->validate(['authoring_version' => 'required|integer|min:1']);
        $result = DB::transaction(function () use (
            $request,
            $course,
            $section
        ): array {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);
            $lockedSection = CourseSection::query()
                ->whereKey($section->id)
                ->where('course_id', $course->id)
                ->lockForUpdate()
                ->firstOrFail();
            $moduleId = (int) $lockedSection->module_id;
            $this->deletion->deleteSection($lockedSection);
            $this->ordering->normalizeModule($lockedCourse, $moduleId);
            $authoringVersion = $this->authoring->advance($lockedCourse);

            return [
                'success' => true,
                'message' => 'تم حذف المحتوى',
                'deleted_section_id' => (int) $section->id,
                'authoring_version' => $authoringVersion,
            ];
        });

        if ($request->expectsJson()) return response()->json($result);

        return redirect()->route('admin.courses.show', $course)
            ->with('success', 'تم حذف القسم بنجاح');
    }

    /**
     * Reorder sections
     */
    public function reorder(Request $request, Course $course)
    {
        $this->assertDraftForStagedAuthoring($course);
        $request->validate([
            'sections' => 'required|array',
            'sections.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('course_sections', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)->whereNull('deleted_at')
                ),
            ],
            'sections.*.order' => 'required|integer|min:0',
            'sections.*.module_id' => [
                'nullable',
                'integer',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
            'authoring_version' => 'required|integer|min:1',
        ], [
            'module_id.required' => 'اختر الوحدة التي سيظهر فيها المحتوى',
            'module_id.exists' => 'الوحدة المختارة لم تعد متاحة',
        ]);

        $result = DB::transaction(function () use ($request, $course): array {
            $lockedCourse = $this->authoring->lock($request, $course);
            $this->assertDraftForStagedAuthoring($lockedCourse);
            $this->ordering->apply($lockedCourse, (array) $request->input('sections'));
            $authoringVersion = $this->authoring->advance($lockedCourse);

            return [
                'success' => true,
                'authoring_version' => $authoringVersion,
                'modules' => $this->outline->graph($lockedCourse->fresh())['modules'],
            ];
        });

        return response()->json($result);
    }

    private function assertDraftForStagedAuthoring(Course $course): void
    {
        if (!$course->is_coming_soon) {
            throw ValidationException::withMessages([
                'course' => [
                    'حوّل الكورس إلى مسودة قبل تغيير بنية المحتوى أو الفيديو ثم أعد نشره بعد الفحص',
                ],
            ]);
        }
    }

    private function ensureSectionBelongsToCourse(Course $course, CourseSection $section): void
    {
        abort_unless((int) $section->course_id === (int) $course->id, 404);
    }

    private function authoringRedirect(Course $course)
    {
        return redirect()->route('admin.courses.show', $course);
    }

}
