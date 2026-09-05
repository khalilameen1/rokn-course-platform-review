<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\AdminPaymentOperationsReadService;
use App\Services\PaymentChannelReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminPaymentOperationsReadServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_paid_state_only_contains_financially_effective_orders(): void
    {
        [$user, $package] = $this->fixtures();
        $paid = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-PAID',
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
            'payment_gateway_response' => ['paymentStatus' => 'EXPIRED'],
        ]);
        $refunded = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-REFUNDED',
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_REFUNDED,
            'approved_at' => now()->subMinute(),
            'reversed_at' => now(),
            'payment_gateway_response' => ['paymentStatus' => 'EXPIRED'],
        ]);
        $cancelled = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-CANCELLED-EXPIRED',
            'status' => Order::STATUS_CANCELLED,
            'payment_gateway_response' => ['paymentStatus' => 'EXPIRED'],
        ]);

        $read = app(AdminPaymentOperationsReadService::class);
        $paidPage = $read->index(['state' => 'paid']);
        self::assertSame([$paid->id], $paidPage['orders']->getCollection()->modelKeys());
        self::assertSame('paid', $paidPage['orders']->first()->payment_operation_state);

        $closedPage = $read->index(['state' => 'cancelled']);
        self::assertSame([$refunded->id], $closedPage['orders']->getCollection()->modelKeys());
        foreach ($closedPage['orders'] as $closedOrder) {
            self::assertSame('cancelled', $closedOrder->payment_operation_state);
            self::assertSame('أُغلقت العملية', $closedOrder->payment_operation_label);
        }

        $expiredOrders = $read->index(['state' => 'expired'])['orders']->getCollection();
        $expiredIds = $expiredOrders->modelKeys();
        self::assertNotContains($paid->id, $expiredIds);
        self::assertNotContains($refunded->id, $expiredIds);
        self::assertContains($cancelled->id, $expiredIds);
        self::assertSame(
            'expired',
            $expiredOrders->firstWhere('id', $cancelled->id)?->payment_operation_state
        );

        $admin = User::query()->forceCreate([
            'name' => 'Finance Admin',
            'email' => 'finance-admin@rokn.test',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web')
            ->get(route('admin.orders.show', $refunded))
            ->assertOk()
            ->assertSee('مسترد')
            ->assertDontSee('توثيق التسوية');
    }

    public function test_local_expiry_does_not_hide_an_authorized_provider_attempt(): void
    {
        [$user, $package] = $this->fixtures();
        $active = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-ACTIVE',
            'final_amount' => 100,
            'checkout_expires_at' => now()->addMinutes(10),
        ]);
        $expired = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-EXPIRED',
            'final_amount' => 200,
            'checkout_expires_at' => now()->subMinute(),
        ]);
        $authorized = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-AUTHORIZED',
            'final_amount' => 300,
            'checkout_expires_at' => now()->subMinute(),
            'payment_gateway_response' => [
                'response' => ['status' => 'EXPIRED', 'paymentStatus' => 'AUTHORIZED'],
                'paymentStatus' => 'UNPAID',
            ],
        ]);
        $providerExpiredBeforeDeadline = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-PROVIDER-EXPIRED-EARLY',
            'final_amount' => 500,
            'checkout_expires_at' => now()->addMinutes(10),
            'payment_gateway_response' => [
                'response' => ['paymentStatus' => 'EXPIRED'],
            ],
        ]);
        $providerPending = $this->packageOrder($user, $package, [
            'order_ref' => 'READ-PROVIDER-PENDING',
            'final_amount' => 400,
            'checkout_expires_at' => now()->subMinute(),
            'payment_gateway_response' => [
                'response' => ['status' => 'SUCCESS', 'paymentStatus' => 'UNPAID'],
            ],
        ]);

        $read = app(AdminPaymentOperationsReadService::class);
        $summary = app(PaymentChannelReportService::class)->pendingCheckoutSummary(
            $read->openProviderCheckouts()
        );

        self::assertSame(2, $summary['count']);
        self::assertSame(400.0, $summary['egp_amount']);

        $pendingOrders = $read->index(['state' => 'pending'])['orders']->getCollection();
        $pendingIds = $pendingOrders->modelKeys();
        self::assertContains($authorized->id, $pendingIds);
        self::assertSame(
            'pending',
            $pendingOrders->firstWhere('id', $authorized->id)?->payment_operation_state
        );
        self::assertNotContains($expired->id, $pendingIds);
        self::assertNotContains($providerPending->id, $pendingIds);
        self::assertNotContains($providerExpiredBeforeDeadline->id, $pendingIds);

        $expiredOrders = $read->index(['state' => 'expired'])['orders']->getCollection();
        $expiredIds = $expiredOrders->modelKeys();
        self::assertContains($expired->id, $expiredIds);
        self::assertContains($providerPending->id, $expiredIds);
        self::assertContains($providerExpiredBeforeDeadline->id, $expiredIds);
        self::assertSame(
            'expired',
            $expiredOrders->firstWhere('id', $providerExpiredBeforeDeadline->id)?->payment_operation_state
        );
        self::assertNotContains($active->id, $expiredIds);
        self::assertNotContains($authorized->id, $expiredIds);
    }

    /** @return array{User, Package} */
    private function fixtures(): array
    {
        $user = User::query()->forceCreate([
            'name' => 'Finance Student',
            'email' => 'finance-student-'.uniqid().'@rokn.test',
            'role' => 'client',
            'active' => true,
        ]);
        $package = Package::query()->create([
            'name_ar' => 'باقة مالية',
            'name_en' => 'Finance package',
            'price' => 400,
            'coins' => 500,
        ]);

        return [$user, $package];
    }

    /** @param array<string, mixed> $overrides */
    private function packageOrder(User $user, Package $package, array $overrides): Order
    {
        return Order::query()->create(array_merge([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => $package->coins,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'order_ref' => 'READ-'.uniqid(),
            'amount' => 100,
            'discount_amount' => 0,
            'final_amount' => 100,
            'gateway_currency' => 'EGP',
            'total_coins' => $package->coins,
            'status' => Order::STATUS_PENDING,
            'financial_status' => Order::FINANCIAL_PENDING,
        ], $overrides));
    }
}
