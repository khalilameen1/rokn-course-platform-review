<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProjectReportRetryContractTest extends TestCase
{
    public function test_retry_route_is_throttled_and_the_response_advertises_only_safe_retries(): void
    {
        $routes = file_get_contents(dirname(__DIR__, 2).'/routes/api.php');
        $presenter = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/ProjectSubmissionPresenter.php'
        );
        $retryService = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/ProjectReportRetryService.php'
        );

        self::assertIsString($routes);
        self::assertIsString($presenter);
        self::assertIsString($retryService);
        self::assertStringContainsString(
            "project-submissions/{submission}/report/retry",
            $routes
        );
        self::assertStringContainsString("'throttle:3,1'", $routes);
        self::assertStringContainsString("'can_retry_report'", $presenter);
        self::assertStringContainsString("'report_retry_endpoint'", $presenter);
        self::assertStringContainsString('ProjectReportRetryPolicy::allows', $retryService);
        self::assertStringContainsString('(string) Str::uuid()', $retryService);
    }

    public function test_the_worker_uses_the_persisted_retry_request_identity(): void
    {
        $job = file_get_contents(
            dirname(__DIR__, 2).'/app/Jobs/GenerateProjectFeedback.php'
        );

        self::assertIsString($job);
        self::assertStringContainsString("'ai_feedback.request_id'", $job);
        self::assertStringContainsString('$requestId', $job);
    }
}
