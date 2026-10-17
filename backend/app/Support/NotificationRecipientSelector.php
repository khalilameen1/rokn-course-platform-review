<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FinancialEntitlementHold;
use App\Models\User;
use App\Services\NotificationDeliveryPolicy;
use Illuminate\Database\Eloquent\Builder;

/**
 * The single definition of who a campaign reaches at a given moment.
 *
 * Delivery (SendStudentNotification) and the dashboard's pre-send recipient
 * preview both use this query, so the count staff confirm is computed by the
 * same rules that later select recipients. Eligibility can still change
 * between preview and delivery (opt-outs, enrolments, cooldowns).
 */
final class NotificationRecipientSelector
{
    /**
     * @param list<int> $userIds
     * @param list<int> $excludeUserIds
     * @return Builder<User>
     */
    public static function students(
        string $notificationType,
        string $audience,
        ?int $courseId,
        array $userIds = [],
        array $excludeUserIds = [],
        ?string $deliveryKey = null
    ): Builder {
        $query = User::query()->students()->where('active', true);

        if (NotificationDeliveryPolicy::isMarketing($notificationType)) {
            $query->where('marketing_notifications_enabled', true);
        }

        $cooldownHours = NotificationDeliveryPolicy::cooldownHours($notificationType);
        if ($cooldownHours > 0) {
            $family = NotificationDeliveryPolicy::cooldownFamily($notificationType);
            $query->where(function ($eligible) use ($cooldownHours, $family, $deliveryKey): void {
                $eligible->whereDoesntHave('studentNotifications', function ($notifications) use ($cooldownHours, $family): void {
                    $notifications
                        ->whereIn('notification_type', $family)
                        ->where('created_at', '>=', now()->subHours($cooldownHours));
                });
                if ($deliveryKey !== null) {
                    $eligible->orWhereHas('studentNotifications', function ($notifications) use ($deliveryKey): void {
                        $notifications->where('delivery_key', $deliveryKey);
                    });
                }
            });
        }

        if ($userIds !== []) {
            $query->whereIn('id', $userIds);
        }

        if ($excludeUserIds !== []) {
            $query->whereNotIn('id', $excludeUserIds);
        }

        if ($courseId !== null && $audience === NotificationAudience::ENROLLED) {
            $query->whereHas('enrollments', function ($enrollments) use ($courseId): void {
                $enrollments
                    ->where('course_id', $courseId)
                    ->where('is_active', true)
                    ->where(function ($expiry): void {
                        $expiry->whereNull('expires_at')->orWhere('expires_at', '>', now());
                    });
            });
        } elseif ($courseId !== null && $audience === NotificationAudience::NOT_ENROLLED) {
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

        return $query;
    }
}
