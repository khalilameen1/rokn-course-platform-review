<?php

namespace App\Services;

use App\Support\BusinessClock;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StreakService
{
    /**
     * Get distinct business-calendar days on which the learner checked in.
     *
     * Reward streaks are earned by opening the app. Reading section completion
     * here made the streak screen disagree with the daily reward response and
     * made a valid check-in look broken until a lesson happened to complete.
     *
     * @return array Array of Y-m-d date strings
     */
    public static function getActivityDatesForUser(int $userId, ?Carbon $from = null, ?Carbon $to = null): array
    {
        $timezone = BusinessClock::timezoneName();
        $query = DB::table('user_reward_checkins')
            ->where('user_id', $userId);

        if ($from !== null) {
            $query->where(
                'checkin_date',
                '>=',
                $from->copy()->timezone($timezone)->format('Y-m-d')
            );
        }
        if ($to !== null) {
            $query->where(
                'checkin_date',
                '<',
                $to->copy()->timezone($timezone)->format('Y-m-d')
            );
        }

        return $query
            ->distinct()
            ->orderBy('checkin_date')
            ->pluck('checkin_date')
            ->map(fn ($date): string => (string) $date)
            ->values()
            ->toArray();
    }

    /**
     * Get streak data for the authenticated user: week view, current streak, status.
     */
    public static function getStreakDataForUser(int $userId): array
    {
        $timezone = BusinessClock::timezoneName();
        $now = Carbon::instance(BusinessClock::now());

        // Week: Sunday to Saturday
        $weekStart = $now->copy()->startOfWeek(Carbon::SUNDAY);
        $weekEnd = $now->copy()->endOfWeek(Carbon::SATURDAY);

        // The week is only presentation. Streak truth must not reset after an
        // arbitrary lookback limit for learners who kept a longer run.
        $activityDates = self::getActivityDatesForUser(
            $userId,
            null,
            $weekEnd->copy()->addDay()->startOfDay()->utc()
        );
        $activitySet = array_flip($activityDates);

        $today = $now->format('Y-m-d');

        // Build week days
        $days = [];
        $current = $weekStart->copy();
        while ($current->lte($weekEnd)) {
            $dateStr = $current->format('Y-m-d');
            $days[] = [
                'date' => $dateStr,
                'day_name' => $current->format('D'),
                'has_streak' => isset($activitySet[$dateStr]),
            ];
            $current->addDay();
        }

        // A streak is not broken at midnight. Until today's check-in arrives,
        // yesterday remains the valid anchor; only a fully missed business
        // day breaks continuity.
        $currentStreak = 0;
        $checkedInToday = isset($activitySet[$today]);
        $yesterday = $now->copy()->subDay();
        $anchor = $checkedInToday
            ? $now->copy()
            : (isset($activitySet[$yesterday->format('Y-m-d')]) ? $yesterday : null);
        if ($anchor) {
            $check = $anchor->copy();
            while (isset($activitySet[$check->format('Y-m-d')])) {
                $currentStreak++;
                $check->subDay();
            }
        }

        $lastStreakBeforeGap = 0;
        if (! $anchor && $activityDates !== []) {
            $latest = collect($activityDates)
                ->filter(fn (string $date): bool => $date < $today)
                ->sortDesc()
                ->first();
            if ($latest) {
                $check = Carbon::parse($latest, $timezone)->startOfDay();
                while (isset($activitySet[$check->format('Y-m-d')])) {
                    $lastStreakBeforeGap++;
                    $check->subDay();
                }
            }
        }

        // Status
        $status = 'no_streak';
        if ($currentStreak >= 1) {
            $status = 'active';
        } elseif ($lastStreakBeforeGap >= 1) {
            $status = 'broken';
        }

        // Status messages
        $statusMessageAr = self::getStatusMessageAr($status, $currentStreak, $lastStreakBeforeGap);
        $statusMessageEn = self::getStatusMessageEn($status, $currentStreak, $lastStreakBeforeGap);

        return [
            'week' => [
                'start' => $weekStart->format('Y-m-d'),
                'end' => $weekEnd->format('Y-m-d'),
                'days' => $days,
            ],
            'current_streak' => $currentStreak,
            'last_streak_before_gap' => $lastStreakBeforeGap,
            'checked_in_today' => $checkedInToday,
            'grace_day' => ! $checkedInToday && $currentStreak > 0,
            'status' => $status,
            'status_message_ar' => $statusMessageAr,
            'status_message_en' => $statusMessageEn,
        ];
    }

    private static function getStatusMessageAr(string $status, int $currentStreak, int $lastStreakBeforeGap): string
    {
        if ($status === 'active') {
            return $currentStreak === 1
                ? 'يوم واحد متتالي بنشاط'
                : "آخر {$currentStreak} أيام متتالية بها نشاط";
        }
        if ($status === 'broken') {
            return $lastStreakBeforeGap === 1
                ? 'كان لديك يوم واحد بنشاط قبل التوقف'
                : "كان لديك {$lastStreakBeforeGap} أيام متتالية بنشاط قبل التوقف";
        }

        return 'لا يوجد سلسلة نشاط حالياً';
    }

    private static function getStatusMessageEn(string $status, int $currentStreak, int $lastStreakBeforeGap): string
    {
        if ($status === 'active') {
            return $currentStreak === 1
                ? '1 consecutive day with activity'
                : "Last {$currentStreak} consecutive days with activity";
        }
        if ($status === 'broken') {
            return $lastStreakBeforeGap === 1
                ? 'You had 1 day with activity before the gap'
                : "You had {$lastStreakBeforeGap} consecutive days with activity before the gap";
        }

        return 'No current streak';
    }
}
