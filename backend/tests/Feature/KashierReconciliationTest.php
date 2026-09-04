<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\PaymentReconciliationCheckpoint;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use App\Services\KashierPaymentService;
use App\Services\KashierReconciliationService;
use App\Services\StudentNotificationService;
use Illuminate\Console\Command;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KashierReconciliationTest extends TestCase
{
    public function test_reconciliation_uses_the_payment_transaction_not_the_3ds_check(): void
    {
        $response = [
            'response' => [
                'status' => 'CAPTURED',
                'transactions' => [
                    [
                        'status' => 'SUCCESS',
                        'operation' => '3dsecure_verify',
                        'transactionId' => 'TX-3DS-CHECK',
                    ],
                    [
                        'status' => 'SUCCESS',
                        'operation' => 'pay',
                        'transactionId' => 'TX-ACTUAL-PAYMENT',
                    ],
                ],
            ],
        ];

        self::assertSame(
            'TX-ACTUAL-PAYMENT',
            app(KashierPaymentService::class)->extractTransactionId($response)
        );
    }

    use RefreshDatabase;

    private User $user;

    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'kashier.mode' => 'test',
            'kashier.test.api_key' => 'test-api-key-not-a-secret',
            'kashier.test.secret_key' => 'test-dashboard-key-not-a-secret',
            'kashier.test.mid' => 'MID-TEST-000',
        ]);
        $this->user = User::query()->forceCreate([
            'name' => 'Reconciliation Student',
            'name_ar' => 'طالب التسوية',
            'name_en' => 'Reconciliation Student',
            'email' => 'reconciliation@rokn.test',
            'phone' => '01000000001',
            'role' => 'client',
            'active' => true,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
        $this->package = Package::query()->create([
            'name_ar' => 'باقة الاختبار',
            'name_en' => 'Test package',
            'price' => 150,
            'coins' => 500,
        ]);
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->andReturnNull()->byDefault();
        });
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_scheduled_reconciliation_fulfils_a_verified_capture_once(): void
    {
        $order = $this->pendingOrder('PKG-RECON-CAPTURE');
        Http::fake($this->providerResponse($order, 'CAPTURED', 'TXN-RECON-CAPTURE'));

        $first = app(KashierReconciliationService::class)->reconcile(100);
        $second = app(KashierReconciliationService::class)->reconcile(100, true);

        self::assertSame(1, $first['fulfilled']);
        self::assertSame(1, $second['consistent']);
        Http::assertSent(static fn ($request): bool =>
            $request->hasHeader(
                'Authorization',
                'test-dashboard-key-not-a-secret'
            )
        );
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'transaction_id' => 'TXN-RECON-CAPTURE',
        ]);
        self::assertSame(500, (int) $this->user->fresh()->wallet_purchased_coins);
        self::assertSame(1, DB::table('wallet_transactions')->where('category', 'package_purchase')->count());
        self::assertSame(0, PaymentReconciliationFinding::query()->count());
        self::assertSame($order->id, PaymentReconciliationCheckpoint::query()->value('cursor_order_id'));
    }

    public function test_expired_checkout_missing_at_provider_is_closed_without_credit(): void
    {
        $order = $this->pendingOrder('PKG-RECON-ABANDONED');
        DB::table('orders')->where('id', $order->id)->update([
            'checkout_expires_at' => now()->subMinute(),
        ]);
        $order->refresh();
        Http::fake([
            'https://test-api.kashier.io/*' => Http::response([], 404),
        ]);

        $result = app(KashierReconciliationService::class)->reconcile(100);

        self::assertSame(1, $result['consistent']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_CANCELLED,
            'financial_status' => Order::FINANCIAL_CANCELLED,
            'transaction_id' => null,
        ]);
        self::assertSame(0, (int) $this->user->fresh()->wallet_coins);
        self::assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_active_checkout_missing_at_provider_remains_open_without_credit(): void
    {
        $order = $this->pendingOrder('PKG-RECON-NOT-OPENED');
        Http::fake([
            'https://test-api.kashier.io/*' => Http::response([], 404),
        ]);

        $result = app(KashierReconciliationService::class)->reconcile(100);

        self::assertSame(1, $result['consistent']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
            'transaction_id' => null,
        ]);
        self::assertSame(0, (int) $this->user->fresh()->wallet_coins);
        self::assertSame(0, DB::table('wallet_transactions')->count());
        self::assertSame(0, PaymentReconciliationFinding::query()->count());
    }

    public function test_provider_history_expiry_does_not_quarantine_a_settled_order(): void
    {
        $order = $this->pendingOrder('PKG-RECON-SETTLED-NOT-FOUND');
        app(KashierPaymentService::class)->fulfillOrder(
            $order,
            'TXN-RECON-SETTLED-NOT-FOUND',
            $this->providerPayload($order, 'CAPTURED', 'TXN-RECON-SETTLED-NOT-FOUND')
        );
        Http::fake([
            'https://test-api.kashier.io/*' => Http::response([], 404),
        ]);

        $result = app(KashierReconciliationService::class)->reconcile(100);

        self::assertSame(1, $result['findings']);
        $settled = $order->fresh();
        self::assertTrue($settled->isFinanciallyEffective());
        self::assertSame('TXN-RECON-SETTLED-NOT-FOUND', $settled->transaction_id);
        self::assertSame(500, (int) $this->user->fresh()->wallet_purchased_coins);
        $this->assertDatabaseHas('payment_reconciliation_findings', [
            'order_id' => $order->id,
            'kind' => 'provider_missing_local_order',
            'state' => PaymentReconciliationFinding::STATE_OPEN,
        ]);
    }

    public function test_reconciliation_uses_payment_status_before_the_success_envelope(): void
    {
        $order = $this->pendingOrder('PKG-RECON-UNPAID');
        Http::fake([
            'https://test-api.kashier.io/*' => Http::response([
                'response' => [
                    'status' => 'SUCCESS',
                    'paymentStatus' => 'unpaid',
                    'merchantOrderId' => $order->order_ref,
                ],
            ]),
        ]);

        $result = app(KashierReconciliationService::class)->reconcile(100);

        self::assertSame(1, $result['consistent']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_CANCELLED,
            'financial_status' => Order::FINANCIAL_CANCELLED,
            'transaction_id' => null,
        ]);
        self::assertSame(0, (int) $this->user->fresh()->wallet_coins);
        self::assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_provider_pending_checkout_remains_open_after_local_expiry(): void
    {
        $order = $this->pendingOrder('PKG-RECON-PROVIDER-PENDING');
        DB::table('orders')->where('id', $order->id)->update([
            'checkout_expires_at' => now()->subMinute(),
        ]);
        $order->refresh();
        Http::fake($this->providerResponse($order, 'PENDING', 'TXN-PENDING'));

        $result = app(KashierReconciliationService::class)->reconcile(100);

        self::assertSame(1, $result['findings']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
        ]);
        $this->assertDatabaseHas('payment_reconciliation_findings', [
            'order_id' => $order->id,
            'kind' => 'provider_pending_after_local_expiry',
            'state' => PaymentReconciliationFinding::STATE_OPEN,
        ]);
        self::assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_provider_reversal_recovers_paid_coins_idempotently(): void
    {
        $order = $this->pendingOrder('PKG-RECON-REFUND');
        Http::fake([
            'https://test-api.kashier.io/*' => Http::sequence()
                ->push($this->providerPayload($order, 'CAPTURED', 'TXN-RECON-REFUND'))
                ->push($this->providerPayload($order, 'REFUNDED', 'TXN-RECON-REFUND'))
                ->push($this->providerPayload($order, 'REFUNDED', 'TXN-RECON-REFUND')),
        ]);
        app(KashierReconciliationService::class)->reconcile(100);

        $first = app(KashierReconciliationService::class)->reconcile(100, true);
        $second = app(KashierReconciliationService::class)->reconcile(100, true);

        self::assertSame(1, $first['reversed'], json_encode($first, JSON_THROW_ON_ERROR));
        self::assertSame(1, $second['reversed'], json_encode($second, JSON_THROW_ON_ERROR));
        self::assertSame(1, $first['findings']);
        self::assertSame(1, $second['findings']);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED,
            'recovered_coins' => 500,
            'unrecovered_coins' => 0,
        ]);
        self::assertSame(0, (int) $this->user->fresh()->wallet_purchased_coins);
        self::assertSame(1, DB::table('wallet_transactions')->where('category', 'package_reversal')->count());
        self::assertSame(
            1,
            DB::table('order_financial_events')
                ->where('order_id', $order->id)
                ->where('event_type', Order::FINANCIAL_REFUNDED)
                ->count()
        );
        $this->assertDatabaseHas('payment_reconciliation_findings', [
            'order_id' => $order->id,
            'kind' => 'provider_reversal_requires_review',
            'state' => PaymentReconciliationFinding::STATE_OPEN,
            'attempts' => 2,
        ]);
    }

    public function test_captured_amount_mismatch_is_quarantined_and_deduplicated(): void
    {
        $order = $this->pendingOrder('PKG-RECON-MISMATCH');
        Http::fake($this->providerResponse($order, 'CAPTURED', 'TXN-RECON-MISMATCH', 149));

        app(KashierReconciliationService::class)->reconcile(100);
        app(KashierReconciliationService::class)->reconcile(100, true);

        $finding = PaymentReconciliationFinding::query()->firstOrFail();
        self::assertSame('captured_evidence_mismatch', $finding->kind);
        self::assertSame(PaymentReconciliationFinding::STATE_OPEN, $finding->state);
        self::assertSame(2, $finding->attempts);
        self::assertSame(Order::FINANCIAL_REVIEW_REQUIRED, $order->fresh()->financial_status);
        self::assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        self::assertSame(0, (int) $this->user->fresh()->wallet_coins);
        self::assertSame(0, DB::table('wallet_transactions')->count());
    }

    public function test_provider_outage_records_operational_evidence_without_mutating_money(): void
    {
        $order = $this->pendingOrder('PKG-RECON-OFFLINE');
        Http::fake([
            'https://test-api.kashier.io/*' => Http::response([], 503),
        ]);

        $stats = app(KashierReconciliationService::class)->reconcile(100);

        self::assertSame(1, $stats['unavailable']);
        self::assertSame(1, $stats['findings']);
        $this->assertDatabaseHas('payment_reconciliation_findings', [
            'order_id' => $order->id,
            'kind' => 'provider_unavailable',
            'state' => PaymentReconciliationFinding::STATE_OPEN,
        ]);
        $this->assertDatabaseHas('payment_reconciliation_checkpoints', [
            'provider' => 'kashier',
            'last_error_code' => 'provider_unavailable',
        ]);
        self::assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        self::assertSame(0, (int) $this->user->fresh()->wallet_coins);
    }

    public function test_command_fails_closed_when_the_provider_is_unavailable(): void
    {
        $this->pendingOrder('PKG-RECON-COMMAND');
        Http::fake([
            'https://test-api.kashier.io/*' => Http::response([], 503),
        ]);

        $exitCode = Artisan::call('payments:reconcile-kashier', ['--limit' => 1]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertStringContainsString('unavailable for the entire batch', Artisan::output());
    }

    private function pendingOrder(string $reference): Order
    {
        return Order::query()->create([
            'user_id' => $this->user->id,
            'package_id' => $this->package->id,
            'package_coins' => 500,
            'order_ref' => $reference,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => 150,
            'discount_amount' => 0,
            'final_amount' => 150,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
            'checkout_expires_at' => now()->addHour(),
        ]);
    }

    /** @return array<string, mixed> */
    private function providerResponse(
        Order $order,
        string $status,
        string $transactionId,
        int|float $amount = 150
    ): array {
        return [
            'https://test-api.kashier.io/*' => Http::response(
                $this->providerPayload($order, $status, $transactionId, $amount),
                200
            ),
        ];
    }

    /** @return array<string, mixed> */
    private function providerPayload(
        Order $order,
        string $status,
        string $transactionId,
        int|float $amount = 150
    ): array {
        return [
            'response' => [
                'status' => $status,
                'transactionId' => $transactionId,
                'merchantOrderId' => $order->order_ref,
                'amount' => $amount,
                'currency' => 'EGP',
            ],
        ];
    }
}
