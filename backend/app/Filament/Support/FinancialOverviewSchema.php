<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Pages\ProductAnalytics;
use App\Filament\Widgets\OverviewCourseCoinsChart;
use App\Filament\Widgets\OverviewRevenueChart;
use App\Support\PaymentReportPresentation;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/** The previous administrator home report, rendered by the same native panel components. */
final class FinancialOverviewSchema
{
    public static function configure(Schema $schema, array $report): Schema
    {
        $stats = $report['revenueStats'];
        $ai = $report['ai'];
        $grossPending = ! $stats['gross_complete'] && $stats['confirmed_gross_count'] === 0;
        $netPending = $stats['provider_settlement_pending_count'] > 0 && $stats['confirmed_net_count'] === 0;
        $unknownAi = $ai['cost_usd'] === null || (! $ai['cost_complete'] && $ai['provider_cost_requests'] === 0 && $ai['cost_usd'] == 0);
        $period = $report['period'];
        $channels = $report['paymentChannelReport'];
        $channelRows = collect($channels['rows'])->map(function (array $row): array {
            foreach (['gross', 'fee', 'net'] as $kind) {
                $detail = PaymentReportPresentation::detail($row, $kind);
                $row[$kind] = PaymentReportPresentation::amount($row, $kind).($detail ? ' · '.$detail : '');
            }

            return $row;
        });

        return $schema->columns(1)->components([
            Section::make('الأداء المالي')->columns(['default' => 1, 'md' => 3])->schema([
                self::entry('gross', 'تحصيل الفترة المؤكد بكل القنوات بالجنيه', $grossPending ? 'بانتظار تأكيد التحصيل' : number_format($stats['total_revenue'], 2))
                    ->helperText(ProductAnalyticsSchema::change($stats['revenue_change'])),
                self::entry('net', 'الصافي المؤكد من كشوف المزودين', $netPending ? 'بانتظار التسوية' : number_format($stats['confirmed_net_revenue'], 2))
                    ->helperText(ProductAnalyticsSchema::change($stats['net_change'])),
                self::entry('ai', 'استهلاك الذكاء الاصطناعي المؤكد USD', $unknownAi ? 'غير متاح' : number_format($ai['cost_usd'], 6))
                    ->helperText(ProductAnalyticsSchema::change($report['aiChange'])),
                self::entry('catalog_estimate', 'تقدير الكتالوج خارج الإجمالي', number_format($stats['catalog_estimated_revenue'], 2))->visible($stats['catalog_estimated_revenue'] > 0),
                self::entry('net_partial', 'عمليات بانتظار كشف التسوية', number_format($stats['provider_settlement_pending_count']))->visible($stats['provider_settlement_pending_count'] > 0),
                self::entry('ai_partial', 'قياس جزئي بلا تكلفة مؤكدة', number_format($ai['estimated_cost_requests']).' طلبًا')->visible(! $ai['cost_complete']),
            ]),
            Section::make('حالة المنصة الآن')->columns(['default' => 2, 'md' => 4])->schema([
                ...collect(['courses' => 'إجمالي الكورسات الآن', 'lessons' => 'إجمالي الدروس الآن', 'students' => 'إجمالي الطلاب الآن'])
                    ->map(fn (string $label, string $key): TextEntry => self::entry('platform_'.$key, $label, number_format($report['platformStats'][$key])))->values()->all(),
                self::entry('pending_cash', 'قيمة معلقة الآن بكل القنوات', number_format($stats['pending_payments'], 2).' جنيه · '.$stats['pending_bills_count'].' عملية'),
            ]),
            Tabs::make('financial-sections')->persistTabInQueryString('tab')->tabs([
                Tab::make('التحصيل')->schema([
                    Livewire::make(OverviewRevenueChart::class, ['months' => $report['monthlyRevenue']])->key('monthly-'.$period->key, isInheritable: false),
                    Section::make('مقارنة التحصيل')->columns(2)->schema([
                        self::entry('current', 'المحصل المؤكد خلال الفترة', $grossPending ? 'بانتظار تأكيد التحصيل' : number_format($stats['total_revenue'], 2)),
                        self::entry('previous', 'الفترة السابقة المماثلة', $stats['previous_period_revenue'] === null ? 'لا توجد فترة مقارنة' : ($stats['previous_gross_unknown'] ? 'بانتظار تأكيد التحصيل' : number_format($stats['previous_period_revenue'], 2)))
                            ->helperText($period->previous()?->description()),
                        self::entry('analytics', 'تحليلات المنتج', 'فتح التحليلات')->url(ProductAnalytics::getUrl(['filters' => ['period' => $period->key]]))->color('primary'),
                    ]),
                    Section::make('تحصيل باقات العملات حسب القناة')->description('لا تُجمع العملات المختلفة مع الجنيه وعمليات الاختبار لا تدخل في المبالغ المؤكدة')->schema([
                        ProductOperationsSchema::rows('channel_rows', $channelRows, [
                            'label' => 'القناة', 'currency' => 'العملة', 'live_count' => 'عمليات حقيقية', 'live_coins' => 'عملات مُصدرة',
                            'gross' => 'الإجمالي المؤكد', 'fee' => 'رسوم مؤكدة', 'net' => 'صافي مؤكد', 'test_count' => 'عمليات اختبار', 'test_coins' => 'عملات الاختبار',
                        ]),
                        ...collect(['gross' => 'الإجمالي المؤكد بالجنيه', 'fee' => 'رسوم مؤكدة بالجنيه', 'net' => 'صافي مؤكد بالجنيه'])
                            ->map(fn (string $label, string $kind): TextEntry => self::entry('egp_'.$kind, $label, PaymentReportPresentation::amount(PaymentReportPresentation::egp($channels), $kind))
                                ->helperText(PaymentReportPresentation::detail(PaymentReportPresentation::egp($channels), $kind)))->values()->all(),
                    ]),
                ]),
                Tab::make('الفواتير')->schema([CommercialReportSchema::invoices($report['invoiceReport'])]),
                Tab::make('عملات الكورسات')->schema([
                    Livewire::make(OverviewCourseCoinsChart::class, ['courses' => $report['courseStats']->all()])->key('course-coins-'.$period->key, isInheritable: false)->visible($report['courseStats']->isNotEmpty()),
                    Section::make('ملخص فتح الكورسات')->description('الإجمالي والعملات منذ البداية مع عدد الفتح خلال الفترة')->schema([
                        ProductOperationsSchema::rows('course_stats', $report['courseStats'], ['name' => 'الكورس', 'total_buy_count' => 'إجمالي الفتح',
                            'current_period_buy_count' => 'الفتح خلال الفترة', 'paid_coins' => 'عملات مشتراة', 'reward_coins' => 'مكافآت', 'incomplete_orders' => 'عمليات تحتاج ربط الدفتر']),
                    ]),
                ]),
            ])->columnSpanFull(),
        ]);
    }

    private static function entry(string $key, string $label, mixed $value): TextEntry
    {
        return TextEntry::make($key)->label($label)->state($value);
    }
}
