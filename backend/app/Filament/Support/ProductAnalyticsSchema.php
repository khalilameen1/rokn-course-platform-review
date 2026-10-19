<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\BusinessClock;
use App\Support\PaymentReportPresentation;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/** Presentation of the existing analytics contract using native, responsive infolist components. */
final class ProductAnalyticsSchema
{
    public const EVENTS = [
        'course_impression' => 'ظهر الكورس', 'course_opened' => 'فتح التفاصيل',
        'sample_started' => 'بدأ العينة', 'sample_completed' => 'أكمل العينة', 'paywall_viewed' => 'فتح نافذة الاشتراك',
        'earn_tasks_opened' => 'فتح مهام العملات', 'checkout_quoted' => 'طلب تفاصيل الاشتراك', 'purchase_started' => 'بدأ الشراء',
        'purchase_completed' => 'اكتمل الشراء', 'project_submitted' => 'سلّم مشروعًا', 'project_passed' => 'اجتاز مشروعًا', 'certificate_issued' => 'صدرت شهادة',
    ];

    public static function configure(Schema $schema, array $report): Schema
    {
        $analytics = $report['analytics'];
        $ai = $analytics['ai'];
        $invoice = $report['invoiceReport'];
        $channels = $report['paymentChannelReport'];

        $report['analytics']['purchase_funnel']['steps'] = collect($analytics['purchase_funnel']['steps'])->map(fn (array $row): array => array_replace($row, [
            'conversion_from_previous' => self::percentValue($row['conversion_from_previous']),
            'conversion_from_entry' => self::percentValue($row['conversion_from_entry']),
        ]))->all();
        $report['analytics']['lesson_completion']['rows'] = $analytics['lesson_completion']['rows']->map(fn (array $row): array => array_replace($row, [
            'completion_rate' => self::percentValue($row['completion_rate']),
            'archive' => $row['lesson_archived'] ? 'نسخة سابقة أو درس لم يعد متاحًا' : ($row['course_archived'] ? 'كورس مؤرشف' : 'درس حالي'),
        ]));
        $report['analytics']['activity'] = collect($analytics['activity'])->map(fn (array $row): array => array_replace($row, ['change' => self::change($row['change'])]))->all();
        if ($channels !== null) {
            $report['paymentChannelReport']['rows'] = collect($channels['rows'])->map(function (array $row): array {
                foreach (['gross', 'fee', 'net'] as $kind) {
                    $detail = PaymentReportPresentation::detail($row, $kind);
                    $row['amount_'.$kind] = PaymentReportPresentation::amount($row, $kind).($detail ? ' · '.$detail : '');
                }

                return $row;
            })->all();
        }
        if ($invoice !== null) {
            $report['invoiceReport']['services'] = collect($invoice['services'])->map(fn (array $row): array => array_replace($row, [
                'actual_egp' => $row['actual_egp'] === null ? 'غير مكتملة' : number_format($row['actual_egp'], 2),
            ]))->all();
        }

        return $schema->state($report)->components([
            Section::make('الاستخدام المرصود')->columns(['default' => 2, 'md' => 3])->schema([
                ...self::numbers(['analytics.quality.actors' => 'أشخاص', 'analytics.quality.sessions' => 'جلسات', 'analytics.quality.events' => 'أحداث']),
                ...collect(['actors' => 'تغير الأشخاص', 'sessions' => 'تغير الجلسات', 'events' => 'تغير الأحداث'])
                    ->map(fn (string $label, string $key): TextEntry => TextEntry::make('change_'.$key)->label($label)->state(self::change($analytics['changes'][$key])))->values()->all(),
            ]),
            Tabs::make('analytics-sections')->persistTabInQueryString('tab')->tabs([
                Tab::make('التعلّم')->schema([
                    Section::make('مسار شراء الكورس المرصود')
                        ->description('طلبوا تفاصيل الاشتراك ثم بدأوا الشراء وأكملوه لنفس الكورس خلال '.$analytics['purchase_funnel']['window_days'].' يومًا')->schema([
                            self::rows('analytics.purchase_funnel.steps', [
                                self::event(), ...self::numbers(['actors' => 'هويات مميزة', 'not_reached_yet' => 'لم يصلوا بعد']),
                                self::percent('conversion_from_previous', 'من الخطوة السابقة'), self::percent('conversion_from_entry', 'من بداية المسار'),
                            ]),
                            TextEntry::make('analytics.purchase_funnel.pending_actors')->label('ما زال وقت التحويل متاحًا لهم')->numeric(),
                            Section::make('كيف يُقرأ مسار الشراء')->collapsible()->collapsed()->schema([
                                TextEntry::make('funnel_definition')->hiddenLabel()->state('المراحل من طلبات السيرفر ولا تشمل الترقية أو أحداث الشراء القديمة من التطبيق  طلب التفاصيل لا يثبت مشاهدة النافذة  كل حساب هوية واحدة ولا نربط الزائر بحساب دون دليل  الأحداث في نفس الثانية لا تحدد ترتيبًا أدق  التحصيل من تقرير المدفوعات'),
                            ]),
                        ]),
                    Section::make('إكمال الدروس المرصود')
                        ->description('بدأوا المتابعة خلال الفترة وبلغوا حد الإكمال خلال '.$analytics['lesson_completion']['window_days'].' يومًا')->schema([
                            self::rows('analytics.lesson_completion.rows', [
                                TextEntry::make('course_title')->label('الكورس'), TextEntry::make('lesson_title')->label('الدرس'),
                                TextEntry::make('archive')->label('حالة الدرس'),
                                ...self::numbers(['starts' => 'بدأوا المتابعة', 'completions' => 'أكملوا', 'not_completed' => 'لم يكملوا بعد', 'pending' => 'ما زال الوقت متاحًا']),
                                self::percent('completion_rate', 'نسبة الإكمال'),
                            ])->visible($analytics['lesson_completion']['rows']->isNotEmpty()),
                            TextEntry::make('no_lessons')->hiddenLabel()->state('لا توجد بدايات متابعة مرصودة في هذه الفترة')->visible($analytics['lesson_completion']['rows']->isEmpty()),
                            Section::make('كيف يُقرأ إكمال الدروس')->collapsible()->collapsed()->schema([
                                TextEntry::make('completion_definition')->hiddenLabel()->state('كل طالب مرة واحدة لكل درس من وقت مشاهدة يقبله الباك وليس فتح المشغل  مرتب حسب أكبر عدد لم يكمل بعد ولا يعني أنهم تركوا الكورس نهائيًا  لا نستنتج بدايات قديمة من سجل التقدم ولا نضم مشاهدة العينة'),
                            ]),
                            TextEntry::make('lesson_limit')->hiddenLabel()->state('عرض أكثر '.$analytics['lesson_completion']['row_limit'].' درسًا من أصل '.$analytics['lesson_completion']['total_lessons'])
                                ->visible($analytics['lesson_completion']['total_lessons'] > $analytics['lesson_completion']['row_limit']),
                        ]),
                ]),
                Tab::make('النشاط')->schema([
                    Section::make('نشاط الاستخدام')->schema([
                        self::rows('analytics.activity', [self::event(), ...self::numbers(['total' => 'أحداث', 'unique_actors' => 'أشخاص']),
                            TextEntry::make('change')->label('تغير الأحداث')]),
                    ]),
                    Section::make('مصدر الوصول والشراء')->schema([
                        self::rows('analytics.attribution', [TextEntry::make('source')->label('المصدر'), TextEntry::make('campaign')->label('الحملة')->default('غير منسوب'), self::event(), ...self::numbers(['actors' => 'أشخاص'])]),
                        TextEntry::make('no_attribution')->hiddenLabel()->state('لا توجد أحداث منسوبة في هذه الفترة')->visible($analytics['attribution']->isEmpty()),
                    ]),
                    Section::make('دفعات الاكتساب')->schema([
                        self::rows('cohorts', [TextEntry::make('date')->label('أول ظهور'), ...self::numbers(['actors' => 'أشخاص جدد'])])->state($analytics['cohorts']->reverse()->take(31)->values()->all()),
                        TextEntry::make('no_cohorts')->hiddenLabel()->state('لا توجد بيانات اكتساب في هذه الفترة')->visible($analytics['cohorts']->isEmpty()),
                    ]),
                    Section::make('جودة القياس')->columns(2)->schema([
                        ...self::numbers(['analytics.quality.events' => 'الأحداث', 'analytics.quality.anonymous_events' => 'قبل تسجيل الدخول', 'analytics.quality.campaign_events' => 'منسوبة لحملة']),
                        TextEntry::make('received')->label('آخر وصول')->state(BusinessClock::format($analytics['quality']['last_received_at']) ?: 'غير متاح'),
                    ]),
                ]),
                Tab::make('التكلفة')->schema([
                    Section::make('تكلفة الذكاء الاصطناعي')->columns(2)->schema([
                        TextEntry::make('ai_unavailable')->hiddenLabel()->state('القياس غير متاح في قاعدة البيانات الحالية')->visible(! $ai['available']),
                        ...self::numbers(['analytics.ai.completed_requests' => 'ردود مكتملة', 'analytics.ai.unanswered_requests' => 'محاولات بلا إجابة مسلّمة',
                            'analytics.ai.failed_requests' => 'محاولات فاشلة أو ملغاة', 'analytics.ai.tokens' => 'التوكنز']),
                        TextEntry::make('confirmed_ai_cost')->label('الاستهلاك المؤكد بالدولار')->state($ai['cost_usd'] === null || (! $ai['cost_complete'] && $ai['provider_cost_requests'] === 0 && $ai['cost_usd'] == 0) ? 'غير متاح' : number_format($ai['cost_usd'], 6)),
                        TextEntry::make('ai_change')->label('تغير الاستهلاك')->state(self::change($analytics['changes']['cost_usd'])),
                        TextEntry::make('ai_measurement')->hiddenLabel()->columnSpanFull()->state($ai['cost_complete'] ? 'القياس مؤكد من المزود أو من إجابة محفوظة بلا تكلفة' : 'قياس جزئي · '.$ai['estimated_cost_requests'].' طلبًا بلا تكلفة مؤكدة ولا يدخل تقديره في المبلغ'),
                    ]),
                    Section::make('فواتير التشغيل المسجلة')->visible($invoice !== null)->schema([
                        TextEntry::make('invoice_basis')->hiddenLabel()->state('فواتير نهائية حسب نهاية فترة الفاتورة دون توزيع تقديري على الأيام أو الطلاب'),
                        TextEntry::make('no_invoices')->hiddenLabel()->state('لم تسجل فواتير لهذه الفترة بعد وهذا لا يعني أن التشغيل بلا تكلفة')->visible(! ($invoice['has_invoices'] ?? false)),
                        TextEntry::make('invoice_total')->label('القيمة المكتملة بالجنيه')->state(number_format($invoice['known_total_egp'] ?? 0, 2))->visible($invoice['has_invoices'] ?? false),
                        TextEntry::make('missing_fx')->label('فواتير تنتظر سعر تحويل')->state($invoice['missing_fx'] ?? 0)->visible(($invoice['missing_fx'] ?? 0) > 0),
                        self::rows('invoiceReport.services', [TextEntry::make('label')->label('الخدمة'), ...self::numbers(['invoices' => 'الفواتير']),
                            TextEntry::make('actual_egp')->label('القيمة بالجنيه')])->visible($invoice['has_invoices'] ?? false),
                    ]),
                ]),
                Tab::make('التحصيل')->schema([
                    TextEntry::make('course_cash')->hiddenLabel()->state('تحصيل باقات العملات على مستوى المنصة لا يُنسب إلى كورس بعينه')->visible($channels === null),
                    Section::make('الإجمالي بالجنيه')->visible($channels !== null)->columns(['default' => 1, 'md' => 3])
                        ->description('لا تُجمع العملات المختلفة أو العمليات التي لم تصل عملتها مع الجنيه')->schema(array_map(
                            fn (string $kind, string $label): TextEntry => TextEntry::make('total_'.$kind)->label($label)
                                ->state($channels !== null ? PaymentReportPresentation::amount(PaymentReportPresentation::egp($channels), $kind) : '')
                                ->helperText($channels !== null ? PaymentReportPresentation::detail(PaymentReportPresentation::egp($channels), $kind) : null),
                            ['gross', 'fee', 'net'], ['إجمالي مؤكد', 'رسوم مؤكدة', 'صافي مؤكد'],
                        )),
                    Section::make('التحصيل حسب القناة')->visible($channels !== null)->description('الاختبار والتقديرات لا يدخلان في المبالغ المؤكدة')->schema([
                        self::rows('paymentChannelReport.rows', [TextEntry::make('label')->label('القناة'), TextEntry::make('currency')->label('العملة'),
                            ...self::numbers(['live_count' => 'عمليات حقيقية', 'live_coins' => 'عملات مُصدرة', 'test_count' => 'عمليات اختبار', 'test_coins' => 'عملات الاختبار']),
                            ...array_map(fn (string $kind, string $label): TextEntry => TextEntry::make('amount_'.$kind)->label($label), ['gross', 'fee', 'net'], ['إجمالي مؤكد', 'رسوم مؤكدة', 'صافي مؤكد']),
                        ]),
                        TextEntry::make('gross_change')->label('تغير التحصيل')->state(self::change($report['paymentChanges']['gross'])),
                        TextEntry::make('net_change')->label('تغير الصافي')->state(self::change($report['paymentChanges']['net'])),
                    ]),
                ]),
            ])->columnSpanFull(),
        ])->columns(1);
    }

    private static function numbers(array $fields): array
    {
        return collect($fields)->map(fn (string $label, string $path): TextEntry => TextEntry::make($path)->label($label)->default('غير متاح')->numeric())->values()->all();
    }

    private static function event(): TextEntry
    {
        return TextEntry::make('event')->label('الخطوة')->columnSpanFull()->formatStateUsing(fn (string $state): string => self::EVENTS[$state] ?? $state);
    }

    private static function percent(string $path, string $label): TextEntry
    {
        return TextEntry::make($path)->label($label);
    }

    private static function percentValue(?float $value): string
    {
        return $value === null ? 'غير متاح' : number_format($value, 1).'%';
    }

    private static function rows(string $path, array $fields): RepeatableEntry
    {
        return RepeatableEntry::make($path)->hiddenLabel()->schema($fields)->columns(['default' => 2, 'md' => 3])->grid(['default' => 1, 'lg' => 2])
            ->visible(fn (RepeatableEntry $component): bool => filled($component->getState()));
    }

    public static function change(array $change): string
    {
        return match ($change['status'] ?? 'unavailable') {
            'new' => 'جديد مقارنة بالفترة السابقة',
            'unchanged' => 'دون تغير 0٪',
            'up', 'down' => ($change['status'] === 'up' ? 'ارتفاع ' : 'انخفاض ').($change['percentage'] == 0 ? 'أقل من 0٫1' : number_format(abs($change['percentage']), 1)).'٪ عن الفترة السابقة',
            default => 'المقارنة غير متاحة',
        };
    }
}
