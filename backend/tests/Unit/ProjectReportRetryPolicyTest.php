<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\ProjectReportRetryPolicy;
use PHPUnit\Framework\TestCase;

final class ProjectReportRetryPolicyTest extends TestCase
{
    public function test_it_allows_only_attempts_that_cannot_repeat_an_unknown_provider_charge(): void
    {
        self::assertTrue(ProjectReportRetryPolicy::allows('worker_failed', 0, null));
        self::assertTrue(ProjectReportRetryPolicy::allows('provider_unavailable', 0, 'failed', 'retry_safe'));
        self::assertTrue(ProjectReportRetryPolicy::allows('ai_rate_limited', 0, 'failed', 'retry_safe'));
        self::assertTrue(ProjectReportRetryPolicy::allows('worker_failed', 1, 'completed', 'settled', true));

        self::assertFalse(ProjectReportRetryPolicy::allows('provider_unavailable', 0, 'completed', 'settled', false));
        self::assertFalse(ProjectReportRetryPolicy::allows('worker_failed', 0, 'failed', 'started'));
        self::assertFalse(ProjectReportRetryPolicy::allows('project_context_missing', 0, null));
        self::assertFalse(ProjectReportRetryPolicy::allows('worker_failed', 2, null));
    }

    public function test_retired_inputs_only_allow_a_durable_replay_within_the_existing_reason_and_retry_limits(): void
    {
        self::assertFalse(ProjectReportRetryPolicy::allows('worker_failed', 0, null, inputsPurged: true));
        self::assertFalse(ProjectReportRetryPolicy::allows('provider_unavailable', 0, 'failed', 'retry_safe', inputsPurged: true));
        self::assertTrue(ProjectReportRetryPolicy::allows('worker_failed', 0, 'completed', 'settled', true, inputsPurged: true));
        self::assertTrue(ProjectReportRetryPolicy::allows('worker_failed', 0, 'reserved', 'landed', inputsPurged: true, hasLandedResponse: true));
        self::assertFalse(ProjectReportRetryPolicy::allows('worker_failed', 0, 'reserved', 'landed', inputsPurged: true));
        self::assertFalse(ProjectReportRetryPolicy::allows('worker_failed', 0, 'failed', 'landed', inputsPurged: true, hasLandedResponse: true));
        self::assertFalse(ProjectReportRetryPolicy::allows('worker_failed', 2, 'reserved', 'landed', inputsPurged: true, hasLandedResponse: true));
        self::assertFalse(ProjectReportRetryPolicy::allows('worker_failed', 2, 'completed', 'settled', true, inputsPurged: true));
        self::assertFalse(ProjectReportRetryPolicy::allows('report_not_included', 0, 'completed', 'settled', true, inputsPurged: true));
    }
}
