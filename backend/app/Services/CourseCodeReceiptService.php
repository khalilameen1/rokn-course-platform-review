<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Bill;
use App\Models\Course;
use App\Models\CourseCode;
use App\Models\Order;
use App\Models\User;

/** Zero-value financial receipt; never a wallet charge or paid-plan entitlement. */
final class CourseCodeReceiptService
{
    /** The redemption owner holds the user, code and course locks in one transaction. */
    public function ensureWithinRedemption(User $user, CourseCode $code, Course $course): Order
    {
        $order = Order::query()->where('user_id', $user->id)
            ->where('course_code_id', $code->id)->orderBy('id')->first();
        if ($order) {
            if ((int) $order->course_id !== (int) $course->id
                || $order->payment_method !== Order::PAYMENT_METHOD_COURSE_CODE
                || $order->status !== Order::STATUS_APPROVED
                || !in_array($order->financial_status, [
                    null, '', Order::FINANCIAL_PENDING, Order::FINANCIAL_SETTLED,
                ], true)
                || (float) $order->final_amount !== 0.0) {
                throw new \LogicException('Existing course-code order does not match its redemption contract.');
            }
            $order->forceFill([
                'financial_status' => Order::FINANCIAL_SETTLED,
                'approved_at' => $order->approved_at ?: now(),
            ])->save();
        } else {
            $order = Order::query()->create([
                'user_id' => $user->id, 'course_id' => $course->id, 'course_code_id' => $code->id,
                'coupon_id' => null, 'coupon_code' => null,
                'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
                'amount' => 0, 'discount_amount' => 0, 'final_amount' => 0,
                'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
                'notes' => 'Course code grant #' . $code->id, 'approved_at' => now(),
            ]);
        }

        // Also repair an existing receipt left by the historical non-atomic flow.
        // A redeemable secret is never copied into notes or coupon fields.
        $bill = Bill::withTrashed()->firstOrNew(['order_id' => $order->id]);
        $bill->forceFill([
            'user_id' => $user->id, 'course_id' => $course->id,
            'bill_number' => $bill->bill_number ?: Bill::numberForOrder((int) $order->id),
            'amount' => 0, 'tax_amount' => 0, 'total_amount' => 0,
            'payment_status' => Bill::PAYMENT_STATUS_PAID,
            'payment_method' => Order::PAYMENT_METHOD_COURSE_CODE,
            'paid_at' => $bill->paid_at ?: now(),
            'notes' => 'Course code grant #' . $code->id, 'deleted_at' => null,
        ])->save();

        return $order;
    }
}
