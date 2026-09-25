<?php

namespace App\Services;

use App\Jobs\SendUserPushNotification;
use App\Models\CoinEarningMethod;
use App\Models\StudentNotification;
use App\Models\User;
use App\Support\DurableJobDispatch;
use App\Support\StudentNotificationIntent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Database\QueryException;

class StudentNotificationService
{
    public function __construct(
        private readonly EngagementMessageService $templates,
        private readonly StudentNotificationPresentationService $presentation
    ) {
    }

    public const TYPE_COURSE_ENROLLED = 'course_enrolled';
    public const TYPE_COINS_CLAIMED = 'coins_claimed';
    public const TYPE_PACKAGE_PURCHASED = 'package_purchased';
    public const TYPE_COURSE_COMPLETED = 'course_completed';
    public const TYPE_CERTIFICATE_READY = 'certificate_ready';
    public const TYPE_INSTITUTIONAL_GRANT = 'institutional_grant';
    public const TYPE_WHATSAPP_CONNECTED = 'whatsapp_connected';
    public const TYPE_SUPPORT_CASE_UPDATE = 'support_case_update';
    public const TYPE_PROJECT_UPDATE = 'project_update';

    /** Null when the template is disabled or the recipient cannot receive it. */
    public function notifyUser(User $user, StudentNotificationIntent $intent): ?StudentNotification
    {
        $copy = $this->templates->notificationPayload(
            $intent->notificationType,
            $intent->templateVariables,
            [
                'title_ar' => $intent->titleAr,
                'title_en' => $intent->titleEn,
                'message_ar' => $intent->messageAr,
                'message_en' => $intent->messageEn,
                'action_label_ar' => null,
                'action_label_en' => null,
                'image_url' => $intent->imageUrl,
            ]
        );
        if ($copy === null) {
            return null;
        }
        $snapshot = $this->deliverySnapshot(
            $intent->notificationType,
            $intent->notifiableType,
            $intent->notifiableId,
            $intent->link ?: ($copy['template_link'] ?? null),
            $intent->imageUrl ?: ($copy['image_url'] ?? null),
            $copy['action_label_ar'] ?? null,
            $copy['action_label_en'] ?? null
        );
        $deliveryKey = $this->normalizeDeliveryKey($intent->deliveryKey ?: (string) Str::uuid());
        $identity = [
            'user_id' => $user->id,
            'delivery_key' => $deliveryKey,
        ];
        $notification = DB::transaction(function () use (
            $user,
            $identity,
            $intent,
            $copy,
            $snapshot
        ): ?StudentNotification {
            // User is the aggregate lock. Account deletion takes the same lock
            // before clearing inbox rows, so a stale callback cannot recreate
            // personal notifications after the account has gone away.
            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->first();
            if (!$lockedUser || !NotificationDeliveryPolicy::allowsInbox($lockedUser, $intent->notificationType)) {
                return null;
            }

            try {
                return StudentNotification::query()->firstOrCreate($identity, [
                    'notification_type' => $intent->notificationType,
                    'notifiable_type' => $intent->notifiableType,
                    'notifiable_id' => $intent->notifiableId,
                    'title_ar' => $copy['title_ar'],
                    'title_en' => $copy['title_en'],
                    'message_ar' => $copy['message_ar'],
                    'message_en' => $copy['message_en'],
                    'link' => $snapshot['link'],
                    'image_url' => $snapshot['image_url'],
                    'action_label_ar' => $snapshot['action_label_ar'],
                    'action_label_en' => $snapshot['action_label_en'],
                    'is_read' => false,
                ]);
            } catch (QueryException $exception) {
                $existing = StudentNotification::query()->where($identity)->first();
                if (!$existing) {
                    throw $exception;
                }

                return $existing;
            }
        });

        if (!$notification) {
            return null;
        }

        // Persist first so the in-app inbox is authoritative. Push delivery is
        // an after-commit side effect and can scale independently on workers.
        if ($notification->wasRecentlyCreated) {
            $this->enqueuePushAfterCommit((int) $notification->id);
        }

        return $notification;
    }

    /**
     * Persist the welcome receipt inside the grant's transaction and user lock.
     * The template key intentionally differs from the persisted receipt type.
     */
    public function welcomeRewardReceipt(
        User $user,
        int $coinsAmount,
        ?int $methodId
    ): void
    {
        $copy = $this->templates->notificationPayload(
            'welcome_bonus_received',
            ['coins' => $coinsAmount],
            [
                'title_ar' => 'وصلت هديتك',
                'title_en' => 'Your balance is ready',
                'message_ar' => $this->arabicDigits($coinsAmount)
                    . ' عملة ركن في محفظتك',
                'message_en' => $coinsAmount . ' Rokn coins are in your wallet',
                'action_label_ar' => 'افتح المحفظة',
                'action_label_en' => 'View balance',
            ]
        );
        if ($copy === null) {
            return;
        }
        $snapshot = $this->deliverySnapshot(
            self::TYPE_COINS_CLAIMED,
            $methodId ? CoinEarningMethod::class : null,
            $methodId,
            $copy['template_link'] ?? null,
            $copy['image_url'] ?? null,
            $copy['action_label_ar'] ?? null,
            $copy['action_label_en'] ?? null
        );

        // The delivery key is the stable identity for this one-time receipt.
        // The surrounding user lock prevents concurrent first-login duplicates.
        $notification = StudentNotification::firstOrCreate(
            [
                'user_id' => $user->id,
                'delivery_key' => $this->normalizeDeliveryKey('registration-bonus:' . $user->id),
            ],
            [
                'notification_type' => self::TYPE_COINS_CLAIMED,
                'notifiable_type' => $methodId ? CoinEarningMethod::class : null,
                'notifiable_id' => $methodId,
                'title_ar' => $copy['title_ar'],
                'title_en' => $copy['title_en'],
                'message_ar' => $copy['message_ar'],
                'message_en' => $copy['message_en'],
                'link' => $snapshot['link'],
                'image_url' => $snapshot['image_url'],
                'action_label_ar' => $snapshot['action_label_ar'],
                'action_label_en' => $snapshot['action_label_en'],
                'is_read' => false,
            ]
        );

        if (!$notification->wasRecentlyCreated) {
            return;
        }

        $this->enqueuePushAfterCommit((int) $notification->id);
    }

    private function enqueuePushAfterCommit(int $notificationId): void
    {
        // Catch inside the commit callback, not only around its registration.
        // Queue connections fail when the callback actually runs; allowing that
        // exception out can make a completed purchase or reward look failed.
        DB::afterCommit(static function () use ($notificationId): void {
            try {
                DurableJobDispatch::now(new SendUserPushNotification($notificationId));
            } catch (\Throwable $exception) {
                // The inbox row is durable and RetryStalledNotificationPushes
                // will enqueue it after the queue connection returns.
                report($exception);
            }
        });
    }

    /** @return array{link:string,image_url:?string,action_label_ar:string,action_label_en:string} */
    private function deliverySnapshot(
        string $type,
        ?string $notifiableType,
        ?int $notifiableId,
        ?string $link,
        ?string $imageUrl,
        ?string $actionLabelAr,
        ?string $actionLabelEn
    ): array {
        $prototype = new StudentNotification([
            'notification_type' => $type,
            'notifiable_type' => $notifiableType,
            'notifiable_id' => $notifiableId,
            'link' => $link,
            'image_url' => $imageUrl,
            'action_label_ar' => $actionLabelAr,
            'action_label_en' => $actionLabelEn,
        ]);

        $presentation = $this->presentation->for($prototype);

        return $presentation;
    }

    private function arabicDigits(int $value): string
    {
        return strtr((string) $value, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }

    private function normalizeDeliveryKey(string $deliveryKey): string
    {
        $deliveryKey = trim($deliveryKey);

        return strlen($deliveryKey) <= 64 ? $deliveryKey : hash('sha256', $deliveryKey);
    }
}
