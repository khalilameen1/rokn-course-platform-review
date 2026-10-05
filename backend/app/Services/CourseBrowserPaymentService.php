<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CourseCheckout;
use App\Models\Order;
use Illuminate\Support\Facades\URL;

/** A scoped payment capability, not an account login or a payment receipt. */
final class CourseBrowserPaymentService
{
    public function payableCheckout(Order $order, string $checkoutId): ?CourseCheckout
    {
        $checkout = CourseCheckout::query()
            ->where('public_id', $checkoutId)
            ->where('user_id', $order->user_id)
            ->where('funding_order_id', $order->id)
            ->where('channel', 'direct')
            ->where('status', 'pending_payment')
            ->first();
        if (!$checkout || !$checkout->canResumePaymentWith($order)) {
            return null;
        }
        return $checkout;
    }

    public function urlFor(Order $order, string $checkoutId): ?string
    {
        $checkout = $this->payableCheckout($order, $checkoutId);
        if (!$checkout) {
            return null;
        }
        // The order, not the abandoned quote timer, owns this payment window.
        $expires = $order->checkout_expires_at
            ?? $order->created_at->copy()->addMinutes(Order::KASHIER_CHECKOUT_TTL_MINUTES);

        return URL::temporarySignedRoute('course-payment.show', $expires, [
            'checkoutId' => $checkout->public_id,
            'order' => $order->id,
        ]);
    }
}
