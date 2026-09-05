<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentReconciliationFinding;
use App\Support\BusinessClock;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final readonly class AdminPaymentOperationsReadService
{
    private const PROVIDER_METHODS = [
        Order::PAYMENT_METHOD_KASHIER,
        Order::PAYMENT_METHOD_GOOGLE_PLAY,
        Order::PAYMENT_METHOD_APP_STORE,
    ];

    private const FAILURE_STATUSES = [
        'UNPAID', 'FAILED', 'FAILURE', 'DECLINED', 'VOIDED',
    ];

    private const CREATED_STATUSES = ['NOT_FOUND', 'UNPAID', 'INITIATED'];

    /**
     * Provider states that may still settle without another learner action.
     * A local HPP deadline must not hide these attempts from operations.
     */
    private const CAPTURABLE_STATUSES = [
        'AUTHORIZED', 'PROCESSING', 'SUCCESS', 'CAPTURED', 'PAID',
    ];

    /** Same first-non-null precedence as KashierGatewayEvidenceService::status(). */
    private const EVIDENCE_STATUS_PATHS = [
        'payment_gateway_response->response->paymentStatus',
        'payment_gateway_response->response->payment_status',
        'payment_gateway_response->response->order->paymentStatus',
        'payment_gateway_response->response->order->payment_status',
        'payment_gateway_response->response->data->0->paymentStatus',
        'payment_gateway_response->response->data->0->payment_status',
        'payment_gateway_response->data->paymentStatus',
        'payment_gateway_response->data->payment_status',
        'payment_gateway_response->paymentStatus',
        'payment_gateway_response->payment_status',
        'payment_gateway_response->response->status',
        'payment_gateway_response->data->status',
        'payment_gateway_response->status',
    ];

    public function __construct(
        private PaymentChannelReportService $channels,
        private CourseFinancialLedgerReportService $financialLedger,
        private KashierGatewayEvidenceService $gatewayEvidence
    ) {
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function index(array $filters): array
    {
        $query = $this->ordersQuery();
        $this->applyFilters($query, $filters);
        $filteredScope = clone $query;

        $orders = $query
            ->latest('created_at')
            ->latest('id')
            ->paginate(25)
            ->withQueryString();
        $this->decorate($orders->getCollection());

        $paymentChannelReport = $this->channels->summary(null, null, clone $filteredScope);
        $rawStatusCounts = (clone $filteredScope)
            ->withoutEagerLoads()
            ->reorder()
            ->selectRaw('status, COUNT(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $paymentMethodOptions = collect($this->channelLabels());

        return [
            'orders' => $orders,
            'filters' => $filters,
            'stats' => [
                'total' => (int) $rawStatusCounts->sum(),
                'pending' => (int) ($rawStatusCounts[Order::STATUS_PENDING] ?? 0),
                'approved' => (int) ($rawStatusCounts[Order::STATUS_APPROVED] ?? 0),
                'rejected' => (int) ($rawStatusCounts[Order::STATUS_REJECTED] ?? 0),
                'cancelled' => (int) ($rawStatusCounts[Order::STATUS_CANCELLED] ?? 0),
                'total_amount' => $paymentChannelReport['egp']['confirmed_gross_amount'],
                'catalog_estimated_amount' => $paymentChannelReport['egp']['catalog_estimated_gross_amount'],
            ],
            'paymentMethodOptions' => $paymentMethodOptions,
            'paymentMethodLabels' => $paymentMethodOptions->all(),
            'paymentChannelReport' => $paymentChannelReport,
        ];
    }

    /** @return array<string, mixed> */
    public function show(Order $order): array
    {
        $order->load([
            'user', 'course', 'package', 'coupon', 'courseCode', 'approvedBy',
            'storePurchase', 'latestPaymentReconciliationFinding',
        ]);
        $this->decorate(collect([$order]));

        return [
            'order' => $order,
            'paymentMethodLabels' => $this->channelLabels(),
            'reconciliationFindingsCount' => PaymentReconciliationFinding::query()
                ->where('order_ref', $order->order_ref)
                ->count(),
        ];
    }

    public function packageOrders(Package $package): LengthAwarePaginator
    {
        $orders = $this->ordersQuery()
            ->where('package_id', $package->getKey())
            ->latest('created_at')
            ->latest('id')
            ->paginate(30, ['*'], 'orders_page')
            ->withQueryString();
        $this->decorate($orders->getCollection());

        return $orders;
    }

    /** Provider checkout attempts that still need operational follow-up. */
    public function openProviderCheckouts(): Builder
    {
        return Order::query()
            ->whereIn('payment_method', self::PROVIDER_METHODS)
            ->whereNotNull('package_id')
            ->where('status', Order::STATUS_PENDING)
            ->where(fn (Builder $open) => $this->whereOperationallyOpen($open));
    }

    /** @return array<string, string> */
    public function channelLabels(): array
    {
        return $this->channels->labels() + [
            Order::PAYMENT_METHOD_WALLET_COINS => 'عملات ركن',
            Order::PAYMENT_METHOD_COURSE_CODE => 'كود جهة تعليمية',
        ];
    }

    /** @param Collection<int, Order> $orders */
    private function decorate(Collection $orders): void
    {
        $this->financialLedger->attachAllocations($orders);
        foreach ($orders as $order) {
            $providerStatus = $order->payment_method === Order::PAYMENT_METHOD_KASHIER
                ? ($this->gatewayEvidence->status($order->payment_gateway_response)
                    ?? strtoupper(trim((string) ($order->latestPaymentReconciliationFinding?->provider_status ?? ''))))
                : strtoupper(trim((string) ($order->storePurchase?->status ?? '')));
            $providerStatus = $providerStatus !== '' ? $providerStatus : null;
            $state = $this->operationState($order, $providerStatus);

            $order->setAttribute('payment_operation_state', $state);
            $order->setAttribute('payment_operation_label', self::stateLabels()[$state]);
            $order->setAttribute('payment_operation_tone', self::stateTones()[$state]);
            $order->setAttribute('provider_evidence_status', $providerStatus);
            $order->setAttribute('provider_evidence_source', $this->evidenceSource($order));
        }
    }

    private function ordersQuery(): Builder
    {
        return Order::query()->with([
            'user', 'course', 'package', 'coupon', 'courseCode', 'approvedBy',
            'storePurchase', 'latestPaymentReconciliationFinding',
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): void
    {
        if (!empty($filters['state'])) {
            $this->applyOperationState($query, (string) $filters['state']);
        }
        if (!empty($filters['payment_method'])) {
            $query->where('payment_method', $filters['payment_method']);
        }
        if (!empty($filters['package_id'])) {
            $query->where('package_id', (int) $filters['package_id']);
        }
        if (!empty($filters['user_search'])) {
            $search = trim((string) $filters['user_search']);
            $query->whereHas('user', static function (Builder $user) use ($search): void {
                $escaped = '%'.addcslashes($search, '%_\\').'%';
                $user->where(function (Builder $match) use ($escaped): void {
                    $match->where('name', 'like', $escaped)
                        ->orWhere('email', 'like', $escaped)
                        ->orWhere('phone', 'like', $escaped);
                });
            });
        }
        if (!empty($filters['course_search'])) {
            $search = '%'.addcslashes(trim((string) $filters['course_search']), '%_\\').'%';
            $query->whereHas('course', static function (Builder $course) use ($search): void {
                $course->where(function (Builder $match) use ($search): void {
                    $match->where('name_ar', 'like', $search)
                        ->orWhere('name_en', 'like', $search);
                });
            });
        }
        if (!empty($filters['date_from'])) {
            [$from] = BusinessClock::localDayRangeUtc((string) $filters['date_from']);
            $query->where('orders.created_at', '>=', $from);
        }
        if (!empty($filters['date_to'])) {
            [, $toExclusive] = BusinessClock::localDayRangeUtc((string) $filters['date_to']);
            $query->where('orders.created_at', '<', $toExclusive);
        }
        if (isset($filters['amount_min'])) {
            $query->where('final_amount', '>=', (float) $filters['amount_min']);
        }
        if (isset($filters['amount_max'])) {
            $query->where('final_amount', '<=', (float) $filters['amount_max']);
        }
    }

    private function applyOperationState(Builder $query, string $state): void
    {
        if ($state === 'paid') {
            $query->financiallyEffective();
            return;
        }
        if ($state === 'expired') {
            $this->whereOperationallyExpired($query);
            return;
        }
        if ($state === 'created') {
            $query->where('status', Order::STATUS_PENDING)
                ->whereIn('payment_method', self::PROVIDER_METHODS)
                ->where(function (Builder $evidence): void {
                    $evidence->where(function (Builder $missing): void {
                        $missing->whereNull('payment_gateway_response')
                            ->whereDoesntHave(
                                'latestPaymentReconciliationFinding',
                                static fn (Builder $finding) => $finding->whereNotNull('provider_status')
                            );
                    })->orWhere(
                        fn (Builder $known) => $this->whereKnownProviderStatus($known, self::CREATED_STATUSES)
                    );
                })
                ->where(fn (Builder $open) => $this->whereOperationallyOpen($open));
            return;
        }
        if ($state === 'pending') {
            $query->where('status', Order::STATUS_PENDING)
                ->where(function (Builder $pending): void {
                    $pending->whereNotIn('payment_method', self::PROVIDER_METHODS)
                        ->orWhere(function (Builder $provider): void {
                            $provider->whereIn('payment_method', self::PROVIDER_METHODS)
                                ->where(function (Builder $evidence): void {
                                    $evidence->whereNotNull('payment_gateway_response')
                                        ->orWhereHas(
                                            'latestPaymentReconciliationFinding',
                                            static fn (Builder $finding) => $finding->whereNotNull('provider_status')
                                        );
                                })
                                ->where(fn (Builder $known) => $this->whereNoKnownProviderStatus($known, self::CREATED_STATUSES))
                                ->where(fn (Builder $open) => $this->whereOperationallyOpen($open));
                        });
                });
            return;
        }
        if ($state === 'failed') {
            $query->where(function (Builder $failed): void {
                $failed->where('status', Order::STATUS_REJECTED)
                    ->orWhere(function (Builder $providerFailure): void {
                        $providerFailure->where('status', Order::STATUS_CANCELLED)
                            ->where(fn (Builder $evidence) => $this->whereFailureEvidence($evidence));
                    });
            });
            return;
        }

        $query->where(function (Builder $closed): void {
            $closed->where(function (Builder $financiallyClosed): void {
                $financiallyClosed->where('status', Order::STATUS_APPROVED)
                    ->where(function (Builder $ineffective): void {
                        $ineffective->whereNull('financial_status')
                            ->orWhere('financial_status', '!=', Order::FINANCIAL_SETTLED)
                            ->orWhereNotNull('reversed_at');
                    });
            })->orWhere(function (Builder $cancelled): void {
                $cancelled->where('status', Order::STATUS_CANCELLED)
                    ->where(fn (Builder $evidence) => $this->whereNoKnownProviderStatus(
                        $evidence,
                        array_merge(['EXPIRED'], self::FAILURE_STATUSES)
                    ));
            });
        });
    }

    private function whereExpiredDeadline(Builder $query): void
    {
        $query->where(function (Builder $dated): void {
            $dated->whereNotNull('checkout_expires_at')
                ->where('checkout_expires_at', '<=', now());
        })->orWhere(function (Builder $legacy): void {
            $legacy->whereNull('checkout_expires_at')
                ->where('payment_method', Order::PAYMENT_METHOD_KASHIER)
                ->where('created_at', '<=', now()->subMinutes(Order::KASHIER_CHECKOUT_TTL_MINUTES));
        });
    }

    private function whereOperationallyExpired(Builder $query): void
    {
        $query->whereIn('payment_method', self::PROVIDER_METHODS)
            ->where(function (Builder $expired): void {
                $expired->where(function (Builder $providerExpired): void {
                    $providerExpired
                        ->whereIn('status', [Order::STATUS_PENDING, Order::STATUS_CANCELLED])
                        ->where(fn (Builder $provider) => $this->whereKnownProviderStatus(
                            $provider,
                            ['EXPIRED']
                        ));
                })->orWhere(function (Builder $localExpiry): void {
                    $localExpiry->where('status', Order::STATUS_PENDING)
                        ->where(fn (Builder $deadline) => $this->whereExpiredDeadline($deadline))
                        ->where(fn (Builder $provider) => $this->whereNoKnownProviderStatus(
                            $provider,
                            self::CAPTURABLE_STATUSES
                        ));
                });
            });
    }

    private function whereOperationallyOpen(Builder $query): void
    {
        $query->where(fn (Builder $provider) => $this->whereNoKnownProviderStatus(
            $provider,
            ['EXPIRED']
        ))->where(function (Builder $open): void {
            $open->whereNot(fn (Builder $deadline) => $this->whereExpiredDeadline($deadline))
                ->orWhere(fn (Builder $provider) => $this->whereKnownProviderStatus(
                    $provider,
                    self::CAPTURABLE_STATUSES
                ));
        });
    }

    private function whereFailureEvidence(Builder $query): void
    {
        $this->whereKnownProviderStatus($query, self::FAILURE_STATUSES);
    }

    /** @param list<string> $statuses */
    private function whereKnownProviderStatus(Builder $query, array $statuses): void
    {
        $this->whereProviderStatusMatches($query, $statuses, true);
    }

    /** @param list<string> $statuses */
    private function whereNoKnownProviderStatus(Builder $query, array $statuses): void
    {
        $this->whereProviderStatusMatches($query, $statuses, false);
    }

    /** @param list<string> $statuses */
    private function whereProviderStatusMatches(Builder $query, array $statuses, bool $matches): void
    {
        $statuses = array_values(array_unique(array_merge(
            $statuses,
            array_map('strtolower', $statuses)
        )));
        $query->where(function (Builder $status) use ($statuses, $matches): void {
            foreach (self::EVIDENCE_STATUS_PATHS as $index => $path) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $earlierPaths = array_slice(self::EVIDENCE_STATUS_PATHS, 0, $index);
                $status->{$method}(function (Builder $candidate) use (
                    $earlierPaths,
                    $path,
                    $statuses,
                    $matches
                ): void {
                    foreach ($earlierPaths as $earlierPath) {
                        $candidate->whereNull($earlierPath);
                    }
                    if ($matches) {
                        $candidate->whereIn($path, $statuses);
                    } else {
                        $candidate->whereNotNull($path)->whereNotIn($path, $statuses);
                    }
                });
            }
            $status->orWhere(function (Builder $fallback) use ($statuses, $matches): void {
                foreach (self::EVIDENCE_STATUS_PATHS as $path) {
                    $fallback->whereNull($path);
                }
                $relation = static fn (Builder $finding) => $finding->whereIn('provider_status', $statuses);
                if ($matches) {
                    $fallback->whereHas('latestPaymentReconciliationFinding', $relation);
                } else {
                    $fallback->whereDoesntHave('latestPaymentReconciliationFinding', $relation);
                }
            });
        });
    }

    private function operationState(Order $order, ?string $providerStatus): string
    {
        if ($order->isFinanciallyEffective()) return 'paid';
        if ($order->status === Order::STATUS_APPROVED) return 'cancelled';
        if (
            $order->requiresProviderVerification()
            && (
                (
                    $order->status === Order::STATUS_PENDING
                    && $order->isCheckoutExpired()
                    && !(
                        $providerStatus !== null
                        && in_array($providerStatus, self::CAPTURABLE_STATUSES, true)
                    )
                )
                || (
                    in_array($order->status, [Order::STATUS_PENDING, Order::STATUS_CANCELLED], true)
                    && $providerStatus === 'EXPIRED'
                )
            )
        ) return 'expired';
        if ($order->status === Order::STATUS_REJECTED) return 'failed';
        if (
            $order->status === Order::STATUS_CANCELLED
            && in_array($providerStatus, self::FAILURE_STATUSES, true)
        ) return 'failed';
        if ($order->status === Order::STATUS_CANCELLED) return 'cancelled';
        if (
            $order->status === Order::STATUS_PENDING
            && $order->requiresProviderVerification()
            && ($providerStatus === null || in_array($providerStatus, self::CREATED_STATUSES, true))
        ) return 'created';

        return 'pending';
    }

    private function evidenceSource(Order $order): ?string
    {
        if ($order->storePurchase) return $order->storePurchase->provider;
        $source = data_get($order->payment_gateway_response, 'verified_via')
            ?? data_get($order->payment_gateway_response, 'source');
        $source = trim((string) $source);

        if ($source === '' && $order->latestPaymentReconciliationFinding) {
            $source = (string) $order->latestPaymentReconciliationFinding->provider;
        }

        return $source !== '' ? $source : null;
    }

    /** @return array<string, string> */
    public static function stateLabels(): array
    {
        return [
            'created' => 'بدأت المحاولة',
            'pending' => 'قيد التأكيد',
            'paid' => 'مدفوع',
            'failed' => 'فشل الدفع',
            'expired' => 'انتهت المحاولة',
            'cancelled' => 'أُغلقت العملية',
        ];
    }

    /** @return array<string, string> */
    public static function stateTones(): array
    {
        return [
            'created' => 'info',
            'pending' => 'warning',
            'paid' => 'success',
            'failed' => 'danger',
            'expired' => 'muted',
            'cancelled' => 'muted',
        ];
    }
}
