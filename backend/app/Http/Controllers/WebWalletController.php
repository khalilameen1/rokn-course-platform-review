<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Package;
use App\Services\KashierCheckoutFlowService;
use App\Services\KashierNotificationFlowService;
use App\Services\PackageChannelPricingService;
use App\Services\ProductFeatureFlagService;
use App\Services\SocialAuthProviderRegistry;
use App\Services\WalletQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

final class WebWalletController extends Controller
{
    public function __construct(
        private readonly KashierCheckoutFlowService $checkout,
        private readonly PackageChannelPricingService $pricing,
        private readonly WalletQueryService $wallet,
        private readonly ProductFeatureFlagService $features
    ) {}

    public function index(Request $request, SocialAuthProviderRegistry $providers)
    {
        $student = $request->user('student');
        $discount = $this->pricing->directDiscountPercent();
        $packages = Package::query()->where('is_active', true)->where('direct_enabled', true)
            ->where('price', '>', 0)->where('coins', '>', 0)
            ->orderBy('sort_order')->orderBy('id')->get();
        $selected = $packages->firstWhere('id', $request->integer('package')) ?? $packages->first();
        $quote = $selected ? [
            'package_id' => (int) $selected->id,
            'expected_amount' => $this->pricing->directPrice($selected, $discount),
            'expected_coins' => (int) $selected->coins,
        ] : null;

        // Keep the same key after a lost redirect or browser Back. A new
        // package/price or a terminal receipt creates a fresh purchase intent.
        $intent = $request->session()->get('wallet.intent');
        if ($student && $quote && (!is_array($intent) || ($intent['quote'] ?? null) !== $quote)) {
            $intent = ['key' => 'web-'.Str::uuid(), 'quote' => $quote];
            $request->session()->put('wallet.intent', $intent);
        }
        $pending = $student ? Order::query()->where('user_id', $student->id)
            ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
            ->where('status', Order::STATUS_PENDING)->latest('id')->first() : null;

        return view('web-wallet.index', [
            'student' => $student,
            'balance' => $student ? $this->wallet->summary($student)['total_balance'] : null,
            'packages' => $packages,
            'selected' => $selected,
            'quote' => $quote,
            'intent' => $intent,
            'discount' => $discount,
            'pricing' => $this->pricing,
            'pending' => $pending,
            'canCheckout' => $student && $this->features->enabled('checkout', 'user:'.$student->id),
            'providers' => $providers->browserAvailable()->mapWithKeys(
                fn (string $provider): array => [$provider => $providers->labels()[$provider]]
            ),
        ]);
    }

    public function pay(Request $request): RedirectResponse
    {
        if (!$this->features->enabled('checkout', 'user:'.$request->user('student')->id)) {
            return redirect()->route('web-wallet.index')->with('error', 'الدفع غير متاح الآن حاول لاحقًا');
        }
        $request->validate([
            'expected_account' => 'required|integer',
            'package_id' => 'required|integer',
            'expected_amount' => 'required|numeric|min:0.01',
            'expected_coins' => 'required|integer|min:1',
            'idempotency_key' => 'required|string|min:16|max:140',
        ]);
        if ($request->integer('expected_account') !== (int) $request->user('student')->id) {
            return redirect()->route('web-wallet.index')->with('error', 'تغيّر الحساب راجع الباقة قبل الدفع');
        }

        return $this->checkoutRedirect($request, $this->checkout->initiate(
            $request,
            user: $request->user('student'),
            callbackUrl: route('web-wallet.callback')
        ));
    }

    public function receipt(Request $request, string $orderRef)
    {
        $order = $this->ownedOrder($request, $orderRef);
        $student = $request->user('student');
        if ($order->status !== Order::STATUS_PENDING
            && $request->session()->get('wallet.intent.key') === $order->checkout_request_key) {
            $request->session()->forget('wallet.intent');
        }
        return view('web-wallet.receipt', [
            'student' => $student,
            'balance' => $this->wallet->summary($student)['total_balance'],
            'order' => $order,
        ]);
    }

    public function status(Request $request, string $orderRef): JsonResponse
    {
        return $this->checkout->status($orderRef, user: $request->user('student'));
    }

    public function reconcile(Request $request, string $orderRef): JsonResponse|RedirectResponse
    {
        $this->ownedOrder($request, $orderRef);
        $result = $this->checkout->status($orderRef, true, $request->user('student'));
        if (data_get($result->getData(true), 'data.checkout_state') === 'pending_provider') {
            $request->session()->flash('wallet_provider_pending', $orderRef);
        }
        return $request->expectsJson() ? $result : redirect()->route('web-wallet.receipt', $orderRef);
    }

    public function resume(Request $request, string $orderRef): RedirectResponse
    {
        $order = $this->ownedOrder($request, $orderRef);
        // Reopen only the selected order's frozen terms, never today's price.
        $request->merge([
            'expected_account' => $request->user('student')->id,
            'package_id' => $order->package_id,
            'expected_amount' => $order->final_amount,
            'expected_coins' => $order->package_coins,
            'idempotency_key' => $order->checkout_request_key,
        ]);
        return $this->pay($request);
    }

    public function abandon(Request $request, string $orderRef): RedirectResponse
    {
        $order = $this->ownedOrder($request, $orderRef);
        $this->checkout->abandon($orderRef, $request->user('student'));
        $order->refresh();
        if ($order->status === Order::STATUS_CANCELLED || $order->status === Order::STATUS_REJECTED) {
            $request->session()->forget('wallet.intent');
            return redirect()->route('web-wallet.index');
        }
        return redirect()->route('web-wallet.receipt', $orderRef);
    }

    public function callback(Request $request, KashierNotificationFlowService $notifications): RedirectResponse
    {
        // Same authenticated gateway evidence and fulfillment as mobile.
        // The return page is different; the financial operation is not.
        $result = $notifications->callback($request)->getData();
        $ref = $result['order_ref'] ?? null;
        if ($request->user('student') && is_string($ref) && Order::query()
            ->where('user_id', $request->user('student')->id)->where('order_ref', $ref)->exists()) {
            return redirect()->route('web-wallet.receipt', $ref);
        }
        return redirect()->route('web-wallet.index')
            ->with('error', 'سجّل الدخول بنفس حسابك لمتابعة الرصيد وحالة الدفع');
    }

    private function ownedOrder(Request $request, string $orderRef): Order
    {
        return Order::query()->where('order_ref', $orderRef)
            ->where('user_id', $request->user('student')->id)
            ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)->firstOrFail();
    }

    private function checkoutRedirect(Request $request, JsonResponse $response): RedirectResponse
    {
        $result = $response->getData(true);
        $data = $result['data'] ?? [];
        if ($response->isSuccessful() && ($data['checkout_state'] ?? null) === 'created'
            && is_string($data['payment_url'] ?? null)) {
            return redirect()->away($data['payment_url'], 303);
        }
        if (is_string($data['order_ref'] ?? null)) {
            if (($data['checkout_state'] ?? null) === 'pending_provider') {
                $request->session()->flash('wallet_provider_pending', $data['order_ref']);
            }
            // An older pending order is presented for review, not silently
            // charged when the student chose a different package.
            return redirect()->route('web-wallet.receipt', $data['order_ref']);
        }
        return redirect()->route('web-wallet.index', ['package' => $request->integer('package_id')])
            ->with('error', $result['message'] ?? 'تعذّر بدء الدفع حاول مرة أخرى');
    }
}
