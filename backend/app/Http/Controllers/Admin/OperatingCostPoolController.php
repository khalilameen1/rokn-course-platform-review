<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Filament\Pages\CommercialReport;
use App\Filament\Resources\OperatingCostResource;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\EditorVersionRequest;
use App\Http\Requests\Admin\OperatingCostExchangeRateRequest;
use App\Http\Requests\Admin\OperatingCostIndexRequest;
use App\Http\Requests\Admin\OperatingCostProfileRequest;
use App\Http\Requests\Admin\OperatingCostReportRequest;
use App\Models\OperatingCostPool;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminOperatingCostAuthoringService;
use App\Services\CourseCostReportService;
use App\Services\PlatformCommercialReportService;
use App\Support\CsvCell;
use Illuminate\Http\RedirectResponse;

final class OperatingCostPoolController extends Controller
{
    public function __construct(private readonly AdminOperatingCostAuthoringService $authoring) {}

    public function index(OperatingCostIndexRequest $request): RedirectResponse
    {
        $filters = $request->validated();
        if ($request->filled('edit_cost')) {
            return redirect(OperatingCostResource::getUrl('edit', ['record' => (int) $request->input('edit_cost')]));
        }

        return redirect(OperatingCostResource::getUrl('index', ['filters' => [
            'service_key' => ['value' => $filters['service_key'] ?? null],
            'criteria' => array_intersect_key($filters, array_flip(['course_id', 'from', 'to'])),
        ]]));
    }

    public function store(OperatingCostProfileRequest $request, AdminAuthoringCreateIntentService $createIntents): RedirectResponse
    {
        $data = $request->authoringData();
        $this->authoring->create($data, (int) $request->user()->id, function (OperatingCostPool $pool) use ($request, $createIntents): void {
            $createIntents->completeRedirect($request, url()->previous(), 302, OperatingCostPool::class, $pool->id);
        });

        return back()->with('success', $data['is_final']
            ? 'تم حفظ الفاتورة النهائية لتقارير التشغيل.'
            : 'تم حفظ الفاتورة غير النهائية؛ لا تدخل في التكاليف المؤكدة.');
    }

    public function update(OperatingCostProfileRequest $request, OperatingCostPool $operatingCost): RedirectResponse
    {
        $version = $request->validated();
        $this->authoring->update(
            (int) $operatingCost->id,
            $request->authoringData(),
            (string) $version['editor_version']
        );

        return back()->with('success', 'تم تحديث تكلفة التشغيل.');
    }

    public function destroy(EditorVersionRequest $request, OperatingCostPool $operatingCost): RedirectResponse
    {
        $validated = $request->validated();
        $this->authoring->delete((int) $operatingCost->id, (string) $validated['editor_version']);

        return back()->with('success', 'تم حذف بند التكلفة.');
    }

    public function updateExchangeRate(OperatingCostExchangeRateRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $this->authoring->updateExchangeRate(
            (float) $data['openrouter_usd_to_egp_rate'],
            (string) $data['editor_version']
        );

        return back()->with('success', 'تم تحديث سعر تحويل تكلفة OpenRouter للتقارير الجديدة.');
    }

    public function report(OperatingCostReportRequest $request): RedirectResponse
    {
        $filters = $request->validated();

        return redirect(CommercialReport::getUrl(['filters' => ['period' => $filters['period'] ?? 'all'] + $filters]));
    }

    public function exportReport(OperatingCostReportRequest $request, PlatformCommercialReportService $reports)
    {
        $filters = $request->validated();
        $report = $reports->report($filters);
        $labels = CourseCostReportService::serviceLabels();

        return response()->streamDownload(function () use ($report, $labels): void {
            $output = fopen('php://output', 'wb');
            fwrite($output, "\xEF\xBB\xBF");
            fputcsv($output, array_merge([
                'الفترة', 'الطالب', 'البريد', 'الكورسات', 'الباقات الحالية', 'مصادر الإتاحة الحالية', 'قنوات الشحن',
                'صافي الدخل', 'تكلفة الخدمات', 'هامش المساهمة', 'نسبة التكلفة للصافي',
                'حالة ربط دفتر العملات',
                'طلبات AI ناجحة', 'طلبات AI فاشلة', 'نسبة فشل AI',
                'طلبات AI بانتظار تكلفة المزود', 'حالة تكلفة AI', 'تكلفة OpenRouter المؤكدة USD',
                'توكنات AI', 'دقائق الفيديو',
                'إشعارات داخل التطبيق', 'إشعارات مقروءة', 'محاولات Push', 'قبله مزود Push',
                'نسبة قبول مزود Push',
            ], array_map(fn (string $label): string => "تكلفة {$label}", $labels)), ',', '"', '');
            foreach ($report['student_rows'] as $row) {
                $serviceCosts = collect($labels)->keys()->map(
                    fn (string $key) => $row['actual_cost_by_service_egp']->get($key)
                )->all();
                fputcsv($output, CsvCell::row(array_merge([
                    $report['period']->label(),
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
                    $row['ai_cost_complete'] ? 'مؤكدة من المزود' : 'تأكيد المزود غير مكتمل',
                    $row['ai_cost_usd'],
                    $row['ai_tokens'],
                    $row['playback_minutes'],
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
}
