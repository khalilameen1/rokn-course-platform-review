<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateProjectFeedbackReply;
use App\Jobs\GenerateProjectFeedback;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Support\DurableJobDispatch;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class RecoverStalledAiFeedback extends Command
{
    protected $signature = 'ai:recover-stalled-feedback {--limit=200}';
    protected $description = 'Requeue lost AI feedback jobs and reconcile abandoned typing leases';

    public function handle(CourseAccessPlanService $plans): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $queued = 0;
        $reportsQueued = 0;
        $reportDispatches = 0;
        $sentReconciliationsQueued = 0;

        $reports = ProjectSubmission::query()
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->where(function ($query): void {
                $query->whereIn(
                    'submission_metadata->ai_feedback->status',
                    ['queued', 'processing']
                )->orWhere(function ($readyWithoutThread): void {
                    $readyWithoutThread
                        ->where('submission_metadata->ai_feedback->status', 'ready')
                        ->whereDoesntHave('feedbackThread');
                })->orWhere(function ($missingMarker): void {
                    $missingMarker
                        ->whereNull('submission_metadata->ai_feedback->status')
                        ->whereDoesntHave('feedbackThread')
                        ->whereIn('evaluation_snapshot->access->terms->project_feedback_level', ['report', 'enhanced']);
                });
            })
            ->where('updated_at', '<=', now()->subSeconds(90))
            ->orderBy('id')
            ->lazyById($limit);
        foreach ($reports as $submission) {
            $submissionId = (int) $submission->id;
            if (data_get($submission->submission_metadata, 'ai_feedback.status') === null
                && !$this->restoreMissingReportIntent($submission, $plans)) {
                continue;
            }
            $reportDispatches++;
            try {
                DurableJobDispatch::now(new GenerateProjectFeedback((int) $submissionId));
                $reportsQueued++;
            } catch (\Throwable $exception) {
                Log::warning('Stalled initial project report could not be requeued.', [
                    'submission_id' => $submissionId,
                    'exception' => $exception::class,
                ]);
            }
            if ($reportDispatches >= $limit) {
                break;
            }
        }

        $queuedMessages = ProjectFeedbackMessage::query()
            ->where('role', 'user')
            ->where('status', ProjectFeedbackMessage::QUEUED)
            ->where('updated_at', '<=', now()->subSeconds(60))
            ->orderBy('id')
            ->limit($limit)
            ->get(['id', 'updated_at']);
        foreach ($queuedMessages as $message) {
            $claimed = ProjectFeedbackMessage::query()
                ->whereKey($message->id)
                ->where('status', ProjectFeedbackMessage::QUEUED)
                ->where('updated_at', $message->updated_at)
                ->update(['updated_at' => now()]);
            if ($claimed !== 1) continue;
            try {
                DurableJobDispatch::now(new GenerateProjectFeedbackReply((int) $message->id));
                $queued++;
            } catch (\Throwable $exception) {
                ProjectFeedbackMessage::query()->whereKey($message->id)->update([
                    'updated_at' => $message->updated_at,
                ]);
                Log::warning('Stalled AI feedback could not be requeued.', [
                    'message_id' => $message->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        $sentStaleBefore = now()->subSeconds(90);
        $staleSent = ProjectFeedbackMessage::query()
            ->where('role', 'user')
            ->where('status', ProjectFeedbackMessage::SENT)
            ->where('updated_at', '<=', $sentStaleBefore)
            ->orderBy('id')
            ->limit($limit)
            ->get(['id']);
        foreach ($staleSent as $message) {
            try {
                // The unique key collapses queued reconciliations. Do not
                // refresh updated_at here: that timestamp is the durable SENT
                // processing lease used by workers to distinguish a live
                // claim from an abandoned one.
                DurableJobDispatch::now(new GenerateProjectFeedbackReply((int) $message->id));
                $sentReconciliationsQueued++;
            } catch (\Throwable $exception) {
                Log::warning('Stalled sent AI feedback could not be reconciled.', [
                    'message_id' => $message->id,
                    'exception' => $exception::class,
                ]);
            }
        }

        $this->info("Requeued {$reportsQueued} initial report(s) and {$queued} AI message(s); queued {$sentReconciliationsQueued} sent lease(s) for settlement reconciliation.");
        return self::SUCCESS;
    }

    private function restoreMissingReportIntent(ProjectSubmission $submission, CourseAccessPlanService $plans): bool
    {
        return DB::transaction(function () use ($submission, $plans): bool {
            if (!User::query()->whereKey($submission->user_id)->where('active', true)->lockForUpdate()->exists()) {
                return false;
            }
            $locked = ProjectSubmission::query()->lockForUpdate()->find($submission->id);
            if (!$locked || $locked->review_status !== ProjectSubmission::STATUS_PASSED
                || data_get($locked->submission_metadata, 'ai_feedback.status') !== null
                || $locked->feedbackThread()->exists()) {
                return false;
            }
            $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($locked);
            $terms = data_get($snapshot, 'access.terms');
            if (!is_array($terms) || !(bool) $plans->publicPayloadFromTerms($terms)['project_report_enabled']) {
                return false;
            }
            $metadata = (array) $locked->submission_metadata;
            $metadata['ai_feedback'] = [
                'status' => 'queued',
                'request_id' => (string) data_get($metadata, 'ai_feedback.request_id', $locked->public_id),
                'retry_count' => (int) data_get($metadata, 'ai_feedback.retry_count', 0),
                'queued_at' => now()->toIso8601String(),
            ];
            $locked->forceFill(['submission_metadata' => $metadata])->save();
            return true;
        }, 3);
    }
}
