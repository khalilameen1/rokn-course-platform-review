<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\SendStudentNotification;
use App\Models\NotificationCampaign;
use App\Models\StudentNotification;
use App\Support\DurableJobDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class NotificationCampaignService
{
    /** @param array<int> $userIds @param array<int> $excludeUserIds */
    public function queue(
        string $notificationType,
        array $userIds,
        ?string $notifiableType,
        ?int $notifiableId,
        string $titleAr,
        string $titleEn,
        string $messageAr,
        string $messageEn,
        ?string $link,
        array $excludeUserIds,
        string $deliveryKey,
        ?int $courseId,
        string $audience,
        ?string $imageUrl = null,
        ?string $actionLabelAr = null,
        ?string $actionLabelEn = null,
        ?\DateTimeInterface $scheduledAt = null,
        ?int $authoredBy = null
    ): bool {
        $userIds = $this->normalizeUserIds($userIds);
        $excludeUserIds = $this->normalizeUserIds($excludeUserIds);
        $this->validateAudienceSelector($userIds, $excludeUserIds, $courseId, $audience);
        $deliveryKey = trim($deliveryKey);
        if ($deliveryKey === '') {
            $deliveryKey = (string) Str::uuid();
        } elseif (strlen($deliveryKey) > 64) {
            $deliveryKey = hash('sha256', $deliveryKey);
        }

        $hasExplicitImage = trim((string) $imageUrl) !== '';
        $presentation = app(StudentNotificationPresentationService::class)->for(
            new StudentNotification([
                'notification_type' => $notificationType,
                'notifiable_type' => $notifiableType,
                'notifiable_id' => $notifiableId,
                'link' => $link,
                'image_url' => $imageUrl,
                'action_label_ar' => $actionLabelAr,
                'action_label_en' => $actionLabelEn,
            ])
        );
        $link = $presentation['link'];
        $imageUrl = $presentation['image_url'];
        $actionLabelAr = $presentation['action_label_ar'];
        $actionLabelEn = $presentation['action_label_en'];

        $requestedAt = $scheduledAt
            ? \Illuminate\Support\Carbon::instance($scheduledAt)->utc()
            : now();
        $allowedAt = NotificationDeliveryPolicy::nextAllowedAt($notificationType, $requestedAt);
        $scheduledAt = $allowedAt->isAfter(now()->addSeconds(30)) ? $allowedAt : null;
        $isScheduled = $scheduledAt
            && $scheduledAt->isAfter(now()->addSeconds(30));
        $campaignValues = [
            'notification_type' => $notificationType,
            'audience' => $audience,
            'course_id' => $courseId,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'user_ids' => array_values($userIds),
            'exclude_user_ids' => array_values($excludeUserIds),
            'authored_by' => $authoredBy && $authoredBy > 0 ? $authoredBy : null,
            'title_ar' => $titleAr,
            'title_en' => $titleEn,
            'message_ar' => $messageAr,
            'message_en' => $messageEn,
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
            if (!$this->sameImmutablePayload($campaign, $campaignValues, $hasExplicitImage)) {
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

    /** @param array<int,mixed> $ids @return array<int> */
    private function normalizeUserIds(array $ids): array
    {
        $ids = array_values(array_filter(array_unique(array_map('intval', $ids)),
            static fn (int $id): bool => $id > 0));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /** @param array<int> $userIds @param array<int> $excludeUserIds */
    private function validateAudienceSelector(
        array $userIds,
        array $excludeUserIds,
        ?int $courseId,
        string $audience
    ): void {
        if (!in_array($audience, [
            SendStudentNotification::AUDIENCE_ALL,
            SendStudentNotification::AUDIENCE_ENROLLED,
            SendStudentNotification::AUDIENCE_NOT_ENROLLED,
        ], true)) {
            throw new \InvalidArgumentException('Unsupported notification audience selector.');
        }
        if ($courseId !== null && $courseId <= 0) {
            throw new \InvalidArgumentException('Course selector must contain a positive course ID.');
        }
        if ($audience !== SendStudentNotification::AUDIENCE_ALL && $courseId === null) {
            throw new \InvalidArgumentException('Course ID is required for a course notification audience.');
        }
        if (count($userIds) > SendStudentNotification::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification audience exceeds the safe broadcast limit.');
        }
        if (count($excludeUserIds) > SendStudentNotification::MAX_EXPLICIT_USER_IDS) {
            throw new \InvalidArgumentException('Explicit notification exclusions exceed the safe broadcast limit.');
        }
    }

    /** @param array<string,mixed> $expected */
    private function sameImmutablePayload(
        NotificationCampaign $campaign,
        array $expected,
        bool $hasExplicitImage
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
        if ($hasExplicitImage
            && (string) ($campaign->image_url ?? '') !== (string) ($expected['image_url'] ?? '')) {
            return false;
        }

        // The destination and CTA are presentation snapshots. A course can be
        // withdrawn between attempts, so recomputing them must not make a retry
        // of the same delivery key look like a different campaign. The first
        // committed snapshot remains authoritative.

        return $this->normalizeUserIds((array) ($campaign->user_ids ?? []))
                === $this->normalizeUserIds((array) ($expected['user_ids'] ?? []))
            && $this->normalizeUserIds((array) ($campaign->exclude_user_ids ?? []))
                === $this->normalizeUserIds((array) ($expected['exclude_user_ids'] ?? []));
    }
}
