<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseCodeRequest;
use App\Models\CourseCode;
use App\Models\DesignSetting;
use App\Services\AdminCourseCodeReadService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use App\Services\AdminCourseCodeAuthoringService;
use App\Support\BusinessClock;
use App\Support\CourseCodeEditorVersion;
use Illuminate\Validation\ValidationException;

class CourseCodeController extends Controller
{
    public function __construct(
        private readonly AdminCourseCodeAuthoringService $authoring,
        private readonly AdminCourseCodeReadService $reader
    )
    {
    }

    /**
     * Get design settings for the views
     */
    private function getDesignSettings()
    {
        return DesignSetting::getDefaultSettings();
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = $this->reader->query($this->filters($request));

        $courseCodes = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString();
        $courses = $this->reader->courseOptions();
        $designSettings = $this->getDesignSettings();
        $editorVersions = $courseCodes->getCollection()->mapWithKeys(
            fn (CourseCode $code): array => [$code->id => CourseCodeEditorVersion::for($code)]
        );

        return view('admin.course-codes.index', compact(
            'courseCodes', 'courses', 'designSettings', 'editorVersions'
        ));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $courses = $this->reader->courseOptions();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-codes.create', compact('courses', 'designSettings'));
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \App\Http\Requests\Admin\CourseCodeRequest  $request
     * @return \Illuminate\Http\Response
     */
    public function store(CourseCodeRequest $request, AdminAuthoringCreateIntentService $createIntents)
    {
        try {
            $numberOfCodes = max(1, (int) $request->input('number_of_codes', 1));

            $payload = [
                'name' => $request->input('name'),
                'type' => $request->input('type'),
                'start_date' => BusinessClock::localInputToUtc($request->input('start_date')),
                'expiry_date' => BusinessClock::localInputToUtc($request->input('expiry_date')),
                'max_uses' => $request->input('max_uses'),
                'description' => $request->input('description'),
                'allowed_email_domains' => $this->emailDomains($request->input('allowed_email_domains')),
                'is_grant' => $request->boolean('is_grant'),
                // Partial lesson grants require scoped access from dashboard to player.
                'course_id' => $request->integer('course_id'),
            ];
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
                : "تم إنشاء الكود بنجاح";

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
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
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
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
     */
    public function edit(CourseCode $courseCode)
    {
        $courses = $this->reader->courseOptions();
        $designSettings = $this->getDesignSettings();
        $editorVersion = CourseCodeEditorVersion::for($courseCode);

        return view('admin.course-codes.edit', compact('courseCode', 'courses', 'designSettings', 'editorVersion'));
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \App\Http\Requests\Admin\CourseCodeRequest  $request
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
     */
    public function update(CourseCodeRequest $request, CourseCode $courseCode)
    {
        try {
            $data = $request->validated();

            // Remove fields that shouldn't be updated
            $editorVersion = (string) $data['editor_version'];
            unset($data['number_of_codes'], $data['authoring_request_id'], $data['editor_version']);
            $data['allowed_email_domains'] = $this->emailDomains(
                $request->input('allowed_email_domains')
            );
            $data['is_grant'] = $request->boolean('is_grant');
            foreach (['start_date', 'expiry_date'] as $field) {
                if (array_key_exists($field, $data)) {
                    $data[$field] = BusinessClock::localInputToUtc($data[$field]);
                }
            }

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
     * @param  \App\Models\CourseCode  $courseCode
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, CourseCode $courseCode)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
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

    private function emailDomains(?string $value): ?array
    {
        $domains = collect(preg_split('/[,\r\n]+/', (string) $value))
            ->map(fn ($domain) => ltrim(mb_strtolower(trim((string) $domain)), '@'))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return $domains ?: null;
    }

    /**
     * Bulk actions for course codes
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function bulkAction(Request $request)
    {
        $request->validate([
            'action' => 'required|in:delete,activate,deactivate',
            'selected_codes' => 'required|array|min:1',
            'selected_codes.*' => 'integer|distinct|exists:course_codes,id',
            'editor_versions' => 'required|array',
            'editor_versions.*' => 'required|string|size:64',
        ]);

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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function getLessons(Request $request)
    {
        $courseId = $request->input('course_id');

        if (!$courseId) {
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
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\StreamedResponse
     */
    public function export(Request $request)
    {
        $filters = $this->filters($request);
        $filename = 'course_codes_' . BusinessClock::now()->format('Y-m-d_H-i-s') . '.csv';
        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];
        $callback = function () use ($filters): void {
            $file = fopen('php://output', 'w');
            try {
                fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));
                foreach ($this->reader->csvRows($filters) as $row) fputcsv($file, $row);
            } finally {
                fclose($file);
            }
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export course codes to PDF for printing
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportToPdf(Request $request)
    {
        try {
            set_time_limit(300);

            $courseCodes = $this->reader->pdfRows($this->filters($request));

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
                'total_codes' => $courseCodes->count()
            ];

            // Generate PDF using LaravelPdf package (mPDF wrapper)
            $pdf = \PDF::loadView('admin.course-codes.pdf', $data);
            
            // Set PDF metadata dynamically from settings
            $pdf->setAuthor($designSettings->name_ar ?? 'منصة تعليمية');
            $pdf->setCreator($designSettings->name_ar ?? 'منصة تعليمية');

            // Generate filename
            $filename = 'Course_Codes_' . BusinessClock::now()->format('Y-m-d_H-i-s') . '.pdf';

            // Force download the PDF
            return $pdf->download($filename);

        } catch (ValidationException $e) {
            throw $e;
        } catch (\Exception $e) {
            report($e);
            return back()->with('error', 'تعذر تصدير الأكواد الآن');
        }
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'code' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'course_id' => ['nullable', 'integer'],
            'lesson_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string'],
        ]);
    }
}

