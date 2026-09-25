<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCourseOutlinePresenter;
use App\Services\AdminCourseSectionApplicationService;
use App\Http\Requests\Admin\CourseSectionInput;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

class CourseSectionController extends Controller
{
    public function __construct(
        private readonly AdminCourseSectionApplicationService $sections,
        private readonly AdminAuthoringCreateIntentService $createIntents,
        private readonly CourseSectionInput $input,
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
        $this->sections->assertDraft($course);
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
        $this->sections->assertDraft($course);
        $edit = $this->input->validate($request, $course, null, true);
        try {
            $payload = $this->sections->store(
                $course, $edit, $request->user(),
                function (Course $lockedCourse, CourseSection $section, array $payload) use ($request): void {
                    if ($request->expectsJson()) {
                        $this->createIntents->completeJson($request, $payload, 200, CourseSection::class, $section->id);
                    } else {
                        $this->createIntents->completeRedirect(
                            $request, route('admin.courses.show', $lockedCourse), 302, CourseSection::class, $section->id
                        );
                    }
                }
            );
            if ($request->expectsJson()) return response()->json($payload);

            return $this->authoringRedirect($course)->with('success', 'تم إضافة القسم بنجاح');
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) throw $e;
            report($e);
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'تعذر إضافة القسم الآن'], 500);
            }

            return redirect()->back()->with('error', 'حدث خطأ أثناء إضافة القسم')->withInput();
        }
    }

    /**
     * Show the form for editing a section
     */
    public function edit(Course $course, CourseSection $section)
    {
        $this->sections->assertBelongsToCourse($course, $section);

        return $this->authoringRedirect($course);
    }

    /**
     * Update the specified section
     */
    public function update(Request $request, Course $course, CourseSection $section)
    {
        $this->sections->assertDraft($course);
        $this->sections->assertBelongsToCourse($course, $section);
        $content = $section->sectionable;
        $hasVideo = $section->getSectionType() === 'lesson' && $content instanceof Lesson
            && trim((string) $content->bunny_video_id) !== '';
        $edit = $this->input->validate($request, $course, $section, !$hasVideo);
        try {
            $payload = $this->sections->update($course, $section, $edit, $request->user());
            if ($request->expectsJson()) return response()->json($payload);

            return $this->authoringRedirect($course)->with('success', 'تم تحديث القسم بنجاح');
        } catch (Throwable $e) {
            if ($e instanceof ValidationException) throw $e;
            report($e);
            if ($request->expectsJson()) {
                return response()->json(['success' => false, 'message' => 'تعذر تحديث القسم الآن'], 500);
            }

            return redirect()->back()->with('error', 'تعذر تحديث القسم الآن')->withInput();
        }
    }

    /**
     * Remove the specified section
     */
    public function destroy(Request $request, Course $course, CourseSection $section)
    {
        $this->sections->assertBelongsToCourse($course, $section);
        $this->sections->assertDraft($course);
        $validated = $request->validate(['authoring_version' => 'required|integer|min:1']);
        $result = $this->sections->delete($course, $section, (int) $validated['authoring_version']);

        if ($request->expectsJson()) return response()->json($result);

        return redirect()->route('admin.courses.show', $course)
            ->with('success', 'تم حذف القسم بنجاح');
    }

    /**
     * Reorder sections
     */
    public function reorder(Request $request, Course $course)
    {
        $this->sections->assertDraft($course);
        $validated = $request->validate([
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

        $result = $this->sections->reorder($course, $validated['sections'], (int) $validated['authoring_version']);

        return response()->json($result);
    }

    private function authoringRedirect(Course $course)
    {
        return redirect()->route('admin.courses.show', $course);
    }

}
