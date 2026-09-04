<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\User;
use App\Models\NotificationCampaign;
use App\Models\NotificationCampaignRecipient;
use App\Models\Course;
use App\Models\FinancialEntitlementHold;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Support\DurableJobDispatch;

class SendStudentNotification implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const AUDIENCE_ALL = 'all';
    public const AUDIENCE_ENROLLED = 'enrolled';
    public const AUDIENCE_NOT_ENROLLED = 'not_enrolled';
    public const MAX_EXPLICIT_USER_IDS = 500;

    protected string $deliveryKey;

    public int $tries = 3;
    public int $timeout = 120;
    public int $uniqueFor = 900;
    public array $backoff = [15, 60, 180];

    public function __construct(string $deliveryKey)
    {
        $deliveryKey = trim($deliveryKey);
        if ($deliveryKey === '') {
            throw new \InvalidArgumentException('Notification delivery key is required.');
        }

        $this->deliveryKey = strlen($deliveryKey) <= 64
            ? $deliveryKey
            : hash('sha256', $deliveryKey);
        $this->onQueue((string) config('queue.channels.notifications', 'notifications'));
    }

    public function uniqueId(): string
    {
        return $this->deliveryKey;
    }

    public function handle(): void
    {
        try {
            $campaign = NotificationCampaign::query()
                ->where('delivery_key', $this->deliveryKey)
                ->firstOrFail();
            if (!$this->courseStillDeliverable($campaign)) {
                $this->finishWithdrawnCourseCampaign();
                return;
            }

            $claimedCampaign = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->whereIn('status', [
                    NotificationCampaign::STATUS_QUEUED,
                    NotificationCampaign::STATUS_DELIVERING,
                ])
                ->update([
                    'status' => NotificationCampaign::STATUS_DELIVERING,
                    'failed_at' => null,
                    'failure_code' => null,
                ]);
            if ($claimedCampaign !== 1) {
                return;
            }

            $notificationType = (string) $campaign->notification_type;
            $userIds = $this->normalizeUserIds((array) ($campaign->user_ids ?? []));
            $excludeUserIds = $this->normalizeUserIds((array) ($campaign->exclude_user_ids ?? []));
            $courseId = $campaign->course_id ? (int) $campaign->course_id : null;
            $audience = (string) $campaign->audience;
            $query = User::query()->students()->where('active', true);

            if (NotificationDeliveryPolicy::isMarketing($notificationType)) {
                $query->where('marketing_notifications_enabled', true);
            }

            $cooldownHours = NotificationDeliveryPolicy::cooldownHours($notificationType);
            if ($cooldownHours > 0) {
                $family = NotificationDeliveryPolicy::cooldownFamily($notificationType);
                $query->where(function ($eligible) use ($cooldownHours, $family): void {
                    $eligible->whereDoesntHave('studentNotifications', function ($notifications) use ($cooldownHours, $family): void {
                        $notifications
                            ->whereIn('notification_type', $family)
                            ->where('created_at', '>=', now()->subHours($cooldownHours));
                    })->orWhereHas('studentNotifications', function ($notifications): void {
                        $notifications->where('delivery_key', $this->deliveryKey);
                    });
                });
            }

            if ($userIds !== []) {
                $query->whereIn('id', $userIds);
            }

            if ($excludeUserIds !== []) {
                $query->whereNotIn('id', $excludeUserIds);
            }

            if ($courseId !== null && $audience === self::AUDIENCE_ENROLLED) {
                $query->whereHas('enrollments', function ($enrollments) use ($courseId): void {
                    $enrollments
                        ->where('course_id', $courseId)
                        ->where('is_active', true)
                        ->where(function ($expiry): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
            } elseif ($courseId !== null && $audience === self::AUDIENCE_NOT_ENROLLED) {
                $query->whereDoesntHave('enrollments', function ($enrollments) use ($courseId): void {
                    $enrollments
                        ->where('course_id', $courseId)
                        ->where('is_active', true)
                        ->where(function ($expiry): void {
                            $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                        });
                });
            }
            if ($courseId !== null) {
                $query->whereNotExists(function ($holds) use ($courseId): void {
                    $holds->selectRaw('1')
                        ->from('financial_entitlement_holds')
                        ->whereColumn('financial_entitlement_holds.user_id', 'users.id')
                        ->where('course_id', $courseId)
                        ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                        ->whereIn('entitlement_scope', ['course', 'plan', 'chat']);
                });
            }
            $queuedRecipientsCount = 0;

            if (!$campaign->selection_finished_at) {
                $query->where('id', '>', (int) $campaign->selection_cursor);
                $query->select('id')->orderBy('id')->chunkById(500, function ($students) use ($campaign): void {
                    $now = now();
                    $rows = $students->map(fn ($student): array => [
                        'notification_campaign_id' => $campaign->id,
                        'user_id' => (int) $student->id,
                        'status' => NotificationCampaignRecipient::STATUS_PENDING,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ])->all();
                    if ($rows !== []) {
                        NotificationCampaignRecipient::query()->insertOrIgnore($rows);
                        NotificationCampaign::query()->whereKey($campaign->id)->update([
                            'selection_cursor' => (int) $students->max('id'),
                            'updated_at' => now(),
                        ]);
                    }
                }, 'id');

                $queuedRecipientsCount = NotificationCampaignRecipient::query()
                    ->where('notification_campaign_id', $campaign->id)
                    ->count();
                NotificationCampaign::query()->whereKey($campaign->id)->update([
                    'recipients_count' => $queuedRecipientsCount,
                    'selection_finished_at' => now(),
                    'coordinator_finished_at' => now(),
                    'status' => $queuedRecipientsCount > 0
                        ? NotificationCampaign::STATUS_DELIVERING
                        : NotificationCampaign::STATUS_COMPLETED,
                    'completed_at' => $queuedRecipientsCount > 0 ? null : now(),
                    'failed_at' => null,
                    'failure_code' => null,
                    'updated_at' => now(),
                ]);
                $this->dispatchPendingRecipients($campaign);
                $this->completeResolvedCampaign($campaign, $queuedRecipientsCount);
            } else {
                NotificationCampaignRecipient::query()
                    ->where('notification_campaign_id', $campaign->id)
                    ->where('status', NotificationCampaignRecipient::STATUS_DELIVERING)
                    ->where('claimed_at', '<=', now()->subMinutes(15))
                    ->update([
                        'status' => NotificationCampaignRecipient::STATUS_PENDING,
                        'claimed_at' => null,
                        'resolution_code' => 'coordinator_recovery',
                        'updated_at' => now(),
                    ]);
                $this->dispatchPendingRecipients($campaign);
                $queuedRecipientsCount = (int) $campaign->recipients_count;
                NotificationCampaign::query()->whereKey($campaign->id)->update([
                    'coordinator_finished_at' => now(),
                    'updated_at' => now(),
                ]);
                $this->completeResolvedCampaign($campaign, $queuedRecipientsCount);
            }

            Log::info('Student notification chunks queued', [
                'notification_type' => $notificationType,
                'explicit_user_ids_count' => count($userIds),
                'excluded_user_ids_count' => count($excludeUserIds),
                'queued_recipients_count' => $queuedRecipientsCount,
                'audience'          => $audience,
                'course_id'         => $courseId,
                'notifiable_type'   => $campaign->notifiable_type,
                'notifiable_id'     => $campaign->notifiable_id,
                'delivery_key'      => $this->deliveryKey,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to send student notifications', [
                'delivery_key'      => $this->deliveryKey,
                'exception'         => $e::class,
                'error_fingerprint' => hash('sha256', $e->getMessage()),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        NotificationCampaign::query()
            ->where('delivery_key', $this->deliveryKey)
            ->whereIn('status', [
                NotificationCampaign::STATUS_QUEUED,
                NotificationCampaign::STATUS_DELIVERING,
            ])
            ->update([
                'status' => NotificationCampaign::STATUS_FAILED,
                'failed_at' => now(),
                'failure_code' => 'coordinator_' . substr(hash('sha256', $exception::class), 0, 12),
        ]);
        Log::error('SendStudentNotification job failed', [
            'delivery_key'      => $this->deliveryKey,
            'exception'         => $exception::class,
            'error_fingerprint' => hash('sha256', $exception->getMessage()),
        ]);
    }

    private function courseStillDeliverable(NotificationCampaign $campaign): bool
    {
        if (!$campaign->course_id) return true;
        $course = Course::query()->find((int) $campaign->course_id);
        if (!$course || !$course->isPublishedForLearning()) return false;

        return $campaign->canDeliverHiddenCourse()
            || (bool) $course->is_catalog_visible;
    }

    private function finishWithdrawnCourseCampaign(): void
    {
        NotificationCampaign::query()
            ->where('delivery_key', $this->deliveryKey)
            ->whereIn('status', [
                NotificationCampaign::STATUS_QUEUED,
                NotificationCampaign::STATUS_SCHEDULED,
                NotificationCampaign::STATUS_DELIVERING,
            ])
            ->update([
                'status' => NotificationCampaign::STATUS_COMPLETED,
                'recipients_count' => 0,
                'resolved_count' => 0,
                'completed_at' => now(),
                'coordinator_finished_at' => now(),
                'failure_code' => 'course_withdrawn_before_delivery',
                'updated_at' => now(),
            ]);
    }

    /**
     * @param array<int, mixed> $userIds
     * @return array<int>
     */
    private function normalizeUserIds(array $userIds): array
    {
        $normalized = array_map('intval', $userIds);

        return array_values(array_filter(array_unique($normalized), static fn (int $id): bool => $id > 0));
    }

    /** @param array<int> $userIds */
    private function dispatchChunk(array $userIds): void
    {
        if ($userIds === []) return;
        DurableJobDispatch::now(new DeliverStudentNotificationChunk(
            $userIds,
            $this->deliveryKey
        ));
    }

    private function dispatchPendingRecipients(NotificationCampaign $campaign): void
    {
        NotificationCampaignRecipient::query()
            ->where('notification_campaign_id', $campaign->id)
            ->where('status', NotificationCampaignRecipient::STATUS_PENDING)
            ->select(['id', 'user_id'])
            ->orderBy('id')
            ->chunkById(500, function ($recipients): void {
                $this->dispatchChunk($recipients->pluck('user_id')->map(
                    fn ($id): int => (int) $id
                )->all());
            });
    }

    private function completeResolvedCampaign(NotificationCampaign $campaign, int $recipientCount): void
    {
        DB::transaction(function () use ($campaign, $recipientCount): void {
            $freshCampaign = NotificationCampaign::query()
                ->whereKey($campaign->id)
                ->lockForUpdate()
                ->first();
            if (!$freshCampaign) return;
            $inbox = NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->where('status', NotificationCampaignRecipient::STATUS_INBOX)
                ->count();
            $skipped = NotificationCampaignRecipient::query()
                ->where('notification_campaign_id', $campaign->id)
                ->where('status', NotificationCampaignRecipient::STATUS_SKIPPED)
                ->count();
            $resolved = $inbox + $skipped;
            $complete = $resolved >= $recipientCount;
            $freshCampaign->forceFill([
                'inbox_count' => $inbox,
                'skipped_count' => $skipped,
                'resolved_count' => $resolved,
                'status' => $complete
                    ? NotificationCampaign::STATUS_COMPLETED
                    : NotificationCampaign::STATUS_DELIVERING,
                'completed_at' => $complete ? now() : null,
                'failed_at' => null,
                'failure_code' => $complete ? null : $freshCampaign->failure_code,
            ])->save();
        }, 3);
    }

}
