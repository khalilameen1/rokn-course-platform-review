<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Contact;
use App\Support\ContactEditorVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Contact state changes and the verified-request deletion workflow, with explicit audit actors. */
final class AdminContactWorkflowService
{
    public function __construct(
        private readonly ContactAccountLookupService $accountsByEmail,
        private readonly AccountDeletionService $deletion
    ) {
    }

    public function markRead(int $contactId, string $expected): void
    {
        DB::transaction(function () use ($contactId, $expected): void {
            $locked = $this->lockedCurrent($contactId, $expected);
            if (!$locked->read) $locked->forceFill(['read' => true])->save();
        }, 3);
    }

    public function deleteMessage(int $contactId, string $expected): void
    {
        DB::transaction(function () use ($contactId, $expected): void {
            $locked = $this->lockedCurrent($contactId, $expected);
            if ($locked->isAccountDeletionRequest()) {
                throw ValidationException::withMessages([
                    'editor_version' => ['لا يمكن حذف سجل طلب حذف حساب'],
                ]);
            }
            $locked->delete();
        }, 3);
    }

    public function markProcessing(int $contactId, string $expected, int $actorId): void
    {
        DB::transaction(function () use ($contactId, $expected, $actorId): void {
            $locked = $this->lockedCurrent($contactId, $expected);
            if (!$locked->isAccountDeletionRequest() || $locked->isResolved()) {
                throw ValidationException::withMessages([
                    'editor_version' => ['لا يمكن بدء معالجة هذا الطلب في حالته الحالية'],
                ]);
            }

            $metadata = (array) ($locked->resolution_metadata ?? []);
            $metadata['processing_started_at'] = now()->toIso8601String();
            $metadata['processing_started_by'] = $actorId;
            $locked->forceFill([
                'read' => true,
                'resolution_status' => Contact::RESOLUTION_PROCESSING,
                'resolution_metadata' => $metadata,
            ])->save();
        }, 3);

    }

    /** @param array{editor_version:string,outcome:string,resolution_note?:?string} $validated */
    public function closeDeletionRequest(int $contactId, array $validated, int $actorId): void
    {
        DB::transaction(function () use ($contactId, $validated, $actorId): void {
            $locked = $this->lockedCurrent($contactId, (string) $validated['editor_version']);
            if (!$locked->isAccountDeletionRequest() || $locked->isResolved() || !$locked->isProcessing()) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت حالة الطلب\nحدّث الصفحة قبل الإغلاق"],
                ]);
            }
            $matchedUser = $this->accountsByEmail->forEmail($locked->email);
            if (in_array($validated['outcome'], ['self_service_completed', 'no_account_found'], true) && $matchedUser) {
                throw ValidationException::withMessages([
                    'outcome' => ['الحساب المطابق ما زال موجودًا'],
                ]);
            }

            $metadata = (array) ($locked->resolution_metadata ?? []);
            $metadata['outcome'] = $validated['outcome'];
            $metadata['note'] = trim((string) ($validated['resolution_note'] ?? '')) ?: null;
            $locked->forceFill([
                'read' => true,
                'resolution_status' => Contact::RESOLUTION_CLOSED,
                'resolved_at' => now(),
                'resolved_by' => $actorId,
                'resolved_user_id' => $matchedUser?->id,
                'resolution_metadata' => $metadata,
            ])->save();
        }, 3);

    }

    /**
     * Caller must validate the administrator's identity-verification and deletion confirmations.
     * @param array{editor_version:string,account_email:string,verification_note:string} $validated
     * @return bool Whether account file cleanup remains pending.
     */
    public function executeVerifiedDeletion(int $contactId, array $validated, int $actorId): bool
    {
        return DB::transaction(function () use ($contactId, $validated, $actorId): bool {
            $locked = $this->lockedCurrent($contactId, (string) $validated['editor_version']);
            if (!$locked->isAccountDeletionRequest() || $locked->isResolved() || !$locked->isProcessing()) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّرت حالة الطلب\nحدّث الصفحة قبل تنفيذ الحذف"],
                ]);
            }
            $matchedUser = $this->accountsByEmail->forEmail($locked->email);
            if (!$matchedUser || strtolower((string) $matchedUser->role) !== 'client') {
                throw ValidationException::withMessages([
                    'account_email' => ['لا يوجد حساب طالب نشط مطابق لهذا الطلب'],
                ]);
            }
            $confirmedEmail = Str::lower(trim((string) $validated['account_email']));
            if (!hash_equals(Str::lower(trim((string) $matchedUser->email)), $confirmedEmail)) {
                throw ValidationException::withMessages([
                    'account_email' => ['بريد التأكيد لا يطابق الحساب المطلوب حذفه'],
                ]);
            }

            $cleanup = $this->deletion->delete($matchedUser);
            $pending = (bool) (
                $cleanup['local_cleanup_pending']
                || $cleanup['remote_portfolio_cleanup_pending']
            );
            $metadata = (array) ($locked->resolution_metadata ?? []);
            $metadata['outcome'] = 'manual_verified_deletion';
            $metadata['note'] = trim((string) $validated['verification_note']);
            $metadata['cleanup_pending'] = $pending;
            $locked->forceFill([
                'read' => true,
                'resolution_status' => Contact::RESOLUTION_CLOSED,
                'resolved_at' => now(),
                'resolved_by' => $actorId,
                'resolved_user_id' => $matchedUser->id,
                'resolution_metadata' => $metadata,
            ])->save();
            return $pending;
        }, 3);

    }

    private function lockedCurrent(int $contactId, string $expected): Contact
    {
        $locked = Contact::query()->whereKey($contactId)->lockForUpdate()->firstOrFail();
        if (!hash_equals(ContactEditorVersion::for($locked), $expected)) {
            throw ValidationException::withMessages([
                'editor_version' => ["تغيّرت الرسالة منذ فتح الصفحة\nحدّثها قبل تنفيذ الإجراء"],
            ]);
        }
        return $locked;
    }


}
