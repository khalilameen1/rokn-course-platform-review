<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\User;

final class NotificationDeliveryPolicy
{
    private const MARKETING_TYPES = [
        'admin_broadcast',
        'course_promotion',
        'course_recommendation',
        'new_course',
        'coin_offer',
    ];

    private const LEARNING_TYPES = [
        'learning_nudge',
        'learning_reminder',
        'streak_reminder',
        'continue_course',
    ];

    public static function isMarketing(string $type): bool
    {
        return in_array($type, self::MARKETING_TYPES, true);
    }

    /** @return array<int,string> */
    public static function marketingTypes(): array
    {
        return self::MARKETING_TYPES;
    }

    public static function isLearningReminder(string $type): bool
    {
        return in_array($type, self::LEARNING_TYPES, true);
    }

    public static function defaultCooldownHours(string $type): int
    {
        if (self::isMarketing($type)) return 72;
        if (self::isLearningReminder($type)) return 24;
        return 0;
    }

    public static function cooldownHours(string $type): int
    {
        $configured = AdminNotification::query()
            ->where('system_key', $type)
            ->value('cooldown_hours');

        return $configured === null
            ? self::defaultCooldownHours($type)
            : max(0, (int) $configured);
    }

    /** @return array<int,string> */
    public static function frequencyFamily(string $type): array
    {
        if (self::isMarketing($type)) return self::MARKETING_TYPES;
        if (self::isLearningReminder($type)) return self::LEARNING_TYPES;
        return [$type];
    }

    /** @return array<int,string> */
    public static function cooldownFamily(string $type): array
    {
        return match ($type) {
            'new_course_lesson', 'course_update' => [
                'new_course_lesson',
                'course_update',
            ],
            'learning_nudge', 'continue_course' => [
                'learning_nudge',
                'continue_course',
            ],
            default => self::frequencyFamily($type),
        };
    }

    /** Marketing and retention pushes wait for the shared quiet window to end. */
    public static function nextAllowedAt(string $type, ?\DateTimeInterface $requested = null): \Illuminate\Support\Carbon
    {
        $time = $requested
            ? \Illuminate\Support\Carbon::instance($requested)->setTimezone('Africa/Cairo')
            : now('Africa/Cairo');
        if (!self::isMarketing($type) && !self::isLearningReminder($type)) {
            return $time->utc();
        }
        $hour = (int) $time->format('G');
        if ($hour >= 22) return $time->copy()->addDay()->startOfDay()->addHours(9)->utc();
        if ($hour < 9) return $time->copy()->startOfDay()->addHours(9)->utc();
        return $time->utc();
    }

    public static function allowsInbox(User $user, string $type): bool
    {
        return (bool) $user->active
            && strtolower(trim((string) $user->role)) === 'client'
            && (!self::isMarketing($type) || (bool) $user->marketing_notifications_enabled);
    }

    /** Service receipts remain in the inbox; this only controls waking a device. */
    public static function allowsPush(User $user, string $type): bool
    {
        return self::allowsInbox($user, $type) && (bool) $user->notifications_status;
    }
}
