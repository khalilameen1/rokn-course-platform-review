<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\PaymentReconciliationFinding;
use Illuminate\Database\Eloquent\Builder;

final class AdminPaymentFindingReadService
{
    /** @return Builder<PaymentReconciliationFinding> */
    public function query(): Builder
    {
        return PaymentReconciliationFinding::query()->with(['order', 'resolver:id,name'])
            ->orderByRaw("CASE state WHEN 'open' THEN 0 WHEN 'ignored' THEN 1 ELSE 2 END")
            ->latest('last_seen_at')->latest('id');
    }

    /** @param Builder<PaymentReconciliationFinding> $query @return Builder<PaymentReconciliationFinding> */
    public function search(Builder $query, string $reference): Builder
    {
        $pattern = '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], trim($reference)).'%';

        return $query->whereRaw("order_ref LIKE ? ESCAPE '!'", [$pattern]);
    }

    /** @return array<string, string> */
    public function kinds(): array
    {
        return PaymentReconciliationFinding::query()->whereNotNull('kind')->distinct()->orderBy('kind')->pluck('kind')
            ->mapWithKeys(fn (string $kind): array => [$kind => self::KINDS[$kind] ?? $kind])->all();
    }

    /** @return array<string, int> */
    public function stateCounts(): array
    {
        return PaymentReconciliationFinding::query()->selectRaw('state, COUNT(*) AS aggregate')->groupBy('state')->pluck('aggregate', 'state')
            ->map(fn ($count): int => (int) $count)->all();
    }

    public const STATES = ['open' => 'مفتوحة', 'resolved' => 'محلولة', 'ignored' => 'متجاهلة'];

    public const KINDS = [
        'provider_unavailable' => 'تعذر الاتصال بمزود الدفع', 'provider_status_missing' => 'المزود لم يُرجع حالة',
        'captured_evidence_mismatch' => 'بيانات التحصيل لا تطابق الطلب', 'captured_transaction_conflict' => 'مرجع التحصيل مرتبط بعملية أخرى',
        'captured_local_fulfillment_blocked' => 'تم التحصيل ولم يكتمل التسليم', 'provider_reversal_requires_review' => 'المزود عكس عملية مكتملة',
        'provider_failed_local_settled' => 'المزود رفض عملية مسجلة محليًا كمكتملة', 'provider_pending_after_local_expiry' => 'العملية ما زالت معلقة لدى المزود بعد انتهائها محليًا',
        'provider_local_status_mismatch' => 'حالة المزود تختلف عن حالة الطلب', 'late_capture_overlaps_newer_payment' => 'تحصيل متأخر يتداخل مع محاولة أحدث',
        'capture_after_account_deletion' => 'تم التحصيل بعد حذف الحساب', 'reconciliation_exception' => 'تعذر إكمال فحص المطابقة',
    ];

    public const NEXT_STEPS = [
        'provider_unavailable' => 'أعد المراجعة بعد عودة المزود', 'provider_status_missing' => 'تحقق من العملية في لوحة المزود',
        'captured_evidence_mismatch' => 'طابق المبلغ والعملة ومرجع العملية', 'captured_transaction_conflict' => 'تحقق من الطلب الصحيح قبل أي تسوية',
        'captured_local_fulfillment_blocked' => 'راجع سبب تعطل التسليم في الطلب', 'provider_reversal_requires_review' => 'راجع الاسترداد وأثره المالي',
        'provider_failed_local_settled' => 'راجع دليل الدفع قبل تسجيل القرار', 'provider_pending_after_local_expiry' => 'انتظر نتيجة نهائية أو تحقق لدى المزود',
        'provider_local_status_mismatch' => 'طابق حالة الطلب مع دليل المزود', 'late_capture_overlaps_newer_payment' => 'راجع المحاولتين قبل أي تدخل',
        'capture_after_account_deletion' => 'صعّد العملية للدعم المالي', 'reconciliation_exception' => 'راجع اتصال المزود ثم أعد الفحص',
    ];
}
