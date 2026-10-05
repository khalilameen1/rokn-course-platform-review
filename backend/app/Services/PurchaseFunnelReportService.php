<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\BusinessClock;
use App\Support\ReportPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Read-only sequential conversion, separate from independent event activity.
 *
 * Reference semantics, not copied PostHog engine code:
 * https://posthog.com/docs/product-analytics/funnels
 * Shipped use: https://posthog.com/customers/supabase
 * SQL composition reuses Laravel's existing Query Builder; the PostHog
 * Python/HogQL/ClickHouse engine is not a compatible PHP/MySQL dependency.
 *
 * The selected report period is the entrance cohort. Subsequent steps may
 * follow that period's end, within the conversion window and up to observed_at.
 * Identity means the stored actor_key, not an inferred guest/account merge.
 * This report neither proves cash collection nor mutates commerce records.
 */
final class PurchaseFunnelReportService
{
    public function __construct(private readonly SequentialProductEventQuery $journeys) {}

    private const WINDOW_DAYS = 14;
    // A valid requested quote is the common commerce entry, not proof of a
    // human impression. Modal/preview/task activity stays separate. All three
    // transitions use server time: device clock skew cannot reverse the flow.
    private const STEPS = ['checkout_quoted', 'purchase_started', 'purchase_completed'];

    /** @return array<string, mixed> */
    public function report(?int $courseId, ReportPeriod $period): array
    {
        $observedAt = BusinessClock::utcNow()->startOfSecond();
        $journeys = $this->journeys->build($courseId, $period, self::STEPS,
            ['course_id'], self::WINDOW_DAYS, $observedAt);

        // Every entrance is a candidate, so a failed first visit cannot erase
        // a later successful attempt. Repeats/courses still count an actor once.
        // Course equality is enforced BEFORE this cross-course actor roll-up.
        $actors = DB::query()->fromSub($journeys, 'journey')
            ->select('actor_key')->selectRaw('MAX(deadline_at) as latest_deadline_at')
            ->groupBy('actor_key');
        foreach (array_keys(self::STEPS) as $index) {
            $actors->selectRaw('MAX(step_'.$index.'_at) as step_'.$index.'_at');
        }
        $summary = DB::query()->fromSub($actors, 'actor');
        foreach (array_keys(self::STEPS) as $index) {
            $summary->selectRaw('COUNT(step_'.$index.'_at) as step_'.$index.'_actors');
        }
        $summary->selectRaw(
            'COALESCE(SUM(CASE WHEN step_2_at IS NULL AND latest_deadline_at >= ? THEN 1 ELSE 0 END), 0) as pending_actors',
            [$observedAt->toDateTimeString()],
        );
        $totals = $summary->first();
        $entered = (int) $totals->step_0_actors;
        $previous = null;
        $steps = [];
        foreach (self::STEPS as $index => $event) {
            $count = (int) $totals->{'step_'.$index.'_actors'};
            $steps[] = [
                'event' => $event,
                'actors' => $count,
                'conversion_from_entry' => $entered > 0 ? round(100 * $count / $entered, 1) : null,
                'conversion_from_previous' => $previous > 0 ? round(100 * $count / $previous, 1) : null,
                'not_reached_yet' => $previous === null ? null : $previous - $count,
            ];
            $previous = $count;
        }

        return [
            'steps' => $steps,
            'window_days' => self::WINDOW_DAYS,
            'observed_at' => $observedAt,
            'pending_actors' => (int) $totals->pending_actors,
            'course_id' => $courseId,
        ];
    }

}
