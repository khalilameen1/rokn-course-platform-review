<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\ProjectSubmission;
use App\Support\ProjectSubmissionLifecycle;
use PHPUnit\Framework\TestCase;

final class ProjectSubmissionLifecycleTest extends TestCase
{
    public function test_it_maps_internal_submission_states_to_the_public_lifecycle(): void
    {
        self::assertSame('evaluating', ProjectSubmissionLifecycle::submissionStatus(ProjectSubmission::STATUS_PENDING));
        self::assertSame('passed', ProjectSubmissionLifecycle::submissionStatus(ProjectSubmission::STATUS_PASSED));
        self::assertSame('needs_changes', ProjectSubmissionLifecycle::submissionStatus(ProjectSubmission::STATUS_NEEDS_RESUBMISSION));
        self::assertSame('evaluating', ProjectSubmissionLifecycle::submissionStatus('unexpected'));
    }

    public function test_an_included_report_has_a_complete_lifecycle_before_and_after_thread_creation(): void
    {
        self::assertSame('queued', ProjectSubmissionLifecycle::reportStatus(true, null, 'queued', ProjectSubmission::STATUS_PASSED));
        self::assertSame('queued', ProjectSubmissionLifecycle::reportStatus(true, 'processing', 'processing', ProjectSubmission::STATUS_PASSED));
        self::assertSame('ready', ProjectSubmissionLifecycle::reportStatus(true, 'ready', 'ready', ProjectSubmission::STATUS_PASSED));
        self::assertSame('failed', ProjectSubmissionLifecycle::reportStatus(true, null, 'unavailable', ProjectSubmission::STATUS_PASSED));
        self::assertSame('queued', ProjectSubmissionLifecycle::reportStatus(true, 'failed', 'queued', ProjectSubmission::STATUS_PASSED));
        self::assertSame('not_requested', ProjectSubmissionLifecycle::reportStatus(true, null, null, ProjectSubmission::STATUS_PENDING));
        self::assertSame('not_requested', ProjectSubmissionLifecycle::reportStatus(true, null, null, ProjectSubmission::STATUS_NEEDS_RESUBMISSION));
    }

    public function test_pass_only_never_presents_a_phantom_report(): void
    {
        self::assertSame('not_included', ProjectSubmissionLifecycle::reportStatus(false, 'processing', 'queued', ProjectSubmission::STATUS_PASSED));
    }
}
