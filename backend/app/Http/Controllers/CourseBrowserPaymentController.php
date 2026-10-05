<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Services\CourseBrowserPaymentService;
use App\Services\KashierService;
use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;

final class CourseBrowserPaymentController extends Controller
{
    public function __invoke(Request $request, string $checkoutId,
        CourseBrowserPaymentService $payments, KashierService $kashier): RedirectResponse
    {
        $order = Order::query()->findOrFail($request->integer('order'));
        // A copied or modified URL cannot change the recipient, amount or plan.
        $checkout = $payments->payableCheckout($order, $checkoutId);
        abort_unless($checkout !== null, 410);
        abort_unless(User::query()->whereKey($checkout->user_id)->where('active', true)->exists(), 410);
        $gatewayUrl = $kashier->getHppUrl((string) $order->order_ref,
            number_format((float) $order->final_amount, 2, '.', ''), 'EGP', route('payment.callback'));
        abort_unless(is_string($gatewayUrl)
            && preg_match('~\Ahttps://checkout\.kashier\.io(?:/|\?|$)~i', $gatewayUrl), 503);

        // Use Kashier's full hosted page, including its bank verification flow.
        // This signed handoff grants no account access and never settles an order.
        return redirect()->away($gatewayUrl, 303)->withHeaders([
            'Cache-Control' => 'private, no-store, max-age=0',
            'Referrer-Policy' => 'no-referrer',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
        ]);
    }
}
