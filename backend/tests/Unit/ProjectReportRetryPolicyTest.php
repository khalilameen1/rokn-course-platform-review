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
}
