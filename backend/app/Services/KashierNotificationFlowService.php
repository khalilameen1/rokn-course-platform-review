<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final readonly class KashierNotificationFlowService
{
    public function __construct(
        private KashierService $kashier,
        private KashierPaymentService $payments,
        private PaymentApiResponseService $responses,
        private KashierCallbackSignatureService $signatures
    ) {
    }

    public function callback(Request $request): View
    {
        [
            'params' => $params,
            'signature_valid' => $isValidSignature,
            'has_signature' => $hasSignatureCandidate,
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
        ] = $this->notification($request);

        if (!$this->payments->isValidOrderReference($orderRef)) {
            Log::warning('Kashier callback rejected: missing or invalid order reference');

            return view('payment.result', [
                'success' => false,
                'order_ref' => null,
                'message' => 'تعذّر التحقق من عملية الدفع',
            ]);
        }

        Log::info('Kashier callback received', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
            'has_signature' => $hasSignatureCandidate,
        ]);

        if (
            !$this->payments->isCaptureNotificationStatus($paymentStatus)
            && !$hasSignatureCandidate
        ) {
            return $this->handleUnsignedCallbackFailure(
                $params,
                $orderRef,
                $paymentStatus,
                $transactionId
            );
        }

        if (!$isValidSignature) {
            Log::warning('Kashier callback: invalid signature', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'تعذّر التحقق من عملية الدفع',
            ]);
        }

        Log::info('Kashier callback: signature validated', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
        ]);

        $order = Order::byOrderRef($orderRef)->with(['user', 'package'])->first();

        if (!$order) {
            Log::warning('Kashier callback: order not found', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return view('payment.result', [
                'success' => false,
                'order_ref' => $orderRef,
                'message' => 'عملية الدفع غير متاحة',
            ]);
        }

        return $this->callbackResponse($this->applySignedNotification(
            $order,
            $orderRef,
            $paymentStatus,
            $transactionId,
            $params,
            'callback'
        ));
    }

    public function webhook(Request $request): JsonResponse
    {
        [
            'params' => $params,
            'signature_valid' => $isValidSignature,
            'has_signature' => $hasSignatureCandidate,
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
        ] = $this->notification($request);

        if (!$this->payments->isValidOrderReference($orderRef)) {
            Log::warning('Kashier webhook rejected: missing or invalid order reference');

            return $this->responses->make(
                false,
                'Invalid payment reference',
                [],
                422,
                'invalid_payment_reference'
            );
        }

        Log::info('Kashier webhook received', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
            'transaction_id' => $transactionId,
            'has_signature' => $hasSignatureCandidate,
        ]);

        if (
            !$this->payments->isCaptureNotificationStatus($paymentStatus)
            && !$hasSignatureCandidate
        ) {
            Log::warning('Kashier webhook: unsigned failure notification', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return $this->responses->make(true, 'Unsigned failure ignored', [], 202);
        }

        if (!$isValidSignature) {
            Log::warning('Kashier webhook: invalid signature', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return $this->responses->make(false, 'Invalid signature', [], 403, 'invalid_signature');
        }

        Log::info('Kashier webhook: signature validated', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
        ]);

        $order = Order::byOrderRef($orderRef)->with(['user', 'package'])->first();

        if (!$order) {
            Log::warning('Kashier webhook: order not found', [
                'order_ref' => $orderRef,
                'payment_status' => $paymentStatus,
            ]);

            return $this->responses->make(false, 'Order not found', [], 404, 'order_not_found');
        }

        return $this->webhookResponse($this->applySignedNotification(
            $order,
            $orderRef,
            $paymentStatus,
            $transactionId,
            $params,
            'webhook'
        ));
    }

    /**
     * @param array<string, mixed> $params
     */
    private function handleUnsignedCallbackFailure(
        array $params,
        string $orderRef,
        string $paymentStatus,
        ?string $transactionId
    ): View {
        Log::warning('Kashier callback: unsigned failure redirect', [
            'order_ref' => $orderRef,
            'payment_status' => $paymentStatus,
        ]);

        $order = Order::byOrderRef($orderRef)->with(['user', 'package'])->first();

        if ($order && $order->isFinanciallyEffective()) {
            return view('payment.result', [
                'success' => true,
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'تمت معالجة الدفع من قبل',
            ]);
        }

        if ($paymentStatus === 'SERVERERROR' && $order && $order->status === Order::STATUS_PENDING) {
            $apiResponse = $this->payments->verifyOrderViaApi($orderRef);
            if ($this->payments->isOrderCaptured($apiResponse)) {
                try {
                    $transactionId = $this->payments->extractTransactionId($apiResponse) ?? $transactionId;
                    $order = $this->payments->fulfillOrder($order, $transactionId, array_merge($params, [
                        'verified_via' => 'kashier_api',
                        'kashier_api_response' => $apiResponse,
                    ]));

                    if ($this->payments->transactionIdConflicts($order, $transactionId)) {
                        return view('payment.result', [
                            'success' => false,
                            'order_ref' => $orderRef,
                            'message' => 'نراجع حالة الدفع الآن',
                        ]);
                    }

                    if (!$order->isFinanciallyEffective()) {
                        return view('payment.result', [
                            'success' => false,
                            'order_ref' => $orderRef,
                            'message' => "أغلقت صفحة الدفع\nنراجع المبلغ المدفوع الآن",
                        ]);
                    }

                    Log::info('Kashier callback: payment fulfilled via API after serverError redirect', [
                        'order_ref' => $orderRef,
                        'order_id' => $order->id,
                        'transaction_id' => $transactionId,
                    ]);

                    return view('payment.result', [
                        'success' => true,
                        'order_ref' => $orderRef,
                        'transaction_id' => $transactionId,
                        'package' => $order->package,
                        'coins_credited' => $this->payments->coinAmount($order),
                        'message' => 'تم الدفع بنجاح',
                    ]);
                } catch (\Exception $exception) {
                    Log::error('Kashier callback: API-confirmed payment fulfillment failed', [
                        'order_ref' => $orderRef,
                        'order_id' => $order->id,
                        'exception' => $exception::class,
                        'error_fingerprint' => hash('sha256', $exception->getMessage()),
                    ]);
                }
            }

            Log::info('Kashier callback: serverError redirect — order left pending for status polling', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
            ]);

            return view('payment.result', [
                'success' => false,
                'pending' => true,
                'order_ref' => $orderRef,
                'message' => "نعالج عملية الدفع الآن\nعد إلى التطبيق لمتابعة حالتها",
            ]);
        }

        if ($order && $order->status === Order::STATUS_PENDING) {
            Log::info('Kashier callback: unsigned failure left order unchanged', [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
            ]);
        } elseif ($order) {
            Log::info('Kashier callback: unsigned failure — order not in pending state, skipped', [
                'order_ref' => $orderRef,
                'order_status' => $order->status,
            ]);
        } else {
            Log::warning('Kashier callback: unsigned failure — order not found', [
                'order_ref' => $orderRef,
            ]);
        }

        return view('payment.result', [
            'success' => false,
            'order_ref' => $orderRef,
            'message' => 'لم تكتمل عملية الدفع',
        ]);
    }

    /**
     * Apply one signed provider observation. Callback and webhook differ only
     * in presentation; all financial transitions happen here.
     *
     * @param array<string, mixed> $params
     * @return array{state: string, order: Order, order_ref: string, transaction_id: ?string}
     */
    private function applySignedNotification(
        Order $order,
        string $orderRef,
        string $paymentStatus,
        ?string $transactionId,
        array $params,
        string $source
    ): array {
        if ($reversalType = $this->payments->financialReversalType($paymentStatus)) {
            $this->payments->recordFinancialReversal(
                $order,
                $reversalType,
                $paymentStatus,
                $transactionId,
                $params
            );

            return $this->notificationResult('reversal', $order->fresh(['user', 'package']), $orderRef, $transactionId);
        }

        $isCapture = $this->payments->isCaptureNotificationStatus($paymentStatus);
        if ($isCapture && $this->payments->flagApprovedTransactionConflict($order, $transactionId, $params)) {
            return $this->notificationResult('conflict', $order->fresh(['user', 'package']), $orderRef, $transactionId);
        }

        if ($order->isFinanciallyEffective()) {
            Log::info("Kashier {$source}: order already approved (idempotent)", [
                'order_ref' => $orderRef,
                'transaction_id' => $order->transaction_id,
            ]);

            return $this->notificationResult('settled', $order, $orderRef, $order->transaction_id);
        }

        if (!$isCapture) {
            if (!$this->payments->isProviderFailureStatus($paymentStatus)) {
                return $this->notificationResult('pending', $order, $orderRef, $transactionId);
            }

            $order = $this->payments->cancelPendingOrder($order, $params);
            if ($order->isFinanciallyEffective()) {
                return $this->notificationResult('settled', $order, $orderRef, $order->transaction_id);
            }

            Log::warning("Kashier {$source}: payment failed (signed)", [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'payment_status' => $paymentStatus,
                'transaction_id' => $transactionId,
            ]);

            return $this->notificationResult('failed', $order, $orderRef, $transactionId);
        }

        [$transactionId, $captureEvidence] = $this->payments->captureEvidenceWithTransactionId(
            $orderRef,
            $transactionId,
            $params
        );

        try {
            $order = $this->payments->fulfillOrder($order, $transactionId, $captureEvidence);

            if ($this->payments->transactionIdConflicts($order, $transactionId)) {
                return $this->notificationResult('conflict', $order, $orderRef, $transactionId);
            }
            if (!$order->isFinanciallyEffective()) {
                return $this->notificationResult('capture_review', $order, $orderRef, $transactionId);
            }

            Log::info("Kashier {$source}: payment fulfilled successfully", [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'user_id' => $order->user_id,
                'package_id' => $order->package_id,
                'coins_credited' => $this->payments->coinAmount($order),
                'wallet_total' => $order->user->wallet_coins,
            ]);

            return $this->notificationResult('paid', $order, $orderRef, $transactionId);
        } catch (\Exception $exception) {
            Log::error("Kashier {$source}: fulfillment failed after successful payment", [
                'order_ref' => $orderRef,
                'order_id' => $order->id,
                'transaction_id' => $transactionId,
                'user_id' => $order->user_id,
                'exception' => $exception::class,
                'error_fingerprint' => hash('sha256', $exception->getMessage()),
            ]);

            return $this->notificationResult('fulfillment_error', $order, $orderRef, $transactionId);
        }
    }

    /**
     * @return array{state: string, order: Order, order_ref: string, transaction_id: ?string}
     */
    private function notificationResult(
        string $state,
        Order $order,
        string $orderRef,
        ?string $transactionId
    ): array {
        return [
            'state' => $state,
            'order' => $order,
            'order_ref' => $orderRef,
            'transaction_id' => $transactionId,
        ];
    }

    /** @param array{state: string, order: Order, order_ref: string, transaction_id: ?string} $result */
    private function callbackResponse(array $result): View
    {
        $order = $result['order'];
        $base = ['order_ref' => $result['order_ref']];

        return match ($result['state']) {
            'paid' => view('payment.result', $base + [
                'success' => true,
                'transaction_id' => $result['transaction_id'],
                'package' => $order->package,
                'coins_credited' => $this->payments->coinAmount($order),
                'message' => 'تم الدفع بنجاح',
            ]),
            'settled' => view('payment.result', $base + [
                'success' => true,
                'transaction_id' => $order->transaction_id,
                'package' => $order->package,
                'message' => 'تمت معالجة الدفع من قبل',
            ]),
            'pending' => view('payment.result', $base + [
                'success' => false,
                'pending' => true,
                'message' => "نعالج عملية الدفع الآن\nعد إلى التطبيق لمتابعة حالتها",
            ]),
            'reversal' => view('payment.result', $base + [
                'success' => false,
                'message' => 'نراجع عملية رد المبلغ',
            ]),
            'conflict' => view('payment.result', $base + [
                'success' => false,
                'message' => 'نراجع حالة الدفع الآن',
            ]),
            'capture_review' => view('payment.result', $base + [
                'success' => false,
                'message' => "وصل الدفع\nنراجع إضافة العملات الآن",
            ]),
            'fulfillment_error' => view('payment.result', $base + [
                'success' => false,
                'message' => "وصل الدفع ولم تُضف العملات\nتواصل مع الدعم",
            ]),
            default => view('payment.result', $base + [
                'success' => false,
                'message' => 'لم تكتمل عملية الدفع',
            ]),
        };
    }

    /** @param array{state: string, order: Order, order_ref: string, transaction_id: ?string} $result */
    private function webhookResponse(array $result): JsonResponse
    {
        return match ($result['state']) {
            'paid' => $this->responses->make(true, 'Webhook processed successfully'),
            'settled' => $this->responses->make(true, 'Already processed'),
            'pending' => $this->responses->make(true, 'Payment state accepted for reconciliation', [], 202),
            'reversal' => $this->responses->make(true, 'Financial reversal queued for review'),
            'conflict' => $this->responses->make(true, 'Conflicting payment event queued for review'),
            'capture_review' => $this->responses->make(true, 'Payment capture queued for review'),
            'fulfillment_error' => $this->responses->make(false, 'Fulfillment error', [], 500, 'fulfillment_error'),
            default => $this->responses->make(true, 'Payment failure recorded'),
        };
    }

    /**
     * @return array{
     *   params: array<string, mixed>,
     *   signature_valid: bool,
     *   has_signature: bool,
     *   order_ref: mixed,
     *   payment_status: string,
     *   transaction_id: ?string
     * }
     */
    private function notification(Request $request): array
    {
        $params = $request->all();
        $isValidSignature = $this->signatures->validate(
            $this->signatureHeaders($request),
            $request->getContent(),
            $this->kashier,
            $params
        );

        return [
            'params' => $params,
            'signature_valid' => $isValidSignature,
            'has_signature' => $this->hasSignatureCandidate($request, $params),
            'order_ref' => $this->firstScalar($params, [
                'merchantOrderId',
                'merchant_order_id',
                'order_ref',
                'data.merchantOrderId',
                'data.merchant_order_id',
                'data.order_ref',
                'response.merchantOrderId',
                'response.merchant_order_id',
                'response.order_ref',
            ]),
            'payment_status' => strtoupper(trim((string) (
                $this->firstScalar($params, [
                    'paymentStatus',
                    'payment_status',
                    'status',
                    'data.paymentStatus',
                    'data.payment_status',
                    'data.status',
                    'response.paymentStatus',
                    'response.payment_status',
                    'response.status',
                ]) ?? 'UNKNOWN'
            ))),
            'transaction_id' => $this->payments->normalizeTransactionId(
                $this->firstScalar($params, [
                    'transactionId',
                    'transaction_id',
                    'data.transactionId',
                    'data.transaction_id',
                    'response.transactionId',
                    'response.transaction_id',
                ])
            ),
        ];
    }

    /** @param array<string, mixed> $payload @param list<string> $paths */
    private function firstScalar(array $payload, array $paths): int|float|string|null
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);
            if (is_int($value) || is_float($value) || is_string($value)) {
                return $value;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $params */
    private function hasSignatureCandidate(Request $request, array $params): bool
    {
        foreach ([
            $request->header('x-kashier-signature'),
            $request->header('kashier-signature'),
            $request->header('signature'),
            $params['kashierSignature'] ?? null,
            $params['signature'] ?? null,
            $params['hash'] ?? null,
            data_get($params, 'data.kashierSignature'),
            data_get($params, 'data.signature'),
            data_get($params, 'data.hash'),
        ] as $candidate) {
            if (is_scalar($candidate) && trim((string) $candidate) !== '') {
                return true;
            }
        }

        return false;
    }

    /** @return list<mixed> */
    private function signatureHeaders(Request $request): array
    {
        return [
            $request->header('x-kashier-signature'),
            $request->header('kashier-signature'),
            $request->header('signature'),
        ];
    }
}
