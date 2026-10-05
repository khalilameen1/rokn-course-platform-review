<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * One ordered journey query for purchase and lesson conversion.
 * PostHog sequential/cohort semantics, not its Python/ClickHouse source:
 * https://posthog.com/docs/product-analytics/funnels
 * Real use: https://posthog.com/customers/supabase
 * Reuses Laravel Query Builder already installed in this project.
 */
final class SequentialProductEventQuery
{
    /** @param list<string> $steps @param list<string> $dimensions */
    public function build(?int $courseId, ReportPeriod $period, array $steps,
        array $dimensions, int $windowDays, CarbonImmutable $observedAt): Builder
    {
        // Column identifiers are an internal contract, never request values.
        if (!in_array($dimensions, [['course_id'], ['course_id', 'lesson_id']], true)
            || count($steps) < 2 || $windowDays < 1 || $windowDays > 365) {
            throw new \LogicException('Unsupported product journey dimensions or window.');
        }
        $entries = $period->apply(DB::table('product_events as entry'), 'entry.occurred_at')
            ->where('entry.event_name', $steps[0])->where('entry.source', 'server')
            ->whereNotNull('entry.actor_key')
            ->when($courseId !== null, fn ($query) => $query->where('entry.course_id', $courseId))
            ->where('entry.occurred_at', '<', $observedAt)
            ->select('entry.actor_key')
            ->selectRaw('entry.occurred_at as step_0_at')
            ->selectRaw($this->deadlineExpression($windowDays).' as deadline_at');
        foreach ($dimensions as $dimension) {
            $entries->addSelect('entry.'.$dimension)->whereNotNull('entry.'.$dimension);
        }
        $journeys = $entries;
        foreach (array_slice($steps, 1, null, true) as $index => $event) {
            $nextStep = DB::table('product_events as next_step')
                ->selectRaw('MIN(next_step.occurred_at)')
                ->whereColumn('next_step.actor_key', 'journey.actor_key')
                ->where('next_step.event_name', $event)->where('next_step.source', 'server')
                // Storage is second-precision; receipt order is not event order.
                ->whereColumn('next_step.occurred_at', '>=', 'journey.step_'.($index - 1).'_at')
                ->whereColumn('next_step.occurred_at', '<=', 'journey.deadline_at')
                ->where('next_step.occurred_at', '<', $observedAt);
            foreach ($dimensions as $dimension) {
                $nextStep->whereColumn('next_step.'.$dimension, 'journey.'.$dimension);
            }
            // Retain every entry candidate: an early unsuccessful visit must
            // not erase a later successful attempt inside its own window.
            $journeys = DB::query()->fromSub($journeys, 'journey')
                ->select('journey.*')->selectSub($nextStep, 'step_'.$index.'_at');
        }
        return $journeys;
    }

    private function deadlineExpression(int $days): string
    {
        return match (DB::connection()->getDriverName()) {
            'mysql', 'mariadb' => "DATE_ADD(entry.occurred_at, INTERVAL {$days} DAY)",
            'sqlite' => "datetime(entry.occurred_at, '+{$days} days')",
            'pgsql' => "entry.occurred_at + INTERVAL '{$days} days'",
            'sqlsrv' => "DATEADD(day, {$days}, entry.occurred_at)",
            default => throw new \LogicException('Unsupported funnel report database driver.'),
        };
    }
}
