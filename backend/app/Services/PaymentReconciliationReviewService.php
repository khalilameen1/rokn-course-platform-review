<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentReconciliationFinding;
use App\Support\PaymentFindingEditorVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Human review of evidence, never settlement, compensation or receipt verification. */
final class PaymentReconciliationReviewService
{
    public function transition(int $id, string $targetState, string $note, string $editorVersion, int $actorId): void
    {
        if (!in_array($targetState, [
            PaymentReconciliationFinding::STATE_OPEN, PaymentReconciliationFinding::STATE_RESOLVED,
            PaymentReconciliationFinding::STATE_IGNORED,
        ], true)) {
            throw new \InvalidArgumentException('Unknown reconciliation review state.');
        }
        $note = trim($note);
        if (mb_strlen($note) < 3 || mb_strlen($note) > 2000) {
            throw ValidationException::withMessages(['note' => 'اكتب سبب القرار بوضوح']);
        }
        DB::transaction(function () use ($id, $targetState, $note, $editorVersion, $actorId): void {
            $finding = PaymentReconciliationFinding::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $allowedStates = $targetState === PaymentReconciliationFinding::STATE_OPEN
                ? [PaymentReconciliationFinding::STATE_RESOLVED, PaymentReconciliationFinding::STATE_IGNORED]
                : [PaymentReconciliationFinding::STATE_OPEN];
            if (!in_array($finding->state, $allowedStates, true)) {
                throw ValidationException::withMessages([
                    'finding' => 'تغيرت حالة نتيجة التسوية بالفعل. حدّث الصفحة قبل تسجيل قرار جديد.',
                ]);
            }
            if (!hash_equals(PaymentFindingEditorVersion::for($finding), $editorVersion)) {
                throw ValidationException::withMessages([
                    'finding' => 'وصل دليل دفع أحدث منذ فتح الصفحة. راجعه قبل تسجيل القرار.',
                ]);
            }
            $finding->update([
                'state' => $targetState,
                'resolved_at' => $targetState === PaymentReconciliationFinding::STATE_OPEN ? null : now(),
                'resolved_by' => $targetState === PaymentReconciliationFinding::STATE_OPEN ? null : $actorId,
                'resolution_note' => $note,
            ]);
        }, 3);
    }
}
