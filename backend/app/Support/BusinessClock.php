<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * One boundary between UTC persistence and ROKN's business calendar.
 *
 * Instants are persisted and compared in UTC. Calendar days and values typed
 * into datetime-local controls are interpreted in the configured business
 * timezone, including its daylight-saving transitions.
 */
final class BusinessClock
{
    public static function timezoneName(): string
    {
        $timezone = (string) config('app.business_timezone', 'Africa/Cairo');

        try {
            new DateTimeZone($timezone);
        } catch (\Throwable) {
            return 'Africa/Cairo';
        }

        return $timezone;
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezoneName());
    }

    public static function utcNow(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC');
    }

    public static function toUtc(DateTimeInterface|string|null $value): ?CarbonImmutable
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->utc();
    }

    /** Interpret an HTML datetime-local value as a business-time instant. */
    public static function localInputToUtc(mixed $value): ?CarbonImmutable
    {
        $value = trim((string) ($value ?? ''));
        if ($value === '') {
            return null;
        }

        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            try {
                $date = CarbonImmutable::createFromFormat($format, $value, self::timezoneName());
                if ($date !== false && $date->format($format) === $value) {
                    return $date->utc();
                }
            } catch (\Throwable) {
                // Try the next accepted datetime-local precision.
            }
        }

        throw new InvalidArgumentException('Invalid business datetime.');
    }

    /** Render a stored UTC instant for an HTML datetime-local control. */
    public static function forDateTimeInput(DateTimeInterface|string|null $value): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        return CarbonImmutable::parse($value)
            ->utc()
            ->setTimezone(self::timezoneName())
            ->format('Y-m-d\TH:i');
    }

    public static function format(DateTimeInterface|string|null $value, string $format = 'Y-m-d H:i'): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        return CarbonImmutable::parse($value)
            ->utc()
            ->setTimezone(self::timezoneName())
            ->locale('ar')
            ->format($format);
    }

    /** A deterministic short label for human-facing dashboards and messages. */
    public static function relative(DateTimeInterface|string|null $value, ?string $locale = null): string
    {
        if ($value === null || trim((string) $value) === '') {
            return '';
        }

        $locale = RoknLocale::normalize($locale ?? app()->getLocale()) ?? RoknLocale::ARABIC;
        $instant = CarbonImmutable::parse($value)->utc()->setTimezone(self::timezoneName());
        $now = self::now();
        $elapsed = $now->getTimestamp() - $instant->getTimestamp();
        if ($elapsed < -300) {
            return self::display($value);
        }
        if ($elapsed < 60) {
            return $locale === RoknLocale::ARABIC ? 'الآن' : 'now';
        }

        $calendarDays = (int) round(
            $instant->startOfDay()->diffInDays($now->startOfDay(), false)
        );
        if ($calendarDays === 1) {
            return $locale === RoknLocale::ARABIC ? 'أمس' : 'yesterday';
        }
        if ($calendarDays > 1 && $calendarDays <= 6) {
            return $locale === RoknLocale::ARABIC
                ? 'منذ '.self::arabicCount($calendarDays, 'يوم', 'يومين', 'أيام', 'يومًا', 'يوم')
                : $calendarDays.' days ago';
        }
        if ($calendarDays > 6) {
            return self::display($value);
        }

        if ($elapsed < 3600) {
            $minutes = max(1, intdiv($elapsed, 60));

            return $locale === RoknLocale::ARABIC
                ? 'منذ '.self::arabicCount($minutes, 'دقيقة', 'دقيقتين', 'دقائق', 'دقيقة', 'دقيقة')
                : ($minutes === 1 ? '1 minute ago' : $minutes.' minutes ago');
        }

        $hours = max(1, intdiv($elapsed, 3600));

        return $locale === RoknLocale::ARABIC
            ? 'منذ '.self::arabicCount($hours, 'ساعة', 'ساعتين', 'ساعات', 'ساعة', 'ساعة')
            : ($hours === 1 ? '1 hour ago' : $hours.' hours ago');
    }

    /** Format a visible Cairo date with the UI locale's digits. */
    public static function display(DateTimeInterface|string|null $value, string $format = 'Y-m-d H:i'): string
    {
        $formatted = self::format($value, $format);
        if ($formatted === '' || ! RoknLocale::isArabic()) {
            return $formatted;
        }

        return strtr($formatted, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }

    private static function arabicCount(
        int $value,
        string $one,
        string $two,
        string $few,
        string $many,
        string $other
    ): string {
        if ($value === 1) {
            return $one;
        }
        if ($value === 2) {
            return $two;
        }
        $modulo = $value % 100;
        $noun = $modulo >= 3 && $modulo <= 10
            ? $few
            : ($modulo >= 11 && $modulo <= 99 ? $many : $other);

        return strtr((string) $value, [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]).' '.$noun;
    }

    /**
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} UTC half-open range
     */
    public static function localDayRangeUtc(string $from, ?string $to = null): array
    {
        $start = self::localDate($from)->startOfDay();
        $endExclusive = self::localDate($to ?? $from)->addDay()->startOfDay();

        if ($endExclusive->lessThanOrEqualTo($start)) {
            throw new InvalidArgumentException('Invalid business date range.');
        }

        return [$start->utc(), $endExclusive->utc()];
    }

    public static function localDate(string $value): CarbonImmutable
    {
        $value = trim($value);
        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, self::timezoneName());
        if ($date === false || $date->format('Y-m-d') !== $value) {
            throw new InvalidArgumentException('Invalid business date.');
        }

        return $date;
    }
}
