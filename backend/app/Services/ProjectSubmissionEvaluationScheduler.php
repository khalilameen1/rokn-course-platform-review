<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\EvaluateProjectSubmission;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\DurableJobDispatch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Durable evaluation scheduling only: a recovery deadline never grants a pass. */
final class ProjectSubmissionEvaluationScheduler
{
    public function dispatchIfDue(ProjectSubmission $submission): ProjectSubmission
    {
        if ($submission->review_status !== ProjectSubmission::STATUS_PENDING) {
            return $submission;
        }
        $dispatch = false;
        $result = DB::transaction(function () use ($submission, &$dispatch): ProjectSubmission {
            // Account deletion owns the learner row before scrubbing this
            // aggregate. Taking the same owner lock first prevents a delayed
            // fallback job from recreating review/progress data afterwards.
            $learner = User::query()
                ->whereKey($submission->user_id)
                ->lockForUpdate()
                ->first();
            if (!$learner || !$learner->active) {
                return $submission->fresh();
            }
            $locked = ProjectSubmission::query()->lockForUpdate()->findOrFail($submission->id);
            if ($locked->review_status !== ProjectSubmission::STATUS_PENDING) {
                return $locked;
            }

            $metadata = (array) $locked->submission_metadata;
            $evaluation = (array) ($metadata['evaluation'] ?? []);
            if (in_array($evaluation['status'] ?? '', ['ready', 'unavailable'], true)) {
                return $locked;
            }
            if ($evaluation !== [] && $locked->auto_pass_at?->isFuture()) {
                return $locked;
            }
            if ($evaluation === []) {
                $evaluation = [
                    'version' => 1,
                    'status' => 'queued',
                    'request_id' => (string) Str::uuid(),
                    'queued_at' => now()->toIso8601String(),
                    'retry_count' => 0,
                    'retry_safe' => false,
                ];
            }
            $metadata['evaluation'] = $evaluation;
            $locked->forceFill([
                'submission_metadata' => $metadata,
                'auto_pass_at' => now()->addSeconds(100),
            ])->save();
            $dispatch = true;
            return $locked;
        });
        if ($dispatch) {
            try {
                DurableJobDispatch::afterCommit(new EvaluateProjectSubmission((int) $result->id));
            } catch (\Throwable $exception) {
                // The pending marker remains durable for the minute recovery worker.
                report($exception);
            }
        }
        return $result->fresh();
    }

    public function recoverDue(int $limit = 100): int
    {
        $count = 0;
        ProjectSubmission::query()
            ->where('review_status', ProjectSubmission::STATUS_PENDING)
            ->where(function ($query): void {
                $query->whereNull('auto_pass_at')->orWhere('auto_pass_at', '<=', now());
            })
            ->where(function ($query): void {
                $query->whereNull('submission_metadata->evaluation->status')
                    ->orWhereIn('submission_metadata->evaluation->status', ['queued', 'processing']);
            })
            ->orderBy('id')
            ->limit($limit)
            ->get()
            ->each(function (ProjectSubmission $submission) use (&$count): void {
                $this->dispatchIfDue($submission);
                $count++;
            });

        return $count;
    }
}
