<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/** One stored learner preference, not a second notification scheduler. */
final class LearningReminderSchedule
{
    public const HOURS = [10, 15, 20];
    public const DEFAULT_HOUR = 20;
    public const DEFAULT_TIMEZONE = 'Africa/Cairo';

    public static function hour(mixed $value): int
    {
        return in_array((int) $value, self::HOURS, true) ? (int) $value : self::DEFAULT_HOUR;
    }

    public static function timezone(mixed $value): string
    {
        $zone = trim((string) $value);
        return in_array($zone, \DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC), true)
            ? $zone : self::DEFAULT_TIMEZONE;
    }

    /** The hourly window permits bounded recovery without sending all day. */
    public static function dueHour(string $timezone, CarbonInterface $clock): ?int
    {
        $hour = (int) $clock->copy()->setTimezone(self::timezone($timezone))->format('G');
        return in_array($hour, self::HOURS, true) ? $hour : null;
    }
}
