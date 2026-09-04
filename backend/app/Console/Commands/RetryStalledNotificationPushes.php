<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\SendUserPushNotification;
use App\Models\StudentNotification;
use App\Models\NotificationPushDelivery;
use App\Services\NotificationDeliveryPolicy;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;

final class RetryStalledNotificationPushes extends Command
{
    protected $signature = 'notifications:retry-stalled {--limit=500}';
    protected $description = 'Dispatch untouched pushes and quarantine claims with an unknown provider outcome';

    public function handle(): int
    {
        $remaining = max(1, min(5000, (int) $this->option('limit')));
        return $this->handlePerDevice($remaining);
    }

    private function handlePerDevice(int $remaining): int
    {
        // Rows claimed before the per-device ledger existed have no token-level
        // evidence to reconcile. A worker may have reached FCM, so retrying
        // could notify twice; quarantine the parent and keep the inbox copy.
        $legacyClaimIds = StudentNotification::query()
            ->whereDoesntHave('pushDeliveries')
            ->whereNotNull('push_attempted_at')
            ->whereNull('push_sent_at')
            ->whereNull('push_failed_at')
            ->where('push_attempted_at', '<=', now()->subMinutes(15))
            ->limit(5000)
            ->pluck('id');
        $legacyClaimed = StudentNotification::query()
            ->whereIn('id', $legacyClaimIds)
            ->update([
                'push_failed_at' => now(),
                'push_failure_code' => 'delivery_unknown_after_worker_loss',
                'updated_at' => now(),
            ]);
        $retiredUntouched = $this->retireUntouchedParents();
        $unboundDeliveryIds = NotificationPushDelivery::query()
            ->from('notification_push_deliveries as delivery')
            ->join('student_notifications as notification', 'notification.id', '=', 'delivery.student_notification_id')
            ->leftJoin('user_device_tokens as token', 'token.id', '=', 'delivery.user_device_token_id')
            ->whereIn('delivery.status', [
                NotificationPushDelivery::STATUS_PENDING,
                NotificationPushDelivery::STATUS_RETRYABLE,
            ])
            ->where(function ($query): void {
                $query->whereNull('token.id')
                    ->orWhereColumn('token.user_id', '<>', 'notification.user_id');
            })
            ->limit(5000)
            ->pluck('delivery.id');
        $unboundNotificationIds = NotificationPushDelivery::query()
            ->whereIn('id', $unboundDeliveryIds)
            ->pluck('student_notification_id')
            ->unique()
            ->values();
        NotificationPushDelivery::query()
            ->whereIn('id', $unboundDeliveryIds)
            ->update([
                'status' => NotificationPushDelivery::STATUS_SUPERSEDED,
                'failed_at' => now(),
                'failure_code' => 'token_unbound',
                'updated_at' => now(),
            ]);
        $ineligibleDeliveryIds = NotificationPushDelivery::query()
            ->from('notification_push_deliveries as delivery')
            ->join('student_notifications as notification', 'notification.id', '=', 'delivery.student_notification_id')
            ->join('users as owner', 'owner.id', '=', 'notification.user_id')
            ->whereIn('delivery.status', [
                NotificationPushDelivery::STATUS_PENDING,
                NotificationPushDelivery::STATUS_RETRYABLE,
            ])
            ->where(function ($query): void {
                $query->where('owner.active', false)
                    ->orWhereRaw('LOWER(owner.role) <> ?', ['client'])
                    ->orWhere('owner.notifications_status', false)
                    ->orWhere(function ($marketing): void {
                        $marketing->whereIn(
                            'notification.notification_type',
                            NotificationDeliveryPolicy::marketingTypes()
                        )->where('owner.marketing_notifications_enabled', false);
                    });
            })
            ->limit(5000)
            ->pluck('delivery.id');
        $ineligibleNotificationIds = NotificationPushDelivery::query()
            ->whereIn('id', $ineligibleDeliveryIds)
            ->pluck('student_notification_id')
            ->unique()
            ->values();
        NotificationPushDelivery::query()
            ->whereIn('id', $ineligibleDeliveryIds)
            ->update([
                'status' => NotificationPushDelivery::STATUS_SUPERSEDED,
                'failed_at' => now(),
                'failure_code' => 'push_preference_changed',
                'updated_at' => now(),
            ]);

        $staleClaimed = NotificationPushDelivery::query()
            ->where('status', NotificationPushDelivery::STATUS_DISPATCHING)
            ->where('attempted_at', '<=', now()->subMinutes(15))
            ->update([
                // FCM may already have accepted the request. Its API has no
                // application idempotency key, so never duplicate an uncertain
                // device delivery. Keep the authoritative inbox and expose it.
                'status' => NotificationPushDelivery::STATUS_UNKNOWN,
                'failed_at' => now(),
                'failure_code' => 'worker_lost_after_provider_start',
                'updated_at' => now(),
            ]);

        $queued = 0;
        $dispatchFailures = 0;
        StudentNotification::query()
            ->where('created_at', '>=', now()->subDays(7))
            ->whereHas('user', fn ($users) => $users
                ->where('active', true)
                ->students()
                ->where('notifications_status', true)
                ->whereHas('deviceTokens'))
            ->where(function ($policy): void {
                $policy->whereNotIn(
                    'notification_type',
                    NotificationDeliveryPolicy::marketingTypes()
                )->orWhereHas('user', fn ($users) => $users
                    ->where('marketing_notifications_enabled', true));
            })
            ->where(function ($query): void {
                $query->where(function ($untouched): void {
                    $untouched->whereDoesntHave('pushDeliveries')
                        ->whereNull('push_attempted_at')
                        ->whereNull('push_sent_at')
                        ->whereNull('push_failed_at');
                })
                    ->orWhereHas('pushDeliveries', fn ($deliveries) => $deliveries
                        ->whereIn('status', [
                            NotificationPushDelivery::STATUS_PENDING,
                            NotificationPushDelivery::STATUS_RETRYABLE,
                        ]))
                    ->orWhere(function ($rotated): void {
                        // A token row can be replaced after an incomplete
                        // attempt. Queue the inbox item only when no device
                        // accepted it and a current token has no delivery row.
                        $rotated->whereNull('push_sent_at')
                            ->whereHas('pushDeliveries')
                            ->whereRaw(<<<'SQL'
EXISTS (
    SELECT 1
    FROM user_device_tokens current_token
    WHERE current_token.user_id = student_notifications.user_id
      AND NOT EXISTS (
          SELECT 1
          FROM notification_push_deliveries current_delivery
          WHERE current_delivery.student_notification_id = student_notifications.id
            AND current_delivery.user_device_token_id = current_token.id
      )
)
SQL);
                    });
            })
            ->select('id')
            ->orderBy('id')
            ->chunkById(100, function ($notifications) use (&$remaining, &$queued, &$dispatchFailures): bool {
                foreach ($notifications as $notification) {
                    if ($remaining-- <= 0) return false;
                    try {
                        DurableJobDispatch::now(
                            new SendUserPushNotification((int) $notification->id)
                        );
                        $queued++;
                    } catch (\Throwable $exception) {
                        $dispatchFailures++;
                        report($exception);
                    }
                }
                return $remaining > 0;
            });

        // Refresh only the parent rows touched by uncertain device attempts.
        $unknownNotificationIds = NotificationPushDelivery::query()
            ->where('status', NotificationPushDelivery::STATUS_UNKNOWN)
            ->where('updated_at', '>=', now()->subMinutes(1))
            ->pluck('student_notification_id');
        $this->refreshParentOutcomes(
            $unboundNotificationIds
                ->merge($ineligibleNotificationIds)
                ->merge($unknownNotificationIds)
                ->unique()
        );

        $this->info("Queued {$queued} push job(s); {$dispatchFailures} queue write(s) failed; retired {$retiredUntouched} untouched parent(s), {$unboundDeliveryIds->count()} unbound and {$ineligibleDeliveryIds->count()} ineligible device claim(s); quarantined {$staleClaimed} uncertain device claim(s) and {$legacyClaimed} legacy claim(s).");
        return self::SUCCESS;
    }

    /**
     * A lost queue write can leave an inbox row without a device ledger. If the
     * account has since become ineligible, the normal work selector cannot pick
     * it up, so settle that parent explicitly instead of reporting it as stale
     * forever. Old untouched pushes expire rather than surprising a learner a
     * week after the inbox event.
     */
    private function retireUntouchedParents(): int
    {
        $retired = 0;
        $settle = function ($query, string $code) use (&$retired): void {
            $ids = $query->limit(5000)->pluck('id');
            if ($ids->isEmpty()) return;
            $retired += StudentNotification::query()->whereIn('id', $ids)->update([
                'push_failed_at' => now(),
                'push_failure_code' => $code,
                'updated_at' => now(),
            ]);
        };

        $settle(
            $this->untouchedParents()->whereDoesntHave('user', fn ($users) => $users
                ->where('active', true)->students()),
            'account_inactive'
        );
        $settle(
            $this->untouchedParents()->where(function ($ineligible): void {
                $ineligible->whereHas('user', fn ($users) => $users
                    ->where('active', true)
                    ->students()
                    ->where('notifications_status', false))
                    ->orWhere(function ($marketing): void {
                        $marketing->whereIn(
                            'notification_type',
                            NotificationDeliveryPolicy::marketingTypes()
                        )->whereHas('user', fn ($users) => $users
                            ->where('active', true)
                            ->students()
                            ->where('marketing_notifications_enabled', false));
                    });
            }),
            'preference_disabled'
        );
        $settle(
            $this->untouchedParents()
                ->whereHas('user', fn ($users) => $users
                    ->where('active', true)
                    ->students()
                    ->where('notifications_status', true))
                ->where(function ($policy): void {
                    $policy->whereNotIn(
                        'notification_type',
                        NotificationDeliveryPolicy::marketingTypes()
                    )->orWhereHas('user', fn ($users) => $users
                        ->where('marketing_notifications_enabled', true));
                })
                ->whereDoesntHave('user.deviceTokens'),
            'no_registered_device'
        );
        $settle(
            $this->untouchedParents()->where('created_at', '<', now()->subDays(7)),
            'delivery_window_expired'
        );

        return $retired;
    }

    private function untouchedParents(): \Illuminate\Database\Eloquent\Builder
    {
        return StudentNotification::query()
            ->whereDoesntHave('pushDeliveries')
            ->whereNull('push_attempted_at')
            ->whereNull('push_sent_at')
            ->whereNull('push_failed_at');
    }

    private function refreshParentOutcomes(\Illuminate\Support\Collection $notificationIds): void
    {
        foreach ($notificationIds as $notificationId) {
            $deliveries = NotificationPushDelivery::query()
                ->where('student_notification_id', $notificationId)
                ->get();
            if ($deliveries->isEmpty()) continue;
            $accepted = $deliveries->where('status', NotificationPushDelivery::STATUS_ACCEPTED);
            $active = $deliveries->whereIn('status', [
                NotificationPushDelivery::STATUS_PENDING,
                NotificationPushDelivery::STATUS_DISPATCHING,
                NotificationPushDelivery::STATUS_RETRYABLE,
            ]);
            $unknown = $deliveries->contains(
                fn ($delivery): bool => $delivery->status === NotificationPushDelivery::STATUS_UNKNOWN
            );
            $providerFailure = $deliveries->contains(fn ($delivery): bool => in_array($delivery->status, [
                NotificationPushDelivery::STATUS_FAILED,
                NotificationPushDelivery::STATUS_UNKNOWN,
            ], true));
            $superseded = $deliveries->contains(
                fn ($delivery): bool => $delivery->status === NotificationPushDelivery::STATUS_SUPERSEDED
            );
            $failed = $providerFailure || $superseded;
            StudentNotification::query()->whereKey($notificationId)->update([
                'push_attempted_at' => $deliveries->pluck('attempted_at')->filter()->sort()->first(),
                'push_attempts' => $deliveries->sum('attempts'),
                'push_sent_at' => $accepted->pluck('accepted_at')->filter()->sort()->first(),
                'push_failed_at' => $active->isEmpty() && $failed ? now() : null,
                'push_failure_code' => $failed
                    ? ($unknown
                        ? 'delivery_unknown_after_worker_loss'
                        : ($accepted->isNotEmpty()
                            ? 'partial_delivery'
                            : ($providerFailure ? 'delivery_failed' : 'not_push_eligible')))
                    : null,
                'updated_at' => now(),
            ]);
        }
    }
}
