<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\ProjectSubmissionEvaluationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

final class EvaluateProjectSubmission implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 80;
    public int $uniqueFor = 100;
    public bool $failOnTimeout = true;
    public string $executionId;

    public function __construct(public int $submissionId)
    {
        $this->executionId = (string) Str::uuid();
        $this->onQueue('ai-feedback');
    }

    public function uniqueId(): string { return 'project-review:'.$this->submissionId; }
    public function backoff(): array { return [15]; }

    public function handle(ProjectSubmissionEvaluationService $evaluations): void
    {
        $evaluations->evaluate($this->submissionId, $this->executionId);
    }

    public function failed(?\Throwable $exception): void
    {
        app(ProjectSubmissionEvaluationService::class)->fail($this->submissionId, $this->executionId);
    }
}
