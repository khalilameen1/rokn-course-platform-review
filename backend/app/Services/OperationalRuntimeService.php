<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RecordQueueHeartbeat;
use App\Models\InternalSignal;
use App\Models\OperationalIncident;
use App\Models\OutboxEvent;
use App\Models\ProjectFeedbackMessage;
use App\Models\StudentNotification;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class OperationalRuntimeService
{
    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        $now = now();
        $maxHeartbeatAge = max(60, (int) config('operations.queue_heartbeat_max_age_seconds', 180));
        $queues = [];
        foreach (RecordQueueHeartbeat::requiredQueues() as $queue) {
            $heartbeat = $this->cacheDate(RecordQueueHeartbeat::cacheKey($queue));
            $queues[$queue] = [
                'size' => $this->queueSize($queue),
                'last_heartbeat_at' => $heartbeat,
                'heartbeat_age_seconds' => $heartbeat ? $heartbeat->diffInSeconds($now) : null,
                'healthy' => $heartbeat?->gte($now->copy()->subSeconds($maxHeartbeatAge)) ?? false,
            ];
        }

        $scheduler = $this->cacheDate((string) config(
            'operations.scheduler_heartbeat_key',
            'operations:scheduler-heartbeat:v1'
        ));

        return [
            'checked_at' => $now,
            'scheduler' => [
                'last_heartbeat_at' => $scheduler,
                'age_seconds' => $scheduler ? $scheduler->diffInSeconds($now) : null,
                'healthy' => $scheduler?->gte($now->copy()->subSeconds(
                    max(60, (int) config('operations.scheduler_heartbeat_max_age_seconds', 180))
                )) ?? false,
            ],
            'queues' => $queues,
            'failed_jobs' => $this->failedJobs(),
            'outbox' => $this->outbox(),
            'internal_signals' => $this->internalSignals(),
            'notifications' => $this->notifications(),
            'certificates' => $this->certificates(),
            'ai' => $this->ai(),
            'cleanup' => $this->cleanup(),
            'payment_callbacks' => $this->paymentCallbacks(),
            'providers' => $this->providers(),
            'rate_limits' => $this->rateLimits(),
        ];
    }

    /**
     * @return array{alerts:list<OperationalIncident>,resolved:list<OperationalIncident>}
     */
    public function reconcileIncidents(): array
    {
        if (! Schema::hasTable('operational_incidents')) {
            return ['alerts' => [], 'resolved' => []];
        }
        $detected = collect($this->detect($this->snapshot()))->keyBy('code');
        $alerts = [];
        $now = now();
        $repeatAfter = $now->copy()->subMinutes(max(
            5,
            (int) config('operations.alert_repeat_minutes', 30)
        ));

        DB::transaction(function () use ($detected, $now, $repeatAfter, &$alerts): void {
            foreach ($detected as $code => $item) {
                $incident = OperationalIncident::query()->lockForUpdate()->firstOrNew(['code' => $code]);
                $wasOpen = $incident->exists && $incident->status === OperationalIncident::STATUS_OPEN;
                $escalated = $wasOpen
                    && $this->severityRank((string) $item['severity'])
                        > $this->severityRank((string) $incident->severity);
                $shouldAlert = ! $wasOpen
                    || $escalated
                    || ! $incident->last_alerted_at
                    || $incident->last_alerted_at->lte($repeatAfter);

                $incident->fill([
                    'category' => $item['category'],
                    'severity' => $item['severity'],
                    'status' => OperationalIncident::STATUS_OPEN,
                    'summary' => $item['summary'],
                    'affected_count' => max(0, (int) $item['affected_count']),
                    'metadata' => $item['metadata'] ?? null,
                    'first_seen_at' => $wasOpen ? $incident->first_seen_at : $now,
                    'last_seen_at' => $now,
                    'resolved_at' => null,
                    'occurrence_count' => $wasOpen
                        ? max(1, (int) $incident->occurrence_count + 1)
                        : 1,
                    'last_alerted_at' => $shouldAlert ? $now : $incident->last_alerted_at,
                ])->save();
                if ($shouldAlert) {
                    $alerts[] = $incident->fresh();
                }
            }
        }, 3);

        $resolved = [];
        OperationalIncident::query()
            ->where('status', OperationalIncident::STATUS_OPEN)
            ->whereNotIn('code', $detected->keys()->all() ?: ['__none__'])
            ->orderBy('id')
            ->chunkById(100, function ($incidents) use ($now, &$resolved): void {
                foreach ($incidents as $incident) {
                    $incident->forceFill([
                        'status' => OperationalIncident::STATUS_RESOLVED,
                        'resolved_at' => $now,
                        'last_seen_at' => $now,
                    ])->save();
                    $resolved[] = $incident;
                }
            });

        return ['alerts' => $alerts, 'resolved' => $resolved];
    }

    /** @return list<array<string, mixed>> */
    private function detect(array $snapshot): array
    {
        $incidents = [];
        $add = static function (
            string $code,
            string $category,
            string $severity,
            string $summary,
            int $affected,
            array $metadata = []
        ) use (&$incidents): void {
            $incidents[] = compact('code', 'category', 'severity', 'summary') + [
                'affected_count' => max(0, $affected),
                'metadata' => $metadata ?: null,
            ];
        };

        $scheduler = $snapshot['scheduler'];
        if (! $scheduler['healthy']) {
            $age = $scheduler['age_seconds'];
            $add(
                'scheduler.stale',
                'scheduler',
                $age === null || $age >= 600 ? 'critical' : 'warning',
                'الجدولة الدورية لا ترسل نبضًا حديثًا',
                1,
                ['age_seconds' => $age]
            );
        }
        foreach ($snapshot['queues'] as $queue => $state) {
            if (! $state['healthy']) {
                $age = $state['heartbeat_age_seconds'];
                $add(
                    'queue.'.preg_replace('/[^a-z0-9_-]/i', '_', (string) $queue).'.stale',
                    'queue',
                    $age === null || $age >= 600 ? 'critical' : 'warning',
                    'عامل طابور '.$queue.' لا ينفذ مهامًا حديثة',
                    max(1, (int) ($state['size'] ?? 0)),
                    ['queue' => $queue, 'size' => $state['size'], 'age_seconds' => $age]
                );
            }
            $limit = max(1, (int) data_get(
                config('operations.queue_backlog_limits', []),
                (string) $queue,
                1000
            ));
            $size = $state['size'];
            if ($size !== null && $size > $limit) {
                $add(
                    'queue.'.preg_replace('/[^a-z0-9_-]/i', '_', (string) $queue).'.backlog',
                    'queue',
                    $size >= $limit * 2 ? 'critical' : 'warning',
                    'طابور '.$queue.' ينمو أسرع من الاستهلاك',
                    (int) $size,
                    ['queue' => $queue, 'size' => $size, 'limit' => $limit]
                );
            }
        }

        foreach ([
            ['failed_jobs', 'queue', 'تعطلت مهام ووصلت إلى قائمة الفشل'],
            ['failed', 'webhook', 'أحداث webhook وصلت إلى قائمة الفشل'],
            ['blocked', 'webhook', 'أحداث webhook تنتظر حدثًا أقدم لم يكتمل'],
            ['stale_internal', 'internal_signal', 'آثار داخلية مهمة تنتظر الاستعادة'],
            ['stale', 'notification', 'إشعارات لم تصل إلى push بعد المهلة'],
            ['failed_pushes', 'notification', 'إشعارات push استنفدت محاولاتها'],
            ['failed_campaigns', 'notification', 'حملات إشعار تعطلت قبل اكتمال البريد الداخلي'],
            ['pending_certificates', 'certificate', 'شهادات مستحقة ما زال ملفها معلقًا'],
            ['failed_certificates', 'certificate', 'استعادة ملفات شهادات تعطلت'],
            ['stale_messages', 'ai', 'ردود AI بقيت معلقة بعد مهلة العامل'],
            ['expired_reservations', 'ai', 'حجوزات تكلفة AI انتهت دون تسوية'],
            ['failed_files', 'cleanup', 'ملفات حسابات محذوفة لم تُحذف بعد المحاولات'],
            ['stale_files', 'cleanup', 'مهام حذف ملفات توقفت أثناء التنفيذ'],
            ['failed_bunny', 'cleanup', 'تنظيف فيديوهات Bunny يتكرر فشله'],
            ['review_required', 'payment', 'إشعارات دفع تحتاج مطابقة تشغيلية'],
            ['stalled_store_events', 'payment', 'إشعارات متجر استُلمت ولم يكتمل تصنيفها'],
            ['reconciliation_stale', 'payment', 'مطابقة Kashier الدورية لم تكتمل في موعدها'],
            ['reconciliation_failed', 'payment', 'آخر دورة مطابقة مع Kashier تعطلت'],
            ['openrouter_circuit', 'provider', 'دائرة حماية OpenRouter مفتوحة بعد أعطال متتابعة'],
            ['bunny_circuit', 'provider', 'دائرة حماية فحص Bunny مفتوحة بعد أعطال متتابعة'],
            ['fcm_circuit', 'provider', 'دائرة حماية FCM مفتوحة بعد أعطال متتابعة'],
            ['rate_limit_spike', 'abuse', 'قفزة كبيرة في الطلبات المرفوضة تحتاج مراجعة المصدر والمسار'],
        ] as [$key, $category, $summary]) {
            $source = match ($key) {
                'failed_jobs' => $snapshot['failed_jobs'],
                'failed', 'blocked' => $snapshot['outbox'],
                'stale_internal' => $snapshot['internal_signals'],
                'stale', 'failed_pushes', 'failed_campaigns' => $snapshot['notifications'],
                'pending_certificates', 'failed_certificates' => $snapshot['certificates'],
                'stale_messages', 'expired_reservations' => $snapshot['ai'],
                'failed_files', 'stale_files', 'failed_bunny' => $snapshot['cleanup'],
                'openrouter_circuit', 'bunny_circuit', 'fcm_circuit' => $snapshot['providers'],
                'rate_limit_spike' => $snapshot['rate_limits'],
                default => $snapshot['payment_callbacks'],
            };
            $count = (int) ($source[$key] ?? 0);
            if ($count <= 0) {
                continue;
            }
            $affected = (int) ($source[$key.'_affected'] ?? $count);
            $add(
                $category.'.'.$key,
                $category,
                $count >= 20 || in_array($key, ['failed', 'expired_reservations'], true)
                    ? 'critical'
                    : 'warning',
                $summary,
                $affected,
                ['count' => $count, 'oldest_at' => $source[$key.'_oldest_at'] ?? null]
            );
        }

        return $incidents;
    }

    /** @return array<string, mixed> */
    private function failedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['failed_jobs' => 0, 'recent' => []];
        }

        return [
            'failed_jobs' => (int) DB::table('failed_jobs')->count(),
            'failed_jobs_oldest_at' => DB::table('failed_jobs')->min('failed_at'),
            'by_queue' => DB::table('failed_jobs')
                ->selectRaw('queue, COUNT(*) AS total')
                ->groupBy('queue')
                ->orderByDesc('total')
                ->limit(12)
                ->get()
                ->map(fn ($row): array => ['queue' => (string) $row->queue, 'count' => (int) $row->total])
                ->all(),
            'recent' => DB::table('failed_jobs')
                ->latest('failed_at')
                ->limit(10)
                ->get(['id', 'queue', 'failed_at'])
                ->map(fn ($row): array => [
                    'id' => (int) $row->id,
                    'queue' => (string) $row->queue,
                    'failed_at' => $row->failed_at,
                ])->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function outbox(): array
    {
        if (! Schema::hasTable('outbox_events')) {
            return ['failed' => 0, 'blocked' => 0, 'pending' => 0];
        }
        $base = DB::table('outbox_events');

        return [
            'pending' => (clone $base)->where('status', OutboxEvent::STATUS_PENDING)->count(),
            'processing' => (clone $base)->where('status', OutboxEvent::STATUS_PROCESSING)->count(),
            'failed' => (clone $base)->where('status', OutboxEvent::STATUS_FAILED)->count(),
            'skipped' => (clone $base)->where('status', OutboxEvent::STATUS_SKIPPED)->count(),
            'failed_oldest_at' => (clone $base)->where('status', OutboxEvent::STATUS_FAILED)->min('updated_at'),
            'blocked' => (clone $base)->where('status', OutboxEvent::STATUS_BLOCKED)->count(),
            'blocked_oldest_at' => (clone $base)->where('status', OutboxEvent::STATUS_BLOCKED)->min('updated_at'),
            'failed_events' => (clone $base)
                ->where('status', OutboxEvent::STATUS_FAILED)
                ->latest('updated_at')
                ->limit(10)
                ->get(['id', 'topic', 'aggregate_type', 'aggregate_id', 'attempts', 'updated_at'])
                ->all(),
            'failed_deliveries' => Schema::hasTable('webhook_deliveries')
                ? DB::table('webhook_deliveries')->where('status', 'failed')->count()
                : 0,
        ];
    }

    /** @return array<string, mixed> */
    private function internalSignals(): array
    {
        if (! Schema::hasTable('internal_signals')) {
            return ['stale_internal' => 0, 'pending' => 0, 'processing' => 0];
        }

        $staleBefore = now()->subMinutes(5);
        $base = InternalSignal::query();
        $stale = InternalSignal::query()
            ->where(function ($query) use ($staleBefore): void {
                $query->where(function ($pending) use ($staleBefore): void {
                    $pending->where('status', InternalSignal::STATUS_PENDING)
                        ->where('created_at', '<=', $staleBefore);
                })->orWhere(function ($processing) use ($staleBefore): void {
                    $processing->where('status', InternalSignal::STATUS_PROCESSING)
                        ->where(function ($lease) use ($staleBefore): void {
                            $lease->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
                        });
                });
            });

        return [
            'pending' => (clone $base)->where('status', InternalSignal::STATUS_PENDING)->count(),
            'processing' => (clone $base)->where('status', InternalSignal::STATUS_PROCESSING)->count(),
            'stale_internal' => (clone $stale)->count(),
            'stale_internal_oldest_at' => (clone $stale)->min('created_at'),
            'stale_internal_affected' => (clone $stale)
                ->distinct()
                ->count('aggregate_id'),
        ];
    }

    /** @return array<string, mixed> */
    private function notifications(): array
    {
        $result = [
            'stale' => 0,
            'stale_affected' => 0,
            'failed_pushes' => 0,
            'failed_pushes_affected' => 0,
            'failed_campaigns' => 0,
            'failed_campaigns_affected' => 0,
        ];
        if (Schema::hasTable('student_notifications')) {
            $hasPushDeadLetter = Schema::hasColumn('student_notifications', 'push_failed_at');
            // One aggregate pass per signal instead of count + distinct count
            // + min over the same rows. The actionable dead-letter filter is
            // served by student_push_actionable_dead_letters (push_sent_at,
            // push_failure_code, push_failed_at, user_id): NOT IN on the second
            // column becomes ranges that skip benign codes, and the index
            // covers the aggregate.
            [$result['stale'], $result['stale_affected'], $result['stale_oldest_at']] = $this->countAffectedOldest(
                StudentNotification::query()->toBase()
                    ->whereNull('push_sent_at')
                    ->when($hasPushDeadLetter, fn ($query) => $query->whereNull('push_failed_at'))
                    ->where('created_at', '<=', now()->subMinutes(15)),
                'created_at'
            );
            if ($hasPushDeadLetter) {
                [
                    $result['failed_pushes'],
                    $result['failed_pushes_affected'],
                    $result['failed_pushes_oldest_at'],
                ] = $this->countAffectedOldest(
                    StudentNotification::query()->toBase()
                        ->whereNull('push_sent_at')
                        ->whereNotIn('push_failure_code', [
                            'not_push_eligible',
                            'preference_disabled',
                            'account_inactive',
                            'no_registered_device',
                            'token_unbound',
                            'delivery_window_expired',
                        ])
                        ->whereNotNull('push_failed_at'),
                    'push_failed_at'
                );
            }
        }
        if (Schema::hasTable('notification_campaigns')) {
            $failed = DB::table('notification_campaigns')->where('status', 'failed');
            $result['failed_campaigns'] = (clone $failed)->count();
            $result['failed_campaigns_affected'] = (int) (clone $failed)
                ->selectRaw(
                    'COALESCE(SUM(CASE WHEN recipients_count > inbox_count '
                    .'THEN recipients_count - inbox_count ELSE 0 END), 0) AS affected'
                )
                ->value('affected');
            $result['failed_campaigns_oldest_at'] = (clone $failed)->min('failed_at');
        }

        return $result;
    }

    /**
     * COUNT(*), COUNT(DISTINCT user_id) and MIN(column) in one statement.
     *
     * @return array{0:int,1:int,2:mixed}
     */
    private function countAffectedOldest(QueryBuilder $query, string $oldestColumn): array
    {
        $grammar = $query->getGrammar();
        $row = $query->selectRaw(
            'COUNT(*) AS aggregate_count, COUNT(DISTINCT '.$grammar->wrap('user_id').') AS affected_count, '
            .'MIN('.$grammar->wrap($oldestColumn).') AS oldest_value'
        )->first();

        return [
            (int) ($row->aggregate_count ?? 0),
            (int) ($row->affected_count ?? 0),
            $row->oldest_value ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function certificates(): array
    {
        $empty = [
            'pending_certificates' => 0,
            'pending_certificates_affected' => 0,
            'failed_certificates' => 0,
            'failed_certificates_affected' => 0,
        ];
        if (
            ! Schema::hasTable('certificates')
            || ! Schema::hasColumns('certificates', [
                'recovery_attempts',
                'recovery_failed_at',
            ])
        ) {
            return $empty;
        }

        $staleBefore = now()->subMinutes(max(
            2,
            (int) config('operations.certificate_recovery_stale_minutes', 5)
        ));
        $active = static fn ($query) => $query
            ->where(fn ($status) => $status->whereNull('status')->orWhere('status', 'active'));
        $pending = $active(DB::table('certificates'))
            ->where('image_path', 'pending')
            ->whereNull('recovery_failed_at')
            ->where('updated_at', '<=', $staleBefore);
        $failed = $active(DB::table('certificates'))
            ->whereNotNull('recovery_failed_at');

        return [
            'pending_certificates' => (clone $pending)->count(),
            'pending_certificates_affected' => (clone $pending)->distinct()->count('user_id'),
            'pending_certificates_oldest_at' => (clone $pending)->min('updated_at'),
            'failed_certificates' => (clone $failed)->count(),
            'failed_certificates_affected' => (clone $failed)->distinct()->count('user_id'),
            'failed_certificates_oldest_at' => (clone $failed)->min('recovery_failed_at'),
        ];
    }

    /** @return array<string, mixed> */
    private function ai(): array
    {
        $result = ['stale_messages' => 0, 'stale_messages_affected' => 0, 'expired_reservations' => 0, 'expired_reservations_affected' => 0];
        if (Schema::hasTable('project_feedback_messages')) {
            $stale = ProjectFeedbackMessage::query()
                ->whereIn('status', [
                    ProjectFeedbackMessage::QUEUED,
                    ProjectFeedbackMessage::SENT,
                    ProjectFeedbackMessage::STREAMING,
                ])
                ->where('updated_at', '<=', now()->subMinutes(10));
            $result['stale_messages'] = (clone $stale)->count();
            $result['stale_messages_affected'] = (clone $stale)->distinct()->count('thread_id');
            $result['stale_messages_oldest_at'] = (clone $stale)->min('updated_at');
        }
        if (Schema::hasTable('ai_usage_events') && Schema::hasColumn('ai_usage_events', 'reservation_expires_at')) {
            $expired = DB::table('ai_usage_events')
                ->where('status', 'reserved')
                ->where('reservation_expires_at', '<=', now());
            $result['expired_reservations'] = (clone $expired)->count();
            $result['expired_reservations_affected'] = (clone $expired)->distinct()->count('user_id');
            $result['expired_reservations_oldest_at'] = (clone $expired)->min('reservation_expires_at');
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function cleanup(): array
    {
        $result = [
            'failed_files' => 0,
            'failed_files_affected' => 0,
            'stale_files' => 0,
            'stale_files_affected' => 0,
            'failed_bunny' => 0,
            'failed_bunny_oldest_at' => null,
        ];
        if (Schema::hasTable('account_file_deletions')) {
            $failed = DB::table('account_file_deletions')->where('status', 'failed');
            $result['failed_files'] = (clone $failed)->count();
            $result['failed_files_affected'] = (clone $failed)->distinct()->count('user_id');
            $result['failed_files_oldest_at'] = (clone $failed)->min('updated_at');
            $stale = DB::table('account_file_deletions')
                ->where('status', 'processing')
                ->where('updated_at', '<=', now()->subMinutes(15));
            $result['stale_files'] = (clone $stale)->count();
            $result['stale_files_affected'] = (clone $stale)->distinct()->count('user_id');
            $result['stale_files_oldest_at'] = (clone $stale)->min('updated_at');
        }
        if (Schema::hasTable('bunny_video_cleanup_candidates')) {
            $failed = DB::table('bunny_video_cleanup_candidates')
                ->whereNull('remote_deleted_at')
                ->whereNotNull('last_error')
                ->where('attempts', '>=', 3);
            $result['failed_bunny'] = (clone $failed)->count();
            $result['failed_bunny_oldest_at'] = (clone $failed)->min('last_attempt_at');
        }
        if (Schema::hasTable('bunny_storage_cleanup_candidates')) {
            $failedStorage = DB::table('bunny_storage_cleanup_candidates')
                ->whereNull('completed_at')
                ->whereNotNull('last_error')
                ->where(function ($query): void {
                    if (Schema::hasColumn('bunny_storage_cleanup_candidates', 'quarantined_at')) {
                        $query->whereNotNull('quarantined_at')->orWhere('attempts', '>=', 3);
                    } else {
                        $query->where('attempts', '>=', 3);
                    }
                });
            $result['failed_bunny'] += (clone $failedStorage)->count();
            $oldestStorage = (clone $failedStorage)->min('last_attempt_at');
            if ($oldestStorage && (! $result['failed_bunny_oldest_at'] || $oldestStorage < $result['failed_bunny_oldest_at'])) {
                $result['failed_bunny_oldest_at'] = $oldestStorage;
            }
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function paymentCallbacks(): array
    {
        $review = 0;
        $oldest = null;
        $stalled = 0;
        $stalledOldest = null;
        if (Schema::hasTable('store_notification_events')) {
            $query = DB::table('store_notification_events')->where('status', 'review_required');
            $review += (clone $query)->count();
            $oldest = (clone $query)->min('received_at');

            $stalledQuery = DB::table('store_notification_events')
                ->where('status', 'received')
                ->where('received_at', '<=', now()->subMinutes(max(
                    5,
                    (int) config('operations.store_notification_stale_minutes', 10)
                )));
            $stalled = (clone $stalledQuery)->count();
            $stalledOldest = (clone $stalledQuery)->min('received_at');
        }
        if (Schema::hasTable('payment_reconciliation_findings')) {
            $query = DB::table('payment_reconciliation_findings')->where('state', 'open');
            $review += (clone $query)->count();
            $candidate = (clone $query)->min('first_seen_at');
            if ($candidate && (! $oldest || $candidate < $oldest)) {
                $oldest = $candidate;
            }
        }
        $reconciliationStale = 0;
        $reconciliationFailed = 0;
        $reconciliationOldest = null;
        if (Schema::hasTable('payment_reconciliation_checkpoints')) {
            $checkpoint = DB::table('payment_reconciliation_checkpoints')
                ->where('provider', 'kashier')
                ->first(['last_started_at', 'last_completed_at', 'last_error_at']);
            $completedAt = $checkpoint?->last_completed_at;
            $startedAt = $checkpoint?->last_started_at;
            $cutoff = now()->subMinutes(max(
                30,
                (int) config('operations.payment_reconciliation_stale_minutes', 45)
            ));
            if ($startedAt && (! $completedAt || $completedAt < $startedAt) && $startedAt <= $cutoff) {
                $reconciliationStale = 1;
                $reconciliationOldest = $startedAt;
            } elseif ($completedAt && $completedAt <= $cutoff) {
                $reconciliationStale = 1;
                $reconciliationOldest = $completedAt;
            }
            if ($checkpoint?->last_error_at && (! $completedAt || $checkpoint->last_error_at > $completedAt)) {
                $reconciliationFailed = 1;
                $reconciliationOldest = $checkpoint->last_error_at;
            }
        }

        return [
            'review_required' => $review,
            'review_required_oldest_at' => $oldest,
            'stalled_store_events' => $stalled,
            'stalled_store_events_oldest_at' => $stalledOldest,
            'reconciliation_stale' => $reconciliationStale,
            'reconciliation_stale_oldest_at' => $reconciliationOldest,
            'reconciliation_failed' => $reconciliationFailed,
            'reconciliation_failed_oldest_at' => $reconciliationOldest,
        ];
    }

    private function queueSize(string $queue): ?int
    {
        try {
            return Queue::connection()->size($queue);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, int> */
    private function providers(): array
    {
        try {
            return [
                'openrouter_circuit' => Cache::has(OpenRouterService::CIRCUIT_KEY) ? 1 : 0,
                'bunny_circuit' => Cache::has(BunnyService::PROBE_CIRCUIT_KEY) ? 1 : 0,
                'fcm_circuit' => Cache::has(FcmNotificationService::CIRCUIT_KEY) ? 1 : 0,
            ];
        } catch (Throwable) {
            return ['openrouter_circuit' => 0, 'bunny_circuit' => 0, 'fcm_circuit' => 0];
        }
    }

    private function cacheDate(string $key): ?Carbon
    {
        try {
            $value = Cache::get($key);

            return is_string($value) && $value !== '' ? Carbon::parse($value) : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string,mixed> */
    private function rateLimits(): array
    {
        $empty = [
            'last_24h' => 0,
            'affected_actors' => 0,
            'affected_users' => 0,
            'rate_limit_spike' => 0,
            'rate_limit_spike_affected' => 0,
            'top_routes' => [],
        ];
        if (! Schema::hasTable('rate_limit_events')) {
            return $empty;
        }

        $day = DB::table('rate_limit_events')->where('window_started_at', '>=', now()->subDay());
        $recent = DB::table('rate_limit_events')->where('window_started_at', '>=', now()->subMinutes(15));
        $recentHits = (int) (clone $recent)->sum('hit_count');
        $spikeThreshold = max(100, (int) config('rate_limits.operational_spike_15m', 1000));

        return [
            'last_24h' => (int) (clone $day)->sum('hit_count'),
            'affected_actors' => (clone $day)->distinct()->count('bucket_key_hash'),
            'affected_users' => (clone $day)->whereNotNull('user_id')->distinct()->count('user_id'),
            'rate_limit_spike' => $recentHits >= $spikeThreshold ? $recentHits : 0,
            'rate_limit_spike_affected' => (clone $recent)->distinct()->count('bucket_key_hash'),
            'rate_limit_spike_oldest_at' => (clone $recent)->min('window_started_at'),
            'top_routes' => (clone $day)
                ->selectRaw('route_name, SUM(hit_count) AS hits, COUNT(DISTINCT bucket_key_hash) AS actors')
                ->groupBy('route_name')
                ->orderByDesc('hits')
                ->limit(10)
                ->get()
                ->map(fn ($row): array => [
                    'route' => (string) $row->route_name,
                    'hits' => (int) $row->hits,
                    'actors' => (int) $row->actors,
                ])->all(),
        ];
    }

    private function severityRank(string $severity): int
    {
        return match ($severity) {
            'critical' => 2,
            'warning' => 1,
            default => 0,
        };
    }
}
