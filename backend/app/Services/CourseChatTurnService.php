<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AiUsageEvent;
use App\Models\CourseChatTurn;
use App\Models\AiInputAttachment;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class CourseChatTurnService
{
    public function __construct(
        private readonly CourseStagedAuthoringService $stagedAuthoring,
        private readonly AiEntitlementBudgetService $entitlementBudget,
        private readonly PaidAiCallExecutionService $paidCalls,
        private readonly AiInputAttachmentService $attachments
    ) {}

    public function begin(
        int $userId,
        int $courseId,
        ?int $enrollmentId,
        ?int $lessonId,
        string $clientRequestId,
        string $question,
        string $language,
        string $promptVersion,
        array $attachmentIds = []
    ): CourseChatTurn {
        $fingerprint = hash('sha256', json_encode([
            'course_id' => $courseId,
            'lesson_id' => $lessonId,
            'question' => $question,
            'language' => $language,
            'prompt_version' => $promptVersion,
            'attachment_ids' => array_values($attachmentIds),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use (
            $userId,
            $courseId,
            $enrollmentId,
            $lessonId,
            $clientRequestId,
            $question,
            $language,
            $promptVersion,
            $fingerprint,
            $attachmentIds
        ): CourseChatTurn {
            if (!User::query()->whereKey($userId)->where('active', true)->lockForUpdate()->exists()) {
                throw new AuthorizationException('The learner account is no longer active.');
            }
            $existing = CourseChatTurn::query()
                ->where('user_id', $userId)
                ->where('client_request_id', $clientRequestId)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (!hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
                    throw new UnexpectedValueException('Course chat request identity conflict.');
                }

                return $existing;
            }

            $turn = CourseChatTurn::query()->createOrFirst(
                [
                    'user_id' => $userId,
                    'client_request_id' => $clientRequestId,
                ],
                [
                    'public_id' => (string) Str::uuid(),
                    'course_id' => $courseId,
                    'enrollment_id' => $enrollmentId,
                    'lesson_id' => $lessonId,
                    'request_fingerprint' => $fingerprint,
                    'prompt_version' => $promptVersion,
                    'language' => substr($language, 0, 12),
                    'status' => CourseChatTurn::QUEUED,
                    'attachment_count' => count($attachmentIds),
                    'question' => $question,
                    'expires_at' => now()->addDays(max(7, (int) config('openrouter.chat_history_days', 90))),
                ]
            );
            if (!hash_equals((string) $turn->request_fingerprint, $fingerprint)) {
                throw new UnexpectedValueException('Course chat request identity conflict.');
            }

            return $turn;
        }, 3);
    }

    /** @return list<array<string,mixed>> */
    public function context(
        int $userId,
        int $courseId,
        ?int $lessonId,
        string $language,
        string $promptVersion,
        int $excludeTurnId,
        int $characterBudget = 4000
    ): array {
        $currentTurnCreatedAt = CourseChatTurn::query()
            ->whereKey($excludeTurnId)
            ->value('created_at');
        $sessionStartedAt = $currentTurnCreatedAt
            ? \Illuminate\Support\Carbon::parse($currentTurnCreatedAt)->subMinutes(max(
                15,
                (int) config('openrouter.chat_context_session_minutes', 120)
            ))
            : now();
        $history = CourseChatTurn::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('language', $language)
            ->where('prompt_version', $promptVersion)
            ->where('status', CourseChatTurn::COMPLETED)
            ->where('id', '<>', $excludeTurnId)
            ->where('expires_at', '>', now())
            ->where('created_at', '>=', $sessionStartedAt)
            ->orderByDesc('id')
            ->limit(6)
            ->get(['lesson_id', 'question', 'answer']);
        $characterBudget = max(1500, min(6000, $characterBudget));
        $recent = [];
        $used = 0;
        foreach ($history as $turn) {
            $question = trim((string) $turn->question);
            $answer = trim((string) $turn->answer);
            $remaining = $characterBudget - $used;
            if ($remaining < 300) {
                break;
            }

            $questionBudget = min(1200, max(200, (int) floor($remaining * .35)));
            $question = mb_substr($question, 0, $questionBudget);
            $answer = mb_substr($answer, 0, max(100, $remaining - mb_strlen($question)));
            $used += mb_strlen($question) + mb_strlen($answer);
            array_unshift($recent, [
                [
                    'role' => 'user',
                    'content' => ($lessonId !== null && (int) $turn->lesson_id !== $lessonId
                        ? "من مقطع سابق في الكورس\n" : '') . $question,
                ],
                ['role' => 'assistant', 'content' => $answer],
            ]);
        }

        return collect($recent)
            ->flatten(1)
            ->filter(fn (array $message): bool => trim($message['content']) !== '')
            ->values()
            ->all();
    }

    public function markStreaming(?CourseChatTurn $turn): bool
    {
        if (!$turn) {
            return false;
        }

        // Only the worker that starts a queued turn establishes its lease.
        // Client polling of an already-streaming turn must not keep an
        // abandoned request alive forever by refreshing updated_at.
        $claimed = CourseChatTurn::query()
            ->whereKey($turn->id)
            ->where('status', CourseChatTurn::QUEUED)
            ->update([
                'status' => CourseChatTurn::STREAMING,
                'error_code' => null,
                'completed_at' => null,
                'updated_at' => now(),
            ]) === 1;

        return $claimed || CourseChatTurn::query()
            ->whereKey($turn->id)
            ->where('status', CourseChatTurn::STREAMING)
            ->exists();
    }

    public function complete(?CourseChatTurn $turn, string $answer, ?AiUsageEvent $usage = null): bool
    {
        if (!$turn) {
            return false;
        }

        return DB::transaction(function () use ($turn, $answer, $usage): bool {
            $accepted = mb_substr(trim($answer), 0, 12000);
            if ($accepted === '') {
                return false;
            }
            if (!User::query()->whereKey($turn->user_id)->where('active', true)
                ->lockForUpdate()->exists()) return false;
            $locked = CourseChatTurn::query()->lockForUpdate()->find($turn->id);
            if (!$locked) {
                return false;
            }
            if ($locked->status === CourseChatTurn::COMPLETED) {
                return $accepted !== '' && hash_equals((string) $locked->answer, $accepted);
            }
            if (!in_array($locked->status, [
                CourseChatTurn::QUEUED,
                CourseChatTurn::STREAMING,
            ], true)) {
                return false;
            }
            if (!$usage) {
                $usage = AiUsageEvent::query()
                    ->where('request_id', $locked->client_request_id)
                    ->where('user_id', $locked->user_id)
                    ->first();
            }
            $locked->forceFill([
                'status' => CourseChatTurn::COMPLETED,
                'answer' => $accepted,
                'error_code' => null,
                'usage_event_id' => $usage?->id,
                'completed_at' => now(),
            ])->save();

            return true;
        }, 3);
    }

    public function fail(?CourseChatTurn $turn, string $code): void
    {
        if (!$turn || !$this->transition($turn, CourseChatTurn::FAILED, $code, now())) {
            return;
        }

        $safeCode = substr((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $code), 0, 64)
            ?: 'chat_terminal_failure';
        try {
            $firstReport = Cache::add(
                'telemetry:course-chat-terminal:' . hash('sha256', (string) $turn->client_request_id),
                true,
                now()->addDay()
            );
            if (!$firstReport) {
                return;
            }
            Log::error('Course chat turn entered terminal failure.', [
                'source' => 'course_chat',
                'endpoint' => 'course-chat/turns',
                'request_id' => (string) $turn->client_request_id,
                'error_code' => $safeCode,
            ]);
            report(new \RuntimeException('course_chat_terminal_failure:' . $safeCode));
        } catch (\Throwable $reportingFailure) {
            // Observability must never change the learner-facing turn outcome.
        }
    }

    /**
     * Repair the presentation row when the metered event reached a terminal
     * state but a killed worker missed the final turn write. Polling is the
     * learner-facing recovery path, so it must not report "in progress" for
     * work that can no longer make progress.
     */
    public function reconcileTerminalUsage(?CourseChatTurn $turn): ?CourseChatTurn
    {
        if (
            !$turn
            || !in_array($turn->status, [CourseChatTurn::QUEUED, CourseChatTurn::STREAMING], true)
        ) {
            return $turn;
        }

        DB::transaction(function () use ($turn): void {
            $locked = CourseChatTurn::query()->lockForUpdate()->find($turn->id);
            if (!$locked || !in_array($locked->status, [
                CourseChatTurn::QUEUED,
                CourseChatTurn::STREAMING,
            ], true)) {
                return;
            }

            $usage = AiUsageEvent::query()
                ->where('request_id', $locked->client_request_id)
                ->where('user_id', $locked->user_id)
                ->where('enrollment_id', $locked->enrollment_id)
                ->where('feature', 'course_chat')
                ->lockForUpdate()
                ->first();
            if (!$usage || $usage->status === 'reserved') {
                return;
            }

            $accepted = trim((string) data_get($usage->metadata, 'accepted_response', ''));
            if ($usage->status === 'completed' && $accepted !== '') {
                $locked->forceFill([
                    'status' => CourseChatTurn::COMPLETED,
                    'answer' => mb_substr($accepted, 0, 12000),
                    'error_code' => null,
                    'usage_event_id' => $usage->id,
                    'completed_at' => $usage->completed_at ?? now(),
                ])->save();
                $this->paidCalls->markPresented($usage);

                return;
            }

            $locked->forceFill([
                'status' => CourseChatTurn::FAILED,
                'error_code' => $usage->status === 'completed'
                    ? 'chat_provider_outcome_unknown'
                    : 'ai_temporarily_unavailable',
                'completed_at' => now(),
            ])->save();

            return;
        }, 3);

        return CourseChatTurn::query()->find($turn->id);
    }

    public function reconcileForPolling(?CourseChatTurn $turn): ?CourseChatTurn
    {
        $turn = $this->reconcileTerminalUsage($turn);
        if (!$turn || !in_array($turn->status, [
            CourseChatTurn::QUEUED,
            CourseChatTurn::STREAMING,
        ], true)) {
            return $turn;
        }

        $usage = AiUsageEvent::query()
            ->where('request_id', $turn->client_request_id)
            ->where('user_id', $turn->user_id)
            ->where('enrollment_id', $turn->enrollment_id)
            ->where('feature', 'course_chat')
            ->first();
        $landed = $this->paidCalls->landedResult($usage);
        if ($usage?->status === 'reserved' && $landed !== null) {
            $outcome = $this->entitlementBudget->settleForActiveUser(
                $usage,
                $landed,
                (int) $turn->user_id
            );
            if (AiEntitlementBudgetService::settlementAllowsDelivery($outcome)) {
                return $this->reconcileTerminalUsage($turn->fresh());
            }
        }

        [$queuedCutoff, $streamingCutoff] = $this->staleCutoffs();
        $this->reconcileStalledTurn((int) $turn->id, $queuedCutoff, $streamingCutoff);

        return CourseChatTurn::query()->find($turn->id);
    }

    /** Close a queued request that never reached the worker. */
    public function failBeforeDispatch(?CourseChatTurn $turn, string $code): bool
    {
        if (!$turn) {
            return false;
        }

        return CourseChatTurn::query()
            ->whereKey($turn->id)
            ->where('status', CourseChatTurn::QUEUED)
            ->update([
                'status' => CourseChatTurn::FAILED,
                'error_code' => substr($code, 0, 64),
                'completed_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    public function page(
        int $userId,
        int $courseId,
        ?int $lessonId,
        int $perPage = 20
    ): CursorPaginator {
        $lessonAliases = $lessonId === null
            ? []
            : $this->stagedAuthoring->equivalentEntityIds(Lesson::class, $lessonId);

        return CourseChatTurn::query()
            ->where('user_id', $userId)
            ->where('course_id', $courseId)
            ->where('expires_at', '>', now())
            ->when(
                $lessonId === null,
                fn ($query) => $query->whereNull('lesson_id'),
                fn ($query) => $query->whereIn('lesson_id', $lessonAliases)
            )
            ->orderByDesc('id')
            ->cursorPaginate(max(1, min(50, $perPage)));
    }

    public function failStalled(int $limit = 500): int
    {
        [$queuedCutoff, $streamingCutoff] = $this->staleCutoffs();
        $ids = CourseChatTurn::query()
            ->where(function ($query) use ($queuedCutoff, $streamingCutoff): void {
                $query->where(function ($queued) use ($queuedCutoff): void {
                    $queued->where('status', CourseChatTurn::QUEUED)
                        ->where('updated_at', '<=', $queuedCutoff);
                })->orWhere(function ($streaming) use ($streamingCutoff): void {
                    $streaming->where('status', CourseChatTurn::STREAMING)
                        ->where('updated_at', '<=', $streamingCutoff);
                });
            })
            ->orderBy('id')
            ->limit(max(1, min(5000, $limit)))
            ->pluck('id');
        if ($ids->isEmpty()) {
            return 0;
        }

        $closed = 0;
        foreach ($ids as $id) {
            $closed += $this->reconcileStalledTurn(
                (int) $id,
                $queuedCutoff,
                $streamingCutoff
            );
        }

        return $closed;
    }

    /** @return array{0:mixed,1:mixed} */
    private function staleCutoffs(): array
    {
        return [
            now()->subSeconds(max(30, (int) config('openrouter.queue_stale_seconds', 60))),
            now()->subSeconds(max(75, (int) config('openrouter.timeout_seconds', 45) + 45)),
        ];
    }

    private function reconcileStalledTurn(int $id, mixed $queuedCutoff, mixed $streamingCutoff): int
    {
        $releaseUsageId = null;
        $unknownUsageId = null;
        $closed = DB::transaction(function () use (
            $id,
            $queuedCutoff,
            $streamingCutoff,
            &$releaseUsageId,
            &$unknownUsageId
        ): int {
            $turn = CourseChatTurn::query()->lockForUpdate()->find($id);
            $cutoff = $turn?->status === CourseChatTurn::QUEUED
                ? $queuedCutoff
                : $streamingCutoff;
            if (!$turn
                || !in_array($turn->status, [CourseChatTurn::QUEUED, CourseChatTurn::STREAMING], true)
                || $turn->updated_at->isAfter($cutoff)) {
                return 0;
            }

            $usage = AiUsageEvent::query()
                ->where('request_id', $turn->client_request_id)
                ->where('user_id', $turn->user_id)
                ->where('enrollment_id', $turn->enrollment_id)
                ->where('feature', 'course_chat')
                ->lockForUpdate()
                ->first();
            $accepted = trim((string) data_get($usage?->metadata, 'accepted_response', ''));
            if ($usage?->status === 'completed' && $accepted !== '') {
                $annotations = data_get($usage->metadata, 'provider_file_annotations', []);
                if (is_array($annotations) && $annotations !== []) {
                    $owned = $this->attachments->forOwner(
                        AiInputAttachment::OWNER_COURSE_CHAT_TURN,
                        (int) $turn->id
                    );
                    if ($owned->isNotEmpty()) {
                        $this->attachments->markProcessed($owned, $annotations);
                    }
                }
                $turn->forceFill([
                    'status' => CourseChatTurn::COMPLETED,
                    'answer' => mb_substr($accepted, 0, 12000),
                    'error_code' => null,
                    'usage_event_id' => $usage->id,
                    'completed_at' => $usage->completed_at ?? now(),
                ])->save();
                $this->paidCalls->markPresented($usage);

                return 1;
            }

            $providerState = (string) data_get($usage?->metadata, 'provider_call_state', '');
            if ($usage?->status === 'reserved'
                && $providerState === PaidAiCallExecutionService::LANDED) {
                return 0;
            }
            if ($usage?->status === 'reserved'
                && $this->paidCalls->startedState($usage) === PaidAiCallExecutionService::LIVE) {
                return 0;
            }

            $turn->forceFill([
                'status' => CourseChatTurn::FAILED,
                'error_code' => in_array($providerState, ['started', 'outcome_unknown'], true)
                    ? 'chat_provider_outcome_unknown'
                    : 'chat_request_abandoned',
                'completed_at' => now(),
            ])->save();
            if ($usage?->status === 'reserved'
                && in_array($providerState, ['started', 'outcome_unknown'], true)) {
                $unknownUsageId = (int) $usage->id;
            } else {
                $releaseUsageId = $usage?->status === 'reserved' ? (int) $usage->id : null;
            }

            return 1;
        }, 3);

        if ($releaseUsageId) {
            $this->entitlementBudget->release(
                AiUsageEvent::query()->find($releaseUsageId),
                'course_chat_request_abandoned'
            );
        }
        if ($unknownUsageId) {
            $usage = AiUsageEvent::query()->find($unknownUsageId);
            if ($usage) {
                $this->paidCalls->settleUnknown(
                    $this->entitlementBudget,
                    $usage,
                    is_array(data_get($usage->metadata, 'request_context'))
                        ? data_get($usage->metadata, 'request_context')
                        : [],
                    'course_chat_worker_abandoned_after_provider_start'
                );
            }
        }

        return $closed;
    }

    private function transition(
        ?CourseChatTurn $turn,
        string $status,
        ?string $code,
        mixed $completedAt
    ): bool {
        if (!$turn) {
            return false;
        }
        return CourseChatTurn::query()
            ->whereKey($turn->id)
            ->whereIn('status', [CourseChatTurn::QUEUED, CourseChatTurn::STREAMING])
            ->update([
                'status' => $status,
                'error_code' => $code ? substr($code, 0, 64) : null,
                'completed_at' => $completedAt,
                'updated_at' => now(),
            ]) === 1;
    }

    public function cancelForUser(int $userId, string $clientRequestId): string
    {
        return DB::transaction(function () use ($userId, $clientRequestId): string {
            $turn = CourseChatTurn::query()
                ->where('user_id', $userId)
                ->where('client_request_id', $clientRequestId)
                ->lockForUpdate()
                ->first();
            if (!$turn) {
                return 'not_found';
            }
            if ($turn->status === CourseChatTurn::CANCELLED) {
                return 'cancelled';
            }
            if ($turn->status === CourseChatTurn::COMPLETED) {
                return 'not_cancellable';
            }
            if ($turn->status === CourseChatTurn::FAILED) {
                return 'terminal';
            }

            $event = AiUsageEvent::query()
                ->where('request_id', $clientRequestId)
                ->where('user_id', $turn->user_id)
                ->where('enrollment_id', $turn->enrollment_id)
                ->where('feature', 'course_chat')
                ->lockForUpdate()
                ->first();
            // Settlement precedes the turn's presentation write. A completed
            // ledger event must remain recoverable by polling during that gap;
            // cancellation cannot discard an answer whose quota was consumed.
            if ($event?->status === 'completed') {
                return trim((string) data_get($event->metadata, 'accepted_response', '')) !== ''
                    ? 'not_cancellable'
                    : 'terminal';
            }
            if ($event?->status === 'reserved' && in_array(
                data_get($event->metadata, 'provider_call_state'),
                ['started', 'outcome_unknown', PaidAiCallExecutionService::LANDED],
                true
            )) {
                return 'provider_started';
            }
            if ($event?->status === 'reserved') {
                $this->entitlementBudget->release($event, 'learner_cancelled_before_provider');
            }

            $turn->forceFill([
                'status' => CourseChatTurn::CANCELLED,
                'error_code' => 'learner_cancelled',
                'completed_at' => now(),
            ])->save();

            return 'cancelled';
        }, 3);
    }

}
