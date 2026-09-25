<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\FeedbackReport;

final readonly class SupportCaseSubmissionResult
{
    public function __construct(
        public FeedbackReport $report,
        public bool $replayed,
        public ?string $accessToken
    ) {
    }
}
