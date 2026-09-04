<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\ProjectSubmission;

final class ProjectSubmissionLifecycle
{
    public const EVALUATING = 'evaluating';
    public const PASSED = 'passed';
    public const NEEDS_CHANGES = 'needs_changes';

    public const REPORT_NOT_INCLUDED = 'not_included';
    public const REPORT_NOT_REQUESTED = 'not_requested';
    public const REPORT_QUEUED = 'queued';
    public const REPORT_READY = 'ready';
    public const REPORT_FAILED = 'failed';

    public static function submissionStatus(string $internalStatus): string
    {
        return match ($internalStatus) {
            ProjectSubmission::STATUS_PENDING => self::EVALUATING,
            ProjectSubmission::STATUS_PASSED => self::PASSED,
            ProjectSubmission::STATUS_NEEDS_RESUBMISSION => self::NEEDS_CHANGES,
            default => self::EVALUATING,
        };
    }

    public static function reportStatus(
        bool $reportIncluded,
        ?string $threadStatus,
        ?string $generationStatus,
        string $submissionReviewStatus
    ): string {
        if (!$reportIncluded) {
            return self::REPORT_NOT_INCLUDED;
        }
        if ($submissionReviewStatus !== ProjectSubmission::STATUS_PASSED) {
            return self::REPORT_NOT_REQUESTED;
        }

        if ($threadStatus === 'ready') {
            return self::REPORT_READY;
        }
        if ($generationStatus === 'unavailable') {
            return self::REPORT_FAILED;
        }
        if (
            $threadStatus === 'failed'
            && !in_array($generationStatus, ['queued', 'processing', 'ready'], true)
        ) {
            return self::REPORT_FAILED;
        }

        return self::REPORT_QUEUED;
    }

    private function __construct()
    {
    }
}
