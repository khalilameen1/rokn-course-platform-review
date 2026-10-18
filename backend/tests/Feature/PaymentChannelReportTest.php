<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\PaymentChannelReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class PaymentChannelReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_separates_channels_tests_and_unsettled_net_amounts(): void
    {
        $user = User::query()->forceCreate([
            'name' => 'Finance Student',
            'name_ar' => 'طالب التقارير',
            'name_en' => 'Finance Student',
            'email' => 'finance-report@rokn.test',
            'phone' => '01000000007',
            'role' => 'client',
            'active' => true,
        ]);
        $package = Package::query()->create([
            'name_ar' => 'باقة التقارير',
            'name_en' => 'Report package',
            'price' => 100,
            'coins' => 500,
        ]);

        $this->approvedPackageOrder($user, $package, [
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'order_ref' => 'REPORT-KASHIER',
            'final_amount' => 100,
            'gateway_gross_amount' => 100,
            'gateway_fee_amount' => 5,
            'gateway_net_amount' => 95,
            'gateway_settlement_status' => 'settled',
        ]);
        $this->approvedPackageOrder($user, $package, [
            'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY,
            'order_ref' => 'REPORT-GOOGLE',
            'final_amount' => 150,
            'gateway_gross_amount' => 150,
            'gateway_settlement_status' => 'catalog_estimate',
        ]);
        $this->approvedPackageOrder($user, $package, [
            'payment_method' => Order::PAYMENT_METHOD_APP_STORE,
            'order_ref' => 'REPORT-APPLE-TEST',
            'final_amount' => 200,
            'gateway_gross_amount' => 0,
            'gateway_settlement_status' => 'test_purchase',
        ]);

        $report = app(PaymentChannelReportService::class)->summary();
        $rows = $report['rows']->keyBy('method');

        self::assertSame(2, $report['egp']['live_count']);
        self::assertSame(1, $report['egp']['test_count']);
        self::assertSame(1000, $report['egp']['live_coins']);
        self::assertSame(250.0, $report['egp']['gross_amount']);
        self::assertSame(5.0, $report['egp']['confirmed_fee_amount']);
        self::assertSame(95.0, $report['egp']['confirmed_net_amount']);
        self::assertSame(245.0, $report['egp']['estimated_net_amount']);
        self::assertSame(1, $report['egp']['pending_settlement_count']);
        self::assertSame(1, $rows[Order::PAYMENT_METHOD_APP_STORE]['test_count']);
        self::assertSame(0.0, $rows[Order::PAYMENT_METHOD_APP_STORE]['gross_amount']);
    }

    public function test_it_groups_monthly_gross_without_breaking_the_dashboard(): void
    {
        $user = User::query()->forceCreate([
            'name' => 'Monthly Finance Student',
            'name_ar' => 'طالب التقرير الشهري',
            'name_en' => 'Monthly Finance Student',
            'email' => 'monthly-finance-report@rokn.test',
            'phone' => '01000000008',
            'role' => 'client',
            'active' => true,
        ]);
        $package = Package::query()->create([
            'name_ar' => 'باقة شهرية',
            'name_en' => 'Monthly package',
            'price' => 400,
            'coins' => 500,
        ]);
        $approvedAt = CarbonImmutable::parse('2026-08-18 12:00:00', 'UTC');

        $this->approvedPackageOrder($user, $package, [
            'order_ref' => 'REPORT-MONTHLY',
            'gateway_gross_amount' => 400,
            'gateway_settlement_status' => 'settled',
            'approved_at' => $approvedAt,
        ]);

        $totals = app(PaymentChannelReportService::class)->monthlyEgpGross(
            $approvedAt->startOfMonth(),
            $approvedAt->addMonth()->startOfMonth(),
        );

        self::assertSame(400.0, $totals->get('2026-08'));
    }

    /** @param array<string, mixed> $overrides */
    private function approvedPackageOrder(
        User $user,
        Package $package,
        array $overrides
    ): Order {
        return Order::query()->create(array_merge([
            'user_id' => $user->id,
            'package_id' => $package->id,
            'package_coins' => $package->coins,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER,
            'order_ref' => 'REPORT-'.uniqid(),
            'amount' => 100,
            'discount_amount' => 0,
            'final_amount' => 100,
            'gateway_currency' => 'EGP',
            'total_coins' => $package->coins,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'approved_at' => now(),
        ], $overrides));
    }
}
