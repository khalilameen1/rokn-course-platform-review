<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\StudentNotification;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\User;
use App\Models\Course;
use App\Models\FinancialEntitlementHold;
use App\Services\NotificationDeliveryPolicy;
use App\Support\DurableJobDispatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\DB;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class DeliverStudentNotificationChunk implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 900;
    public array $backoff = [15, 60, 180];

    /** @param array<int> $userIds */
    public function __construct(
        private array $userIds,
        private string $deliveryKey
    ) {
        $this->userIds = array_values(array_unique(array_map('intval', $this->userIds)));
        $this->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return hash('sha256', $this->deliveryKey . '|' . implode(',', $this->userIds));
    }

    public function handle(): void
    {
        $campaign = NotificationCampaign::query()
            ->where('delivery_key', $this->deliveryKey)
            ->firstOrFail();
        if ($campaign->status !== NotificationCampaign::STATUS_DELIVERING) {
            return;
        }
        $notificationIds = DB::transaction(function () use ($campaign): array {
            $recipients = NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->whereIn('user_id', $this->userIds)
                ->orderBy('user_id')
                ->lockForUpdate()
                ->get();
            $pending = $recipients->reject(fn (NotificationCampaignRecipient $recipient): bool =>
                in_array($recipient->status, [
                    NotificationCampaignRecipient::STATUS_INBOX,
                    NotificationCampaignRecipient::STATUS_SKIPPED,
                ], true)
            );
            $resolvedUserIds = $recipients
                ->where('status', NotificationCampaignRecipient::STATUS_INBOX)
                ->pluck('user_id')
                ->map(fn ($id): int => (int) $id);
            if ($pending->isEmpty()) {
                return StudentNotification::query()
                    ->where('delivery_key', $this->deliveryKey)
                    ->whereIn('user_id', $resolvedUserIds)
                    ->pluck('id')
                    ->map(fn ($id): int => (int) $id)
                    ->all();
            }

            $courseEligible = true;
            if ($campaign->course_id) {
                $course = Course::query()
                    ->whereKey($campaign->course_id)
                    ->sharedLock()
                    ->first();
                $courseEligible = $course
                    && $course->isPublishedForLearning()
                    && ($campaign->canDeliverHiddenCourse() || (bool) $course->is_catalog_visible);
            }

            $pendingUserIds = $pending->pluck('user_id')->map(fn ($id): int => (int) $id);
            $users = User::query()
                ->whereIn('id', $pendingUserIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $userQuery = User::query()
                ->whereIn('id', $pendingUserIds)
                ->students()
                ->where('active', true);
            if (NotificationDeliveryPolicy::isMarketing((string) $campaign->notification_type)) {
                $userQuery->where('marketing_notifications_enabled', true);
            }
            $cooldownHours = NotificationDeliveryPolicy::cooldownHours(
                (string) $campaign->notification_type
            );
            if ($cooldownHours > 0) {
                $family = NotificationDeliveryPolicy::cooldownFamily(
                    (string) $campaign->notification_type
                );
                $userQuery->whereDoesntHave(
                    'studentNotifications',
                    function ($notifications) use ($cooldownHours, $family): void {
                        $notifications
                            ->whereIn('notification_type', $family)
                            ->where('created_at', '>=', now()->subHours($cooldownHours));
                    }
                );
            }
            if ($campaign->course_id && $campaign->audience === SendStudentNotification::AUDIENCE_ENROLLED) {
                $userQuery->whereHas('enrollments', function ($enrollments) use ($campaign): void {
                    $enrollments
                        ->where('course_id', (int) $campaign->course_id)
                        ->where('is_active', true)
                        ->where(function ($expiry): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
            } elseif ($campaign->course_id && $campaign->audience === SendStudentNotification::AUDIENCE_NOT_ENROLLED) {
                $userQuery->whereDoesntHave('enrollments', function ($enrollments) use ($campaign): void {
                    $enrollments
                        ->where('course_id', (int) $campaign->course_id)
                        ->where('is_active', true)
                        ->where(function ($expiry): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
            }
            if ($campaign->course_id) {
                $userQuery->whereNotExists(function ($holds) use ($campaign): void {
                    $holds->selectRaw('1')
                        ->from('financial_entitlement_holds')
                        ->whereColumn('financial_entitlement_holds.user_id', 'users.id')
                        ->where('course_id', (int) $campaign->course_id)
                        ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                        ->whereIn('entitlement_scope', ['course', 'plan', 'chat']);
                });
            }
            $audienceEligibleUserIds = $userQuery
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->flip();
            $eligibleUserIds = $pendingUserIds->filter(function (int $userId) use (
                $campaign,
                $courseEligible,
                $audienceEligibleUserIds,
                $users
            ): bool {
                $user = $users->get($userId);

                return $courseEligible
                    && $user
                    && $audienceEligibleUserIds->has($userId)
                    && NotificationDeliveryPolicy::allowsInbox(
                        $user,
                        (string) $campaign->notification_type
                    );
            })->values();
            $missingUserIds = $pendingUserIds->reject(fn (int $userId): bool => $users->has($userId));
            $ineligibleUserIds = $pendingUserIds->diff($eligibleUserIds)->diff($missingUserIds);
            $now = now();

            NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->whereIn('user_id', $missingUserIds)
                ->update([
                    'status' => NotificationCampaignRecipient::STATUS_SKIPPED,
                    'attempts' => DB::raw('attempts + 1'),
                    'claimed_at' => $now,
                    'resolved_at' => $now,
                    'resolution_code' => 'account_missing',
                    'updated_at' => $now,
                ]);
            NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->whereIn('user_id', $ineligibleUserIds)
                ->update([
                    'status' => NotificationCampaignRecipient::STATUS_SKIPPED,
                    'attempts' => DB::raw('attempts + 1'),
                    'claimed_at' => $now,
                    'resolved_at' => $now,
                    'resolution_code' => 'preference_or_course_changed',
                    'updated_at' => $now,
                ]);

            if ($eligibleUserIds->isNotEmpty()) {
                $rows = $eligibleUserIds->map(fn (int $userId): array => [
                    'user_id' => $userId,
                    'delivery_key' => $this->deliveryKey,
                    'notification_type' => $campaign->notification_type,
                    'notifiable_type' => $campaign->notifiable_type,
                    'notifiable_id' => $campaign->notifiable_id,
                    'title_ar' => $campaign->title_ar,
                    'title_en' => $campaign->title_en,
                    'message_ar' => $campaign->message_ar,
                    'message_en' => $campaign->message_en,
                    'link' => $campaign->link,
                    'image_url' => $campaign->image_url,
                    'action_label_ar' => $campaign->action_label_ar,
                    'action_label_en' => $campaign->action_label_en,
                    'is_read' => false,
                    'read_at' => null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all();
                StudentNotification::query()->insertOrIgnore($rows);

                NotificationCampaignRecipient::query()
                    ->where('notification_campaign_id', $campaign->id)
                    ->whereIn('user_id', $eligibleUserIds)
                    ->update([
                        'status' => NotificationCampaignRecipient::STATUS_INBOX,
                        'attempts' => DB::raw('attempts + 1'),
                        'claimed_at' => $now,
                        'resolved_at' => $now,
                        'resolution_code' => null,
                        'updated_at' => $now,
                    ]);
            }

            $notificationUserIds = $resolvedUserIds->merge($eligibleUserIds)->unique();
            $notificationIds = StudentNotification::query()
                ->where('delivery_key', $this->deliveryKey)
                ->whereIn('user_id', $notificationUserIds)
                ->pluck('id')
                ->map(fn ($id): int => (int) $id)
                ->all();
            if (count($notificationIds) !== $notificationUserIds->count()) {
                throw new \RuntimeException('Notification inbox snapshot was not persisted for every recipient.');
            }

            return $notificationIds;
        }, 3);

        foreach ($notificationIds as $notificationId) {
            try {
                DurableJobDispatch::afterCommit(new SendUserPushNotification($notificationId));
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        $this->refreshCampaignProgress($campaign);
    }

    private function refreshCampaignProgress(NotificationCampaign $campaign): void
    {
        DB::transaction(function () use ($campaign): void {
            // Serialize the derived counter write. Without the campaign lock,
            // an older chunk can overwrite a just-completed status with its
            // stale pre-final count.
            $freshCampaign = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();
            if (!$freshCampaign) return;
            $counts = NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->selectRaw("SUM(CASE WHEN status = 'inbox' THEN 1 ELSE 0 END) as inbox_count")
                ->selectRaw("SUM(CASE WHEN status = 'skipped' THEN 1 ELSE 0 END) as skipped_count")
                ->selectRaw("SUM(CASE WHEN status IN ('inbox', 'skipped') THEN 1 ELSE 0 END) as resolved_count")
                ->first();
            $inbox = (int) ($counts?->inbox_count ?? 0);
            $skipped = (int) ($counts?->skipped_count ?? 0);
            $resolved = (int) ($counts?->resolved_count ?? 0);
            $complete = $freshCampaign->selection_finished_at !== null
                && $resolved >= (int) $freshCampaign->recipients_count;

            $freshCampaign->forceFill([
                'inbox_count' => $inbox,
                'skipped_count' => $skipped,
                'resolved_count' => $resolved,
                'status' => $complete ? NotificationCampaign::STATUS_COMPLETED : NotificationCampaign::STATUS_DELIVERING,
                'completed_at' => $complete ? now() : null,
                'failed_at' => null,
                'failure_code' => $complete ? null : $freshCampaign->failure_code,
            ])->save();
        }, 3);
    }

    public function failed(\Throwable $exception): void
    {
        $campaign = NotificationCampaign::query()->where('delivery_key', $this->deliveryKey)->first();
        if (!$campaign) return;

        NotificationCampaignRecipient::query()
            ->where('notification_campaign_id', $campaign->id)
            ->whereIn('user_id', $this->userIds)
            ->where('status', NotificationCampaignRecipient::STATUS_DELIVERING)
            ->update([
                'status' => NotificationCampaignRecipient::STATUS_PENDING,
                'claimed_at' => null,
                'resolution_code' => 'worker_retry',
                'updated_at' => now(),
            ]);
        NotificationCampaign::query()->whereKey($campaign->id)->update([
            'failure_code' => 'chunk_' . substr(hash('sha256', $exception::class), 0, 12),
            'updated_at' => now(),
        ]);
    }
}
