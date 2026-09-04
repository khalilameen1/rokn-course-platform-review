<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\GenerateProjectFeedbackReply;
use App\Jobs\GenerateProjectFeedback;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectSubmission;
use App\Support\DurableJobDispatch;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

final class RecoverStalledAiFeedback extends Command
{
    protected $signature = 'ai:recover-stalled-feedback {--limit=200}';
    protected $description = 'Requeue lost AI feedback jobs and reconcile abandoned typing leases';

    public function handle(): int
    {
        $limit = max(1, min(1000, (int) $this->option('limit')));
        $queued = 0;
        $reportsQueued = 0;
        $sentReconciliationsQueued = 0;

        $reportIds = ProjectSubmission::query()
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->where(function ($query): void {
                $query->whereIn(
                    'submission_metadata->ai_feedback->status',
                    ['queued', 'processing']
                )->orWhere(function ($readyWithoutThread): void {
                    $readyWithoutThread
                        ->where('submission_metadata->ai_feedback->status', 'ready')
                        ->whereDoesntHave('feedbackThread');
                });
            })
            ->where('updated_at', '<=', now()->subSeconds(90))
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
        foreach ($reportIds as $submissionId) {
            try {
                DurableJobDispatch::now(new GenerateProjectFeedback((int) $submissionId));
                $reportsQueued++;
            } catch (\Throwable $exception) {
                Log::warning('Stalled initial project report could not be requeued.', [
                    'submission_id' => $submissionId,
                    'exception' => $exception::class,
                ]);
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
}
