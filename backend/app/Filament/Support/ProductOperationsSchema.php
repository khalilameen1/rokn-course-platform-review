<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Pages\OperationalRecovery;
use App\Support\BusinessClock;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/** Native Filament presentation only; all measurements remain owned by the operations read service. */
final class ProductOperationsSchema
{
    public static function configure(Schema $schema, array $report): Schema
    {
        $runtime = $report['runtime'];
        $counts = $report['counts'];
        $finance = $report['finance'];
        $readiness = $report['readiness'];
        $queues = collect($runtime['queues'])->map(fn (array $row, string $name): array => [
            'name' => $name, 'state' => $row['healthy'] ? 'يعمل' : 'متأخر',
            'waiting' => $row['size'] === null ? 'غير متاح' : number_format($row['size']), 'heartbeat' => self::date($row['last_heartbeat_at']),
        ])->values();
        $infrastructure = collect([
            'bunny.stream' => 'Bunny Stream', 'bunny.upload' => 'رفع الفيديو إلى Bunny', 'bunny.playback' => 'تشغيل الفيديو من CDN',
            'bunny.signing' => 'توقيع روابط التشغيل', 'bunny.assets' => 'صور وملفات Bunny',
            'payment.kashier' => 'الدفع عبر Kashier', 'payment.google_play' => 'الدفع عبر Google Play', 'payment.app_store' => 'الدفع عبر App Store',
            'ai' => 'Rokn AI', 'mail' => 'البريد التشغيلي', 'push' => 'إشعارات Firebase',
            'social.google' => 'تسجيل Google', 'social.facebook' => 'تسجيل Facebook', 'social.tiktok' => 'تسجيل TikTok', 'social.apple' => 'تسجيل Apple',
            'social.callbacks' => 'روابط عودة تسجيل الدخول', 'social.handoff' => 'حفظ جلسة تسجيل الدخول',
            'app_links.android' => 'فتح التطبيق على Android', 'app_links.apple' => 'فتح التطبيق على Apple',
            'queue' => 'عامل المهام Queue', 'recovery' => 'النسخ الاحتياطي والاستعادة',
        ])->map(function (string $label, string $path) use ($report): array {
            $capability = data_get($report['capabilityReport'], 'capabilities.'.$path);

            return ['name' => $label, 'state' => data_get($capability, 'required') === false ? 'غير معلن' : (data_get($capability, 'ready') ? 'الإعداد مكتمل' : 'ناقص'), 'reason' => data_get($capability, 'reason', 'لم يتم الفحص')];
        })->push(['name' => 'النسخ المطلوبة للتطبيق', 'state' => $report['mobileReleaseCapability']['ready'] ? 'الإعداد مكتمل' : 'ناقص', 'reason' => $report['mobileReleaseCapability']['reason']]);

        return $schema->components([
            Section::make('حالة التشغيل')->columns(['default' => 2, 'md' => 3])->schema([
                self::value('launch', 'الإطلاق الكامل', $report['launchReady'] ? 'جاهز للإطلاق' : 'غير جاهز')->badge()->color($report['launchReady'] ? 'success' : 'danger'),
                self::value('runtime_state', 'العامل والجدولة', $report['operationalIncidents']->isEmpty() && $report['runtimeHeartbeatFailures']->isEmpty() ? 'لا توجد أعطال مفتوحة' : 'التشغيل يحتاج متابعة'),
                ...self::metrics(['counts.pending_projects' => ['مشاريع تنتظر المراجعة', $counts['pending_projects']], 'counts.financial_anomalies' => ['تنبيهات مالية', $counts['financial_anomalies']],
                    'counts.media_attention' => ['تنبيهات الوسائط', $counts['media_attention']], 'clientFailuresLastDay' => ['أعطال التطبيق آخر 24 ساعة', $report['clientFailuresLastDay']]]),
            ]),
            Tabs::make('operations-sections')->persistTabInQueryString('tab')->tabs([
                Tab::make('التشغيل')->schema([
                    Section::make('العامل والجدولة')->collapsible()->collapsed()->schema([
                        self::value('scheduler_state', 'الجدولة', $runtime['scheduler']['healthy'] ? 'يعمل' : 'متوقف'),
                        self::value('scheduler_heartbeat', 'آخر نبض بتوقيت القاهرة', self::date($runtime['scheduler']['last_heartbeat_at'])),
                        self::rows('queues', $queues, ['name' => 'الطابور', 'state' => 'الحالة', 'waiting' => 'في الانتظار', 'heartbeat' => 'آخر نبض بتوقيت القاهرة']),
                    ]),
                    Section::make('الأعطال المفتوحة')->schema([
                        self::rows('incidents', $report['operationalIncidents']->map(fn ($row): array => [
                            'summary' => $row->summary, 'severity' => $row->severity === 'critical' ? 'حرج' : 'تنبيه', 'code' => $row->code,
                            'affected' => number_format($row->affected_count), 'first' => self::date($row->first_seen_at), 'last' => self::date($row->last_seen_at),
                        ]), ['summary' => 'العطل', 'severity' => 'الحالة', 'code' => 'الكود', 'affected' => 'الأثر', 'first' => 'بدأ بتوقيت القاهرة', 'last' => 'آخر رصد بتوقيت القاهرة']),
                    ]),
                    Section::make('المهام والإرسال')->columns(['default' => 2, 'md' => 3])->schema([
                        ...self::metrics(['failed_jobs' => ['مهام فاشلة', data_get($runtime, 'failed_jobs.failed_jobs', 0)],
                            'outbox_pending' => ['webhooks تنتظر', data_get($runtime, 'outbox.pending', 0)], 'outbox_blocked' => ['محجوب بالترتيب', data_get($runtime, 'outbox.blocked', 0)],
                            'outbox_skipped' => ['تم تجاوزها', data_get($runtime, 'outbox.skipped', 0)], 'outbox_failed' => ['webhooks فاشلة', data_get($runtime, 'outbox.failed', 0)],
                            'push_failed' => ['Push متعطل', data_get($runtime, 'notifications.failed_pushes', 0)],
                            'certificates_pending' => ['شهادات معلقة', data_get($runtime, 'certificates.pending_certificates', 0)], 'certificates_failed' => ['استعادة شهادة فشلت', data_get($runtime, 'certificates.failed_certificates', 0)],
                            'ai_stale' => ['AI معلق', data_get($runtime, 'ai.stale_messages', 0)],
                            'cleanup_failed' => ['تنظيف متعطل', data_get($runtime, 'cleanup.failed_files', 0) + data_get($runtime, 'cleanup.stale_files', 0) + data_get($runtime, 'cleanup.failed_bunny', 0)],
                            'payment_intervention' => ['دفع يحتاج تدخل', data_get($runtime, 'payment_callbacks.review_required', 0) + data_get($runtime, 'payment_callbacks.stalled_store_events', 0)],
                        ]),
                        self::value('recover', 'التعامل مع الفشل', 'استعادة المهام')->url(OperationalRecovery::getUrl())->color('primary'),
                        self::rows('failed_by_queue', data_get($runtime, 'failed_jobs.by_queue', []), ['queue' => 'الطابور', 'count' => 'مهام فاشلة'])->columnSpanFull(),
                    ]),
                    Section::make('حدود الاستخدام آخر 24 ساعة')->collapsible()->collapsed()->schema([
                        ...self::metrics(['limited_requests' => ['طلبات', data_get($runtime, 'rate_limits.last_24h', 0)], 'limited_actors' => ['مصادر', data_get($runtime, 'rate_limits.affected_actors', 0)]]),
                        self::rows('limited_routes', data_get($runtime, 'rate_limits.top_routes', []), ['route' => 'المسار', 'hits' => 'الطلبات', 'actors' => 'المصادر']),
                    ]),
                    Section::make('أعطال التطبيق الفعلية')->schema([
                        self::rows('client_failures', $report['recentClientFailures']->map(fn ($row): array => [
                            'user' => $row->user?->name ?: 'زائر', 'email' => $row->user?->email ?: 'غير متاح',
                            'release' => ($row->app_version ?: 'غير متاح').' · build '.($row->build_number ?: 'غير متاح'),
                            'place' => $row->endpoint ?: $row->screen_key ?: 'غير متاح', 'reason' => $row->error_code ?: 'UNKNOWN_ERROR',
                            'request' => $row->request_id ?: 'غير متاح', 'time' => self::date($row->occurred_at),
                        ]), ['user' => 'المستخدم', 'email' => 'البريد', 'release' => 'الإصدار', 'place' => 'المكان', 'reason' => 'السبب', 'request' => 'مرجع الطلب', 'time' => 'بتوقيت القاهرة']),
                    ]),
                ]),
                Tab::make('الوسائط')->schema([
                    Section::make('جاهزية الوسائط')->columns(['default' => 2, 'md' => 3])->description('صلاحية المشاهدة منفصلة عن تنبيهات بيانات الكورس والمقطع')->schema([
                        ...self::metrics(['counts.media_ready' => ['صالح للمشاهدة', $counts['media_ready']], 'media_attention' => ['تنبيهات', $counts['media_attention']], 'sessions_today' => ['جلسات اليوم', $counts['playback_sessions_today']]]),
                        self::rows('media', $report['mediaAttention']->map(fn ($row): array => [
                            'course' => $row->course?->name_ar ?: $row->course?->name_en ?: 'غير متاح', 'lesson' => $row->title,
                            'state' => match ($row->operations_playback_status) {
                                'ready' => 'جاهز', 'quarantined' => 'معزول للمراجعة', 'misconfigured' => 'مصدر غير مكتمل', 'processing' => 'قيد المعالجة', 'failed' => 'فشل التجهيز', default => 'غير جاهز'
                            },
                            'reason' => implode(' · ', (array) $row->operations_attention_reasons), 'probe' => self::date($row->mediaState?->last_probe_at),
                        ]), ['course' => 'الكورس', 'lesson' => 'المقطع', 'state' => 'التشغيل', 'reason' => 'سبب المتابعة', 'probe' => 'آخر فحص بتوقيت القاهرة'])->columnSpanFull(),
                    ]),
                    ...PlaybackOperationsSchema::summary($report['playbackOperations']),
                    Section::make('فحص سلامة الوسائط')->columns(2)->schema([
                        self::value('reconcile_state', 'الحالة', match (data_get($report, 'mediaReconcileStatus.state')) {
                            'running' => 'جارٍ الفحص', 'completed' => 'اكتمل', 'attention' => 'تحتاج متابعة', 'failed' => 'تعذر الفحص', default => 'لم يعمل الفحص الدوري بعد'
                        }),
                        self::value('reconcile_progress', 'الكورسات', data_get($report, 'mediaReconcileStatus.processed_courses', 0).' من '.data_get($report, 'mediaReconcileStatus.total_courses', 0)),
                        self::value('reconcile_finish', 'آخر انتهاء بتوقيت القاهرة', self::date(data_get($report, 'mediaReconcileStatus.finished_at'))),
                        ...self::metrics(['media_healthy' => ['سليم', data_get($report, 'mediaReconcileStatus.summary.healthy', 0)], 'media_review' => ['متابعة', data_get($report, 'mediaReconcileStatus.summary.attention', 0)], 'media_quarantine' => ['معزول', data_get($report, 'mediaReconcileStatus.summary.quarantined', 0)]]),
                    ]),
                ]),
                Tab::make('المنتج')->schema([
                    Section::make('المحتوى والطلاب')->columns(['default' => 2, 'md' => 3])->schema(self::metrics(collect([
                        'published' => 'الكورسات المنشورة', 'coming_soon' => 'بطاقات قريبًا', 'packages' => 'الباقات', 'reward_tasks' => 'مهام ربح فعالة',
                        'grants' => 'منح مؤسسية', 'grant_claims' => 'طلاب فعّلوا منحة', 'grant_upgrades' => 'ترقيات المسار الكامل',
                        'pending_projects' => 'مشاريع تنتظر المراجعة', 'certificates' => 'شهادات صادرة', 'certificates_pending' => 'شهادات معلقة',
                        'certificates_revoked' => 'شهادات ملغاة', 'portfolio_items' => 'أعمال Portfolio',
                    ])->map(fn (string $label, string $key): array => [$label, $counts[$key]])->all())),
                ]),
                Tab::make('الجاهزية')->schema([
                    Section::make('جاهزية التشغيل')->columns(2)->schema(collect([
                        'hero' => 'كورس رئيسي واحد', 'published_course' => 'كورس ظاهر فعليًا في التطبيق', 'auth_methods' => 'طرق الدخول المعلنة',
                        'packages' => 'باقات قابلة للشراء', 'reward_tasks' => 'مهام ربح فعالة', 'support' => 'دعم واتساب', 'external_monitoring' => 'Sentry وNightwatch مربوطان',
                    ])->map(fn (string $label, string $key): TextEntry => self::value('readiness_'.$key, $label, $readiness[$key] ? 'جاهز' : 'ناقص'))->values()->all()),
                    Section::make('جاهزية البنية')->description('وجود الإعداد لا يثبت عمل الخدمة')->schema([
                        self::rows('infrastructure', $infrastructure, ['name' => 'الخدمة', 'state' => 'الإعداد', 'reason' => 'التفاصيل']),
                    ]),
                    Section::make('آخر نجاح حقيقي رصدته المنصة')->schema([
                        self::rows('provider_evidence', collect($report['providerEvidence'])->map(fn (array $row): array => ['label' => $row['label'], 'success' => self::date($row['last_success_at'])]), ['label' => 'الخدمة', 'success' => 'نجاح مسجل بتوقيت القاهرة']),
                    ]),
                    Section::make('جاهزية النسخ والاستعادة')->description('دليل قراءة فقط ولا ينفذ نسخًا أو استعادة')->schema([
                        self::value('backup_state', 'الجاهزية', data_get($report, 'backupReadiness.ready') ? 'موثقة' : 'تحتاج استكمالًا'),
                        self::value('backup_provider', 'المزود', data_get($report, 'backupReadiness.provider') ?: 'لم يُحدد'),
                        self::rows('backup_checks', collect(data_get($report, 'backupReadiness.checks', []))->map(fn ($passed, string $key): array => [
                            'check' => ['runbook' => 'دليل التشغيل', 'provider' => 'مزود النسخ', 'recent_backup' => 'نسخة حديثة', 'recent_restore_drill' => 'تجربة استعادة', 'recovery_mode_cleared' => 'وضع التعافي مغلق'][$key] ?? $key,
                            'state' => $passed ? 'مكتمل' : 'ناقص',
                        ])->values(), ['check' => 'الفحص', 'state' => 'الحالة']),
                    ]),
                ]),
                Tab::make('التحصيل')->schema([
                    Section::make('فصل الإيراد عن المكافآت')->columns(2)->schema([
                        ...collect(['cash_revenue' => 'إجمالي التحصيل المؤكد بكل القنوات', 'cash_revenue_catalog_estimate' => 'تقدير الكتالوج خارج الإجمالي',
                            'confirmed_net_revenue' => 'الصافي المؤكد من كشوف التسوية', 'estimated_net_revenue' => 'الصافي الحالي يشمل التقديري'])
                            ->map(fn (string $label, string $key): TextEntry => self::value('finance_'.$key, $label, number_format($finance[$key], 2).' جنيه'))->values()->all(),
                        ...self::metrics(collect(['pending_settlements' => 'تنتظر كشف التسوية', 'course_paid_coins' => 'عملات مشتراة استُهلكت', 'course_reward_coins' => 'عملات مكافآت استُهلكت',
                            'course_ledger_incomplete_orders' => 'عمليات كورس تحتاج ربط الدفتر', 'grant_upgrade_paid_coins' => 'ترقيات المنح بعملات مدفوعة', 'grant_upgrade_reward_coins' => 'ترقيات المنح بمكافآت', 'refunds' => 'استرداد أو مراجعة'])
                            ->map(fn (string $label, string $key): array => [$label, $finance[$key]])->all()),
                    ]),
                    Section::make('مراجعة تكلفة الخدمات المدفوعة')->description('تُعزل مزايا التكلفة للطالب المخالف فقط لا بقية الطلاب')->schema([
                        self::rows('financial_anomalies', $report['financialAnomalies']->map(fn ($row): array => [
                            'student' => $row->user?->name ?: '#'.$row->user_id, 'email' => $row->user?->email ?: 'غير متاح',
                            'course' => $row->course?->name_ar ?: $row->course?->name_en ?: 'غير متاح', 'plan' => data_get($row->metadata, 'plan_code', 'غير متاح'),
                            'expected' => number_format($row->expected_paid_coins), 'actual' => number_format($row->actual_paid_coins),
                            'order' => $row->order?->order_ref ?: '#'.$row->order_id, 'time' => self::date($row->detected_at),
                        ]), ['student' => 'الطالب', 'email' => 'البريد', 'course' => 'الكورس', 'plan' => 'الفئة', 'expected' => 'المفروض مدفوع', 'actual' => 'الفعلي', 'order' => 'الطلب', 'time' => 'اكتُشف بتوقيت القاهرة']),
                    ]),
                    Section::make('مراجعة إشعارات المتاجر')->schema([
                        self::rows('store_reviews', $report['storeNotificationReviews']->map(fn ($row): array => [
                            'provider' => $row->provider === 'google_play' ? 'Google Play' : 'App Store', 'event' => $row->event_type ?: 'غير محدد',
                            'reference' => $row->event_id, 'reason' => $row->error_code ?: 'تحتاج قرارًا تشغيليًا', 'time' => self::date($row->received_at),
                        ]), ['provider' => 'المتجر', 'event' => 'الحدث', 'reference' => 'المرجع الآمن', 'reason' => 'سبب المراجعة', 'time' => 'الاستلام بتوقيت القاهرة']),
                    ]),
                ]),
            ])->columnSpanFull(),
        ])->columns(1);
    }

    public static function courseFields(): array
    {
        return collect(['name' => 'الكورس', 'state' => 'الحالة', 'price' => 'السعر', 'map' => 'الخريطة', 'students' => 'الطلاب', 'rating' => 'التقييم', 'coins' => 'مدفوعة / مكافآت', 'incomplete' => 'عمليات دفتر غير مكتملة', 'ai' => 'Rokn AI'])
            ->map(fn (string $label, string $key): TextEntry => TextEntry::make($key)->label($label))->values()->all();
    }

    private static function metrics(array $fields): array
    {
        return collect($fields)->map(fn (array $field, string $key): TextEntry => self::value($key, $field[0], number_format($field[1])))->values()->all();
    }

    private static function value(string $key, string $label, mixed $value): TextEntry
    {
        return TextEntry::make($key)->label($label)->state($value);
    }

    public static function rows(string $key, iterable $rows, array $fields): RepeatableEntry
    {
        $rows = collect($rows)->values()->all();

        return RepeatableEntry::make($key)->hiddenLabel()->state($rows ?: [['empty' => 'لا توجد حالات مسجلة']])->schema($rows
            ? collect($fields)->map(fn (string $label, string $path): TextEntry => TextEntry::make($path)->label($label)->default('غير متاح'))->values()->all()
            : [TextEntry::make('empty')->hiddenLabel()->columnSpanFull()])
            ->columns(['default' => 2, 'md' => 3])->grid(['default' => 1, 'lg' => 2]);
    }

    public static function date(mixed $value): string
    {
        return filled($value) ? (BusinessClock::format($value) ?: 'غير متاح') : 'لم يُرصد';
    }
}
