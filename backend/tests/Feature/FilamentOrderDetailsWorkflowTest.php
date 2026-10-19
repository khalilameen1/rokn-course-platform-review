<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OrderResource;
use App\Filament\Resources\OrderResource\Pages\ViewOrder;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\Order;
use App\Models\Package;
use App\Models\StaffRole;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\FinancialProvenanceService;
use App\Services\StaffRoleService;
use App\Services\WalletService;
use App\Support\BusinessClock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentOrderDetailsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->actingAs($this->user('admin'), 'web');
    }

    public function test_saved_detail_link_redirects_and_pending_provider_has_no_manual_approval(): void
    {
        $order = $this->packageOrder(['status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING]);
        $this->get(route('admin.orders.show', $order))->assertRedirect(OrderResource::getUrl('view', ['record' => $order->id]));
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertSee('فتح صفحة الدفع لا يثبت التحصيل')
            ->assertActionHidden('settlement')->assertActionHidden('compensate')->assertActionHidden('resolve');
        self::assertFalse(OrderResource::canEdit($order));
        self::assertFalse(OrderResource::canDelete($order));
    }

    public function test_native_settlement_saves_exact_figures_once_and_rejects_a_stale_open_form(): void
    {
        $order = $this->packageOrder();
        $first = Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('settlement');
        $stale = Livewire::test(ViewOrder::class, ['record' => $order->id])->mountAction('settlement');
        $first->setActionData($this->statement())->callMountedAction()->assertHasNoActionErrors()->assertActionHidden('settlement');
        self::assertSame('97.00', $order->fresh()->gateway_net_amount);
        self::assertSame('3.00', $order->fresh()->gateway_fee_amount);
        self::assertSame('dashboard_statement', $order->fresh()->payment_gateway_response['settlement']['source']);
        $stale->setActionData([...$this->statement(), 'net_amount' => '96.00', 'fee_amount' => '4.00'])->callMountedAction();
        self::assertSame('97.00', $order->fresh()->gateway_net_amount);
        self::assertSame('statement-test', $order->fresh()->payment_gateway_response['settlement']['provider_reference']);
    }

    public function test_invalid_net_and_test_purchase_never_turn_into_live_revenue(): void
    {
        $order = $this->packageOrder();
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('settlement', [...$this->statement(), 'net_amount' => '80.00'])
            ->assertNotified('تعذر حفظ الإجراء — راجع البيانات وحالة الطلب ثم أعد المحاولة');
        self::assertNull($order->fresh()->gateway_net_amount);
        $test = $this->packageOrder(['gateway_settlement_status' => 'test_purchase']);
        Livewire::test(ViewOrder::class, ['record' => $test->id])->assertActionHidden('settlement')->assertSee('اختبار — لا يدخل في الإيراد');
    }

    public function test_compensation_uses_the_original_ledger_and_current_remaining_limit(): void
    {
        $student = $this->user();
        $course = Course::factory()->create(['tenant_id' => 1, 'is_coming_soon' => true]);
        $wallet = app(WalletService::class);
        $wallet->credit($student->id, 500, 'test-credit', (string) Str::uuid());
        $debit = $wallet->debit($student->id, 500, 'course_purchase', (string) Str::uuid(), $course);
        $order = Order::query()->create(['user_id' => $student->id, 'course_id' => $course->id, 'wallet_transaction_id' => $debit->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 500, 'final_amount' => 500, 'total_coins' => 500,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED]);
        Livewire::test(ViewOrder::class, ['record' => $order->id])->assertActionVisible('compensate')
            ->callAction('compensate', ['amount' => 200, 'note' => 'تعويض عن عطل تشغيل موثق'])->assertHasNoActionErrors();
        self::assertSame(200, (int) WalletTransaction::query()->where('category', 'course_service_compensation')->sum('amount'));
        Livewire::test(ViewOrder::class, ['record' => $order->id])->callAction('compensate', ['amount' => 301, 'note' => 'تعويض يتجاوز المتبقي من الطلب'])
            ->assertHasActionErrors(['amount' => 'max']);
        self::assertSame(200, (int) WalletTransaction::query()->where('category', 'course_service_compensation')->sum('amount'));
    }

    public function test_moderator_cannot_open_finance_detail(): void
    {
        $order = $this->packageOrder();
        $this->actingAs($this->user('moderator'), 'web')->get(OrderResource::getUrl('view', ['record' => $order->id]))->assertForbidden();
    }

    public function test_financial_decision_uses_the_owner_and_records_its_reference(): void
    {
        $order = $this->packageOrder(['financial_status' => Order::FINANCIAL_REVIEW_REQUIRED]);
        $credit = app(WalletService::class)->credit($order->user_id, 500, 'package_purchase', (string) Str::uuid(), $order, [], WalletTransaction::BUCKET_PAID);
        app(FinancialProvenanceService::class)->recordPaidPackageCredit($order, $credit);
        Livewire::test(ViewOrder::class, ['record' => $order->id])
            ->callAction('resolve', ['resolution' => 'waived', 'note' => 'إعفاء موثق بمرجع مراجعة داخلي'])->assertHasNoActionErrors()
            ->assertActionHidden('resolve');
        self::assertSame(Order::FINANCIAL_REVERSED, $order->fresh()->financial_status);
        $this->assertDatabaseHas('order_financial_events', ['order_id' => $order->id, 'event_type' => 'resolution_waived']);
    }

    public function test_read_only_finance_staff_never_get_write_actions(): void
    {
        $owner = auth('web')->user();
        $reader = $this->user('admin');
        $roles = app(StaffRoleService::class);
        $roles->seedDefaults();
        $role = StaffRole::findOrCreate('financial_reader_test', 'web');
        $role->syncPermissions(['finance.read']);
        $roles->assign($owner, $reader->id, [(int) $role->id]);
        $this->actingAs($reader, 'web');
        Livewire::test(ViewOrder::class, ['record' => $this->packageOrder()->id])
            ->assertActionHidden('settlement')->assertActionHidden('compensate')->assertActionHidden('resolve');
    }

    private function statement(): array
    {
        return ['gross_amount' => '100.00', 'fee_amount' => '3.00', 'net_amount' => '97.00', 'currency' => 'EGP',
            'settled_at' => BusinessClock::now()->subHour()->format('Y-m-d H:i:s'), 'provider_reference' => 'statement-test'];
    }

    private function packageOrder(array $overrides = []): Order
    {
        $package = Package::query()->create(['name_ar' => 'حزمة اختبار التفاصيل', 'name_en' => 'Details', 'price' => 100, 'coins' => 500]);

        return Order::query()->create([...['user_id' => $this->user()->id, 'package_id' => $package->id, 'package_coins' => 500,
            'order_ref' => (string) Str::uuid(), 'payment_method' => Order::PAYMENT_METHOD_KASHIER, 'amount' => 100, 'final_amount' => 100,
            'gateway_gross_amount' => 100, 'gateway_currency' => 'EGP', 'gateway_settlement_status' => 'captured',
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()], ...$overrides]);
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate(['name' => 'Order '.$role, 'email' => Str::uuid().'@order-details.test', 'role' => $role, 'active' => true]);
    }
}
