<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\PaymentFindingResource;
use App\Filament\Resources\PaymentFindingResource\Pages\ListPaymentFindings;
use App\Filament\Widgets\PaymentFindingStats;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Order;
use App\Models\PaymentReconciliationFinding;
use App\Models\User;
use App\Services\AdminPaymentFindingReadService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentPaymentFindingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->actingAs($this->user('admin'), 'web');
    }

    public function test_native_filters_and_literal_reference_search_share_the_same_reader(): void
    {
        $match = $this->finding('ORDER_100%');
        $other = $this->finding('ORDER-OTHER');
        $closed = $this->finding('ORDER-CLOSED', ['state' => 'resolved']);
        $page = Livewire::test(ListPaymentFindings::class)->assertCanSeeTableRecords([$match, $other])->assertCanNotSeeTableRecords([$closed]);
        $page->searchTable('_100%')->assertCanSeeTableRecords([$match])->assertCanNotSeeTableRecords([$other, $closed]);
        $page->searchTable('')->filterTable('state', 'resolved')->assertCanSeeTableRecords([$closed])->assertCanNotSeeTableRecords([$match, $other]);
        $page->filterTable('state', null)->filterTable('kind', 'provider_unavailable')->assertCountTableRecords(3);
        self::assertSame(['open' => 2, 'resolved' => 1], app(AdminPaymentFindingReadService::class)->stateCounts());
        Livewire::test(PaymentFindingStats::class)->assertSee('إجمالي النتائج')->assertSee('محلولة');
        $this->get(route('admin.payment-reconciliation-findings.index', ['order_ref' => '_100%', 'state' => '']))
            ->assertRedirect(PaymentFindingResource::listUrl(['order_ref' => '_100%', 'state' => null]));
    }

    public function test_decisions_are_documented_without_changing_an_order_or_wallet(): void
    {
        $user = $this->user();
        $order = Order::query()->create(['user_id' => $user->id, 'order_ref' => 'DECISION-ONLY', 'amount' => 100, 'final_amount' => 100,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING]);
        $finding = $this->finding($order->order_ref, ['order_id' => $order->id]);
        $original = $order->fresh()->getRawOriginal();
        $page = Livewire::test(ListPaymentFindings::class)->callTableAction('resolve', $finding, ['note' => 'دليل مزود تم فحصه'])->assertHasNoTableActionErrors();
        self::assertSame('resolved', $finding->fresh()->state);
        self::assertSame(auth('web')->id(), $finding->fresh()->resolved_by);
        $page->filterTable('state', 'resolved')->callTableAction('reopen', $finding, ['note' => 'ظهر دليل يحتاج الفحص'])->assertHasNoTableActionErrors();
        self::assertSame('open', $finding->fresh()->state);
        self::assertNull($finding->fresh()->resolved_by);
        $page->filterTable('state', 'open')->callTableAction('ignore', $finding, ['note' => 'اختلاف معروف موثق'])->assertHasNoTableActionErrors();
        self::assertSame('ignored', $finding->fresh()->state);
        self::assertSame($original, $order->fresh()->getRawOriginal());
        self::assertSame(0, (int) $user->fresh()->wallet_coins);
    }

    public function test_new_provider_evidence_rejects_an_already_open_decision_form(): void
    {
        $finding = $this->finding('OLD-EVIDENCE');
        $page = Livewire::test(ListPaymentFindings::class)->mountTableAction('resolve', $finding);
        $finding->update(['attempts' => 2, 'evidence' => ['status' => 'PAID']]);
        $page->setTableActionData(['note' => 'قرار بني على دليل قديم'])->callMountedTableAction()
            ->assertNotified('وصل دليل دفع أحدث منذ فتح الصفحة. راجعه قبل تسجيل القرار.');
        self::assertSame('open', $finding->fresh()->state);
        self::assertNull($finding->fresh()->resolved_at);
    }

    public function test_blank_decision_is_visible_validation_and_no_generic_delete_exists(): void
    {
        $finding = $this->finding('NOTE-REQUIRED');
        Livewire::test(ListPaymentFindings::class)->callTableAction('ignore', $finding, ['note' => ''])
            ->assertHasTableActionErrors(['note' => 'required']);
        self::assertFalse(PaymentFindingResource::canCreate());
        self::assertFalse(PaymentFindingResource::canEdit($finding));
        self::assertFalse(PaymentFindingResource::canDelete($finding));
        self::assertSame('open', $finding->fresh()->state);
    }

    public function test_evidence_action_opens_and_rows_eager_load_their_context(): void
    {
        $finding = $this->finding('ESCAPED-EVIDENCE', ['evidence' => ['detail' => '<script>alert(1)</script>']]);
        $page = Livewire::test(ListPaymentFindings::class)->mountTableAction('evidence', $finding)
            ->assertActionMounted(TestAction::make('evidence')->table($finding));
        foreach ($page->instance()->getTableRecords() as $record) {
            self::assertTrue($record->relationLoaded('order'));
            self::assertTrue($record->relationLoaded('resolver'));
        }
    }

    public function test_moderator_cannot_open_the_native_financial_queue(): void
    {
        $this->actingAs($this->user('moderator'), 'web')->get(PaymentFindingResource::getUrl())->assertForbidden();
    }

    private function finding(string $reference, array $overrides = []): PaymentReconciliationFinding
    {
        return PaymentReconciliationFinding::query()->create(['provider' => 'kashier', 'order_ref' => $reference, 'fingerprint' => hash('sha256', $reference),
            'kind' => 'provider_unavailable', 'state' => 'open', 'attempts' => 1, 'first_seen_at' => now(), 'last_seen_at' => now(), ...$overrides]);
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate(['name' => 'Finding '.$role, 'email' => Str::uuid().'@finding.test', 'role' => $role, 'active' => true]);
    }
}
