<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\OrderResource\Pages\PaymentReport;
use App\Filament\Resources\PackageResource;
use App\Filament\Resources\PackageResource\Pages\CreatePackage;
use App\Filament\Resources\PackageResource\Pages\EditPackage;
use App\Filament\Resources\PackageResource\Pages\ListPackages;
use App\Filament\Resources\PackageResource\Pages\ViewPackage;
use App\Filament\Resources\PackageResource\RelationManagers\OrdersRelationManager;
use App\Filament\Widgets\OrderChannelReport;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Order;
use App\Models\Package;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentPackageWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المالية', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_create_and_edit_keep_price_coins_and_channel_contracts(): void
    {
        Livewire::test(CreatePackage::class)->fillForm($this->fields())->call('create')->assertHasNoFormErrors();
        $package = Package::query()->sole();
        self::assertSame('120.00', $package->price);
        self::assertSame(900, $package->coins);
        Livewire::test(EditPackage::class, ['record' => $package->id])->fillForm([
            'price' => 130, 'google_enabled' => true, 'google_product_id' => 'rokn.native.900',
        ])->call('save')->assertHasNoFormErrors();
        self::assertSame('130.00', $package->fresh()->price);
        self::assertTrue($package->fresh()->availableChannels()['google']);
        Livewire::test(ViewPackage::class, ['record' => $package->id])->assertSee('rokn.native.900');
    }

    public function test_channel_business_refusal_appears_on_the_actual_visibility_field(): void
    {
        Livewire::test(CreatePackage::class)->fillForm([...$this->fields(), 'direct_enabled' => false])
            ->call('create')->assertHasFormErrors(['is_active']);
        self::assertSame(0, Package::query()->count());
        Livewire::test(CreatePackage::class)->fillForm([...$this->fields(), 'is_active' => false, 'direct_enabled' => false])
            ->call('create')->assertHasNoFormErrors();
        $package = Package::query()->sole();
        Livewire::test(EditPackage::class, ['record' => $package->id])->fillForm(['is_active' => true])
            ->call('save')->assertHasFormErrors(['is_active']);
        self::assertFalse($package->fresh()->is_active);
    }

    public function test_sku_validation_and_the_issued_identity_owner_are_not_bypassed_by_native_forms(): void
    {
        $package = Package::query()->create([...$this->fields(), 'google_enabled' => true, 'google_product_id' => 'rokn.native.900']);
        Livewire::test(CreatePackage::class)->fillForm([...$this->fields(), 'google_enabled' => true])
            ->call('create')->assertHasFormErrors(['google_product_id' => 'required']);
        Livewire::test(CreatePackage::class)->fillForm([...$this->fields(), 'google_enabled' => true, 'google_product_id' => 'rokn.native.900'])
            ->call('create')->assertHasFormErrors(['google_product_id' => 'unique']);
        Livewire::test(EditPackage::class, ['record' => $package->id])->fillForm(['coins' => 1200])->call('save');
        self::assertSame(900, $package->fresh()->coins);
        Livewire::test(EditPackage::class, ['record' => $package->id])->fillForm([
            'is_active' => false, 'google_enabled' => false, 'direct_enabled' => false,
        ])->call('save')->assertHasNoFormErrors();
        self::assertFalse($package->fresh()->is_active);
        self::assertSame('rokn.native.900', $package->fresh()->getAttribute('google_product_id'));
        Livewire::test(ListPackages::class)->callTableAction('remove', $package->fresh());
        self::assertTrue(Package::query()->whereKey($package->id)->exists());
    }

    public function test_stale_edit_and_stale_delete_confirmation_cannot_remove_a_newer_product(): void
    {
        $package = Package::query()->create($this->fields());
        $editor = Livewire::test(EditPackage::class, ['record' => $package->id]);
        $list = Livewire::test(ListPackages::class)->mountTableAction('remove', $package);
        $package->update(['price' => 150]);
        $editor->fillForm(['price' => 90])->call('save');
        self::assertSame('150.00', $package->fresh()->price);
        $list->callMountedTableAction();
        self::assertTrue(Package::query()->whereKey($package->id)->exists());
        Livewire::test(ListPackages::class)->callTableAction('remove', $package->fresh());
        self::assertFalse(Package::query()->whereKey($package->id)->exists());
    }

    public function test_package_history_and_financial_report_keep_scope_and_currency(): void
    {
        $package = Package::query()->create($this->fields());
        $other = Package::query()->create([...$this->fields(), 'name_ar' => 'حزمة أخرى']);
        $egp = $this->order($package, ['gateway_currency' => 'EGP', 'gateway_gross_amount' => 100, 'gateway_net_amount' => 96]);
        $usd = $this->order($package, ['gateway_currency' => 'USD', 'gateway_gross_amount' => 30, 'gateway_net_amount' => 29]);
        $foreign = $this->order($other, ['gateway_currency' => 'EGP', 'gateway_gross_amount' => 500, 'gateway_net_amount' => 480]);
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $package, 'pageClass' => ViewPackage::class])
            ->assertCanSeeTableRecords([$egp, $usd])->assertCanNotSeeTableRecords([$foreign])
            ->assertSee('96.00 EGP')->assertSee('29.00 USD')
            ->searchTable($foreign->order_ref)->assertCountTableRecords(0);
        $data = Livewire::test(PaymentReport::class, ['filters' => ['package_id' => $package->id]])->instance()->getWidgetData();
        self::assertSame(100.0, $data['report']['stats']['total_amount']);
        Livewire::test(OrderChannelReport::class, $data)->assertSee('96.00')->assertSee('29.00')->assertSee('USD');
        Livewire::test(ListPackages::class)->assertDontSee('125.00')->assertTableColumnExists('orders_count');
    }

    public function test_permissions_and_legacy_bookmarks_preserve_the_current_workspace(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $package = Package::query()->create($this->fields());
        $this->get(route('admin.packages.create'))->assertRedirect(PackageResource::getUrl('create'));
        $this->get(route('admin.packages.edit', $package))->assertRedirect(PackageResource::getUrl('edit', ['record' => $package->id]));
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListPackages::class)->assertForbidden();
        Livewire::test(CreatePackage::class)->assertForbidden();
        Livewire::test(EditPackage::class, ['record' => $package->id])->assertForbidden();
        Livewire::test(OrdersRelationManager::class, ['ownerRecord' => $package, 'pageClass' => ViewPackage::class])->assertForbidden();
    }

    private function fields(): array
    {
        return ['name_ar' => 'حزمة رُكن', 'name_en' => 'Rokn package', 'price' => 120, 'coins' => 900,
            'sort_order' => 100, 'is_active' => true, 'direct_enabled' => true, 'google_enabled' => false, 'apple_enabled' => false];
    }

    private function order(Package $package, array $fields): Order
    {
        return Order::query()->create($fields + [
            'user_id' => auth('web')->id(), 'package_id' => $package->id, 'package_coins' => 900,
            'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY, 'order_ref' => uniqid('PACKAGE-'),
            'amount' => 100, 'final_amount' => 100, 'total_coins' => 900,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now(),
        ]);
    }
}
