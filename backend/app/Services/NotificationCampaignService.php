<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Models\StudentNotification;
use App\Support\DurableJobDispatch;
use Illuminate\Support\Facades\DB;
use App\Support\NotificationCampaignIntent;

final class NotificationCampaignService
{
    public function __construct(private readonly StudentNotificationPresentationService $presentation)
    {
    }

    public function queue(NotificationCampaignIntent $intent): bool
    {
        $deliveryKey = $intent->deliveryKey;
        $presentation = $this->presentation->for(
            new StudentNotification([
                'notification_type' => $intent->notificationType,
                'notifiable_type' => $intent->notifiableType,
                'notifiable_id' => $intent->notifiableId,
                'link' => $intent->link,
                'image_url' => $intent->imageUrl,
                'action_label_ar' => $intent->actionLabelAr,
                'action_label_en' => $intent->actionLabelEn,
            ])
        );
        $link = $presentation['link'];
        $imageUrl = $presentation['image_url'];
        $actionLabelAr = $presentation['action_label_ar'];
        $actionLabelEn = $presentation['action_label_en'];

        $requestedAt = $intent->scheduledAt
            ? \Illuminate\Support\Carbon::instance($intent->scheduledAt)->utc()
            : now();
        $allowedAt = NotificationDeliveryPolicy::nextAllowedAt($intent->notificationType, $requestedAt);
        $scheduledAt = $allowedAt->isAfter(now()->addSeconds(30)) ? $allowedAt : null;
        $isScheduled = $scheduledAt
            && $scheduledAt->isAfter(now()->addSeconds(30));
        $campaignValues = [
            'notification_type' => $intent->notificationType,
            'audience' => $intent->audience->selector,
            'course_id' => $intent->audience->courseId,
            'notifiable_type' => $intent->notifiableType,
            'notifiable_id' => $intent->notifiableId,
            'user_ids' => array_values($intent->audience->userIds),
            'exclude_user_ids' => array_values($intent->audience->excludeUserIds),
            'authored_by' => $intent->authoredBy,
            'title_ar' => $intent->titleAr,
            'title_en' => $intent->titleEn,
            'message_ar' => $intent->messageAr,
            'message_en' => $intent->messageEn,
            'action_label_ar' => $actionLabelAr,
            'action_label_en' => $actionLabelEn,
            'link' => $link,
            'image_url' => $imageUrl,
            'status' => $isScheduled
                ? NotificationCampaign::STATUS_SCHEDULED
                : NotificationCampaign::STATUS_QUEUED,
            'queued_at' => $isScheduled ? null : now(),
        ];
        $campaignValues['scheduled_at'] = $isScheduled ? $scheduledAt : null;
        $campaign = NotificationCampaign::query()->firstOrCreate(
            ['delivery_key' => $deliveryKey],
            $campaignValues
        );

        if (!$campaign->wasRecentlyCreated) {
            if (!$this->sameImmutablePayload($campaign, $campaignValues, $intent)) {
                throw new \DomainException('notification_delivery_key_payload_mismatch');
            }
            return false;
        }

        if ($isScheduled) {
            return true;
        }

        // A queue connection can fail when the commit callback actually runs,
        // after the campaign and its image reference are already durable. Do
        // not turn that committed campaign into a false failed form submit (or
        // let the controller delete its image). Persist a retryable dead letter
        // while keeping the dashboard request successful and truthful.
        DB::afterCommit(static function () use ($deliveryKey, $campaign): void {
            try {
                DurableJobDispatch::now(new SendStudentNotification($deliveryKey));
            } catch (\Throwable $exception) {
                NotificationCampaign::query()
                    ->whereKey($campaign->getKey())
                    ->where('status', NotificationCampaign::STATUS_QUEUED)
                    ->update([
                        'status' => NotificationCampaign::STATUS_FAILED,
                        'failed_at' => now(),
                        'failure_code' => 'queue_' . substr(hash('sha256', $exception::class), 0, 12),
                        'updated_at' => now(),
                    ]);
                report($exception);
            }
        });
        return true;
    }

    /**
     * Manually recover a campaign after automatic delivery recovery is exhausted.
     * Existing inbox rows keep the same delivery key, so the retry fills only the
     * missing recipients instead of creating a second notification.
     */
    public function retry(NotificationCampaign $campaign): bool
    {
        $claimed = NotificationCampaign::query()
            ->whereKey($campaign->getKey())
            ->where('status', NotificationCampaign::STATUS_FAILED)
            ->update([
                'status' => NotificationCampaign::STATUS_QUEUED,
                'retry_count' => 0,
                'queued_at' => now(),
                'coordinator_finished_at' => null,
                'completed_at' => null,
                'failed_at' => null,
                'failure_code' => null,
                'updated_at' => now(),
            ]);

        if ($claimed !== 1) {
            return false;
        }

        $job = $this->jobForCampaign($campaign);

        try {
            DurableJobDispatch::afterCommit($job);
        } catch (\Throwable $exception) {
            NotificationCampaign::query()
                ->whereKey($campaign->getKey())
                ->where('status', NotificationCampaign::STATUS_QUEUED)
                ->update([
                    'status' => NotificationCampaign::STATUS_FAILED,
                    'failed_at' => now(),
                    'failure_code' => 'queue_' . substr(hash('sha256', $exception::class), 0, 12),
                    'updated_at' => now(),
                ]);
            throw $exception;
        }

        return true;
    }

    public function jobForCampaign(NotificationCampaign $campaign): SendStudentNotification
    {
        return new SendStudentNotification((string) $campaign->delivery_key);
    }

    /** @param array<string,mixed> $expected */
    private function sameImmutablePayload(
        NotificationCampaign $campaign,
        array $expected,
        NotificationCampaignIntent $intent
    ): bool
    {
        foreach ([
            'notification_type', 'audience', 'notifiable_type', 'title_ar', 'title_en',
            'message_ar', 'message_en',
        ] as $field) {
            if ((string) ($campaign->{$field} ?? '') !== (string) ($expected[$field] ?? '')) {
                return false;
            }
        }
        foreach (['course_id', 'notifiable_id'] as $field) {
            if ((int) ($campaign->{$field} ?? 0) !== (int) ($expected[$field] ?? 0)) {
                return false;
            }
        }
        if ((int) ($campaign->authored_by ?? 0) !== (int) ($expected['authored_by'] ?? 0)) {
            return false;
        }
        if ($intent->hasExplicitImage()
            && (string) ($campaign->image_url ?? '') !== (string) ($expected['image_url'] ?? '')) {
            return false;
        }

        // The destination and CTA are presentation snapshots. A course can be
        // withdrawn between attempts, so recomputing them must not make a retry
        // of the same delivery key look like a different campaign. The first
        // committed snapshot remains authoritative.

        return $intent->audience->matchesRecipients(
            (array) ($campaign->user_ids ?? []),
            (array) ($campaign->exclude_user_ids ?? [])
        );
    }
}
