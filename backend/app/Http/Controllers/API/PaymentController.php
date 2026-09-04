<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Services\KashierCheckoutFlowService;
use App\Services\KashierNotificationFlowService;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PaymentController extends Controller
{
    public function initiate(Request $request, KashierCheckoutFlowService $checkout): JsonResponse
    {
        return $checkout->initiate($request);
    }

    public function callback(Request $request, KashierNotificationFlowService $notifications): View
    {
        return $notifications->callback($request);
    }

    public function webhook(Request $request, KashierNotificationFlowService $notifications): JsonResponse
    {
        return $notifications->webhook($request);
    }

    public function status(
        string $orderRef,
        KashierCheckoutFlowService $checkout
    ): JsonResponse {
        return $checkout->status($orderRef, false);
    }

    public function reconcile(
        string $orderRef,
        KashierCheckoutFlowService $checkout
    ): JsonResponse {
        return $checkout->status($orderRef, true);
    }

    public function abandon(
        string $orderRef,
        KashierCheckoutFlowService $checkout
    ): JsonResponse {
        return $checkout->abandon($orderRef);
    }
}
