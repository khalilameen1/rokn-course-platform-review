<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Services\CourseCostReportService;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

/** Display projection only; null costs and non-attributable invoices are never converted to zero. */
final class CommercialReportSchema
{
    private const MONEY = ['gross_egp' => 'نقد منسوب للكورسات', 'net_egp' => 'صافي بوابة الدفع', 'service_cost_egp' => 'تكلفة تشغيل فعلية', 'margin_egp' => 'هامش المساهمة'];

    private const USAGE = ['ai_requests' => 'طلبات AI ناجحة', 'ai_failed_requests' => 'طلبات AI فاشلة', 'ai_unanswered_requests' => 'بلا نتيجة',
        'ai_estimated_requests' => 'بانتظار تكلفة المزود', 'ai_tokens' => 'توكنات AI', 'playback_minutes' => 'دقائق الفيديو',
        'in_app_notifications' => 'إشعارات داخل التطبيق', 'read_notifications' => 'إشعارات مقروءة', 'push_attempts' => 'محاولات Push', 'push_provider_accepted' => 'قبله مزود Push'];

    public static function configure(Schema $schema, array $report): Schema
    {
        $serviceRows = $report['service_breakdown']->map(fn (array $row): array => [
            'label' => $row['label'], 'usage' => self::serviceUsage($row, $report), 'actual' => self::money($row['actual_egp']),
            'share' => self::percent($row['share_of_actual_cost_percentage']), 'decision' => $row['actual_egp'] === null ? 'أكمل الفاتورة أو سعر التحويل' : ((float) $row['actual_egp'] === 0.0 ? 'مبلغ صفري مؤكد' : 'مبلغ مسجل مؤكد'),
        ]);

        return $schema->columns(1)->components([
            Section::make('ملخص الفترة')->columns(['default' => 2, 'md' => 3])->schema([
                ...collect(self::MONEY)->map(fn (string $label, string $key): TextEntry => self::entry($key, $label, self::money($report[$key]))
                    ->helperText(ProductAnalyticsSchema::change($report['comparisons'][$key])))->values()->all(),
                self::entry('unique_students', 'طلاب مختلفون', number_format($report['unique_students'])),
                self::entry('active_enrollments', 'تسجيلات نشطة', number_format($report['active_enrollments'])),
                self::entry('ai_cost_usd', 'OpenRouter مؤكد USD', self::usd($report['ai_cost_usd'], $report['ai_cost_complete'], $report['ai_measurement_available'] ?? true))
                    ->helperText(ProductAnalyticsSchema::change($report['comparisons']['ai_cost_usd'])),
                self::entry('playback_minutes', 'دقيقة فيديو مقاسة', number_format($report['playback_minutes'], 0)),
            ]),
            Section::make('اكتمال القياس')->schema([
                self::entry('cost_incomplete', 'التكلفة والصافي', 'لا ربح نهائي قبل اكتمال التسويات والفواتير وسعر التحويل')->visible($report['net_egp'] === null || $report['service_cost_egp'] === null),
                self::entry('ledger_incomplete', 'ربط الدفتر', 'عمليات غير مرتبطة بدفتر العملات حُجبت من الدخل')->visible(! $report['coin_allocation_complete']),
                self::entry('ai_pending', 'تكلفة المزود', number_format($report['ai_estimated_requests']).' طلبًا بانتظار التأكيد')->visible($report['ai_estimated_requests'] > 0),
                self::entry('warnings', 'تفاصيل النقص', $report['cost_warnings']->all())->visible($report['cost_warnings']->isNotEmpty()),
            ])->visible($report['net_egp'] === null || $report['service_cost_egp'] === null || ! $report['coin_allocation_complete'] || $report['ai_estimated_requests'] > 0),
            Tabs::make('commercial-sections')->persistTabInQueryString('tab')->tabs([
                Tab::make('الخدمات')->schema([
                    Section::make('الخدمات المدفوعة')->collapsible()->collapsed()->description('رسوم كاشير مخصومة من الصافي ولا تُضاف مرة ثانية')->schema([
                        ProductOperationsSchema::rows('services', $serviceRows, ['label' => 'الخدمة', 'usage' => 'الاستهلاك المقاس', 'actual' => 'تكلفة مؤكدة بالجنيه', 'share' => 'من إجمالي التكلفة', 'decision' => 'ملاحظة القرار']),
                    ]),
                    self::invoices($report['provider_invoice_report']),
                    Section::make('نسب ومتوسطات')->collapsible()->collapsed()->columns(2)->schema([
                        self::entry('average_net', 'متوسط الصافي لكل طالب', self::money($report['average_net_per_student_egp'])),
                        self::entry('average_cost', 'متوسط التكلفة لكل طالب', self::money($report['average_cost_per_student_egp'])),
                        self::entry('cost_to_net', 'التكلفة من صافي السعر', self::percent($report['cost_to_net_revenue_percentage'])),
                        self::entry('margin_rate', 'نسبة هامش المساهمة', self::percent($report['contribution_margin_percentage'])),
                        self::entry('cost_per_tokens', 'تكلفة 1000 توكن USD', $report['ai_cost_per_1000_tokens_usd'] === null ? 'غير متاح' : '$'.number_format($report['ai_cost_per_1000_tokens_usd'], 6)),
                        self::entry('failure_rate', 'طلبات AI لم تكتمل', self::percent($report['ai_failure_rate_percentage'])),
                    ]),
                ]),
                Tab::make('الكورسات والفئات')->schema([
                    Section::make('الفئة وقت العملية')->description('العقد المسجل وقت الشراء أو الاستخدام دون نسبة تكاليف المنصة إلى الفئة')->schema([
                        ProductOperationsSchema::rows('plans', $report['plan_breakdown']->map(fn (array $row): array => [
                            'name' => $row['plan_name'], 'net' => self::money($row['period_metrics']['cash_net_egp']), 'ai' => self::usd($row['period_metrics']['ai_cost_usd'], true),
                            'change' => ProductAnalyticsSchema::change($row['comparisons']['cash_net_egp']),
                        ]), ['name' => 'الفئة', 'net' => 'الصافي', 'ai' => 'OpenRouter مؤكد USD', 'change' => 'مقارنة الفترة السابقة']),
                    ]),
                    Section::make('حسب الكورس')->schema([
                        ProductOperationsSchema::rows('courses', $report['course_breakdown']->map(fn (array $row): array => [
                            'name' => $row['course_name'].($row['course_archived'] ? ' · مؤرشف' : ''), 'students' => number_format($row['students']),
                            'net' => self::money($row['net_egp']), 'cost' => self::money($row['service_cost_egp']), 'rate' => self::percent($row['cost_to_net_revenue_percentage']),
                            'change' => ProductAnalyticsSchema::change($row['comparisons']['cash_net_egp']),
                            'url' => $row['course_archived'] ? route('admin.courses.index', ['state' => 'archived', 'search' => $row['course_name']]) : route('admin.courses.show', ['course' => $row['course_id'], 'tab' => 'commercial-report', 'period' => $report['period']->key]).'#commercial-report',
                        ]), ['name' => 'الكورس', 'students' => 'طلاب فريدون', 'net' => 'الصافي', 'cost' => 'التكلفة', 'rate' => 'التكلفة من الصافي', 'change' => 'مقارنة الفترة السابقة',
                            'url' => 'رابط التقرير'])
                            ->schema([
                                TextEntry::make('url')->hidden(),
                                TextEntry::make('name')->label('الكورس')->url(fn (Get $get): ?string => $get('url'))->color('primary'),
                                ...collect(['students' => 'طلاب فريدون', 'net' => 'الصافي', 'cost' => 'التكلفة', 'rate' => 'التكلفة من الصافي', 'change' => 'مقارنة الفترة السابقة'])
                                    ->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label))->values()->all(),
                            ])->visible($report['course_breakdown']->isNotEmpty()),
                        self::entry('no_course_rows', 'الكورسات', 'لا توجد حالات مسجلة')->visible($report['course_breakdown']->isEmpty()),
                    ]),
                ]),
                Tab::make('الإتاحة والاستهلاك')->schema([
                    Section::make('حسب مصدر الإتاحة الحالي')->schema([
                        ProductOperationsSchema::rows('sources', $report['source_breakdown']->map(fn (array $row, string $name): array => [
                            'name' => $name, 'students' => number_format($row['students']), 'enrollments' => number_format($row['enrollments']),
                            'net' => self::money($row['net_egp']), 'cost' => self::money($row['service_cost_egp']), 'margin' => self::money($row['margin_egp']), 'rate' => self::percent($row['cost_to_net_revenue_percentage']),
                        ]), ['name' => 'المصدر', 'students' => 'طلاب فريدون', 'enrollments' => 'تسجيلات', 'net' => 'الصافي', 'cost' => 'التكلفة', 'margin' => 'الهامش', 'rate' => 'التكلفة من الصافي']),
                    ]),
                    Section::make('الاستهلاك المرصود')->columns(2)->schema([
                        ...collect(self::USAGE)->map(fn (string $label, string $key): TextEntry => self::entry('usage_'.$key, $label, number_format($report[$key])))->values()->all(),
                        self::entry('push_rate', 'نسبة قبول مزود Push', self::percent($report['push_provider_acceptance_rate_percentage'])),
                    ]),
                ]),
            ])->columnSpanFull(),
        ]);
    }

    public static function invoices(?array $report): Section
    {
        return Section::make('فواتير التشغيل المسجلة')->description('الفواتير النهائية حسب نهاية فترة الفاتورة دون توزيع تقديري على الطلاب')->schema($report === null ? [
            self::entry('cohort_invoices', 'نطاق الإتاحة', 'الفلاتر تمثل الطلاب وفئاتهم ومصادر إتاحتهم الحالية ولا تُنسب فواتير المنصة إلى هذا التجمع'),
        ] : [
            self::entry('no_invoices', 'الفواتير', 'لم تسجل فواتير لهذه الفترة بعد وهذا لا يعني أن التشغيل بلا تكلفة')->visible(! $report['has_invoices']),
            self::entry('known_invoices', 'قيمة مكتملة التسجيل وليست إجمالي كل الخدمات', self::money($report['known_total_egp']))->visible($report['has_invoices']),
            self::entry('missing_fx', 'فواتير تنتظر سعر التحويل', $report['missing_fx'])->visible($report['missing_fx'] > 0),
            ProductOperationsSchema::rows('invoices', collect($report['services'])->map(fn (array $row): array => ['label' => $row['label'], 'count' => $row['invoices'], 'actual' => self::money($row['actual_egp'])]), ['label' => 'الخدمة', 'count' => 'الفواتير', 'actual' => 'القيمة بالجنيه'])->visible($report['has_invoices']),
        ]);
    }

    public static function student(array $row): array
    {
        $result = ['name' => $row['user']->name ?? 'مستخدم محذوف', 'email' => $row['user']?->email ?: 'غير متاح',
            'courses' => $row['courses']->implode('، '), 'plans' => $row['plans']->implode('، '), 'sources' => $row['sources']->implode('، '),
            'payment_channels' => $row['payment_channels']->implode('، '), 'allocation' => $row['coin_allocation_complete'] ? 'مكتمل' : 'غير مكتمل',
            'ai_cost_usd' => self::usd($row['ai_cost_usd'], $row['ai_cost_complete']),
            'ai_failure_rate_percentage' => self::percent($row['ai_failure_rate_percentage']), 'push_provider_acceptance_rate_percentage' => self::percent($row['push_provider_acceptance_rate_percentage']),
            'cost_to_net_revenue_percentage' => self::percent($row['cost_to_net_revenue_percentage'])];
        foreach (self::MONEY as $key => $label) {
            $result[$key] = self::money($row[$key]);
        }
        foreach (self::USAGE as $key => $label) {
            $result[$key] = number_format($row[$key]);
        }
        foreach (CourseCostReportService::serviceLabels() as $key => $label) {
            $result['service_'.$key] = self::money($row['actual_cost_by_service_egp']->get($key));
        }

        return $result;
    }

    public static function studentFields(array $display): array
    {
        $fields = static fn (array $labels): array => collect($labels)->map(fn (string $label, string $key): TextEntry => self::entry($key, $label, filled($display[$key]) ? $display[$key] : 'غير متاح'))->values()->all();
        $serviceLabels = [];
        foreach (CourseCostReportService::serviceLabels() as $key => $label) {
            $serviceLabels['service_'.$key] = $label;
        }

        return [
            Section::make('الطالب والكورسات')->schema($fields(['name' => 'الطالب', 'email' => 'البريد', 'courses' => 'الكورسات'])),
            Section::make('الدخل والتكلفة')->columns(['default' => 2])->schema($fields(self::MONEY + ['cost_to_net_revenue_percentage' => 'التكلفة من الصافي'])),
            Section::make('تفاصيل الإتاحة')->collapsible()->collapsed()->schema($fields(['plans' => 'الفئات الحالية', 'sources' => 'مصادر الإتاحة', 'payment_channels' => 'قنوات الشحن', 'allocation' => 'ربط الدفتر'])),
            Section::make('الاستهلاك')->collapsible()->collapsed()->columns(['default' => 2])->schema($fields(self::USAGE + [
                'ai_cost_usd' => 'OpenRouter مؤكد USD', 'ai_failure_rate_percentage' => 'نسبة فشل AI', 'push_provider_acceptance_rate_percentage' => 'نسبة قبول مزود Push',
            ])),
            Section::make('تكلفة كل خدمة')->collapsible()->collapsed()->columns(['default' => 2])->schema($fields($serviceLabels)),
        ];
    }

    private static function serviceUsage(array $row, array $report): string
    {
        return match ($row['key']) {
            'openrouter' => number_format($row['requests']).' ناجح · '.number_format($row['failed_requests']).' فاشل · '.number_format($report['ai_unanswered_requests']).' بلا نتيجة · '.number_format($row['units']).' توكن · '.self::usd($row['cost_usd'], $report['ai_cost_complete']),
            'bunny_delivery' => number_format($row['minutes'], 0).' دقيقة',
            'notifications' => $row['in_app_notifications'].' داخل التطبيق · '.$row['push_attempts'].' محاولة Push · '.$row['push_provider_accepted'].' قبله المزود · '.self::percent($row['push_provider_acceptance_rate_percentage']),
            default => 'فواتير نهائية مسجلة دون توزيع على الطلاب',
        };
    }

    public static function money(mixed $value): string
    {
        return $value === null ? 'غير مكتمل' : number_format($value, 2).' ج.م';
    }

    public static function percent(mixed $value): string
    {
        return $value === null ? 'غير متاح' : number_format($value, 2).'%';
    }

    public static function usd(mixed $value, bool $complete, bool $available = true): string
    {
        return ! $available || $value === null ? 'غير متاح' : (! $complete && (float) $value === 0.0 ? 'بانتظار التأكيد' : '$'.number_format($value, 6).($complete ? '' : ' · مؤكد جزئيًا'));
    }

    private static function entry(string $key, string $label, mixed $value): TextEntry
    {
        return TextEntry::make($key)->label($label)->state($value);
    }
}
