<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\FeedbackReport;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Binds verified order compensation to a case; the order ledger owns the credit. */
final class SupportCaseCompensationService
{
    public function __construct(
        private readonly OrderLifecycleService $orders,
        private readonly SupportCaseService $cases
    ) {
    }

    /** @param array<string, mixed> $validated Validated amount, note and case version. */
    public function compensate(FeedbackReport $feedback, array $validated, ?int $actorId): void
    {
        abort_unless($feedback->order_id, 422);
        $eventKey = 'support-case-compensation:'.$feedback->id.':'.hash('sha256', $validated['amount'].'|'.trim($validated['note']));

        DB::transaction(function () use ($feedback, $validated, $eventKey, $actorId): void {
            if ($feedback->user_id) {
                User::withTrashed()->whereKey($feedback->user_id)->lockForUpdate()->firstOrFail();
            }
            $order = Order::query()->lockForUpdate()->findOrFail($feedback->order_id);
            $locked = FeedbackReport::query()->lockForUpdate()->findOrFail($feedback->id);
            abort_if((int) $locked->version !== (int) $validated['version'], 409, "تغيّرت الحالة\nحدّث الصفحة قبل تسجيل التعويض");
            abort_unless(
                (int) $order->id === (int) $locked->order_id
                && (int) $order->user_id === (int) $locked->user_id,
                422
            );
            $this->orders->compensateCourseOrder(
                $order,
                (int) $validated['amount'],
                trim($validated['note']),
                $eventKey,
                $actorId
            );
            $locked->update(['resolution_kind' => 'compensated', 'version' => (int) $locked->version + 1]);
            $this->cases->event($locked, $actorId, 'compensated', null, null, [
                'order_id' => $locked->order_id,
                'compensation_event_key' => $eventKey,
            ]);
        }, 3);
    }
}
