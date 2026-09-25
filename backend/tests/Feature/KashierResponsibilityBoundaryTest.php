<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\KashierCheckoutOrderService;
use App\Services\KashierConfigurationService;
use App\Services\KashierGatewayEvidenceService;
use App\Services\KashierOrderSettlementService;
use App\Services\KashierProviderOrderService;
use App\Services\OrderLifecycleService;
use App\Services\PackageChannelPricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class KashierResponsibilityBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_evidence_and_purchased_coin_reads_do_not_resolve_gateway_or_financial_writers(): void
    {
        $this->forbidDependencies([
            KashierProviderOrderService::class,
            KashierOrderSettlementService::class,
            KashierCheckoutOrderService::class,
            OrderLifecycleService::class,
            KashierConfigurationService::class,
        ]);
        $order = new Order([
            'transaction_id' => 'TXN-READ-1',
            'order_ref' => 'PKG-BOUNDARY-READ',
            'final_amount' => 150,
            'package_coins' => 500,
        ]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void { $queries[] = $query->sql; });

        $evidence = app(KashierGatewayEvidenceService::class);
        $payload = ['paymentStatus' => 'CAPTURED', 'amount' => 150, 'currency' => 'EGP'];
        self::assertTrue($evidence->isCaptured($payload));
        self::assertFalse($evidence->transactionIdConflicts($order, 'TXN-READ-1'));
        self::assertTrue($evidence->transactionIdConflicts($order, 'TXN-READ-2'));
        self::assertFalse($evidence->transactionIdConflicts($order, null));
        $evidence->assertMatches($order, $payload);
        self::assertSame(500, $order->packageCoinAmount());
        $order->package_coins = null;
        $order->setRelation('package', new Package(['coins' => 900]));
        self::assertSame(0, $order->packageCoinAmount(), 'Never substitute current catalogue coins for missing purchased terms.');
        self::assertSame([], $queries);
        Http::assertNothingSent();
    }

    public function test_checkout_reserves_terms_without_resolving_provider_or_settlement(): void
    {
        [$user, $package] = $this->buyerAndPackage();
        $this->forbidDependencies([
            KashierProviderOrderService::class,
            KashierOrderSettlementService::class,
            KashierGatewayEvidenceService::class,
            KashierConfigurationService::class,
        ]);
        $checkout = app(KashierCheckoutOrderService::class);
        $first = $checkout->beginCheckout($user, $package, 'boundary-checkout-1');
        $order = $first['order'];
        $amount = (float) $order->final_amount;
        $package->update(['coins' => 900, 'price' => 300]);
        $replay = $checkout->beginCheckout($user, $package, 'boundary-checkout-1', $amount, 500);

        self::assertFalse($first['reused']);
        self::assertTrue($replay['reused']);
        self::assertSame($order->id, $replay['order']->id);
        self::assertSame(500, $replay['order']->packageCoinAmount());
        self::assertSame($amount, (float) $replay['order']->final_amount);
        self::assertSame(Order::STATUS_PENDING, $order->status);
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(1, Order::query()->count());
        Http::assertNothingSent();
    }

    public function test_local_settlement_uses_purchased_snapshot_and_replays_without_remote_or_pricing_dependencies(): void
    {
        [$user, $package] = $this->buyerAndPackage();
        $order = Order::query()->create([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => 500,
            'order_ref' => 'PKG-BOUNDARY-CAPTURE',
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'amount' => 150,
            'discount_amount' => 0,
            'final_amount' => 150,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
            'checkout_expires_at' => now()->addHour(),
        ]);
        $package->update(['coins' => 900]);
        $this->forbidDependencies([
            KashierProviderOrderService::class,
            KashierCheckoutOrderService::class,
            KashierConfigurationService::class,
            PackageChannelPricingService::class,
        ]);
        $settlement = app(KashierOrderSettlementService::class);
        $payload = ['amount' => 150, 'currency' => 'EGP', 'merchantOrderId' => $order->order_ref];
        $first = $settlement->fulfillOrder($order, 'TXN-BOUNDARY-CAPTURE', $payload);
        $replay = $settlement->fulfillOrder($order, 'TXN-BOUNDARY-CAPTURE', $payload);

        self::assertSame(Order::STATUS_APPROVED, $first->status);
        self::assertSame(Order::FINANCIAL_SETTLED, $replay->financial_status);
        self::assertSame(500, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(1, DB::table('wallet_transactions')->where('category', 'package_purchase')->count());
        self::assertSame(500, (int) DB::table('package_user')->where('order_id', $order->id)->value('coins'));
        Http::assertNothingSent();
    }

    public function test_missing_transaction_evidence_can_be_fetched_without_resolving_financial_writers(): void
    {
        $this->forbidDependencies([KashierOrderSettlementService::class, OrderLifecycleService::class]);
        config([
            'kashier.mode' => 'test',
            'kashier.test.api_key' => 'test-key',
            'kashier.test.secret_key' => 'test-secret',
            'kashier.test.mid' => 'test-merchant',
            'kashier.test.base_url' => 'https://checkout.example.invalid',
        ]);
        $reference = 'PKG-BOUNDARY-PROBE';
        Http::fake(['https://test-api.kashier.io/*' => Http::response(['response' => [
            'status' => 'CAPTURED', 'transactionId' => 'TXN-PROBE', 'merchantOrderId' => $reference,
            'amount' => 150, 'currency' => 'EGP',
        ]])]);
        $queries = [];
        DB::listen(static function ($query) use (&$queries): void { $queries[] = $query->sql; });

        $provider = app(KashierProviderOrderService::class);
        [$transactionId, $payload] = $provider->captureEvidence($reference, null, ['callback' => true]);
        self::assertSame('TXN-PROBE', $transactionId);
        self::assertTrue($payload['callback']);
        self::assertSame('kashier_api_missing_transaction_id', $payload['verified_via']);
        self::assertSame(['TXN-EXISTING', ['callback' => true]], $provider->captureEvidence(
            $reference, 'TXN-EXISTING', ['callback' => true]
        ));
        Http::assertSentCount(1);
        self::assertSame([], $queries);
    }

    /** @param list<class-string> $classes */
    private function forbidDependencies(array $classes): void
    {
        foreach ($classes as $class) {
            $this->app->bind($class, static function () use ($class): never {
                throw new \LogicException('Wrong responsibility dependency: '.$class);
            });
        }
    }

    /** @return array{User, Package} */
    private function buyerAndPackage(): array
    {
        return [
            User::query()->forceCreate([
                'name' => 'Boundary Student', 'email' => 'boundary@rokn.test', 'phone' => '01000000001',
                'role' => 'client', 'active' => true, 'wallet_coins' => 0,
                'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
            ]),
            Package::query()->create([
                'name_ar' => 'باقة الاختبار', 'name_en' => 'Test package', 'price' => 150, 'coins' => 500,
                'is_active' => true, 'direct_enabled' => true,
            ]),
        ];
    }
}
