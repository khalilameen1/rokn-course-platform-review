<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\StudentNotificationIntent;

use App\Models\FeedbackReport;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class SupportCaseService
{
    public const CUSTOMER_STATUSES = ['new', 'reviewing', 'waiting_for_user', 'resolved', 'closed', 'dismissed'];

    public function __construct(
        private readonly StudentNotificationService $notifications,
        private readonly SupportCaseScreenshotService $screenshots,
        private readonly SupportCaseAccessService $access
    )
    {
    }

    public function appendLearnerMessage(
        FeedbackReport $report,
        ?User $user,
        string $body,
        string $clientRequestId,
        ?UploadedFile $screenshot = null
    ): SupportCaseMessage {
        $body = trim($body);
        $fingerprint = hash('sha256', json_encode([
            'body' => $body,
            'attachment' => $this->screenshots->fingerprint($screenshot),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $existing = SupportCaseMessage::query()
            ->where('feedback_report_id', $report->id)
            ->where('client_request_id', $clientRequestId)
            ->first();
        if ($existing) {
            abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
            return $existing;
        }

        $stagedAttachment = $screenshot
            ? $this->screenshots->stage($report, $clientRequestId, $screenshot)
            : null;

        return DB::transaction(function () use (
                $report,
                $user,
                $body,
                $clientRequestId,
                $fingerprint,
                $stagedAttachment
            ): SupportCaseMessage {
                if ($user) {
                    User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                }
                $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
                $existing = SupportCaseMessage::query()
                    ->where('feedback_report_id', $locked->id)
                    ->where('client_request_id', $clientRequestId)
                    ->first();
                if ($existing) {
                    abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
                    return $existing;
                }

                $fromStatus = (string) $locked->status;
                $reopened = in_array($fromStatus, ['resolved', 'closed', 'dismissed'], true);
                $message = SupportCaseMessage::query()->create([
                    'public_id' => (string) Str::ulid(),
                    'feedback_report_id' => $locked->id,
                    'author_id' => $user?->id,
                    'author_type' => SupportCaseMessage::AUTHOR_LEARNER,
                    'visibility' => SupportCaseMessage::VISIBILITY_CUSTOMER,
                    'body' => $body,
                    'client_request_id' => $clientRequestId,
                    'request_fingerprint' => $fingerprint,
                ]);

                if ($stagedAttachment) {
                    $this->screenshots->attach($locked, $message, $stagedAttachment);
                }

                $updates = [
                    'last_user_message_at' => now(),
                    'version' => (int) $locked->version + 1,
                    'updated_at' => now(),
                ];
                if ($reopened) {
                    $updates += [
                        'status' => 'reviewing',
                        'resolved_at' => null,
                        'closed_at' => null,
                        'reopened_at' => now(),
                    ];
                }
                $locked->update($updates);
                $this->event($locked, $user?->id, $reopened ? 'reopened' : 'learner_replied', $fromStatus, $updates['status'] ?? $fromStatus);

                return $message;
            }, 3);
    }

    public function appendStaffMessage(
        FeedbackReport $report,
        User $staff,
        string $body,
        string $visibility,
        string $clientRequestId,
        int $expectedVersion
    ): SupportCaseMessage {
        $body = trim($body);
        $visibility = $visibility === SupportCaseMessage::VISIBILITY_INTERNAL
            ? SupportCaseMessage::VISIBILITY_INTERNAL
            : SupportCaseMessage::VISIBILITY_CUSTOMER;
        $fingerprint = hash('sha256', $visibility.'|'.$body);

        $message = DB::transaction(function () use (
            $report,
            $staff,
            $body,
            $visibility,
            $clientRequestId,
            $expectedVersion,
            $fingerprint
        ): SupportCaseMessage {
            if ($report->user_id) {
                User::withTrashed()->whereKey($report->user_id)->lockForUpdate()->first();
            }
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
            $existing = SupportCaseMessage::query()
                ->where('feedback_report_id', $locked->id)
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existing) {
                abort_unless(hash_equals((string) $existing->request_fingerprint, $fingerprint), 409);
                if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER && $locked->user_id) {
                    $this->notifyCustomer($locked, $existing);
                }
                return $existing;
            }
            abort_if((int) $locked->version !== $expectedVersion, 409, "عدّل شخص آخر هذه الحالة\nحدّث الصفحة ثم أعد المحاولة");

            $message = SupportCaseMessage::query()->create([
                'public_id' => (string) Str::ulid(),
                'feedback_report_id' => $locked->id,
                'author_id' => $staff->id,
                'author_type' => SupportCaseMessage::AUTHOR_STAFF,
                'visibility' => $visibility,
                'body' => $body,
                'client_request_id' => $clientRequestId,
                'request_fingerprint' => $fingerprint,
            ]);
            $updates = ['version' => (int) $locked->version + 1, 'updated_at' => now()];
            if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER) {
                $updates['last_staff_message_at'] = now();
                if ($locked->status === 'new') $updates['status'] = 'reviewing';
            }
            $locked->update($updates);
            $this->event($locked, $staff->id, $visibility === 'internal' ? 'internal_note' : 'staff_replied');
            if ($visibility === SupportCaseMessage::VISIBILITY_CUSTOMER && $locked->user_id) {
                $this->notifyCustomer($locked, $message);
            }

            return $message;
        }, 3);
        return $message;
    }

    /** @param array<string, mixed> $validated Validated staff state edit, including expected version. */
    public function updateState(FeedbackReport $feedback, array $validated, ?int $actorId): void
    {
        DB::transaction(function () use ($feedback, $validated, $actorId): void {
            if ($feedback->user_id) {
                User::withTrashed()->whereKey($feedback->user_id)->lockForUpdate()->first();
            }
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($feedback->id);
            $fromStatus = (string) $locked->status;
            $closed = in_array($validated['status'], ['resolved', 'closed', 'dismissed'], true);
            $desiredAssignedTo = isset($validated['assigned_to'])
                ? (int) $validated['assigned_to']
                : null;
            $desiredResolutionKind = $closed
                ? ($validated['resolution_kind'] ?? null)
                : null;
            if ((int) $locked->version !== (int) $validated['version']) {
                // A browser may retry the same form after the first response was
                // lost. Treat an already-applied desired state as success, while
                // retaining optimistic locking for every genuinely stale edit.
                $alreadyApplied = $fromStatus === $validated['status']
                    && (string) $locked->priority === $validated['priority']
                    && ($locked->assigned_to === null ? null : (int) $locked->assigned_to) === $desiredAssignedTo
                    && ($locked->resolution_kind ?: null) === $desiredResolutionKind;
                abort_unless($alreadyApplied, 409, "عدّل شخص آخر هذه الحالة\nحدّث الصفحة ثم أعد المحاولة");
                return;
            }
            $statusChanged = $fromStatus !== $validated['status'];
            $updates = [
                'status' => $validated['status'],
                'priority' => $validated['priority'],
                'assigned_to' => $desiredAssignedTo,
                'resolution_kind' => $desiredResolutionKind,
                'resolved_at' => $validated['status'] === 'resolved' ? ($locked->resolved_at ?: now()) : null,
                'closed_at' => in_array($validated['status'], ['closed', 'dismissed'], true)
                    ? ($locked->closed_at ?: now()) : null,
                'version' => (int) $locked->version + 1,
            ];
            if (!$locked->last_staff_message_at && $locked->priority !== $validated['priority']) {
                $updates['first_response_due_at'] = $this->firstResponseDueAt($validated['priority']);
            }
            $locked->update($updates);
            $this->event($locked, $actorId, 'updated', $fromStatus, $validated['status'], [
                'assigned_to' => $updates['assigned_to'],
                'priority' => $updates['priority'],
                'resolution_kind' => $updates['resolution_kind'],
            ]);
            if ($statusChanged && in_array($validated['status'], ['waiting_for_user', 'resolved', 'closed'], true)) {
                $this->notifyStatus(
                    $locked,
                    $validated['status'],
                    'support-case:'.$locked->id.':status:'.$validated['status'].':v'.$updates['version']
                );
            }
        }, 3);
    }

    public function claim(FeedbackReport $report, User $user, ?string $accessToken): FeedbackReport
    {
        return DB::transaction(function () use ($report, $user, $accessToken): FeedbackReport {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($report->id);
            $this->access->authorizeViewer($locked, $user, $accessToken);
            abort_if($locked->user_id && (int) $locked->user_id !== (int) $user->id, 404);
            if (!$locked->user_id) {
                $locked->update([
                    'user_id' => $user->id,
                    'guest_access_hash' => null,
                    'version' => (int) $locked->version + 1,
                ]);
                $this->event($locked, $user->id, 'claimed');
            }

            return $locked;
        }, 3);
    }

    public function firstResponseDueAt(string $priority = 'normal'): \Carbon\CarbonInterface
    {
        $hours = ['urgent' => 2, 'high' => 8, 'normal' => 24, 'low' => 72][$priority] ?? 24;
        return now()->addHours($hours);
    }

    public function notifyStatus(FeedbackReport $report, string $status, string $deliveryKey): void
    {
        $user = $report->user;
        if (!$user) return;
        [$titleAr, $messageAr] = match ($status) {
            'resolved' => ['تم حل البلاغ', 'راجع رد فريق الدعم على البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
            'waiting_for_user' => ['ينتظر الدعم ردك', 'أرسل التفاصيل المطلوبة في البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
            default => ['تحديث على بلاغك', 'راجع آخر تحديث على البلاغ '.strtoupper(substr((string) $report->public_id, -8))],
        };
        $this->notifications->notifyUser(
            $user,
            new StudentNotificationIntent(
                notificationType: StudentNotificationService::TYPE_SUPPORT_CASE_UPDATE,
                titleAr: $titleAr,
                titleEn: 'Support case updated',
                messageAr: $messageAr,
                messageEn: 'Your support case was updated',
                link: 'rokn://support/'.$report->public_id,
                notifiableType: FeedbackReport::class,
                notifiableId: (int) $report->id,
                deliveryKey: $deliveryKey,
                templateVariables: ['case' => strtoupper(substr((string) $report->public_id, -8))]
            )
        );
    }

    public function event(
        FeedbackReport $report,
        ?int $actorId,
        string $type,
        ?string $fromStatus = null,
        ?string $toStatus = null,
        array $metadata = []
    ): SupportCaseEvent {
        return SupportCaseEvent::query()->create([
            'feedback_report_id' => $report->id,
            'actor_id' => $actorId,
            'event_type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'metadata' => $this->safeEventMetadata($metadata),
        ]);
    }

    private function notifyCustomer(FeedbackReport $report, SupportCaseMessage $message): void
    {
        $user = $report->user;
        if (!$user) return;
        $this->notifications->notifyUser(
            $user,
            new StudentNotificationIntent(
                notificationType: StudentNotificationService::TYPE_SUPPORT_CASE_UPDATE,
                titleAr: 'رد فريق الدعم',
                titleEn: 'Support replied',
                messageAr: 'لديك رد جديد على البلاغ '.strtoupper(substr((string) $report->public_id, -8)),
                messageEn: 'You have a new support reply',
                link: 'rokn://support/'.$report->public_id,
                notifiableType: FeedbackReport::class,
                notifiableId: (int) $report->id,
                deliveryKey: 'support-case:'.$report->id.':message:'.$message->id,
                templateVariables: ['case' => strtoupper(substr((string) $report->public_id, -8))]
            )
        );
    }

    private function safeEventMetadata(array $metadata): array
    {
        return array_intersect_key($metadata, array_flip([
            'assigned_to', 'priority', 'resolution_kind', 'order_id', 'compensation_event_key',
        ]));
    }
}
