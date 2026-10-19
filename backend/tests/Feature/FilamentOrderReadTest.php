<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\ListOrders;
use App\Filament\Resources\OrderResource\Pages\PaymentReport;
use App\Filament\Widgets\OrderChannelReport;
use App\Filament\Widgets\OrderSummary;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use App\Services\AdminPaymentOperationsReadService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentOrderReadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::query()->forceCreate(['name_ar' => 'المالية', 'email' => 'finance-native@example.test', 'role' => 'admin', 'active' => true]);
        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_table_uses_financial_state_not_the_legacy_approved_flag(): void
    {
        $paid = $this->order(['status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()]);
        $refunded = $this->order(['status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_REFUNDED, 'approved_at' => now(), 'reversed_at' => now()]);
        $page = Livewire::test(ListOrders::class)->filterTable('criteria', ['state' => 'paid']);
        $page->assertCanSeeTableRecords([$paid])->assertCanNotSeeTableRecords([$refunded]);
        $page->filterTable('criteria', ['state' => 'cancelled'])->assertCanSeeTableRecords([$refunded])
            ->assertCanNotSeeTableRecords([$paid])->assertSee('مسترد');
    }

    public function test_unknown_net_remains_unknown_and_test_purchases_are_marked(): void
    {
        $order = $this->order(['gateway_gross_amount' => '11.11', 'gateway_net_amount' => null, 'gateway_settlement_status' => 'test_purchase']);
        Livewire::test(ListOrders::class)->assertCanSeeTableRecords([$order])
            ->assertSee('11.11 EGP')->assertSee('بانتظار التسوية')->assertSee('اختبار خارج الإيراد');
        self::assertNull($order->fresh()->gateway_net_amount);
    }

    public function test_search_and_csv_use_the_same_reference_and_arabic_student_scope(): void
    {
        $target = $this->order(['order_ref' => 'REF-NATIVE-0123']);
        $other = $this->order(['order_ref' => 'REF-OTHER-9999']);
        $page = Livewire::test(ListOrders::class)->searchTable('REF-NATIVE-0123')
            ->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$other]);
        parse_str((string) parse_url($page->instance()->exportUrl(), PHP_URL_QUERY), $filters);
        self::assertSame('REF-NATIVE-0123', $filters['search']);
        $ids = [];
        app(AdminPaymentOperationsReadService::class)->eachForExport($filters, function (Order $order) use (&$ids): void {
            $ids[] = $order->id;
        });
        self::assertSame([$target->id], $ids);
        $target->user->update(['name_ar' => 'طالب الاسم العربي']);
        $page->searchTable('طالب الاسم العربي')->assertCanSeeTableRecords([$target])->assertCanNotSeeTableRecords([$other]);
    }

    public function test_one_sided_amount_filter_works_and_invalid_input_is_rejected(): void
    {
        $small = $this->order(['final_amount' => '25.00']);
        $large = $this->order(['final_amount' => '250.00']);
        Livewire::test(ListOrders::class)->filterTable('criteria', ['amount_max' => 50])
            ->assertCanSeeTableRecords([$small])->assertCanNotSeeTableRecords([$large]);
        Livewire::test(ListOrders::class)->set('tableDeferredFilters.criteria.amount_min', -1)
            ->call('applyTableFilters')->assertHasErrors('tableDeferredFilters.criteria.amount_min');
        Livewire::test(ListOrders::class)->set('tableDeferredFilters.criteria.amount_min', 100)
            ->set('tableDeferredFilters.criteria.amount_max', 10)->call('applyTableFilters')
            ->assertHasErrors('tableDeferredFilters.criteria.amount_max');
    }

    public function test_end_date_alone_is_valid_but_an_inverted_range_is_not(): void
    {
        Livewire::test(ListOrders::class)->set('tableDeferredFilters.criteria.date_to', '2026-10-19')
            ->call('applyTableFilters')->assertHasNoErrors();
        Livewire::test(ListOrders::class)->set('tableDeferredFilters.criteria.date_from', '2026-10-20')
            ->set('tableDeferredFilters.criteria.date_to', '2026-10-19')->call('applyTableFilters')
            ->assertHasErrors('tableDeferredFilters.criteria.date_to');
    }

    public function test_batched_projection_does_not_add_a_query_for_each_extra_order(): void
    {
        $this->order();
        $read = app(AdminPaymentOperationsReadService::class);
        $measure = function () use ($read): int {
            DB::enableQueryLog();
            DB::flushQueryLog();
            $orders = $read->ordersQuery()->get();
            $read->decorate($orders);
            foreach ($orders as $order) {
                self::assertNotNull($order->payment_operation_label);
                $order->user?->name;
                $order->package?->name_ar;
            }
            $count = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $count;
        };
        $one = $measure();
        for ($i = 0; $i < 15; $i++) {
            $this->order();
        }
        self::assertLessThanOrEqual($one + 1, $measure());
    }

    public function test_report_preserves_confirmed_money_and_excludes_test_and_foreign_currency(): void
    {
        $paid = ['status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()];
        $this->order($paid + ['gateway_gross_amount' => 100, 'gateway_fee_amount' => 4, 'gateway_net_amount' => 96]);
        $this->order($paid + ['gateway_gross_amount' => 11.11, 'gateway_settlement_status' => 'test_purchase']);
        $this->order($paid + ['gateway_gross_amount' => 30, 'gateway_currency' => 'USD']);
        $page = Livewire::test(PaymentReport::class)->assertOk();
        $data = $page->instance()->getWidgetData();
        self::assertSame(100.0, $data['report']['stats']['total_amount']);
        self::assertTrue($data['report']['paymentChannelReport']['has_other_currencies']);
        Livewire::test(OrderSummary::class, $data)->assertSee('100.00');
        Livewire::test(OrderChannelReport::class, $data)->assertSee('96.00')->assertSee('USD')->assertSee('لا تُجمع العملات المختلفة');
    }

    public function test_report_validates_before_applying_filters_and_exports_only_the_applied_scope(): void
    {
        $target = $this->order(['order_ref' => 'REPORT-ONE', 'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()]);
        $this->order(['order_ref' => 'REPORT-TWO']);
        $page = Livewire::test(PaymentReport::class)->set('filters.search', 'REPORT-ONE')->call('applyReportFilters')->assertHasNoErrors();
        self::assertSame(1, $page->instance()->getWidgetData()['report']['stats']['total']);
        self::assertSame('REPORT-ONE', $page->instance()->appliedFilters['search']);
        $page->set('filters.amount_min', 100)->set('filters.amount_max', 10)->call('applyReportFilters')->assertHasErrors('filters.amount_max');
        self::assertSame(['search' => 'REPORT-ONE'], $page->instance()->appliedFilters);
        Livewire::test(OrderChannelReport::class, $page->instance()->getWidgetData())->assertSee('بانتظار التسوية');
        self::assertNotNull($target->fresh());
    }

    public function test_report_and_its_widgets_deny_staff_without_finance_access(): void
    {
        $moderator = User::query()->forceCreate(['email' => 'non-finance@example.test', 'role' => 'moderator', 'active' => true]);
        $this->actingAs($moderator, 'web');
        Livewire::test(PaymentReport::class)->assertForbidden();
        Livewire::test(OrderChannelReport::class)->assertForbidden();
        Livewire::test(OrderSummary::class)->assertForbidden();
    }

    private function order(array $overrides = []): Order
    {
        $user = User::query()->forceCreate(['name_ar' => 'طالب المالية', 'email' => uniqid('student-').'@example.test', 'role' => 'client', 'active' => true]);
        $package = Package::query()->create(['name_ar' => 'حزمة ركن', 'name_en' => 'Rokn coins', 'price' => 100, 'coins' => 900]);

        return Order::query()->create($overrides + [
            'user_id' => $user->id, 'package_id' => $package->id, 'package_coins' => 900,
            'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'order_ref' => uniqid('FIN-'),
            'amount' => 100, 'discount_amount' => 0, 'final_amount' => 100, 'gateway_currency' => 'EGP',
            'total_coins' => 900, 'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
        ]);
    }
}
