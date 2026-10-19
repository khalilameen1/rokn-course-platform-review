<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Exceptions\KashierCheckoutConflict;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\AdminOrderSettlementService;
use App\Services\KashierCheckoutOrderService;
use App\Services\KashierGatewayEvidenceService;
use App\Services\PackageChannelPricingService;
use App\Support\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Every EGP figure is computed once, exactly, and rounded HALF_UP at the column. */
final class MoneySettlementArithmeticTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public function test_statement_settlement_sums_binary_fractions_exactly(): void
    {
        [$user, $package] = $this->buyerAndPackage();
        $order = $this->approvedKashierOrder($user, $package, ['final_amount' => '0.30', 'amount' => '0.30']);
        $admin = $this->admin();

        // 0.1 + 0.2 is not 0.3 in binary floating point; the ledger must still agree.
        app(AdminOrderSettlementService::class)->record($order, [
            'gross_amount' => '0.3', 'fee_amount' => '0.1', 'net_amount' => '0.2',
            'currency' => 'egp', 'settled_at' => $this->settledAt(), 'provider_reference' => 'STMT-FRACTION',
        ], (int) $admin->id);

        $settled = $order->fresh();
        self::assertSame('0.30', $settled->gateway_gross_amount);
        self::assertSame('0.10', $settled->gateway_fee_amount);
        self::assertSame('0.20', $settled->gateway_net_amount);
        self::assertSame('EGP', $settled->gateway_currency);
        self::assertSame('settled', $settled->gateway_settlement_status);
    }

    public function test_statement_gross_may_differ_from_the_captured_gross_by_two_piastres_only(): void
    {
        [$user, $package] = $this->buyerAndPackage();
        $admin = $this->admin();
        $settlements = app(AdminOrderSettlementService::class);

        $accepted = $this->approvedKashierOrder($user, $package, ['gateway_gross_amount' => '100.00']);
        $settlements->record($accepted, $this->statement('100.02', '3.00', '97.02'), (int) $admin->id);
        self::assertSame('100.00', $accepted->fresh()->gateway_gross_amount, 'The captured gross is never overwritten.');
        self::assertSame('97.02', $accepted->fresh()->gateway_net_amount);

        $rejected = $this->approvedKashierOrder($user, $package, [
            'gateway_gross_amount' => '100.00', 'order_ref' => 'PKG-REJECTED-GROSS',
        ]);
        try {
            $settlements->record($rejected, $this->statement('100.03', '3.00', '97.03'), (int) $admin->id);
            self::fail('A three-piastre gross difference must be refused.');
        } catch (\DomainException $exception) {
            self::assertSame('Settlement gross does not match the captured gross.', $exception->getMessage());
        }
        self::assertNull($rejected->fresh()->gateway_net_amount);

        $netSlack = $this->approvedKashierOrder($user, $package, ['order_ref' => 'PKG-NET-SLACK']);
        $settlements->record($netSlack, $this->statement('100.00', '3.00', '96.98'), (int) $admin->id);
        self::assertSame('96.98', $netSlack->fresh()->gateway_net_amount);

        $netBroken = $this->approvedKashierOrder($user, $package, ['order_ref' => 'PKG-NET-BROKEN']);
        try {
            $settlements->record($netBroken, $this->statement('100.00', '3.00', '96.97'), (int) $admin->id);
            self::fail('Net must equal gross minus fees within two piastres.');
        } catch (\DomainException $exception) {
            self::assertSame('Settlement net does not equal gross minus fees.', $exception->getMessage());
        }
    }

    public function test_kashier_amount_evidence_keeps_its_sub_piastre_tolerance_and_exact_fee_math(): void
    {
        $evidence = app(KashierGatewayEvidenceService::class);
        $order = new Order(['order_ref' => 'PKG-EVIDENCE', 'final_amount' => '1250.00', 'package_coins' => 500]);

        $evidence->assertMatches($order, ['amount' => '1,250.00', 'currency' => 'EGP']);
        $evidence->assertMatches($order, ['amount' => 1250.005, 'currency' => 'EGP']);
        try {
            $evidence->assertMatches($order, ['amount' => '1250.01', 'currency' => 'EGP']);
            self::fail('A one-piastre difference is a different payment.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Kashier payment amount mismatch.', $exception->getMessage());
        }

        $facts = $evidence->settlementFacts($order, ['fee' => '2.5', 'currency' => 'egp']);
        self::assertSame('1250.00', $facts['gateway_gross_amount']);
        self::assertSame('2.50', $facts['gateway_fee_amount']);
        self::assertSame('1247.50', $facts['gateway_net_amount']);
        self::assertSame('settled', $facts['gateway_settlement_status']);

        $tenth = new Order(['order_ref' => 'PKG-TENTH', 'final_amount' => '0.30', 'package_coins' => 1]);
        $tenthFacts = $evidence->settlementFacts($tenth, ['fee' => 0.1]);
        self::assertSame('0.20', $tenthFacts['gateway_net_amount']);

        $noNet = $evidence->settlementFacts($order, ['paymentStatus' => 'CAPTURED']);
        self::assertArrayNotHasKey('gateway_fee_amount', $noNet);
        self::assertArrayNotHasKey('gateway_net_amount', $noNet);
        self::assertSame('captured', $noNet['gateway_settlement_status']);
    }

    public function test_checkout_quotes_compare_whole_piastres_and_store_decimal_columns(): void
    {
        [$user, $package] = $this->buyerAndPackage();
        $package->forceFill(['price' => '149.99'])->save();
        $pricing = app(PackageChannelPricingService::class);
        self::assertSame(134.99, $pricing->directPrice($package, 10.0));
        self::assertSame(149.99, $pricing->directPrice($package, 0.0));

        $checkout = app(KashierCheckoutOrderService::class);
        $begun = $checkout->beginCheckout($user, $package, 'money-checkout-1', '134.99', 500);
        $order = $begun['order'];
        self::assertSame('149.99', $order->fresh()->amount);
        self::assertSame('15.00', $order->fresh()->discount_amount);
        self::assertSame('134.99', $order->fresh()->final_amount);

        $replay = $checkout->beginCheckout($user, $package, 'money-checkout-1', 134.99, 500);
        self::assertTrue($replay['reused']);
        self::assertSame($order->id, $replay['order']->id);

        try {
            $checkout->beginCheckout($user, $package, 'money-checkout-1', '135.00', 500);
            self::fail('A replay with a different amount must be refused.');
        } catch (KashierCheckoutConflict $exception) {
            self::assertSame(KashierCheckoutConflict::PACKAGE_TERMS_CHANGED, $exception->reason);
        }
    }

    /** @return array<string, string> */
    private function statement(string $gross, string $fee, string $net): array
    {
        return [
            'gross_amount' => $gross, 'fee_amount' => $fee, 'net_amount' => $net,
            'currency' => 'EGP', 'settled_at' => $this->settledAt(), 'provider_reference' => 'STMT-'.$gross,
        ];
    }

    private function settledAt(): string
    {
        return BusinessClock::utcNow()->subHour()->setTimezone(BusinessClock::timezoneName())->format('Y-m-d H:i');
    }

    /** @param array<string, mixed> $overrides */
    private function approvedKashierOrder(User $user, Package $package, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => $package->coins,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'order_ref' => 'PKG-'.uniqid(),
            'transaction_id' => 'TXN-'.uniqid(),
            'amount' => '100.00',
            'discount_amount' => 0,
            'final_amount' => '100.00',
            'gateway_currency' => 'EGP',
            'total_coins' => $package->coins,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ], $overrides));
    }

    private function admin(): User
    {
        return User::query()->forceCreate([
            'name' => 'Money Admin', 'email' => 'money-admin@rokn.test', 'role' => 'admin', 'active' => true,
        ]);
    }

    /** @return array{User, Package} */
    private function buyerAndPackage(): array
    {
        return [
            User::query()->forceCreate([
                'name' => 'Money Student', 'email' => 'money-student@rokn.test', 'phone' => '01000000002',
                'role' => 'client', 'active' => true, 'wallet_coins' => 0,
                'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
            ]),
            Package::query()->create([
                'name_ar' => 'باقة المال', 'name_en' => 'Money package', 'price' => 100, 'coins' => 500,
                'is_active' => true, 'direct_enabled' => true,
            ]),
        ];
    }
}
