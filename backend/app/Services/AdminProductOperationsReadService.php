<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Certificate;
use App\Models\ClientEvent;
use App\Models\CoinEarningMethod;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\CourseGrantClaim;
use App\Models\FinancialAnomaly;
use App\Models\Order;
use App\Models\Package;
use App\Models\PortfolioItem;
use App\Models\ProjectSubmission;
use App\Models\Setting;
use App\Models\StudentNotification;
use App\Models\StoreNotificationEvent;
use App\Models\Lesson;
use App\Models\PlaybackSession;
use App\Models\OperationalIncident;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\Schema;

/** Read-only dashboard composition. Recovery commands are never executed by this report. */
final class AdminProductOperationsReadService
{
    public function __construct(
        private readonly ProductionCapabilityService $capabilities,
        private readonly AppReleasePolicyService $releasePolicy,
        private readonly CourseCatalogueQueryService $catalogue,
        private readonly PlaybackOperationsService $playbackOperationsService,
        private readonly OperationsReadinessService $operationsReadiness,
        private readonly ProductFeatureFlagService $productFeatureFlags,
        private readonly PaymentChannelReportService $paymentChannels,
        private readonly CourseFinancialLedgerReportService $financialLedger,
        private readonly ProviderOperationalEvidenceService $providerEvidenceService,
        private readonly OperationalRuntimeService $operationalRuntime,
    ) {
    }

    /** @return array<string, mixed> */
    public function report(): array
    {
        [$todayStart, $todayEnd] = BusinessClock::localDayRangeUtc(
            BusinessClock::now()->format('Y-m-d')
        );
        $courses = Course::query()
            ->withCount([
                'sections',
                'modules',
                'activeEnrollments',
                'ratings',
                'accessPlans as ai_plans_count' => fn ($query) => $query
                    ->where(function ($plans): void {
                        $plans->where('chat_enabled', true)
                            ->orWhereIn('project_feedback_level', ['report', 'enhanced']);
                    }),
            ])
            ->withAvg('ratings', 'rating')
            ->orderByDesc('is_main_course')
            ->orderByDesc('id')
            ->get();
        $courseCoinSummaries = $this->financialLedger->courseSummaries(collect($courses->modelKeys()));
        $courses->each(function (Course $course) use ($courseCoinSummaries): void {
            $summary = $courseCoinSummaries->get((int) $course->id, []);
            $course->setAttribute('total_coins_spent', (int) ($summary['total_coins'] ?? 0));
            $course->setAttribute('paid_coins_spent', (int) ($summary['paid_coins'] ?? 0));
            $course->setAttribute('reward_coins_spent', (int) ($summary['reward_coins'] ?? 0));
            $course->setAttribute(
                'coin_ledger_incomplete_orders',
                (int) ($summary['incomplete_orders'] ?? 0)
            );
        });

        $settings = Setting::query()->first() ?? new Setting();
        $capabilityReport = $this->capabilities->report();
        $mobileRelease = $this->releasePolicy->launchReadiness();
        $missingReleaseChannels = collect($mobileRelease['required_channels'] ?? [])
            ->reject(fn (string $channel): bool => (bool) data_get($mobileRelease, "channels.{$channel}.ready"))
            ->map(fn (string $channel): string => match ($channel) {
                AppReleasePolicyService::CHANNEL_DIRECT => 'نسخة APK المباشرة',
                AppReleasePolicyService::CHANNEL_PLAY => 'نسخة Google Play',
                AppReleasePolicyService::CHANNEL_APP_STORE => 'نسخة App Store',
                default => $channel,
            })
            ->values();
        $mobileReleaseCapability = [
            'ready' => (bool) ($mobileRelease['ready'] ?? false),
            'reason' => (bool) ($mobileRelease['ready'] ?? false)
                ? 'توجد نسخة فعالة لكل قناة إصدار معلنة'
                : ($missingReleaseChannels->isEmpty()
                    ? 'لا توجد قناة إصدار معلنة'
                    : 'لا توجد نسخة فعالة وصالحة: '.$missingReleaseChannels->implode(' و')),
        ];
        $readiness = [
            'hero' => Course::query()->where('is_main_course', true)->count() === 1,
            // Measure the public boundary itself. A published database row can
            // still be hidden or malformed and therefore absent from the app.
            'published_course' => $this->catalogue->constrainPublic(Course::query())->exists(),
            'auth_methods' => (bool) data_get($capabilityReport, 'capabilities.social.ready'),
            'packages' => Package::query()->where('price', '>', 0)->where('coins', '>', 0)->exists(),
            'reward_tasks' => CoinEarningMethod::query()->active()->exists(),
            'support' => filled($settings->support_whatsapp_url),
            'external_monitoring' => filled(config('sentry.dsn'))
                && (bool) config('nightwatch.enabled')
                && filled(config('nightwatch.token')),
        ];

        $grantUpgradeOrders = Order::query()
            ->financiallyEffective()
            ->where(function ($upgrades): void {
                $upgrades->where('notes', 'like', 'Full-track upgrade from grant order #%')
                    ->orWhereHas('parentOrder.courseCode', function ($codes): void {
                        $codes->where('is_grant', true)
                            ->orWhereNotNull('allowed_email_domains');
                    });
            })
            ->get();
        $grantUpgradeAllocations = $this->financialLedger->allocationsForOrders(
            $grantUpgradeOrders
        );

        $hasIntegrityState = Schema::hasTable('lesson_media_states')
            && Schema::hasColumn('lesson_media_states', 'integrity_status');
        $mediaAttentionQuery = Lesson::query()
            ->where(function ($lessons) use ($hasIntegrityState): void {
                $lessons
                    ->whereNull('video_source_type')
                    ->orWhere('video_source_type', '<>', 'bunny')
                    ->orWhereNull('bunny_video_id')
                    ->orWhere('bunny_video_id', '')
                    ->orWhereRaw("TRIM(COALESCE(lessons.bunny_video_id, '')) = ''")
                    ->orWhereDoesntHave('mediaState')
                    ->orWhereHas('mediaState', function ($state) use ($hasIntegrityState): void {
                        $state->where(function ($health) use ($hasIntegrityState): void {
                            $health
                                ->whereIn('status', ['unknown', 'processing', 'failed'])
                                ->orWhereNull('last_reconciled_at')
                                ->orWhereNull('duration_seconds')
                                ->orWhere('duration_seconds', '<=', 0)
                                ->orWhereNotNull('quarantined_at')
                                ->orWhereRaw(
                                    "LOWER(TRIM(COALESCE(lesson_media_states.provider_media_id, ''))) <> LOWER(TRIM(COALESCE(lessons.bunny_video_id, '')))"
                                );
                            if ($hasIntegrityState) {
                                $health->orWhereIn('integrity_status', ['attention', 'quarantined']);
                            }
                        });
                    });
            });
        $mediaReadyCount = 0;
        Lesson::query()
            ->select(['id', 'video_source_type', 'bunny_video_id'])
            ->where('video_source_type', 'bunny')
            ->whereNotNull('bunny_video_id')
            ->where('bunny_video_id', '<>', '')
            ->whereRaw("TRIM(COALESCE(lessons.bunny_video_id, '')) <> ''")
            ->with(['mediaState' => fn ($state) => $state->select([
                'id',
                'lesson_id',
                'provider_media_id',
                'status',
                'duration_seconds',
                'integrity_status',
                'integrity_issues',
                'last_reconciled_at',
                'quarantined_at',
            ])])
            ->whereHas('mediaState', function ($state) use ($hasIntegrityState): void {
                $state->where('status', 'ready')
                    ->whereNotNull('last_reconciled_at')
                    ->whereRaw(
                        "LOWER(TRIM(COALESCE(lesson_media_states.provider_media_id, ''))) = LOWER(TRIM(COALESCE(lessons.bunny_video_id, '')))"
                    );
                if ($hasIntegrityState) {
                    $state->where(function ($integrity): void {
                        $integrity->whereNull('integrity_status')
                            ->orWhere('integrity_status', '<>', 'quarantined');
                    });
                }
            })
            ->chunkById(250, function ($lessons) use (&$mediaReadyCount): void {
                $mediaReadyCount += $lessons
                    ->filter(fn (Lesson $lesson): bool => $this->mediaIsVerifiedForPlayback($lesson))
                    ->count();
            });

        $issuedCertificates = Certificate::query()
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', 'active');
            })
            ->whereNull('revoked_at')
            ->whereNotNull('image_path')
            ->where('image_path', '<>', '')
            ->where('image_path', '<>', 'pending');
        $pendingCertificates = Certificate::query()
            ->where(function ($query): void {
                $query->whereNull('status')->orWhere('status', 'active');
            })
            ->whereNull('revoked_at')
            ->where('image_path', 'pending');
        $revokedCertificates = Certificate::query()
            ->where(function ($query): void {
                $query->where('status', 'revoked')->orWhereNotNull('revoked_at');
            });

        $counts = [
            'courses' => $courses->count(),
            'published' => $courses->where('is_coming_soon', false)->count(),
            'coming_soon' => $courses->where('is_coming_soon', true)->where('is_catalog_visible', true)->count(),
            'packages' => Package::query()->count(),
            'reward_tasks' => CoinEarningMethod::query()->active()->count(),
            'grants' => CourseCode::query()
                ->where(function ($query): void {
                    $query->where('is_grant', true)
                        ->orWhereNotNull('allowed_email_domains');
                })
                ->count(),
            'grant_claims' => CourseGrantClaim::query()->count(),
            'grant_upgrades' => $grantUpgradeOrders->count(),
            'pending_projects' => ProjectSubmission::query()->where('review_status', ProjectSubmission::STATUS_PENDING)->count(),
            'certificates' => $issuedCertificates->count(),
            'certificates_pending' => $pendingCertificates->count(),
            'certificates_revoked' => $revokedCertificates->count(),
            'portfolio_items' => PortfolioItem::query()->count(),
            'notifications' => StudentNotification::query()->count(),
            'media_ready' => $mediaReadyCount,
            'media_attention' => (clone $mediaAttentionQuery)->count(),
            'playback_sessions_today' => PlaybackSession::query()
                ->where('started_at', '>=', $todayStart)
                ->where('started_at', '<', $todayEnd)
                ->count(),
            'financial_anomalies' => Schema::hasTable('financial_anomalies')
                ? FinancialAnomaly::query()->where('status', FinancialAnomaly::STATUS_OPEN)->count()
                : 0,
            'store_notification_reviews' => Schema::hasTable('store_notification_events')
                ? StoreNotificationEvent::query()
                    ->where('status', StoreNotificationEvent::STATUS_REVIEW_REQUIRED)
                    ->count()
                : 0,
        ];

        $financialAnomalies = Schema::hasTable('financial_anomalies')
            ? FinancialAnomaly::query()
                ->with([
                    'user:id,name,email',
                    'course:id,name_ar,name_en',
                    'order:id,order_ref',
                ])
                ->where('status', FinancialAnomaly::STATUS_OPEN)
                ->latest('detected_at')
                ->limit(20)
                ->get()
            : collect();

        $storeNotificationReviews = Schema::hasTable('store_notification_events')
            ? StoreNotificationEvent::query()
                ->where('status', StoreNotificationEvent::STATUS_REVIEW_REQUIRED)
                ->latest('received_at')
                ->limit(20)
                ->get()
            : collect();

        $mediaAttention = (clone $mediaAttentionQuery)
            ->with(['course:id,name_ar,name_en', 'mediaState'])
            ->orderByDesc('updated_at')
            ->limit(20)
            ->get();
        $mediaAttention->each(function (Lesson $lesson): void {
            $state = $lesson->mediaState;
            $playbackStatus = match (true) {
                $this->mediaIsVerifiedForPlayback($lesson) => 'ready',
                !$lesson->usesBunnyVideo() => 'misconfigured',
                $state?->integrity_status === 'quarantined', $state?->quarantined_at !== null => 'quarantined',
                in_array((string) $state?->status, ['processing', 'failed'], true) => (string) $state->status,
                default => 'not_ready',
            };
            $lesson->setAttribute('operations_playback_status', $playbackStatus);
            $lesson->setAttribute(
                'operations_attention_reasons',
                $this->mediaAttentionReasons($lesson)
            );
        });

        $paymentChannelReport = $this->paymentChannels->summary();
        $finance = [
            'cash_revenue' => (float) $paymentChannelReport['egp']['confirmed_gross_amount'],
            'cash_revenue_catalog_estimate' => (float) $paymentChannelReport['egp']['catalog_estimated_gross_amount'],
            'confirmed_net_revenue' => (float) $paymentChannelReport['egp']['confirmed_net_amount'],
            'estimated_net_revenue' => (float) $paymentChannelReport['egp']['estimated_net_amount'],
            'pending_settlements' => (int) $paymentChannelReport['egp']['pending_settlement_count'],
            'course_coins' => (int) $courses->sum('total_coins_spent'),
            'course_paid_coins' => (int) $courses->sum('paid_coins_spent'),
            'course_reward_coins' => (int) $courses->sum('reward_coins_spent'),
            'course_ledger_incomplete_orders' => (int) $courses->sum(
                'coin_ledger_incomplete_orders'
            ),
            'refunds' => Order::query()->whereIn('financial_status', [
                Order::FINANCIAL_REFUNDED,
                Order::FINANCIAL_CHARGEBACK,
                Order::FINANCIAL_REVERSED,
                Order::FINANCIAL_PARTIALLY_RECOVERED,
                Order::FINANCIAL_REVIEW_REQUIRED,
            ])->count(),
            'grant_upgrade_paid_coins' => (int) $grantUpgradeAllocations->sum('paid_coins'),
            'grant_upgrade_reward_coins' => (int) $grantUpgradeAllocations->sum('reward_coins'),
        ];

        $playbackOperations = $this->playbackOperationsService->snapshot(12);
        $mediaReconcileStatus = $this->operationsReadiness->mediaReconcileStatus();
        $backupReadiness = $this->operationsReadiness->backupReadiness();
        $featureFlags = $this->productFeatureFlags->operationalSnapshot();
        $providerEvidence = $this->providerEvidenceService->report();
        $runtime = $this->operationalRuntime->snapshot();
        $runtimeHeartbeatFailures = collect(data_get($runtime, 'queues', []))
            ->filter(fn (array $queue): bool => !(bool) ($queue['healthy'] ?? false))
            ->keys()
            ->values();
        if (!(bool) data_get($runtime, 'scheduler.healthy')) {
            $runtimeHeartbeatFailures->prepend('scheduler');
        }
        $launchReady = (bool) data_get($capabilityReport, 'ready')
            && (bool) ($mobileRelease['ready'] ?? false)
            && $readiness['external_monitoring'];
        $operationalIncidents = Schema::hasTable('operational_incidents')
            ? OperationalIncident::query()
                ->where('status', OperationalIncident::STATUS_OPEN)
                ->orderByRaw("CASE WHEN severity = 'critical' THEN 0 ELSE 1 END")
                ->orderBy('first_seen_at')
                ->limit(50)
                ->get()
            : collect();
        $recentClientFailures = Schema::hasTable('client_events')
            ? ClientEvent::query()
                ->with('user:id,name,email')
                ->whereIn('severity', ['error', 'fatal'])
                ->latest('occurred_at')
                ->limit(20)
                ->get()
            : collect();
        $clientFailuresLastDay = Schema::hasTable('client_events')
            ? ClientEvent::query()
                ->whereIn('severity', ['error', 'fatal'])
                ->where('occurred_at', '>=', now()->subDay())
                ->count()
            : 0;

        return compact(
            'courses', 'settings', 'readiness', 'counts', 'finance', 'capabilityReport', 'mediaAttention',
            'playbackOperations', 'mediaReconcileStatus', 'backupReadiness', 'featureFlags',
            'financialAnomalies', 'paymentChannelReport', 'storeNotificationReviews', 'providerEvidence',
            'runtime', 'operationalIncidents', 'runtimeHeartbeatFailures', 'launchReady',
            'mobileReleaseCapability', 'recentClientFailures', 'clientFailuresLastDay'
        );
    }

    private function mediaIsVerifiedForPlayback(Lesson $lesson): bool
    {
        $state = $lesson->mediaState;

        return $lesson->hasReadyMediaState()
            && $state !== null
            && $state->quarantined_at === null
            && (int) $state->duration_seconds > 0
            && !$state->hasBlockingIntegrityIssue();
    }

    /** @return array<int, string> */
    private function mediaAttentionReasons(Lesson $lesson): array
    {
        $labels = [
            'course_cover_missing' => 'غلاف الكورس غير مكتمل',
            'course_missing' => 'الكورس المرتبط غير موجود',
            'missing_secure_source' => 'مصدر الفيديو غير مكتمل',
            'media_generation_changed_during_probe' => 'تم استبدال الفيديو أثناء الفحص',
            'provider_media_missing' => 'الفيديو غير موجود لدى مزود البث',
            'provider_guid_mismatch' => 'هوية الفيديو لا تطابق المقطع',
            'provider_library_mismatch' => 'الفيديو مرتبط بمكتبة أخرى',
            'provider_encode_failed' => 'فشلت معالجة الفيديو',
            'provider_unreachable' => 'تعذر الوصول إلى مزود البث',
            'provider_still_processing' => 'الفيديو ما زال قيد المعالجة',
            'duration_missing' => 'مدة الفيديو غير متاحة',
            'duration_mismatch' => 'مدة الفيديو تحتاج مراجعة',
            'quality_ladder_missing' => 'جودات العرض لم تكتمل',
            'thumbnail_unverified' => 'صورة المقطع غير متاحة',
            'thumbnail_delivery_unavailable' => 'تعذر تحميل صورة المقطع',
            'signed_manifest_unavailable' => 'رابط تشغيل الفيديو غير متاح',
            'manifest_http_error' => 'ملف تشغيل الفيديو غير متاح',
            'manifest_invalid' => 'ملف تشغيل الفيديو غير صالح',
            'manifest_unreachable' => 'تعذر فحص ملف تشغيل الفيديو',
        ];

        $reasons = collect((array) $lesson->mediaState?->integrity_issues)
            ->map(fn ($issue): ?string => is_array($issue)
                ? ($labels[(string) ($issue['code'] ?? '')] ?? null)
                : null)
            ->filter()
            ->unique()
            ->values();

        if ($reasons->isEmpty()) {
            $fallback = match ((string) $lesson->getAttribute('operations_playback_status')) {
                'misconfigured' => 'مصدر الفيديو غير مكتمل',
                'processing' => 'الفيديو ما زال قيد المعالجة',
                'failed' => 'فشل تجهيز الفيديو',
                default => 'تفاصيل التشغيل تحتاج مراجعة',
            };
            $reasons->push($fallback);
        }

        return $reasons->all();
    }
}
