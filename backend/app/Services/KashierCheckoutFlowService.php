<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

final readonly class KashierCheckoutFlowService
{
    public function __construct(
        private KashierService $kashier,
        private KashierPaymentService $payments,
        private PaymentApiResponseService $responses,
        private KashierConfigurationService $configuration
    ) {
    }

    public function initiate(Request $request, bool $allowPendingRecovery = true): JsonResponse
    {
        $bodyHasKey = array_key_exists('idempotency_key', $request->all());
        $headerHasKey = $request->hasHeader('Idempotency-Key');
        $bodyKey = $bodyHasKey ? $request->input('idempotency_key') : null;
        $headerKey = $headerHasKey ? $request->header('Idempotency-Key') : null;

        if (
            $bodyHasKey
            && $headerHasKey
            && (
                !is_string($bodyKey)
                || !is_string($headerKey)
                || !hash_equals($bodyKey, $headerKey)
            )
        ) {
            return $this->responses->make(
                false,
                "تغيّر طلب الدفع أثناء التنفيذ\nأعد المحاولة",
                [],
                422,
                'checkout_idempotency_mismatch',
                [
                    'idempotency_key' => [
                        'أعد محاولة الدفع',
                    ],
                ]
            );
        }

        if (!$bodyHasKey && $headerHasKey) {
            $request->merge(['idempotency_key' => $headerKey]);
        }

        try {
            $validated = $request->validate([
                'package_id' => 'required|integer|exists:packages,id',
                'expected_amount' => 'nullable|numeric|min:0.01|max:100000000',
                'expected_coins' => 'nullable|integer|min:1|max:1000000000',
                'idempotency_key' => [
                    'nullable',
                    'string',
                    'min:16',
                    'max:140',
                    'regex:/\A[A-Za-z0-9][A-Za-z0-9._:-]{15,139}\z/D',
                ],
            ]);
        } catch (ValidationException $exception) {
            return $this->responses->make(
                false,
                'راجع بيانات الدفع',
                [],
                422,
                'validation_error',
                $exception->errors()
            );
        }

        /** @var User $user */
        $user = auth('api')->user();
        $package = Package::findOrFail($request->package_id);

        try {
            $this->configuration->get();
        } catch (\RuntimeException $exception) {
            Log::critical('Kashier payment initiation blocked by configuration', [
                'exception' => $exception::class,
            ]);

            return $this->responses->make(
                false,
                "الدفع غير متاح الآن\nحاول لاحقًا",
                [],
                503,
                'payment_configuration_unavailable'
            );
        }

        Log::info('Kashier payment initiation started', [
            'user_id' => $user->id,
            'package_id' => $package->id,
            'amount' => $package->price,
        ]);

        $clientRequestKey = (string) ($validated['idempotency_key'] ?? '');
        $orderRef = null;
        try {
            $checkout = $this->payments->beginCheckout(
                $user,
                $package,
                $clientRequestKey,
                isset($validated['expected_amount']) ? (float) $validated['expected_amount'] : null,
                isset($validated['expected_coins']) ? (int) $validated['expected_coins'] : null
            );

            /** @var Order $order */
            $order = $checkout['order'];
            $orderRef = (string) $order->order_ref;

            if ($checkout['closed'] !== null) {
                if ($checkout['closed'] === 'expired' && $order->status === Order::STATUS_PENDING) {
                    $order = $this->reconcileProviderOrder($order);
                    if ($order->status === Order::STATUS_PENDING) {
                        return $this->pendingCheckoutResponse($order);
                    }
                }
                if ($order->isFinanciallyEffective()) {
                    return $this->completedCheckoutResponse($order);
                }
                $expired = $checkout['closed'] === 'expired'
                    && $order->status !== Order::STATUS_APPROVED;

                return $this->responses->make(
                    false,
                    $expired
                        ? "انتهت محاولة الدفع\nابدأ محاولة جديدة"
                        : "أغلقت محاولة الدفع\nابدأ محاولة جديدة",
                    [
                        'order_ref' => $orderRef,
                        'status' => $order->status,
                    ],
                    409,
                    $expired ? 'checkout_attempt_expired' : 'checkout_attempt_closed'
                );
            }

            Log::info('Kashier order created', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'user_id' => $user->id,
                'package_id' => $package->id,
                'amount' => $package->price,
                'is_premium_user' => $order->is_premium_user,
                'idempotent_replay' => $checkout['reused'],
            ]);
        } catch (\UnexpectedValueException $exception) {
            $pendingCheckout = $exception->getMessage() === 'A previous payment is still pending confirmation.';
            $packageUnavailable = $exception->getMessage()
                === 'This package is not available for checkout.';
            $packageTermsChanged = in_array($exception->getMessage(), [
                'Package terms changed before checkout.',
                'Checkout idempotency key was replayed with different package terms.',
            ], true);
            $pendingOrder = $pendingCheckout
                ? Order::query()
                    ->where('user_id', $user->id)
                    ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                    ->where('status', Order::STATUS_PENDING)
                    ->with('package')
                    ->latest('id')
                    ->first()
                : null;
            if ($pendingOrder) {
                $pendingOrder = $this->reconcileProviderOrder(
                    $pendingOrder,
                    (int) $package->id
                );
                if ($pendingOrder->isFinanciallyEffective()) {
                    return $this->completedCheckoutResponse($pendingOrder);
                }
                if ($pendingOrder->status === Order::STATUS_APPROVED) {
                    return $this->responses->make(
                        false,
                        'نراجع عملية الدفع السابقة',
                        [
                            'order_ref' => (string) $pendingOrder->order_ref,
                            'order_status' => (string) $pendingOrder->status,
                            'checkout_state' => 'payment_under_review',
                            'financial_status' => (string) $pendingOrder->financial_status,
                        ],
                        409,
                        'payment_under_review'
                    );
                }
                if ($pendingOrder->status !== Order::STATUS_PENDING && $allowPendingRecovery) {
                    return $this->initiate($request, false);
                }
                if ($pendingOrder->status === Order::STATUS_PENDING) {
                    return $this->pendingCheckoutResponse($pendingOrder);
                }
            }
            return $this->responses->make(
                false,
                $pendingCheckout
                    ? 'لديك عملية دفع قيد التأكيد'
                    : ($packageUnavailable
                        ? 'الباقة غير متاحة الآن'
                        : ($packageTermsChanged
                            ? "تغيّرت تفاصيل الباقة\nراجعها قبل الدفع"
                            : "تغيّر طلب الدفع أثناء التنفيذ\nأعد المحاولة")),
                [],
                409,
                $pendingCheckout
                    ? 'pending_checkout_exists'
                    : ($packageUnavailable
                        ? 'package_not_available'
                        : ($packageTermsChanged
                            ? 'package_terms_changed'
                            : 'checkout_idempotency_conflict'))
            );
        } catch (\Throwable $exception) {
            Log::error('Kashier order creation failed', [
                'user_id' => $user->id,
                'package_id' => $package->id,
                'order_ref' => $orderRef,
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);

            return $this->responses->make(false, 'تعذّر بدء الدفع', [], 500);
        }

        try {
            $hppUrl = $this->kashier->getHppUrl(
                $orderRef,
                number_format((float) $order->final_amount, 2, '.', ''),
                'EGP',
                route('payment.callback')
            );
        } catch (\Throwable $exception) {
            report($exception);

            // No hosted-payment URL was handed to the learner, so this local
            // intent cannot be chargeable. Closing it here prevents a gateway
            // configuration/network error from becoming a phantom pending
            // checkout that blocks every later attempt.
            try {
                $order = $this->payments->cancelPendingOrder($order, [
                    'verified_via' => 'hpp_url_generation_failed',
                    'failure_class' => $exception::class,
                ]);
            } catch (\Throwable $cancellationException) {
                report($cancellationException);
            }

            return $this->responses->make(
                false,
                "الدفع غير متاح الآن\nحاول بعد لحظات",
                [],
                503,
                'checkout_temporarily_unavailable'
            );
        }

        Log::info('Kashier HPP URL generated', [
            'order_ref' => $orderRef,
            'user_id' => $user->id,
        ]);

        return $this->responses->make(true, 'تم تجهيز صفحة الدفع', [
            'checkout_state' => 'created',
            'payment_url' => $hppUrl,
            'order_ref' => $orderRef,
            'idempotency_key' => $order->checkout_request_key,
            'checkout_expires_at' => $order->checkout_expires_at?->toIso8601String(),
            'amount' => $order->final_amount,
            'package' => [
                'id' => $package->id,
                'name_ar' => $package->name_ar,
                'name_en' => $package->name_en,
                'coins' => $this->payments->coinAmount($order),
            ],
        ]);
    }

    public function status(
        string $orderRef,
        bool $reconcile = false
    ): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $order = Order::byOrderRef($orderRef)
            ->where('user_id', $user->id)
            ->with('package')
            ->first();

        if (!$order) {
            Log::info('Kashier status poll: order not found', [
                'order_ref' => $orderRef,
                'user_id' => $user->id,
            ]);

            return $this->responses->make(
                false,
                'عملية الدفع غير متاحة',
                [],
                404,
                'order_not_found'
            );
        }

        if ($reconcile && $order->status === Order::STATUS_PENDING) {
            $order = $this->reconcileProviderOrder($order);
        }

        Log::info('Kashier status poll', [
            'order_ref' => $orderRef,
            'order_id' => $order->id,
            'user_id' => $user->id,
            'status' => $order->status,
            'reconciliation_requested' => $reconcile,
        ]);

        return $this->responses->make(true, 'تم تحميل حالة الدفع', [
            'order_ref' => $order->order_ref,
            'status' => $order->status,
            'checkout_state' => $this->checkoutState($order),
            'financial_status' => $order->financial_status,
            'reversed_at' => $order->reversed_at?->toIso8601String(),
            'transaction_id' => $order->transaction_id,
            'amount' => $order->final_amount,
            'checkout_expires_at' => $order->checkout_expires_at?->toIso8601String(),
            'package' => $order->package ? [
                'id' => $order->package->id,
                'name_ar' => $order->package->name_ar,
                'name_en' => $order->package->name_en,
                'coins' => $this->payments->coinAmount($order),
            ] : null,
            'approved_at' => $order->approved_at,
        ]);
    }

    public function abandon(string $orderRef): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();
        $order = Order::byOrderRef($orderRef)
            ->where('user_id', $user->id)
            ->with('package')
            ->first();

        if (!$order) {
            return $this->responses->make(
                false,
                'عملية الدفع غير متاحة',
                [],
                404,
                'order_not_found'
            );
        }

        if ($order->status !== Order::STATUS_PENDING) {
            return $this->responses->make(true, 'تم تحميل حالة الدفع', [
                'order_ref' => (string) $order->order_ref,
                'status' => (string) $order->status,
                'financial_status' => (string) $order->financial_status,
                'coins_added' => $order->isFinanciallyEffective()
                    ? $this->payments->coinAmount($order)
                    : 0,
            ]);
        }

        // Closing a browser surface is not payment evidence. Ask Kashier
        // before releasing the local intent so a capture racing with the
        // learner's back gesture is never lost or turned into a second debit.
        $provider = $this->payments->verifyOrderViaApi((string) $order->order_ref);
        $runtimeState = null;
        if ($this->payments->isOrderCaptured($provider)) {
            $order = $this->payments->fulfillOrder(
                $order,
                $this->payments->extractTransactionId($provider),
                [
                    'verified_via' => 'kashier_api_checkout_abandon',
                    'kashier_api_response' => $provider,
                ]
            );
        } elseif ($provider !== null) {
            $providerStatus = $this->payments->providerOrderStatus($provider);
            if ($reversalType = $this->payments->financialReversalType((string) $providerStatus)) {
                $this->payments->recordFinancialReversal(
                    $order,
                    $reversalType,
                    (string) $providerStatus,
                    $this->payments->extractTransactionId($provider),
                    $provider
                );
                $order = $order->fresh(['package']);
            } elseif (!$this->payments->providerStatusMayCaptureWithoutLearner($providerStatus)) {
                $order = $this->payments->cancelPendingOrder($order, [
                    'verified_via' => 'kashier_api_checkout_abandon',
                    'provider_status' => $providerStatus,
                    'kashier_api_response' => $provider,
                ]);
            } else {
                $runtimeState = 'pending_provider';
            }
        } else {
            $runtimeState = 'pending_provider';
        }

        $order = $order->fresh(['package']);
        if ($order->status === Order::STATUS_PENDING && $runtimeState !== null) {
            $order = $this->withCheckoutState($order, $runtimeState);
        }

        return $this->responses->make(true, 'تم تحميل حالة الدفع', [
            'order_ref' => (string) $order->order_ref,
            'status' => (string) $order->status,
            'checkout_state' => $this->checkoutState($order),
            'financial_status' => (string) $order->financial_status,
            'coins_added' => $order->isFinanciallyEffective()
                ? $this->payments->coinAmount($order)
                : 0,
        ]);
    }

    private function reconcileProviderOrder(
        Order $order,
        ?int $replacementPackageId = null
    ): Order
    {
        $apiResponse = $this->payments->verifyOrderViaApi((string) $order->order_ref);
        if ($this->payments->isOrderCaptured($apiResponse)) {
            try {
                return $this->payments->fulfillOrder(
                    $order,
                    $this->payments->extractTransactionId($apiResponse),
                    [
                        'verified_via' => 'kashier_api_status_poll',
                        'kashier_api_response' => $apiResponse,
                    ]
                );
            } catch (\Throwable $exception) {
                Log::error('Kashier status reconciliation failed', [
                    'order_ref' => $order->order_ref,
                    'order_id' => $order->id,
                    'user_id' => $order->user_id,
                    'exception' => $exception::class,
                    'error_fingerprint' => hash('sha256', $exception->getMessage()),
                ]);

                return $order->fresh(['package']);
            }
        }

        $providerStatus = $this->payments->providerOrderStatus($apiResponse);
        if ($reversalType = $this->payments->financialReversalType((string) $providerStatus)) {
            $this->payments->recordFinancialReversal(
                $order,
                $reversalType,
                (string) $providerStatus,
                $this->payments->extractTransactionId($apiResponse),
                $apiResponse ?? []
            );

            return $order->fresh(['package']);
        }
        if ($order->isCheckoutExpired() && $apiResponse === null) {
            // The local checkout window is the ownership lease for an HPP
            // intent. Once it expires, a provider outage must not turn that
            // lease into a permanent account-wide checkout lock. A later
            // authenticated capture can still settle this cancelled row
            // exactly once through fulfillOrder().
            return $this->payments->cancelPendingOrder($order, [
                'verified_via' => 'local_checkout_expiry',
                'provider_lookup' => 'unavailable',
            ]);
        }
        if ($providerStatus === 'NOT_FOUND' && $replacementPackageId !== null) {
            // A new idempotency key is a new learner intent. Once Kashier
            // proves the older reference does not exist, that local row must
            // not block a fresh attempt even when both target one package.
            return $this->payments->cancelPendingOrder($order, [
                'verified_via' => 'kashier_api_checkout_replacement',
                'provider_status' => $providerStatus,
                'replaced_by_package_id' => $replacementPackageId,
            ]);
        }
        if (
            $providerStatus === 'NOT_FOUND'
            && !$order->isCheckoutExpired()
        ) {
            // Kashier may not create its order until the hosted page is
            // opened. Absence during an active local checkout is therefore
            // not payment failure and must not release a second payable
            // attempt. Expiry or an explicit abandon remains authoritative.
            return $this->withCheckoutState($order->fresh(['package']), 'checkout_opened');
        }
        if ($this->payments->isProviderFailureStatus($providerStatus)) {
            return $this->payments->cancelPendingOrder($order, $apiResponse);
        }

        if (
            $order->isCheckoutExpired()
            && !$this->payments->providerStatusMayCaptureWithoutLearner($providerStatus)
        ) {
            // Local expiry only releases an old intent after Kashier proves it
            // is not authorized/processing. This keeps a stale PENDING link
            // from blocking a retry without opening two chargeable attempts.
            return $this->payments->cancelPendingOrder($order, $apiResponse);
        }

        return $this->withCheckoutState(
            $order->fresh(['package']),
            $this->payments->providerStatusMayCaptureWithoutLearner($providerStatus)
                ? 'pending_provider'
                : 'checkout_opened'
        );
    }

    private function pendingCheckoutResponse(Order $order): JsonResponse
    {
        $paymentUrl = null;
        if ($order->financial_status !== Order::FINANCIAL_REVIEW_REQUIRED) {
            try {
                $paymentUrl = $this->kashier->getHppUrl(
                    (string) $order->order_ref,
                    number_format((float) $order->final_amount, 2, '.', ''),
                    'EGP',
                    route('payment.callback')
                );
            } catch (\Throwable $exception) {
                report($exception);
            }
        }

        return $this->responses->make(
            false,
            'لديك محاولة دفع مفتوحة',
            [
                'order_ref' => (string) $order->order_ref,
                'status' => (string) $order->status,
                'checkout_state' => $this->checkoutState($order),
                'payment_url' => $paymentUrl,
                'checkout_expires_at' => $order->checkout_expires_at?->toIso8601String(),
                'amount' => $order->final_amount,
                'package' => [
                    'id' => (int) $order->package_id,
                    'coins' => $this->payments->coinAmount($order),
                ],
            ],
            409,
            'pending_checkout_exists'
        );
    }

    private function completedCheckoutResponse(Order $order): JsonResponse
    {
        $order->loadMissing('package');

        return $this->responses->make(true, 'تمت معالجة عملية الدفع', [
            'order_ref' => (string) $order->order_ref,
            'order_status' => (string) $order->status,
            'checkout_state' => 'paid',
            'financial_status' => (string) $order->financial_status,
            'transaction_id' => $order->transaction_id,
            'coins_added' => $this->payments->coinAmount($order),
            'package' => $order->package ? [
                'id' => (int) $order->package->id,
                'name_ar' => (string) $order->package->name_ar,
                'name_en' => (string) $order->package->name_en,
                'coins' => $this->payments->coinAmount($order),
            ] : null,
        ]);
    }

    private function checkoutState(Order $order): string
    {
        if ($order->isFinanciallyEffective()) return 'paid';
        if (in_array($order->status, [Order::STATUS_CANCELLED, Order::STATUS_REJECTED], true)) {
            return 'cancelled';
        }
        if (
            $order->status === Order::STATUS_PENDING
            && $order->getAttribute('checkout_runtime_state')
        ) {
            return (string) $order->getAttribute('checkout_runtime_state');
        }
        if ($order->isCheckoutExpired()) return 'expired';

        return $order->status === Order::STATUS_PENDING
            ? 'checkout_opened'
            : 'cancelled';
    }

    private function withCheckoutState(Order $order, string $state): Order
    {
        $order->setAttribute('checkout_runtime_state', $state);

        return $order;
    }
}
