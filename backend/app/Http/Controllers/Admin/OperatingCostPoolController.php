<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Course;
use App\Models\OperatingCostPool;
use App\Models\Setting;
use App\Services\CourseCostReportService;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\PlatformCommercialReportService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use App\Support\CsvCell;
use App\Support\AdminEditorVersion;
use App\Support\AdminSingletonLock;
use Illuminate\Validation\ValidationException;

final class OperatingCostPoolController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate([
            'service_key' => ['nullable', Rule::in(array_keys(OperatingCostPool::SERVICES))],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);
        $poolQuery = OperatingCostPool::query()
            ->when($filters['service_key'] ?? null, fn ($query, $service) => $query->where('service_key', $service))
            ->when($filters['course_id'] ?? null, fn ($query, $courseId) => $query->where('course_id', $courseId))
            ->when($filters['from'] ?? null, fn ($query, $from) => $query->where('period_end', '>=', $from))
            ->when($filters['to'] ?? null, fn ($query, $to) => $query->where('period_start', '<=', $to));
        $summaryRows = (clone $poolQuery)
            ->select(['service_key', 'is_final'])
            ->selectRaw("SUM(CASE WHEN currency = 'EGP' THEN amount WHEN currency = 'USD' AND COALESCE(fx_rate_to_egp, 0) > 0 THEN amount * fx_rate_to_egp ELSE 0 END) AS amount_egp")
            ->selectRaw("SUM(CASE WHEN currency = 'USD' AND COALESCE(fx_rate_to_egp, 0) <= 0 THEN 1 ELSE 0 END) AS missing_fx")
            ->groupBy('service_key', 'is_final')
            ->get();
        $pools = (clone $poolQuery)
            ->with('course')
            ->latest('period_end')
            ->latest('id')
            ->paginate(30)
            ->withQueryString();
        $courses = Course::query()
            ->withCount('activeEnrollments')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar']);
        $settings = Setting::query()->first() ?? new Setting();
        $editPool = $request->filled('edit_cost')
            ? OperatingCostPool::query()->findOrFail((int) $request->input('edit_cost'))
            : null;
        $poolEditorVersions = $pools->getCollection()->mapWithKeys(
            fn (OperatingCostPool $pool): array => [$pool->id => $this->editorVersion($pool)]
        );
        $editPoolEditorVersion = $editPool ? $this->editorVersion($editPool) : null;
        $exchangeRateEditorVersion = AdminEditorVersion::for(
            $settings,
            ['openrouter_usd_to_egp_rate']
        );

        $actualRows = $summaryRows->filter(fn ($row): bool => (bool) $row->is_final);
        $estimatedRows = $summaryRows->reject(fn ($row): bool => (bool) $row->is_final);
        $totals = [
            'actual_egp' => round((float) $actualRows->sum('amount_egp'), 2),
            'estimated_egp' => round((float) $estimatedRows->sum('amount_egp'), 2),
            'missing_fx' => (int) $summaryRows->sum('missing_fx'),
        ];
        $serviceSummary = $summaryRows->groupBy('service_key')->map(fn ($serviceRows) => [
            'actual_egp' => round((float) $serviceRows->filter(fn ($row): bool => (bool) $row->is_final)->sum('amount_egp'), 2),
            'estimated_egp' => round((float) $serviceRows->reject(fn ($row): bool => (bool) $row->is_final)->sum('amount_egp'), 2),
        ]);

        return view('admin.operating-costs.index', compact(
            'pools', 'courses', 'settings', 'editPool', 'filters', 'totals', 'serviceSummary',
            'poolEditorVersions', 'editPoolEditorVersion', 'exchangeRateEditorVersion'
        ));
    }

    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $data, $createIntents): void {
            $pool = OperatingCostPool::query()->create($data + ['created_by' => $request->user()->id]);
            $createIntents->completeRedirect($request, url()->previous(), 302, OperatingCostPool::class, $pool->id);
        }, 3);

        return back()->with('success', 'تمت إضافة فاتورة التكلفة وستدخل في تقارير الربحية.');
    }

    public function update(Request $request, OperatingCostPool $operatingCost): RedirectResponse
    {
        $request->validate(['editor_version' => 'required|string|size:64']);
        $data = $this->validated($request);
        DB::transaction(function () use ($request, $operatingCost, $data): void {
            $locked = OperatingCostPool::query()->whereKey($operatingCost->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $request->input('editor_version'))) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت فاتورة التشغيل منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                ]);
            }
            $locked->update($data);
        }, 3);

        return back()->with('success', 'تم تحديث تكلفة التشغيل.');
    }

    public function destroy(Request $request, OperatingCostPool $operatingCost): RedirectResponse
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($operatingCost, $validated): void {
            $locked = OperatingCostPool::query()->whereKey($operatingCost->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals($this->editorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت فاتورة التشغيل منذ فتح الصفحة\nأعد تحميلها قبل الحذف',
                ]);
            }
            $locked->delete();
        }, 3);

        return back()->with('success', 'تم حذف بند التكلفة.');
    }

    public function updateExchangeRate(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'openrouter_usd_to_egp_rate' => ['required', 'numeric', 'min:0.0001', 'max:10000'],
            'editor_version' => ['required', 'string', 'size:64'],
        ]);
        $editorVersion = (string) $data['editor_version'];
        unset($data['editor_version']);
        DB::transaction(function () use ($data, $editorVersion): void {
            AdminSingletonLock::acquire('settings');
            $setting = Setting::query()->lockForUpdate()->first();
            if (!$setting) {
                $setting = new Setting();
            }
            if (!hash_equals(AdminEditorVersion::for(
                $setting,
                ['openrouter_usd_to_egp_rate']
            ), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّر سعر التحويل منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                ]);
            }
            $setting->fill($data)->save();
        }, 3);

        return back()->with('success', 'تم تحديث سعر تحويل تكلفة OpenRouter للتقارير الجديدة.');
    }

    public function report(Request $request, PlatformCommercialReportService $reports): View
    {
        $filters = $this->reportFilters($request);
        $report = $reports->report($filters);
        $studentRows = $report['student_rows'];
        $page = LengthAwarePaginator::resolveCurrentPage();
        $perPage = (int) ($filters['per_page'] ?? 30);
        $students = new LengthAwarePaginator(
            $studentRows->forPage($page, $perPage)->values(),
            $studentRows->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );
        $courses = Course::withTrashed()
            ->whereHas('enrollments')
            ->orderBy('name_ar')
            ->get(['id', 'name_ar']);

        return view('admin.operating-costs.report', compact(
            'report', 'students', 'courses', 'filters'
        ));
    }

    public function exportReport(Request $request, PlatformCommercialReportService $reports)
    {
        $filters = $this->reportFilters($request);
        $report = $reports->report($filters);
        $labels = CourseCostReportService::serviceLabels();

        return response()->streamDownload(function () use ($report, $labels): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_merge([
                'الطالب', 'البريد', 'الكورسات', 'الباقات', 'مصادر الإتاحة', 'قنوات الشحن',
                'صافي الدخل', 'تكلفة الخدمات', 'هامش المساهمة', 'نسبة التكلفة للصافي',
                'حالة ربط دفتر العملات',
                'طلبات AI ناجحة', 'طلبات AI فاشلة', 'نسبة فشل AI',
                'طلبات AI بتكلفة تقديرية', 'حالة تكلفة AI',
                'توكنات AI', 'دقائق الفيديو', 'GB مشاهدة مقدرة',
                'إشعارات داخل التطبيق', 'إشعارات مقروءة', 'محاولات Push', 'قبله مزود Push',
                'نسبة قبول مزود Push',
            ], array_map(fn (string $label): string => "تكلفة {$label}", $labels)), ',', '"', '');
            foreach ($report['student_rows'] as $row) {
                $serviceCosts = collect($labels)->keys()->map(
                    fn (string $key) => $row['actual_cost_by_service_egp']->get($key)
                )->all();
                fputcsv($output, CsvCell::row(array_merge([
                    $row['user']?->name ?? 'مستخدم محذوف',
                    $row['user']?->email,
                    $row['courses']->implode(' | '),
                    $row['plans']->implode(' | '),
                    $row['sources']->implode(' | '),
                    $row['payment_channels']->implode(' | '),
                    $row['net_egp'],
                    $row['service_cost_egp'],
                    $row['margin_egp'],
                    $row['cost_to_net_revenue_percentage'],
                    $row['coin_allocation_complete'] ? 'مكتمل' : 'غير مكتمل',
                    $row['ai_requests'],
                    $row['ai_failed_requests'],
                    $row['ai_failure_rate_percentage'],
                    $row['ai_estimated_requests'],
                    $row['ai_cost_complete'] ? 'مؤكدة من المزود' : 'تتضمن تقديرات',
                    $row['ai_tokens'],
                    $row['playback_minutes'],
                    $row['playback_gb_estimated'],
                    $row['in_app_notifications'],
                    $row['read_notifications'],
                    $row['push_attempts'],
                    $row['push_provider_accepted'],
                    $row['push_provider_acceptance_rate_percentage'],
                ], $serviceCosts)), ',', '"', '');
            }
            fclose($output);
        }, 'rokn-platform-unit-economics.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'service_key' => ['required', Rule::in(array_keys(OperatingCostPool::SERVICES))],
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'amount' => ['required', 'numeric', 'min:0', 'max:1000000000'],
            'currency' => ['required', Rule::in(['EGP', 'USD'])],
            'fx_rate_to_egp' => ['nullable', 'required_if:currency,USD', 'numeric', 'min:0.0001', 'max:10000'],
            'allocation_driver' => ['required', Rule::in(array_keys(OperatingCostPool::DRIVERS))],
            'is_final' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'authoring_request_id' => [$request->isMethod('post') ? 'required' : 'nullable', 'uuid'],
        ]);
        unset($data['authoring_request_id']);
        $data['is_final'] = $request->boolean('is_final');

        return $data;
    }

    /** @return array<string, mixed> */
    private function reportFilters(Request $request): array
    {
        return $request->validate([
            'course_id' => ['nullable', 'integer', 'exists:courses,id'],
            'plan' => ['nullable', 'string', 'max:100'],
            'source' => ['nullable', Rule::in([
                'purchase', 'grant', 'course_code', 'grant_plus_purchase', 'code_plus_purchase',
            ])],
            'q' => ['nullable', 'string', 'max:160'],
            'per_page' => ['nullable', Rule::in([20, 30, 50, 100])],
        ]);
    }

    private function editorVersion(OperatingCostPool $pool): string
    {
        return AdminEditorVersion::for($pool, [
            'name', 'service_key', 'course_id', 'period_start', 'period_end',
            'amount', 'currency', 'fx_rate_to_egp', 'allocation_driver',
            'is_final', 'notes',
        ]);
    }

}
