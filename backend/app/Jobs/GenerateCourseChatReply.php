<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Exceptions\AiProviderUnavailableException;
use App\Models\AiUsageEvent;
use App\Models\CourseEnrollment;
use App\Models\CourseChatTurn;
use App\Models\AiInputAttachment;
use App\Models\User;
use App\Services\AiEntitlementBudgetService;
use App\Services\AiFailurePolicy;
use App\Services\AiInputAttachmentService;
use App\Services\AiStreamCheckpointService;
use App\Services\CourseChatTurnService;
use App\Services\CourseChatAccessService;
use App\Services\OpenRouterService;
use App\Services\PaidAiCallExecutionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Run the paid provider call away from the web worker.
 *
 * The HTTP endpoint creates the durable reservation before dispatch. This
 * job owns only the provider call and settlement for that same request id.
 */
final class GenerateCourseChatReply implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // The provider request already contains an ordered model fallback. One
    // queue retry is enough for a proven pre-generation transient; three full
    // live-chat attempts made a temporary outage look like a two-minute hang.
    public int $tries = 2;
    // Provider streaming may consume the configured 45-second HTTP budget.
    // Keep real headroom for attachment preparation and durable landing after
    // the last byte; otherwise the worker can kill a valid paid answer before
    // it is presented and leave the client polling an unknown outcome.
    public int $timeout = 80;
    public int $uniqueFor = 1200;
    public bool $failOnTimeout = true;
    public string $executionId;

    /** @param list<array{role:string,content:string}> $messages */
    public function __construct(
        public int $turnId,
        public int $enrollmentId,
        public string $model,
        public array $messages,
        public float $temperature,
        public int $maxTokens,
        public array $requestContext
    ) {
        $this->executionId = (string) Str::uuid();
        $this->onQueue((string) config('queue.channels.ai_chat', 'ai-chat'));
    }

    /** @return list<int> */
    public function backoff(): array
    {
        return [5];
    }

    public function uniqueId(): string
    {
        return 'course-chat:' . $this->turnId;
    }

    public function handle(
        OpenRouterService $openRouter,
        AiEntitlementBudgetService $budget,
        CourseChatTurnService $turns,
        CourseChatAccessService $courseAccess,
        AiInputAttachmentService $attachments,
        PaidAiCallExecutionService $paidCalls,
        AiStreamCheckpointService $streamCheckpoints,
        AiFailurePolicy $failurePolicy
    ): void {
        $turn = CourseChatTurn::query()->find($this->turnId);
        if (!$turn) {
            return;
        }
        $event = AiUsageEvent::query()
            ->where('request_id', $turn->client_request_id)
            ->where('user_id', $turn->user_id)
            ->where('enrollment_id', $this->enrollmentId)
            ->where('feature', 'course_chat')
            ->first();
        if (
            !$event
            || (int) $event->course_id !== (int) $turn->course_id
        ) {
            $turns->fail($turn, 'chat_usage_identity_mismatch');
            return;
        }
        if (!User::query()->whereKey($turn->user_id)->where('active', true)->exists()) {
            $budget->release($event, 'account_deleted_before_provider');
            $turns->fail($turn, 'chat_entitlement_unavailable');
            return;
        }
        if ($turn->status === CourseChatTurn::COMPLETED) {
            $paidCalls->markPresented($event);
            return;
        }
        if (in_array($turn->status, [
            CourseChatTurn::FAILED,
            CourseChatTurn::CANCELLED,
        ], true)) {
            $budget->release($event, 'course_chat_turn_closed');
            return;
        }

        $enrollment = CourseEnrollment::query()->find($this->enrollmentId);
        if (
            !$enrollment
            || (int) $enrollment->user_id !== (int) $turn->user_id
            || !$courseAccess->enrollmentGrantsCourse($enrollment, (int) $turn->course_id)
            || !$enrollment->isActive()
            || !$courseAccess->enrollmentAllowsVariableCostFeatures($enrollment)
        ) {
            $budget->release($event, 'chat_entitlement_unavailable');
            $turns->fail($turn, 'chat_entitlement_unavailable');
            return;
        }

        $ownedAttachments = $attachments->forOwner(
            AiInputAttachment::OWNER_COURSE_CHAT_TURN,
            (int) $turn->id
        );
        $expectedAttachmentCount = max(
            (int) ($turn->attachment_count ?? 0),
            (int) ($this->requestContext['attachment_count'] ?? 0)
        );
        if ($ownedAttachments->count() !== $expectedAttachmentCount) {
            $budget->release($event, 'chat_attachment_unavailable');
            $turns->fail($turn, 'chat_attachment_unavailable');
            return;
        }
        $providerMessages = $this->messages;
        if ($ownedAttachments->isNotEmpty()) {
            try {
                $parts = $attachments->providerParts($ownedAttachments);
                if ($parts === []) {
                    throw new \UnexpectedValueException('No readable attachment parts.');
                }
                $last = count($providerMessages) - 1;
                $providerMessages[$last]['content'] = array_merge([
                    ['type' => 'text', 'text' => (string) ($providerMessages[$last]['content'] ?? '')],
                ], $parts);
            } catch (Throwable $exception) {
                report($exception);
                $budget->release($event, 'chat_attachment_unreadable');
                $turns->fail($turn, 'chat_attachment_unreadable');
                return;
            }
        }

        $accepted = trim((string) data_get($event->metadata, 'accepted_response', ''));
        if ($event->status === 'completed' && $accepted !== '') {
            $this->restoreAnnotations($attachments, $ownedAttachments, $event);
            if ($turns->complete($turn, $accepted, $event)) {
                $paidCalls->markPresented($event->fresh());
            }
            return;
        }
        $landedResult = $paidCalls->landedResult($event);
        if ($event->status === 'reserved' && $landedResult !== null) {
            $settlement = $budget->settleForActiveUser(
                $event,
                $landedResult,
                (int) $turn->user_id
            );
            if (AiEntitlementBudgetService::settlementAllowsDelivery($settlement)) {
                $settled = $event->fresh();
                $this->restoreAnnotations($attachments, $ownedAttachments, $settled);
                $answer = trim((string) $landedResult['message']);
                if ($turns->complete($turn, $answer, $settled)) {
                    $paidCalls->markPresented($settled?->fresh());
                }
            }
            return;
        }
        if ($event->status !== 'reserved') {
            $turns->fail($turn, 'chat_reservation_unavailable');
            return;
        }
        // Presentation can fail before the paid request starts without making
        // the provider outcome ambiguous. Mark the turn first, then claim the
        // single provider execution immediately before the HTTP call.
        if (!$turns->markStreaming($turn)) {
            $budget->release($event, 'course_chat_turn_closed_before_provider');
            return;
        }
        $callState = $paidCalls->beginForActiveUser(
            $event, $this->executionId, (int) $turn->user_id
        );
        if ($callState === PaidAiCallExecutionService::INACTIVE) {
            $budget->release($event, 'account_deleted_before_provider');
            $turns->fail($turn, 'chat_entitlement_unavailable');
            return;
        }
        if ($callState !== PaidAiCallExecutionService::START) {
            if ($callState === PaidAiCallExecutionService::LIVE) return;
            $fresh = $event->fresh();
            $landed = $paidCalls->landedResult($fresh);
            if (
                $callState === PaidAiCallExecutionService::LANDED
                && $fresh?->status === 'reserved'
                && $landed !== null
            ) {
                $settlement = $budget->settleForActiveUser(
                    $fresh,
                    $landed,
                    (int) $turn->user_id
                );
                if (AiEntitlementBudgetService::settlementAllowsDelivery($settlement)) {
                    $settled = $fresh->fresh();
                    $this->restoreAnnotations($attachments, $ownedAttachments, $settled);
                    $answer = trim((string) $landed['message']);
                    if ($turns->complete($turn, $answer, $settled)) {
                        $paidCalls->markPresented($settled?->fresh());
                    }
                }
                return;
            }
            $accepted = trim((string) data_get($fresh?->metadata, 'accepted_response', ''));
            if ($fresh?->status === 'completed' && $accepted !== '') {
                $this->restoreAnnotations($attachments, $ownedAttachments, $fresh);
                if ($turns->complete($turn, $accepted, $fresh)) {
                    $paidCalls->markPresented($fresh->fresh());
                }
                return;
            }
            if (
                $callState === PaidAiCallExecutionService::STALE_STARTED
                &&
                $fresh?->status === 'reserved'
                && in_array(
                    data_get($fresh->metadata, 'provider_call_state'),
                    ['started', 'outcome_unknown'],
                    true
                )
            ) {
                $paidCalls->settleUnknown($budget, $fresh, $this->requestContext);
                $turns->fail($turn, 'chat_provider_outcome_unknown');
            }
            return;
        }
        $providerResultKnown = false;
        try {
            $result = $openRouter->chat(
                $this->model,
                $providerMessages,
                $this->temperature,
                $this->maxTokens,
                (string) $event->request_id,
                function (array $providerResult) use ($paidCalls, $event, $turn): void {
                    $providerResult['request_context'] = $this->requestContext;
                    $paidCalls->landSuccessfulResultForActiveUser(
                        $event,
                        $this->executionId,
                        (int) $turn->user_id,
                        $providerResult
                    );
                },
                function (string $partial) use ($streamCheckpoints, $turn): void {
                    $streamCheckpoints->courseChat($turn, $partial);
                },
                true
            );
            $result['request_context'] = $this->requestContext;
            $providerResultKnown = true;
            $landing = $paidCalls->landSuccessfulResultForActiveUser(
                $event,
                $this->executionId,
                (int) $turn->user_id,
                $result
            );
            if ($landing === PaidAiCallExecutionService::INACTIVE) return;
            if ($landing !== PaidAiCallExecutionService::LANDED) {
                throw new \RuntimeException('Provider result landing conflict.');
            }
            $result = $paidCalls->landedResult($event->fresh())
                ?? throw new \RuntimeException('Provider result landing was not durable.');
            $settlement = $budget->settleForActiveUser(
                $event, $result, (int) $turn->user_id
            );
            if (!AiEntitlementBudgetService::settlementAllowsDelivery($settlement)) {
                return;
            }
            if ($ownedAttachments->isNotEmpty()) {
                $attachments->markProcessed(
                    $ownedAttachments,
                    is_array($result['file_annotations'] ?? null) ? $result['file_annotations'] : []
                );
            }
            $answer = trim((string) ($result['message'] ?? ''));
            $settled = $event->fresh();
            if ($turns->complete($turn, $answer, $settled)) {
                $paidCalls->markPresented($settled?->fresh());
            }
        } catch (AiProviderUnavailableException $exception) {
            Log::warning('OpenRouter course chat request was rejected.', array_filter([
                'source' => 'openrouter',
                'endpoint' => 'chat/completions',
                'request_id' => (string) $turn->client_request_id,
                'provider_status' => $exception->providerStatus,
                'provider_code' => $this->safeDiagnosticCode($exception->providerCode),
                'retry_safe' => $exception->retrySafe,
                'outcome_unknown' => $exception->outcomeUnknown,
                'terminal' => !($exception->retrySafe && $this->attempts() < $this->tries),
            ], static fn (mixed $value): bool => $value !== null));
            if ($ownedAttachments->isNotEmpty() && $exception->fileAnnotations !== []) {
                $attachments->markProcessed($ownedAttachments, $exception->fileAnnotations);
            }
            if ($exception->retrySafe && $this->attempts() < $this->tries) {
                $paidCalls->markRetrySafe($event, $this->executionId);
                throw $exception;
            }

            if ($exception->outcomeUnknown) {
                $paidCalls->settleUnknown($budget, $event, $this->requestContext);
                $turns->fail($turn, 'chat_provider_outcome_unknown');
                return;
            }

            $budget->release($event, 'provider_unavailable');
            $turns->fail($turn, $failurePolicy->providerCode($exception));
        } catch (Throwable $exception) {
            $settled = $event->fresh();
            $accepted = trim((string) data_get($settled?->metadata, 'accepted_response', ''));
            if ($settled?->status === 'completed' && $accepted !== '') {
                $this->restoreAnnotations($attachments, $ownedAttachments, $settled);
                if ($turns->complete($turn, $accepted, $settled)) {
                    $paidCalls->markPresented($settled->fresh());
                }
                return;
            }

            if ($providerResultKnown || $paidCalls->landedResult($settled) !== null) {
                // The provider answer is known. Never degrade it to an
                // unknown outcome merely because the first durable settlement
                // or presentation write failed.
                throw $exception;
            }

            $paidCalls->settleUnknown($budget, $event, $this->requestContext);
            $turns->fail($turn, 'chat_provider_outcome_unknown');
            report($exception);
        }
    }

    private function safeDiagnosticCode(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        return substr((string) preg_replace('/[^A-Za-z0-9._-]+/', '_', $code), 0, 64);
    }

    private function restoreAnnotations(
        AiInputAttachmentService $attachments,
        $ownedAttachments,
        ?AiUsageEvent $event
    ): void {
        $annotations = data_get($event?->metadata, 'provider_file_annotations', []);
        if ($ownedAttachments->isNotEmpty() && is_array($annotations) && $annotations !== []) {
            $attachments->markProcessed($ownedAttachments, $annotations);
        }
    }

    public function failed(Throwable $exception): void
    {
        $turn = CourseChatTurn::query()->find($this->turnId);
        $event = $turn
            ? AiUsageEvent::query()
                ->where('request_id', $turn->client_request_id)
                ->where('user_id', $turn->user_id)
                ->where('enrollment_id', $this->enrollmentId)
                ->where('feature', 'course_chat')
                ->first()
            : null;
        if ($event?->status === 'completed') {
            $accepted = trim((string) data_get($event->metadata, 'accepted_response', ''));
            if ($accepted !== '' && $turn) {
                try {
                    $attachmentService = app(AiInputAttachmentService::class);
                    $this->restoreAnnotations(
                        $attachmentService,
                        $attachmentService->forOwner(
                            AiInputAttachment::OWNER_COURSE_CHAT_TURN,
                            (int) $turn->id
                        ),
                        $event
                    );
                    if (app(CourseChatTurnService::class)->complete($turn, $accepted, $event)) {
                        app(PaidAiCallExecutionService::class)->markPresented($event->fresh());
                        return;
                    }
                } catch (Throwable $recoveryException) {
                    report($recoveryException);
                }

                // Settlement is durable. Leave the presentation turn
                // recoverable instead of replacing a paid answer with a
                // terminal failure when the final DB write is unavailable.
                return;
            }

            app(CourseChatTurnService::class)->fail($turn, 'chat_settlement_recovery_failed');
            return;
        }
        $paidCalls = app(PaidAiCallExecutionService::class);
        $landed = $paidCalls->landedResult($event);
        if ($event?->status === 'reserved' && $landed !== null && $turn) {
            try {
                $budget = app(AiEntitlementBudgetService::class);
                $outcome = $budget->settleForActiveUser(
                    $event,
                    $landed,
                    (int) $turn->user_id
                );
                if (AiEntitlementBudgetService::settlementAllowsDelivery($outcome)) {
                    $attachmentService = app(AiInputAttachmentService::class);
                    $owned = $attachmentService->forOwner(
                        AiInputAttachment::OWNER_COURSE_CHAT_TURN,
                        (int) $turn->id
                    );
                    $settled = $event->fresh();
                    $this->restoreAnnotations($attachmentService, $owned, $settled);
                    if (app(CourseChatTurnService::class)->complete(
                        $turn,
                        trim((string) $landed['message']),
                        $settled
                    )) {
                        $paidCalls->markPresented($settled?->fresh());
                    }
                }
            } catch (Throwable $recoveryException) {
                report($recoveryException);
            }
            return;
        }
        if (
            $event?->status === 'reserved'
            && in_array(
                data_get($event->metadata, 'provider_call_state'),
                ['started', 'outcome_unknown'],
                true
            )
        ) {
            app(PaidAiCallExecutionService::class)->settleUnknown(
                app(AiEntitlementBudgetService::class),
                $event,
                $this->requestContext
            );
            $turns = app(CourseChatTurnService::class);
            $turns->fail($turn, 'chat_provider_outcome_unknown');
            return;
        }
        if ($event?->status === 'reserved') {
            app(AiEntitlementBudgetService::class)->release($event, 'course_chat_worker_failed');
        }
        $turns = app(CourseChatTurnService::class);
        $turns->fail($turn, 'ai_temporarily_unavailable');
    }

}
