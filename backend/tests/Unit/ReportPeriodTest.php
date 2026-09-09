<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Tests\TestCase;

final class ReportPeriodTest extends TestCase
{
    public function test_today_uses_cairo_midnight_and_yesterday_the_same_elapsed_time(): void
    {
        config(['app.business_timezone' => 'Africa/Cairo']);
        $period = ReportPeriod::fromKey('today', CarbonImmutable::parse('2026-09-09 12:00:00', 'UTC'));
        self::assertSame('2026-09-08 21:00:00', $period->start->toDateTimeString());
        self::assertSame('2026-09-07 21:00:00', $period->previous()->start->toDateTimeString());
        self::assertSame('2026-09-08 12:00:00', $period->previous()->end->toDateTimeString());
    }

    public function test_rolling_periods_are_equal_contiguous_and_half_open(): void
    {
        foreach (['7d' => 7, '30d' => 30, '90d' => 90] as $key => $days) {
            $period = ReportPeriod::fromKey($key, CarbonImmutable::parse('2026-09-09 12:00:00Z'));
            $previous = $period->previous();
            self::assertSame($days * 86400, $period->end->timestamp - $period->start->timestamp);
            self::assertEquals($period->start, $previous->end);
            self::assertSame($days * 86400, $previous->end->timestamp - $previous->start->timestamp);
            self::assertTrue($period->contains($period->start));
            self::assertFalse($previous->contains($period->start));
            self::assertFalse($period->contains($period->end));
        }
    }

    public function test_all_time_has_no_invented_previous_period(): void
    {
        $period = ReportPeriod::fromKey('all');
        self::assertNull($period->start);
        self::assertNull($period->previous());
        self::assertTrue($period->contains('2020-01-01'));
        self::assertSame('unavailable', ReportPeriod::compare(50, null)['status']);
    }

    public function test_today_comparison_preserves_local_time_across_daylight_saving_changes(): void
    {
        config(['app.business_timezone' => 'Africa/Cairo']);
        foreach (['2026-04-24 12:00:00Z', '2026-10-30 12:00:00Z'] as $now) {
            $period = ReportPeriod::fromKey('today', CarbonImmutable::parse($now));
            $previous = $period->previous();
            self::assertLessThanOrEqual($period->start->timestamp, $previous->end->timestamp);
            self::assertSame($period->end->setTimezone('Africa/Cairo')->format('H:i'),
                $previous->end->setTimezone('Africa/Cairo')->format('H:i'));
        }
    }

    public function test_growth_preserves_unknowns_zero_baselines_and_negative_margins(): void
    {
        self::assertSame('new', ReportPeriod::compare(10, 0)['status']);
        self::assertNull(ReportPeriod::compare(10, 0)['percentage']);
        self::assertSame(0.0, ReportPeriod::compare(0, 0)['percentage']);
        self::assertSame(-100.0, ReportPeriod::compare(0, 10)['percentage']);
        self::assertSame(50.0, ReportPeriod::compare(-5, -10)['percentage']);
        self::assertNull(ReportPeriod::compare(null, 10)['percentage']);
        self::assertSame('up', ReportPeriod::compare(1000.1, 1000)['status']);
        self::assertSame('down', ReportPeriod::compare(999.9, 1000)['status']);
    }

    public function test_unknown_period_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ReportPeriod::fromKey('whatever');
    }
}
