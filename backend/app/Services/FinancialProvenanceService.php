<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\FinancialProvenanceException;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\FinancialEntitlementHold;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletCreditLot;
use App\Models\WalletDebitAllocation;
use App\Models\WalletTransaction;
use App\Support\DatabaseCapabilities;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Tracks the paid-coin lots that fund course entitlements. */
final readonly class FinancialProvenanceService
{
    public function __construct(
        private WalletService $wallet,
        private AiEntitlementBudgetService $aiBudget
    ) {
    }

    public function schemaAvailable(): bool
    {
        return DatabaseCapabilities::hasTable('wallet_credit_lots')
            && DatabaseCapabilities::hasTable('wallet_debit_allocations')
            && DatabaseCapabilities::hasTable('financial_entitlement_holds');
    }

    public function recordPaidPackageCredit(
        Order $packageOrder,
        WalletTransaction $credit
    ): WalletCreditLot {
        if (!$this->schemaAvailable()) {
            // Paid credits require complete source attribution.
            throw new FinancialProvenanceException('Financial provenance is not ready.');
        }

        if (
            !$packageOrder->package_id
            || $packageOrder->status !== Order::STATUS_APPROVED
            || (int) $credit->user_id !== (int) $packageOrder->user_id
            || $credit->direction !== WalletTransaction::DIRECTION_CREDIT
            || $credit->category !== 'package_purchase'
            || $credit->bucket !== WalletTransaction::BUCKET_PAID
            || $credit->source_type !== Order::class
            || (int) $credit->source_id !== (int) $packageOrder->id
            || (int) $packageOrder->package_coins <= 0
            || (int) $credit->amount !== (int) $packageOrder->package_coins
            || (int) $credit->amount !== (int) $credit->paid_amount
            || (int) $credit->paid_amount <= 0
            || (int) $credit->reward_amount !== 0
        ) {
            throw new FinancialProvenanceException('Invalid paid package credit provenance.');
        }

        return DB::transaction(function () use ($packageOrder, $credit): WalletCreditLot {
            $existing = WalletCreditLot::query()
                ->where(function (Builder $query) use ($packageOrder, $credit): void {
                    $query->where('credit_transaction_id', $credit->id)
                        ->orWhere('source_order_id', $packageOrder->id);
                })
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (
                    (int) $existing->user_id !== (int) $packageOrder->user_id
                    || (int) $existing->credit_transaction_id !== (int) $credit->id
                    || (int) $existing->source_order_id !== (int) $packageOrder->id
                    || (int) $existing->original_amount !== (int) $packageOrder->package_coins
                ) {
                    throw new FinancialProvenanceException(
                        'A paid package credit was replayed with different financial facts.'
                    );
                }

                return $existing;
            }

            return WalletCreditLot::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $packageOrder->user_id,
                'source_order_id' => $packageOrder->id,
                'credit_transaction_id' => $credit->id,
                'original_amount' => (int) $credit->paid_amount,
                'remaining_amount' => (int) $credit->paid_amount,
                'recovered_amount' => 0,
                'status' => WalletCreditLot::STATUS_ACTIVE,
                'credited_at' => $credit->occurred_at ?: now(),
                'metadata' => [
                    'package_id' => (int) $packageOrder->package_id,
                    'transaction_id' => $packageOrder->transaction_id,
                ],
            ]);
        }, 3);
    }

    /**
     * A service compensation restores the learner's original paid/reward
     * split, but its paid part is not a second cash receipt. Give that value a
     * separate active lot so the paid-wallet projection remains attributable
     * without letting a future course report count the original package cash
     * twice.
     */
    public function recordPaidCompensationCredit(
        WalletTransaction $originalDebit,
        WalletTransaction $compensation
    ): ?WalletCreditLot {
        $paidAmount = max(0, (int) $compensation->paid_amount);
        if ($paidAmount === 0) {
            return null;
        }
        if (!$this->schemaAvailable()) {
            throw new FinancialProvenanceException('Financial provenance is not ready.');
        }
        if (
            $originalDebit->direction !== WalletTransaction::DIRECTION_DEBIT
            || !in_array($originalDebit->category, [
                'course_purchase',
                'course_chat_upgrade',
                'course_full_track_upgrade',
            ], true)
            || $originalDebit->source_type !== Course::class
            || $compensation->direction !== WalletTransaction::DIRECTION_CREDIT
            || $compensation->category !== 'course_service_compensation'
            || $compensation->source_type !== Order::class
            || !$compensation->source_id
            || (int) $compensation->user_id !== (int) $originalDebit->user_id
            || $paidAmount > (int) $originalDebit->paid_amount
            || (string) data_get($compensation->metadata, 'refunded_transaction_id')
                !== (string) $originalDebit->public_id
            || $paidAmount + (int) $compensation->reward_amount
                !== (int) $compensation->amount
        ) {
            throw new FinancialProvenanceException('Invalid paid compensation provenance.');
        }

        return DB::transaction(function () use (
            $originalDebit,
            $compensation,
            $paidAmount
        ): WalletCreditLot {
            User::withTrashed()
                ->whereKey($compensation->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $courseOrder = Order::query()
                ->whereKey($compensation->source_id)
                ->where('user_id', $compensation->user_id)
                ->where('course_id', $originalDebit->source_id)
                ->where('wallet_transaction_id', $originalDebit->id)
                ->where('payment_method', Order::PAYMENT_METHOD_WALLET_COINS)
                ->lockForUpdate()
                ->first();
            if (!$courseOrder) {
                throw new FinancialProvenanceException(
                    'Paid compensation is not bound to its course order.'
                );
            }
            $existing = WalletCreditLot::query()
                ->where('credit_transaction_id', $compensation->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (
                    (int) $existing->user_id !== (int) $compensation->user_id
                    || $existing->source_order_id !== null
                    || (int) $existing->original_amount !== $paidAmount
                ) {
                    throw new FinancialProvenanceException(
                        'A paid compensation was replayed with different financial facts.'
                    );
                }

                return $existing;
            }

            return WalletCreditLot::query()->create([
                'public_id' => (string) Str::uuid(),
                'user_id' => $compensation->user_id,
                'source_order_id' => null,
                'credit_transaction_id' => $compensation->id,
                'original_amount' => $paidAmount,
                'remaining_amount' => $paidAmount,
                'recovered_amount' => 0,
                'status' => WalletCreditLot::STATUS_ACTIVE,
                'credited_at' => $compensation->occurred_at ?: now(),
                'metadata' => [
                    'provenance_type' => 'course_service_compensation',
                    'refunded_transaction_id' => (string) $originalDebit->public_id,
                ],
            ]);
        }, 3);
    }

    /** Paid debit allocations consume locked lots in FIFO order. */
    public function allocateCourseDebit(
        Order $courseOrder,
        WalletTransaction $debit
    ): void {
        $paidAmount = max(0, (int) $debit->paid_amount);
        if ($paidAmount === 0) {
            return;
        }
        if (!$this->schemaAvailable()) {
            throw new FinancialProvenanceException('Financial provenance is not ready.');
        }
        if (
            !$courseOrder->course_id
            || (int) $courseOrder->user_id !== (int) $debit->user_id
            || $debit->direction !== WalletTransaction::DIRECTION_DEBIT
            || !in_array($debit->category, [
                'course_purchase',
                'course_chat_upgrade',
                'course_full_track_upgrade',
            ], true)
            || $debit->source_type !== Course::class
            || (int) $debit->source_id !== (int) $courseOrder->course_id
            || (int) $courseOrder->wallet_transaction_id !== (int) $debit->id
            || (int) $courseOrder->total_coins !== (int) $debit->amount
            || (int) $courseOrder->paid_coins !== $paidAmount
            || (int) $courseOrder->reward_coins !== (int) $debit->reward_amount
        ) {
            throw new FinancialProvenanceException('Invalid course debit provenance.');
        }

        DB::transaction(function () use ($courseOrder, $debit, $paidAmount): void {
            $entitlementScope = match ($debit->category) {
                'course_chat_upgrade' => 'chat',
                'course_full_track_upgrade' => 'plan',
                default => 'course',
            };
            $existing = WalletDebitAllocation::query()
                ->where('wallet_transaction_id', $debit->id)
                ->lockForUpdate()
                ->get();

            if ($existing->isNotEmpty()) {
                if (
                    (int) $existing->sum('amount') !== $paidAmount
                    || $existing->contains(fn (WalletDebitAllocation $allocation): bool =>
                        (int) $allocation->course_order_id !== (int) $courseOrder->id
                        || (int) $allocation->amount <= 0
                        || $allocation->entitlement_scope !== $entitlementScope
                    )
                    || WalletCreditLot::query()
                        ->whereIn('id', $existing->pluck('credit_lot_id'))
                        ->where('user_id', $courseOrder->user_id)
                        ->count() !== $existing->pluck('credit_lot_id')->unique()->count()
                ) {
                    throw new FinancialProvenanceException(
                        'A course debit was replayed with different paid-lot allocations.'
                    );
                }

                return;
            }

            $remaining = $paidAmount;
            $lots = WalletCreditLot::query()
                ->where('user_id', $courseOrder->user_id)
                ->where('status', WalletCreditLot::STATUS_ACTIVE)
                ->where('remaining_amount', '>', 0)
                ->orderBy('credited_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($lots as $lot) {
                if ($remaining === 0) {
                    break;
                }

                $allocated = min($remaining, (int) $lot->remaining_amount);
                if ($allocated <= 0) {
                    continue;
                }

                WalletDebitAllocation::query()->create([
                    'wallet_transaction_id' => $debit->id,
                    'credit_lot_id' => $lot->id,
                    'course_order_id' => $courseOrder->id,
                    'amount' => $allocated,
                    'entitlement_scope' => $entitlementScope,
                    'allocated_at' => $debit->occurred_at ?: now(),
                ]);
                $lot->forceFill([
                    'remaining_amount' => (int) $lot->remaining_amount - $allocated,
                ])->save();
                $remaining -= $allocated;
            }

            if ($remaining !== 0) {
                throw new FinancialProvenanceException(
                    'Purchased wallet balance has no complete paid-source provenance.'
                );
            }
        }, 3);
    }

    /** @return array{recovered:int,unrecovered:int,holds:int} */
    public function applyPackageReversal(Order $packageOrder, string $reason): array
    {
        if (!$this->schemaAvailable()) {
            throw new FinancialProvenanceException('Financial provenance is not ready.');
        }
        if (
            !$packageOrder->package_id
            || $packageOrder->status !== Order::STATUS_APPROVED
        ) {
            throw new FinancialProvenanceException('Only an approved package order can be reversed.');
        }

        /** @var User $user */
        $user = User::withTrashed()->lockForUpdate()->findOrFail($packageOrder->user_id);
        /** @var WalletCreditLot|null $lot */
        $lot = WalletCreditLot::query()
            ->where('source_order_id', $packageOrder->id)
            ->lockForUpdate()
            ->first();
        if (!$lot) {
            // A reversal requires its original paid-credit lot.
            throw new FinancialProvenanceException(
                'Paid package order has no provenance lot; run the finance backfill.'
            );
        }
        $credit = WalletTransaction::query()
            ->whereKey($lot->credit_transaction_id)
            ->lockForUpdate()
            ->first();
        if (
            !$credit
            || (int) $lot->user_id !== (int) $packageOrder->user_id
            || (int) $lot->source_order_id !== (int) $packageOrder->id
            || (int) $lot->original_amount !== (int) $packageOrder->package_coins
            || (int) $credit->user_id !== (int) $packageOrder->user_id
            || $credit->direction !== WalletTransaction::DIRECTION_CREDIT
            || $credit->category !== 'package_purchase'
            || $credit->bucket !== WalletTransaction::BUCKET_PAID
            || $credit->source_type !== Order::class
            || (int) $credit->source_id !== (int) $packageOrder->id
            || (int) $credit->paid_amount !== (int) $lot->original_amount
            || (int) $credit->amount !== (int) $credit->paid_amount
        ) {
            throw new FinancialProvenanceException(
                'Paid package reversal has inconsistent source provenance.'
            );
        }

        if ($lot->status !== WalletCreditLot::STATUS_ACTIVE) {
            return [
                'recovered' => (int) $packageOrder->recovered_coins,
                'unrecovered' => (int) $packageOrder->unrecovered_coins,
                'holds' => FinancialEntitlementHold::query()
                    ->where('source_order_id', $packageOrder->id)
                    ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                    ->count(),
            ];
        }

        $availableFromLot = max(0, (int) $lot->remaining_amount);
        $walletBalances = $this->wallet->balances($user);
        $recoverable = min(
            $availableFromLot,
            $walletBalances['paid']
        );
        if ($recoverable > 0) {
            $this->wallet->debit(
                (int) $user->id,
                $recoverable,
                'package_reversal',
                'financial-reversal:paid-lot:' . $lot->id,
                $packageOrder,
                ['source_order_id' => $packageOrder->id],
                0
            );
        }

        $lot->forceFill([
            'remaining_amount' => max(0, $availableFromLot - $recoverable),
            'recovered_amount' => (int) $lot->recovered_amount + $recoverable,
            'status' => WalletCreditLot::STATUS_FROZEN,
            'frozen_at' => $lot->frozen_at ?: now(),
            'resolved_at' => null,
            'metadata' => array_merge((array) $lot->metadata, [
                'reversal_reason' => $reason,
            ]),
        ])->save();

        $courseOrders = WalletDebitAllocation::query()
            ->where('credit_lot_id', $lot->id)
            ->whereNotNull('course_order_id')
            ->select(['course_order_id', 'entitlement_scope'])
            ->distinct()
            ->get();
        $holds = 0;

        foreach ($courseOrders as $allocatedOrder) {
            $courseOrderId = (int) $allocatedOrder->course_order_id;
            $entitlementScope = (string) ($allocatedOrder->entitlement_scope ?: 'course');
            /** @var Order|null $courseOrder */
            $courseOrder = Order::query()
                ->whereKey($courseOrderId)
                ->where('user_id', $packageOrder->user_id)
                ->whereNotNull('course_id')
                ->lockForUpdate()
                ->first();
            if (!$courseOrder) {
                continue;
            }

            $priorPlanHold = $entitlementScope === 'plan'
                ? FinancialEntitlementHold::query()
                    ->where('course_order_id', $courseOrder->id)
                    ->where('entitlement_scope', 'plan')
                    ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                    ->orderByDesc('id')
                    ->first()
                : null;
            $enrollmentQuery = CourseEnrollment::query()
                ->where('user_id', $courseOrder->user_id)
                ->where('course_id', $courseOrder->course_id);
            if ($entitlementScope === 'plan') {
                $enrollmentQuery->where('access_plan_order_id', $courseOrder->id);
            } else {
                $enrollmentQuery->where('order_id', $courseOrder->id);
            }
            $enrollment = $enrollmentQuery->lockForUpdate()->first();
            if (!$enrollment && $priorPlanHold?->enrollment_id) {
                // All lots in one plan debit share the same enrollment hold.
                $enrollment = CourseEnrollment::query()
                    ->whereKey($priorPlanHold->enrollment_id)
                    ->where('user_id', $courseOrder->user_id)
                    ->where('course_id', $courseOrder->course_id)
                    ->lockForUpdate()
                    ->first();
            }

            // Unrelated replacement entitlements remain active.
            if (!$enrollment) {
                continue;
            }

            $parentPlanOrder = null;
            if ($entitlementScope === 'plan') {
                $parentPlanOrder = Order::query()
                    ->whereKey($courseOrder->parent_order_id)
                    ->where('user_id', $courseOrder->user_id)
                    ->where('course_id', $courseOrder->course_id)
                    ->lockForUpdate()
                    ->first();
                if (!$parentPlanOrder) {
                    throw new FinancialProvenanceException(
                        'Plan upgrade has no valid parent order; reversal requires manual reconciliation.'
                    );
                }
            }

            $priorFinancialHold = $entitlementScope === 'course'
                ? FinancialEntitlementHold::query()
                    ->where('course_order_id', $courseOrder->id)
                    ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                    ->orderByDesc('id')
                    ->first([
                        'enrollment_deactivated_at',
                        'certificate_id',
                        'certificate_revoked_at',
                    ])
                : null;
            $enrollmentDeactivatedAt = $entitlementScope === 'course' && $enrollment->is_active
                ? now()
                : $priorFinancialHold?->enrollment_deactivated_at;
            $planRevertedAt = $entitlementScope === 'plan'
                ? ($priorPlanHold?->plan_reverted_at ?: now())
                : null;
            $certificate = $entitlementScope === 'course'
                ? Certificate::query()
                    ->where('user_id', $courseOrder->user_id)
                    ->where('course_id', $courseOrder->course_id)
                    ->where('status', 'active')
                    ->lockForUpdate()
                    ->first()
                : null;
            $financialCertificateId = $certificate?->id;
            $certificateRevokedAt = $certificate ? now() : null;
            if (!$financialCertificateId && $entitlementScope === 'course') {
                $financialCertificateId = $priorFinancialHold?->certificate_id;
                $certificateRevokedAt = $priorFinancialHold?->certificate_revoked_at;
            }

            $hold = FinancialEntitlementHold::query()->firstOrNew(
                [
                    'source_order_id' => $packageOrder->id,
                    'course_order_id' => $courseOrder->id,
                ]
            );
            $hold->forceFill([
                'public_id' => $hold->public_id ?: (string) Str::uuid(),
                'user_id' => $courseOrder->user_id,
                'course_id' => $courseOrder->course_id,
                'enrollment_id' => $enrollment?->id,
                'enrollment_deactivated_at' => $enrollmentDeactivatedAt
                    ?: $hold->enrollment_deactivated_at,
                'plan_reverted_at' => $planRevertedAt ?: $hold->plan_reverted_at,
                'certificate_id' => $financialCertificateId ?: $hold->certificate_id,
                'certificate_revoked_at' => $certificateRevokedAt ?: $hold->certificate_revoked_at,
                'status' => FinancialEntitlementHold::STATUS_ACTIVE,
                'entitlement_scope' => $entitlementScope,
                'reason' => $reason,
                'resolution' => null,
                'resolution_note' => null,
                'resolved_by' => null,
                'held_at' => now(),
                'resolved_at' => null,
            ])->save();
            if ($hold->status === FinancialEntitlementHold::STATUS_ACTIVE) {
                $holds++;
            }

            // Deactivate only the entitlement still owned by this order.
            if ($entitlementScope === 'course' && $enrollment->is_active) {
                // This enrollment is already locked and was selected through
                // the owning order above. Persist through the model so course
                // catalogue aggregates are invalidated with every other
                // enrollment transition instead of staying stale for minutes.
                $enrollment->forceFill([
                    'is_active' => false,
                    'updated_at' => $enrollmentDeactivatedAt,
                ])->save();
            }

            if (
                $entitlementScope === 'plan'
                && (int) $enrollment->access_plan_order_id === (int) $courseOrder->id
            ) {
                $this->aiBudget->cancelOutstandingReservations(
                    $enrollment,
                    'plan_payment_reversed'
                );
                $enrollment->forceFill([
                    'access_plan_order_id' => $parentPlanOrder->id,
                    'access_plan_id' => $parentPlanOrder->access_plan_id,
                    'access_plan_snapshot' => $parentPlanOrder->access_plan_snapshot,
                    'updated_at' => $planRevertedAt,
                ])->save();
            }

            if ($entitlementScope === 'course' && $certificate) {
                $certificate->forceFill([
                    'status' => 'revoked',
                    'revoked_at' => $certificateRevokedAt,
                ])->save();
            }
        }

        return [
            'recovered' => $recoverable,
            'unrecovered' => max(0, (int) $lot->original_amount - $recoverable),
            'holds' => $holds,
        ];
    }

    /** @return array{restored_coins:int,released_holds:int} */
    public function resolvePackageReversal(
        Order $packageOrder,
        string $resolution,
        ?int $actorId,
        ?string $note = null
    ): array {
        if (!in_array($resolution, [
            FinancialEntitlementHold::RESOLUTION_REPAID,
            FinancialEntitlementHold::RESOLUTION_WAIVED,
        ], true)) {
            throw new \InvalidArgumentException('Invalid financial resolution.');
        }
        if (!$this->schemaAvailable()) {
            throw new FinancialProvenanceException('Financial provenance is not ready.');
        }

        return DB::transaction(function () use (
            $packageOrder,
            $resolution,
            $actorId,
            $note
        ): array {
            User::withTrashed()->lockForUpdate()->findOrFail($packageOrder->user_id);
            /** @var Order $lockedOrder */
            $lockedOrder = Order::query()->lockForUpdate()->findOrFail($packageOrder->id);
            /** @var WalletCreditLot $lot */
            $lot = WalletCreditLot::query()
                ->where('source_order_id', $lockedOrder->id)
                ->lockForUpdate()
                ->firstOrFail();
            $holds = FinancialEntitlementHold::query()
                ->where('source_order_id', $lockedOrder->id)
                ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                ->lockForUpdate()
                ->get();

            if ($lot->resolved_at) {
                return ['restored_coins' => 0, 'released_holds' => 0];
            }

            $restoredCoins = 0;
            if ($resolution === FinancialEntitlementHold::RESOLUTION_REPAID) {
                $restoredCoins = max(0, (int) $lot->recovered_amount);
                if ($restoredCoins > 0) {
                    $this->wallet->credit(
                        (int) $lockedOrder->user_id,
                        $restoredCoins,
                        'package_reversal_resolution',
                        'financial-resolution:restore-paid-lot:' . $lot->id,
                        $lockedOrder,
                        ['resolution' => $resolution],
                        WalletTransaction::BUCKET_PAID
                    );
                }
                $lot->forceFill([
                    'remaining_amount' => (int) $lot->remaining_amount + $restoredCoins,
                    'recovered_amount' => 0,
                    'status' => WalletCreditLot::STATUS_ACTIVE,
                    'resolved_at' => now(),
                ])->save();
            } else {
                $lot->forceFill([
                    'status' => WalletCreditLot::STATUS_WAIVED,
                    'resolved_at' => now(),
                ])->save();
            }

            foreach ($holds as $hold) {
                $hold->forceFill([
                    'status' => FinancialEntitlementHold::STATUS_RESOLVED,
                    'resolution' => $resolution,
                    'resolution_note' => $note,
                    'resolved_by' => $actorId,
                    'resolved_at' => now(),
                ])->save();

                $hasAnotherHold = FinancialEntitlementHold::query()
                    ->where('course_order_id', $hold->course_order_id)
                    ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                    ->where('entitlement_scope', $hold->entitlement_scope)
                    ->exists();
                if (
                    $hold->entitlement_scope === 'course'
                    && !$hasAnotherHold
                    && $hold->enrollment_id
                    && $hold->enrollment_deactivated_at
                ) {
                    $restoredEnrollment = CourseEnrollment::query()
                        ->whereKey($hold->enrollment_id)
                        ->where('order_id', $hold->course_order_id)
                        ->where('is_active', false)
                        ->where('updated_at', $hold->enrollment_deactivated_at)
                        ->lockForUpdate()
                        ->first();
                    if ($restoredEnrollment) {
                        // Persist through the model just like revocation. The
                        // enrollment aggregate is embedded in catalogue/home
                        // cards, and a bulk update would bypass its after-
                        // commit revision invalidation.
                        $restoredEnrollment->forceFill(['is_active' => true])->save();
                    }
                }
                if (
                    $hold->entitlement_scope === 'course'
                    && !$hasAnotherHold
                    && $hold->certificate_id
                ) {
                    Certificate::query()
                        ->whereKey($hold->certificate_id)
                        ->where('status', 'revoked')
                        ->where('revoked_at', $hold->certificate_revoked_at)
                        ->update([
                            'status' => 'active',
                            'revoked_at' => null,
                            'updated_at' => now(),
                        ]);
                }
                if (
                    $hold->entitlement_scope === 'plan'
                    && !$hasAnotherHold
                    && $hold->enrollment_id
                    && $hold->plan_reverted_at
                ) {
                    $planOrder = Order::query()
                        ->whereKey($hold->course_order_id)
                        ->where('user_id', $hold->user_id)
                        ->where('course_id', $hold->course_id)
                        ->first();
                    if (!$planOrder || !$planOrder->parent_order_id) {
                        throw new FinancialProvenanceException(
                            'Resolved plan hold has no valid upgrade lineage.'
                        );
                    }
                    $enrollment = CourseEnrollment::query()
                        ->whereKey($hold->enrollment_id)
                        ->where('access_plan_order_id', $planOrder->parent_order_id)
                        ->lockForUpdate()
                        ->first();
                    if ($enrollment) {
                        $this->aiBudget->cancelOutstandingReservations(
                            $enrollment,
                            'plan_payment_restored'
                        );
                        $enrollment->forceFill([
                            'access_plan_order_id' => $planOrder->id,
                            'access_plan_id' => $planOrder->access_plan_id,
                            'access_plan_snapshot' => $planOrder->access_plan_snapshot,
                        ])->save();
                    }
                }
            }

            return [
                'restored_coins' => $restoredCoins,
                'released_holds' => $holds->count(),
            ];
        }, 3);
    }

    /** @param list<string> $scopes */
    public function enrollmentHasActiveHold(
        CourseEnrollment $enrollment,
        array $scopes = ['course']
    ): bool
    {
        if (!$this->schemaAvailable() || !$enrollment->order_id) {
            return false;
        }
        $hasCourseScope = in_array('course', $scopes, true);
        $planScopes = array_values(array_intersect($scopes, ['chat', 'plan']));
        $planOrderId = (int) ($enrollment->access_plan_order_id ?: $enrollment->order_id);
        if (!$hasCourseScope && ($planScopes === [] || $planOrderId <= 0)) {
            return false;
        }

        return FinancialEntitlementHold::query()
            ->where('user_id', $enrollment->user_id)
            ->where('course_id', $enrollment->course_id)
            ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
            ->whereIn('entitlement_scope', $scopes)
            ->where(function (Builder $orders) use (
                $enrollment,
                $hasCourseScope,
                $planScopes,
                $planOrderId
            ): void {
                if ($hasCourseScope) {
                    $orders->where(function (Builder $course) use ($enrollment): void {
                        $course->where('entitlement_scope', 'course')
                            ->where('course_order_id', $enrollment->order_id);
                    });
                }

                if ($planScopes !== [] && $planOrderId > 0) {
                    $method = $hasCourseScope ? 'orWhere' : 'where';
                    $orders->{$method}(function (Builder $plan) use ($planOrderId, $planScopes): void {
                        $plan->whereIn('entitlement_scope', $planScopes)
                            ->where('course_order_id', $planOrderId);
                    });
                }
            })
            ->exists();
    }
}
