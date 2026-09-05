<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\CourseCodeRequest;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\DesignSetting;
use App\Models\Lesson;
use App\Services\ArabicPdfService;
use App\Services\AdminAuthoringCreateIntentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Support\BusinessClock;
use App\Support\CsvCell;
use App\Support\UnicodeText;
use App\Support\AdminEditorVersion;
use Illuminate\Validation\ValidationException;

class CourseCodeController extends Controller
{
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
        $query = $this->filteredQuery($request, false);

        // Efficiently update is_active status based on expiry and usage
        // This only runs once per page load and uses a single query
        /*
        DB::statement("
            UPDATE course_codes
            SET is_active = CASE
                WHEN expiry_date IS NOT NULL AND expiry_date < NOW() THEN 0
                WHEN start_date IS NOT NULL AND start_date > NOW() THEN 0
                WHEN used_count >= max_uses THEN 0
                ELSE 1
            END
            WHERE (
                (expiry_date IS NOT NULL AND expiry_date < NOW() AND is_active = 1) OR
                (start_date IS NOT NULL AND start_date > NOW() AND is_active = 1) OR
                (used_count >= max_uses AND is_active = 1) OR
                (
                    (expiry_date IS NULL OR expiry_date >= NOW()) AND
                    (start_date IS NULL OR start_date <= NOW()) AND
                    used_count < max_uses AND
                    is_active = 0
                )
            )
        ");
        */

        $courseCodes = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(10)->withQueryString();
        $courses = Course::query()->orderBy('name_ar')->orderBy('id')->get();
        $lessons = Lesson::query()->orderBy('title_ar')->orderBy('id')->get();
        $designSettings = $this->getDesignSettings();
        $editorVersions = $courseCodes->getCollection()->mapWithKeys(
            fn (CourseCode $code): array => [$code->id => $this->editorVersion($code)]
        );

        return view('admin.course-codes.index', compact(
            'courseCodes', 'courses', 'lessons', 'designSettings', 'editorVersions'
        ));
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        $courses = Course::all();
        $lessons = Lesson::all();
        $designSettings = $this->getDesignSettings();

        return view('admin.course-codes.create', compact('courses', 'lessons', 'designSettings'));
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

            DB::transaction(function () use ($request, $numberOfCodes, $createIntents): void {
                $firstCodeId = null;
                for ($i = 0; $i < $numberOfCodes; $i++) {
                    $codeData = [
                        'code' => CourseCode::generateUniqueCode(),
                        'name' => $request->input('name'),
                        'type' => $request->input('type'),
                        'start_date' => BusinessClock::localInputToUtc($request->input('start_date')),
                        'expiry_date' => BusinessClock::localInputToUtc($request->input('expiry_date')),
                        'max_uses' => $request->input('max_uses'),
                        'description' => $request->input('description'),
                        'allowed_email_domains' => $this->emailDomains($request->input('allowed_email_domains')),
                        'is_grant' => $request->boolean('is_grant'),
                        // A code grants the course entitlement it names. Partial
                        // lesson grants stay unavailable until scoped access
                        // exists from dashboard to player.
                        'course_id' => $request->integer('course_id'),
                    ];

                    $created = CourseCode::create($codeData);
                    $firstCodeId ??= $created->id;
                }
                $createIntents->completeRedirect(
                    $request,
                    route('admin.course-codes.index'),
                    302,
                    CourseCode::class,
                    $firstCodeId
                );
            }, 3);

            $message = $numberOfCodes > 1
                ? "تم إنشاء {$numberOfCodes} أكواد بنجاح"
                : "تم إنشاء الكود بنجاح";

            return redirect()->route('admin.course-codes.index')
                ->with('success', $message);

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
        $editorVersion = $this->editorVersion($courseCode);

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
        $courses = Course::all();
        $lessons = Lesson::all();
        $designSettings = $this->getDesignSettings();
        $editorVersion = $this->editorVersion($courseCode);

        return view('admin.course-codes.edit', compact('courseCode', 'courses', 'lessons', 'designSettings', 'editorVersion'));
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

            DB::transaction(function () use ($courseCode, $data, $editorVersion): void {
                $locked = CourseCode::query()->whereKey($courseCode->id)
                    ->lockForUpdate()->firstOrFail();
                if (!hash_equals($this->editorVersion($locked), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => "تغيّر كود الجهة منذ فتح الصفحة\nأعد تحميله قبل الحفظ",
                    ]);
                }
                $locked->update($data);
            }, 3);

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
        $deactivated = DB::transaction(function () use ($courseCode, $validated): bool {
            $locked = CourseCode::query()->whereKey($courseCode->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّر كود الجهة منذ فتح الصفحة\nأعد تحميله قبل الحذف",
                ]);
            }
            if ($locked->usages()->exists() || $locked->orders()->exists()) {
                $locked->forceFill(['is_active' => false])->save();
                return true;
            }
            $locked->delete();
            return false;
        }, 3);

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

    private function editorVersion(CourseCode $courseCode): string
    {
        return AdminEditorVersion::for($courseCode, [
            'code', 'name', 'type', 'course_id', 'lesson_id', 'lesson_ids',
            'start_date', 'expiry_date', 'max_uses', 'used_count', 'is_active',
            'is_grant', 'description', 'allowed_email_domains',
        ]);
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
            $message = DB::transaction(function () use ($action, $selectedCodes, $versions): string {
                $codes = CourseCode::query()->whereIn('id', $selectedCodes)
                    ->orderBy('id')->lockForUpdate()->get();
                if ($codes->count() !== count(array_unique(array_map('intval', $selectedCodes)))) {
                    throw ValidationException::withMessages([
                        'selected_codes' => "تغيّرت قائمة الأكواد\nأعد تحميل الصفحة قبل المتابعة",
                    ]);
                }
                foreach ($codes as $code) {
                    $submitted = (string) ($versions[$code->id] ?? '');
                    if ($submitted === '' || !hash_equals($this->editorVersion($code), $submitted)) {
                        throw ValidationException::withMessages([
                            'editor_versions' => "تغيّر أحد الأكواد المحددة\nأعد تحميل الصفحة قبل المتابعة",
                        ]);
                    }
                }

                if ($action === 'delete') {
                    $deleted = 0;
                    $deactivated = 0;
                    foreach ($codes as $code) {
                        if ($code->usages()->exists() || $code->orders()->exists()) {
                            $code->forceFill(['is_active' => false])->save();
                            $deactivated++;
                        } else {
                            $code->delete();
                            $deleted++;
                        }
                    }
                    return "حُذف {$deleted} كود وأُوقف {$deactivated} كود مستخدم مع الاحتفاظ بسجله";
                }

                $changed = 0;
                foreach ($codes as $code) {
                    if ($action === 'activate' && $code->type !== 'course') continue;
                    $target = $action === 'activate';
                    if ((bool) $code->is_active !== $target) {
                        $code->forceFill(['is_active' => $target])->save();
                        $changed++;
                    }
                }
                return $action === 'activate'
                    ? "تم تفعيل {$changed} كود صالح"
                    : 'تم إلغاء تفعيل الأكواد المحددة بنجاح';
            }, 3);

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
            $lessons = Lesson::where('list_id', $courseId)
                ->orderBy('priority')
                ->get(['id', 'title']);

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
        $query = $this->filteredQuery($request, false);

        $filename = 'course_codes_' . BusinessClock::now()->format('Y-m-d_H-i-s') . '.csv';

        $headers = [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ];

        $callback = function() use ($query) {
            $file = fopen('php://output', 'w');

            // Add BOM for Arabic text
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Headers
            fputcsv($file, [
                'الكود',
                'الاسم',
                'النوع',
                'الدورة/الدرس',
                'تاريخ البداية',
                'تاريخ الانتهاء',
                'الاستخدامات',
                'الحد الأقصى',
                'منحة مؤسسية',
                'نطاقات البريد',
                'الحالة',
                'تاريخ الإنشاء'
            ]);

            // Stream bounded chunks from the database. A large institutional
            // campaign must not exhaust the dashboard worker before the first
            // CSV byte reaches the browser.
            foreach ($query->reorder()->lazyByIdDesc(500) as $code) {
                fputcsv($file, CsvCell::row([
                    $code->code,
                    $code->name,
                    $this->getTypeName($code->type),
                    $code->target_content_name,
                    $code->start_date ? BusinessClock::format($code->start_date, 'Y-m-d') : '',
                    $code->expiry_date ? BusinessClock::format($code->expiry_date, 'Y-m-d') : '',
                    $code->used_count,
                    $code->max_uses,
                    $code->isInstitutionalGrant() ? 'نعم' : 'لا',
                    implode(', ', $code->allowed_email_domains ?? []),
                    $code->is_active ? 'مفعل' : 'معطل',
                    BusinessClock::format($code->created_at, 'Y-m-d H:i:s')
                ]));
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
    }

    /**
     * Export course codes to PDF for printing
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportToPdf(Request $request, ArabicPdfService $pdfService)
    {
        try {
            set_time_limit(300);

            $courseCodes = $this->filteredQuery($request, false)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->limit(501)
                ->get();

            if ($courseCodes->isEmpty()) {
                return back()->with('error', 'لا توجد أكواد للتصدير');
            }
            if ($courseCodes->count() > 500) {
                return back()->with(
                    'error',
                    "نتيجة PDF أكبر من 500 كود\nضيّق البحث أو استخدم تصدير CSV للسجل الكامل"
                );
            }

            // Transform the data for PDF
            $transformedCodes = collect();
            foreach ($courseCodes as $code) {
                // Get the target content name based on type
                $targetContentName = 'غير محدد';
                if ($code->type === 'course' && $code->course) {
                    $targetContentName = $code->course->name_ar ?? 'غير محدد';
                } elseif ($code->type === 'lesson' && $code->lesson) {
                    $targetContentName = $code->lesson->name_ar ?? 'غير محدد';
                } elseif ($code->type === 'multiple_lessons') {
                    $targetContentName = 'دروس متعددة';
                }

                $transformedCodes->push((object) [
                    'name' => $code->name ?? 'غير محدد',
                    'target_content_name' => $targetContentName,
                    'code' => $code->code ?? 'غير محدد',
                    'type' => $code->type ?? 'course',
                    'max_uses' => $code->max_uses ?? 0,
                    'is_grant' => $code->isInstitutionalGrant(),
                    'allowed_email_domains' => $code->allowed_email_domains ?? [],
                ]);
            }

            $designSettings = $this->getDesignSettings();
            
            $data = [
                'course_codes' => $transformedCodes,
                'platform_name' => $designSettings->name_ar ?? 'منصة تعليمية',
                'export_date' => BusinessClock::now()->format('Y-m-d H:i:s'),
                'total_codes' => $transformedCodes->count()
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

                  } catch (\Exception $e) {
              report($e);
              return back()->with('error', 'تعذر تصدير الأكواد الآن');
          }
    }

    private function filteredQuery(Request $request, bool $withUsages)
    {
        $dates = $request->validate([
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
        ]);
        $relations = ['course', 'lesson'];
        if ($withUsages) {
            $relations[] = 'usages';
        }

        return CourseCode::query()
            ->with($relations)
            ->when($request->filled('code'), fn ($query) =>
                $query->where('code', 'like', '%' . UnicodeText::identifier($request->code) . '%')
            )
            ->when($request->filled('name'), fn ($query) =>
                $query->where('name', 'like', '%' . UnicodeText::clean($request->name, false) . '%')
            )
            ->when($request->filled('type'), fn ($query) =>
                $query->where('type', $request->type)
            )
            ->when($request->filled('course_id'), fn ($query) =>
                $query->where('course_id', $request->course_id)
            )
            ->when($request->filled('lesson_id'), fn ($query) =>
                $query->where('lesson_id', $request->lesson_id)
            )
            ->when($dates['start_date'] ?? null, function ($query, string $date) {
                [$from, $to] = BusinessClock::localDayRangeUtc($date);
                return $query->where('start_date', '>=', $from)->where('start_date', '<', $to);
            })
            ->when($dates['expiry_date'] ?? null, function ($query, string $date) {
                [$from, $to] = BusinessClock::localDayRangeUtc($date);
                return $query->where('expiry_date', '>=', $from)->where('expiry_date', '<', $to);
            })
            ->when($request->filled('status'), function ($query) use ($request) {
                return match ((string) $request->status) {
                    'active' => $query->where('is_active', true),
                    'inactive' => $query->where('is_active', false),
                    'expired' => $query->where('expiry_date', '<', now()),
                    'not_yet_active' => $query->where('start_date', '>', now()),
                    default => $query,
                };
            });
    }

    /**
     * Get Arabic name for code type
     */
    private function getTypeName($type)
    {
        $types = [
            'course' => 'دورة',
            'lesson' => 'درس',
            'multiple_lessons' => 'دروس متعددة'
        ];

        return $types[$type] ?? $type;
    }
}

