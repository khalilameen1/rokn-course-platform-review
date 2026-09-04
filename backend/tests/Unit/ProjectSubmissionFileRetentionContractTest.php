<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ProjectSubmissionFileRetentionContractTest extends TestCase
{
    public function test_retention_detaches_every_database_reference_before_deleting_bytes(): void
    {
        $service = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/ProjectSubmissionFileRetentionService.php'
        );

        self::assertIsString($service);
        self::assertStringContainsString("'submission_text' => null", $service);
        self::assertStringContainsString("'submission_file' => null", $service);
        self::assertStringContainsString("'submission_metadata' => \$retained", $service);
        self::assertStringContainsString("AiInputAttachment::OWNER_PROJECT_SUBMISSION", $service);
        self::assertStringNotContainsString('UserProjectEvaluation', $service);
        self::assertStringContainsString('deleteOrQueue', $service);
    }

    public function test_retryable_reports_are_retained_but_terminal_files_are_bounded(): void
    {
        $service = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/ProjectSubmissionFileRetentionService.php'
        );
        $config = file_get_contents(dirname(__DIR__, 2).'/config/retention.php');

        self::assertIsString($service);
        self::assertIsString($config);
        self::assertStringContainsString("['ready', 'not_applicable']", $service);
        self::assertStringContainsString("\$status === 'unavailable'", $service);
        self::assertStringContainsString('$expiredTerminalFailure', $service);
        self::assertStringContainsString('project_submission_failed_files_days', $service);
        self::assertStringContainsString('project_submission_failed_files_days', $config);
        self::assertStringContainsString('ProjectSubmission::STATUS_NEEDS_RESUBMISSION', $service);
        self::assertStringContainsString("['ready',", $service);
        self::assertStringContainsString("'not_applicable',", $service);
    }

    public function test_account_deletion_collects_secondary_submission_files(): void
    {
        $deletion = file_get_contents(
            dirname(__DIR__, 2).'/app/Services/AccountDeletionService.php'
        );

        self::assertIsString($deletion);
        self::assertStringContainsString("data_get(\$submission->submission_metadata, 'files', [])", $deletion);
        self::assertStringContainsString("\$file['storage_disk']", $deletion);
    }
}
