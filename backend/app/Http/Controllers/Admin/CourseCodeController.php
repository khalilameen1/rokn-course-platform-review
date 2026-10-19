<?php

namespace App\Http\Controllers\Admin;

use App\Filament\Resources\CourseCodeResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseCodeBulkRequest;
use App\Http\Requests\Admin\CourseCodeIndexRequest;
use App\Http\Requests\Admin\CourseCodeRequest;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Models\CourseCode;
use App\Models\DesignSetting;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCourseCodeAuthoringService;
use App\Services\AdminCourseCodeReadService;
use App\Support\BusinessClock;
use App\Support\CourseCodeEditorVersion;
use App\Support\CourseCodeFormData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CourseCodeController extends Controller
{
    public function __construct(
        private readonly AdminCourseCodeAuthoringService $authoring,
        private readonly AdminCourseCodeReadService $reader
    ) {}

    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of the resource.
     */
    public function index(CourseCodeIndexRequest $request): RedirectResponse
    {
        return redirect()->to(CourseCodeResource::listUrl($request->validated()));
    }

    /**
     * Redirect saved create bookmarks to the native editor.
     */
    public function create(): RedirectResponse
    {
        return redirect()->to(CourseCodeResource::getUrl('create'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(CourseCodeRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        try {
            $numberOfCodes = max(1, (int) $request->input('number_of_codes', 1));

            $payload = CourseCodeFormData::payload($request->validated());
            $this->authoring->createBatch($payload, $numberOfCodes, function (CourseCode $first) use ($request, $createIntents): void {
                $createIntents->completeRedirect(
                    $request,
                    route('admin.course-codes.index'),
                    302,
                    CourseCode::class,
                    $first->id
                );
            });

            $message = $numberOfCodes > 1
                ? "تم إنشاء {$numberOfCodes} أكواد بنجاح"
                : 'تم إنشاء الكود بنجاح';

            return redirect()->route('admin.course-codes.index')
                ->with('success', $message);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\DomainException $e) {
            return back()->withInput()->with(
                'error',
                "تعذّر إنشاء الدفعة بهذه الإعدادات\nراجع بيانات الإتاحة ثم أعد المحاولة"
            );
        } catch (\Exception $e) {
            report($e);

            return back()->withInput()->with('error', 'تعذر إنشاء الأكواد الآن');
        }
    }

    /**
     * Display the specified resource.
     *
     * @return Response
     */
    public function show(CourseCode $courseCode)
    {
        $courseCode->load(['course', 'lesson']);
        $usageHistory = $courseCode->usages()
            ->with('user:id,name,email')
            ->orderByDesc('used_at')
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();
        $designSettings = $this->getDesignSettings();
        $editorVersion = CourseCodeEditorVersion::for($courseCode);

        return view('admin.course-codes.show', compact(
            'courseCode', 'usageHistory', 'designSettings', 'editorVersion'
        ));
    }

    /**
     * Redirect saved edit bookmarks to the native editor.
     */
    public function edit(CourseCode $courseCode): RedirectResponse
    {
        return redirect()->to(CourseCodeResource::getUrl('edit', ['record' => $courseCode->id]));
    }

    /**
     * Update the specified resource in storage.
     *
     * @return Response
     */
    public function update(CourseCodeRequest $request, CourseCode $courseCode)
    {
        try {
            $data = CourseCodeFormData::payload($request->validated());
            $editorVersion = (string) $request->validated('editor_version');

            $this->authoring->update((int) $courseCode->id, $data, $editorVersion);

            return redirect()->route('admin.course-codes.index')
                ->with('success', 'تم تحديث الكود بنجاح');

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return back()->withInput()->with('error', 'تعذر تحديث الكود الآن');
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @return Response
     */
    public function destroy(EditorVersionRequest $request, CourseCode $courseCode)
    {
        $validated = $request->validated();
        $deactivated = $this->authoring->delete(
            (int) $courseCode->id,
            (string) $validated['editor_version']
        );

        if ($deactivated) {
            return redirect()->route('admin.course-codes.index')
                ->with('success', 'تم إيقاف الكود مع الاحتفاظ بسجل استخدامه');
        }

        return redirect()->route('admin.course-codes.index')
            ->with('success', 'تم حذف الكود بنجاح');
    }

    /**
     * Bulk actions for course codes
     *
     * @return Response
     */
    public function bulkAction(CourseCodeBulkRequest $request)
    {
        try {
            $action = $request->input('action');
            $selectedCodes = $request->input('selected_codes');

            $versions = (array) $request->input('editor_versions', []);
            $result = $this->authoring->bulk($action, $selectedCodes, $versions);
            $message = match ($action) {
                'delete' => "حُذف {$result['deleted']} كود وأُوقف {$result['deactivated']} كود مستخدم مع الاحتفاظ بسجله",
                'activate' => "تم تفعيل {$result['changed']} كود صالح",
                'deactivate' => 'تم إلغاء تفعيل الأكواد المحددة بنجاح',
            };

            return redirect()->route('admin.course-codes.index')
                ->with('success', $message);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return back()->with('error', 'تعذر تنفيذ العملية الآن');
        }
    }

    /**
     * Get lessons for a specific course via AJAX
     *
     * @return JsonResponse
     */
    public function getLessons(Request $request)
    {
        $courseId = $request->input('course_id');

        if (! $courseId) {
            return response()->json([]);
        }

        try {
            $lessons = $this->reader->lessonOptions((int) $courseId);

            return response()->json($lessons);
        } catch (\Exception $e) {
            report($e);

            return response()->json(['error' => 'حدث خطأ أثناء تحميل الدروس'], 500);
        }
    }

    /**
     * Export codes to CSV
     *
     * @return StreamedResponse
     */
    public function export(CourseCodeIndexRequest $request)
    {
        $filters = $request->validated();
        $filename = 'course_codes_'.BusinessClock::now()->format('Y-m-d_H-i-s').'.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ];
        $callback = function () use ($filters): void {
            $file = fopen('php://output', 'w');
            try {
                fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
                foreach ($this->reader->csvRows($filters) as $row) {
                    fputcsv($file, $row);
                }
            } finally {
                fclose($file);
            }
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export course codes to PDF for printing
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportToPdf(CourseCodeIndexRequest $request)
    {
        try {
            set_time_limit(300);

            $courseCodes = $this->reader->pdfRows($request->validated());

            if ($courseCodes->isEmpty()) {
                return back()->with('error', 'لا توجد أكواد للتصدير');
            }
            if ($courseCodes->count() > AdminCourseCodeReadService::PDF_LIMIT) {
                return back()->with(
                    'error',
                    "نتيجة PDF أكبر من 500 كود\nضيّق البحث أو استخدم تصدير CSV للسجل الكامل"
                );
            }

            $designSettings = $this->getDesignSettings();

            $data = [
                'course_codes' => $courseCodes,
                'platform_name' => $designSettings->name_ar ?? 'منصة تعليمية',
                'export_date' => BusinessClock::now()->format('Y-m-d H:i:s'),
                'total_codes' => $courseCodes->count(),
            ];

            // Generate PDF using LaravelPdf package (mPDF wrapper)
            $pdf = \PDF::loadView('admin.course-codes.pdf', $data);

            // Set PDF metadata dynamically from settings
            $pdf->setAuthor($designSettings->name_ar ?? 'منصة تعليمية');
            $pdf->setCreator($designSettings->name_ar ?? 'منصة تعليمية');

            // Generate filename
            $filename = 'Course_Codes_'.BusinessClock::now()->format('Y-m-d_H-i-s').'.pdf';

            // Force download the PDF
            return $pdf->download($filename);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);

            return back()->with('error', 'تعذر تصدير الأكواد الآن');
        }
    }
}
