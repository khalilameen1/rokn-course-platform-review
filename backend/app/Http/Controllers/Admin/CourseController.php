<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseRequest;
use App\Models\Course;
use App\Models\User;
use App\Services\AdminCourseAuthoringService;
use App\Services\AdminCourseEditorStatePresenter;
use App\Services\AdminCourseLifecycleService;
use App\Services\AdminCoursePageService;
use App\Services\AdminCoursePreviewService;
use App\Services\AdminCourseReportService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class CourseController extends Controller
{
    public function index(Request $request, AdminCoursePageService $pages)
    {
        $filters = $request->validate([
            'search' => 'nullable|string|max:120',
            'classification_id' => 'nullable|integer|exists:classifications,id',
            'state' => 'nullable|string|in:active,archived,all',
        ]);

        return view(
            'admin.courses.index',
            $pages->index($filters, $this->isAdministrator())
        );
    }

    public function create()
    {
        return view('admin.courses.create');
    }

    public function store(CourseRequest $request, AdminCourseAuthoringService $authoring)
    {
        $result = $authoring->create($request, $this->isAdministrator());
        if ($result['status'] === 'failed') {
            return redirect()->back()
                ->withInput()
                ->with('error', 'تعذر حفظ الكورس الآن');
        }

        $course = $result['course'];

        return redirect()->route('admin.courses.show', $course)->with(
            'success',
            $result['status'] === 'existing'
                ? 'تم حفظ الكورس بالفعل'
                : 'تم حفظ الكورس كمسودة. أضف الوحدات والمقاطع، وأضف مشاريع العبور فقط حيث يحتاج المحتوى إليها.'
        );
    }

    public function show(
        Request $request,
        Course $course,
        AdminCoursePageService $pages
    ) {
        $summaryOnly = $request->boolean('summary') && $request->expectsJson();
        $data = $pages->show(
            $course,
            $this->isAdministrator(),
            max(1, $request->integer('commercial_page', 1)),
            !$summaryOnly && ($request->query('tab') === 'commercial-report'
                || $request->has('commercial_page'))
        );

        if ($summaryOnly) {
            return response()->json([
                'course_id' => (int) $data['course']->id,
                'authoring_version' => (int) $data['course']->authoring_version,
                'html' => view('admin.courses.partials.show.course-overview', $data)->render()
                    .view('admin.courses.partials.show.course-readiness', $data)->render(),
            ])->header('Cache-Control', 'private, no-store');
        }

        return view('admin.courses.show', $data);
    }

    public function studentPreview(
        Request $request,
        Course $course,
        AdminCoursePreviewService $preview
    ) {
        $validated = $request->validate(['plan' => 'nullable|string|max:32']);
        /** @var User $actor */
        $actor = $request->user();
        $data = $preview->prepare($course, $actor, $validated['plan'] ?? null, $request);
        abort_if($data['error'] !== null, 422, $data['error']);
        unset($data['error']);

        return response()
            ->view('admin.courses.student-preview', $data)
            ->header('Cache-Control', 'private, no-store, max-age=0');
    }

    public function startDraft(
        Request $request,
        Course $course,
        CourseStagedAuthoringService $stagedAuthoring
    ): Response {
        $canonical = $stagedAuthoring->canonicalFor($course);
        $draft = $stagedAuthoring->draftFor($canonical);
        $payload = [
            'success' => true,
            'canonical_course_id' => (int) $canonical->id,
            'draft_course_id' => (int) $draft->id,
            'authoring_version' => (int) $draft->authoring_version,
        ];

        if ($request->expectsJson()) {
            return response()->json($payload);
        }

        return redirect()
            ->route('admin.courses.show', $draft)
            ->with('success', 'تم فتح مسودة التعديل');
    }

    public function exportCommercialReport(
        Course $course,
        AdminCourseReportService $reports
    ) {
        abort_unless($this->isAdministrator(), 403);
        $export = $reports->csv($course);

        return response()->streamDownload(function () use ($export): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, $export['headings'], ',', '"', '');
            foreach ($export['rows'] as $row) {
                fputcsv($output, $row, ',', '"', '');
            }
            fclose($output);
        }, "course-{$export['course']->id}-commercial-report.csv", [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function edit(Course $course)
    {
        return redirect()->route('admin.courses.show', $course);
    }

    public function update(
        CourseRequest $request,
        Course $course,
        AdminCourseAuthoringService $authoring,
        AdminCourseEditorStatePresenter $editorState
    ) {
        $result = $authoring->update($request, $course, $this->isAdministrator());
        $course = $result['course'];

        if ($request->expectsJson()) {
            $freshCourse = $course->fresh() ?: $course;
            $presented = $editorState->result([
                ...$result,
                'course' => $freshCourse,
            ]);

            return response()->json($presented['payload'], $presented['http_status']);
        }

        if ($result['status'] === 'live_incomplete') {
            return redirect()->back()
                ->withInput()
                ->with('error', 'لم نحفظ التعديل لأن الكورس المنشور يجب أن يظل مكتملًا')
                ->with('publishing_issues', $result['issues']);
        }
        if ($result['status'] === 'save_failed') {
            return redirect()->back()
                ->withInput()
                ->with('error', 'تعذر حفظ تعديلات الكورس الآن');
        }
        if ($result['status'] === 'staged_publish_failed') {
            return redirect()->route('admin.courses.show', $course)
                ->with('success', 'تم حفظ المسودة')
                ->with('error', 'لم يكتمل النشر\nالنسخة الحالية ما زالت متاحة للطلاب');
        }
        if ($result['status'] === 'publish_failed') {
            return redirect()->route('admin.courses.show', $course)
                ->with('success', 'تم حفظ تعديلات الكورس')
                ->with('error', 'لم يكتمل النشر\nأعد تحميل الصفحة وراجعه قبل المحاولة');
        }
        if ($result['status'] === 'not_ready') {
            return redirect()->route('admin.courses.show', $course)
                ->with('error', 'تم حفظ التعديلات، لكن الكورس ما زال مسودة حتى تكتمل عناصر النشر.')
                ->with('publishing_issues', $result['issues']);
        }
        if ($result['status'] === 'catalog_publish_failed') {
            return redirect()->route('admin.courses.show', $course)
                ->with('success', 'تم حفظ تعديلات الكورس')
                ->with('error', 'لم يكتمل إظهار بطاقة الكورس\nأعد تحميل الصفحة ثم حاول مرة أخرى');
        }
        if ($result['status'] === 'catalog_not_ready') {
            return redirect()->route('admin.courses.show', $course)
                ->with('error', 'تم حفظ التعديلات، لكن بطاقة قريبًا ما زالت مخفية حتى تكتمل بياناتها.')
                ->with('publishing_issues', $result['issues']);
        }
        if ($result['status'] === 'hero_failed') {
            return redirect()->route('admin.courses.show', $course)
                ->with('success', 'تم حفظ تعديلات الكورس')
                ->with('error', 'لم يتغير اختيار الواجهة الرئيسية\nأعد تحميل الصفحة ثم حاول مرة أخرى');
        }

        return redirect()->route('admin.courses.show', $course)
            ->with('success', 'تم تحديث الكورس بنجاح.');
    }

    public function destroy(
        Request $request,
        Course $course,
        AdminCourseLifecycleService $lifecycle
    ) {
        abort_unless($this->isAdministrator(), 403);
        $validated = $request->validate([
            'authoring_version' => 'required|integer|min:1',
        ]);
        $result = $lifecycle->archive($course, (int) $validated['authoring_version']);

        if ($result['unlisted']) {
            return redirect()->route('admin.courses.index')->with(
                'success',
                $result['discardedDraft']
                    ? 'أُخفي الكورس من الكتالوج مع استمرار وصول الطلاب وأُغلقت المسودة القديمة'
                    : 'أُخفي الكورس من الكتالوج مع استمرار وصول الطلاب الحاليين'
            );
        }

        return redirect()->route('admin.courses.index')->with(
            'success',
            'نُقلت المسودة غير المنشورة إلى الأرشيف'
        );
    }

    public function restore(
        int $courseId,
        AdminCourseLifecycleService $lifecycle
    ) {
        abort_unless($this->isAdministrator(), 403);
        $result = $lifecycle->restore($courseId);

        return redirect()->route('admin.courses.show', $result['course'])->with(
            'success',
            $result['preserved_learner_access']
                ? 'استُعيد الكورس مخفيًا من الكتالوج وعاد وصول الطلاب الحاليين'
                : 'استُعيد الكورس كمسودة مخفية للمراجعة قبل النشر'
        );
    }

    private function isAdministrator(): bool
    {
        return strtolower(trim((string) optional(auth()->user())->role)) === 'admin';
    }
}
