<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ProductFeatureSwitches;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\ProductFeatureFlag;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\AdminProductFeatureAuthoringService;
use App\Services\ProductFeatureFlagService;
use App\Support\BusinessClock;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FilamentProductFeatureSwitchesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'التشغيل', 'email' => 'ops@rokn.test', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_reading_declared_switches_does_not_create_rows(): void
    {
        $this->get(ProductFeatureSwitches::getUrl())->assertOk()->assertSee('مفاتيح تشغيل المنتج');
        self::assertSame(0, ProductFeatureFlag::query()->count());
        Livewire::test(ProductFeatureSwitches::class)->assertSee('تشغيل الفيديو')->assertSee('رفع المشاريع');
        self::assertSame(0, ProductFeatureFlag::query()->count());
    }

    public function test_native_decision_uses_existing_owner_and_cairo_time_then_updates_effective_state(): void
    {
        $localExpiry = BusinessClock::utcNow()->addDay()->setTimezone(BusinessClock::timezoneName())->format('Y-m-d\TH:i');
        Livewire::test(ProductFeatureSwitches::class)->callTableAction('decide', 'checkout', [
            'enabled' => false, 'rollout_percentage' => 0, 'reason' => 'Payment provider maintenance', 'expires_at' => $localExpiry,
        ])->assertHasNoActionErrors()->assertNotified('تم تطبيق قرار التشغيل');
        $flag = ProductFeatureFlag::query()->where('key', 'checkout')->sole();
        self::assertFalse($flag->enabled);
        self::assertSame('ops@rokn.test', $flag->owner);
        self::assertSame(BusinessClock::localInputToUtc($localExpiry)->format('Y-m-d H:i'), $flag->expires_at->format('Y-m-d H:i'));
        self::assertFalse(app(ProductFeatureFlagService::class)->enabled('checkout', 'user:1'));
    }

    public function test_invalid_decisions_stay_in_the_modal_without_writes(): void
    {
        Livewire::test(ProductFeatureSwitches::class)->callTableAction('decide', 'ai_chat', [
            'enabled' => true, 'rollout_percentage' => 101, 'reason' => 'قصير',
        ])->assertHasActionErrors(['rollout_percentage', 'reason']);
        Livewire::test(ProductFeatureSwitches::class)->callTableAction('decide', 'ai_chat', [
            'enabled' => true, 'rollout_percentage' => 100, 'reason' => 'A valid explanation', 'expires_at' => '2000-01-01T12:00',
        ])->assertHasActionErrors(['expires_at']);
        self::assertSame(0, ProductFeatureFlag::query()->count());
    }

    public function test_open_modal_cannot_reverse_a_newer_decision_even_after_polling(): void
    {
        $page = Livewire::test(ProductFeatureSwitches::class)->mountTableAction('decide', 'checkout');
        $service = app(ProductFeatureFlagService::class);
        app(AdminProductFeatureAuthoringService::class)->update('checkout', [
            'enabled' => false, 'rollout_percentage' => 0, 'reason' => 'New operations decision',
            'editor_version' => $service->editorVersion('checkout', null),
        ], 'other@rokn.test');
        $page->call('$refresh')->setTableActionData(['enabled' => true, 'rollout_percentage' => 100, 'reason' => 'Stale operations decision'])
            ->callMountedTableAction()->assertNotified('تغيّر قرار التشغيل');
        self::assertFalse($service->enabled('checkout', 'user:1'));
        self::assertSame('other@rokn.test', ProductFeatureFlag::query()->where('key', 'checkout')->sole()->owner);
    }

    public function test_operations_reader_can_read_but_cannot_mount_or_submit_decisions(): void
    {
        $reader = User::query()->forceCreate(['role' => 'moderator', 'active' => true]);
        $member = StaffMember::query()->create(['user_id' => $reader->id]);
        $member->givePermissionTo(Permission::findOrCreate('operations.read', 'web'));
        $this->actingAs($reader, 'web');
        Livewire::test(ProductFeatureSwitches::class)->assertOk()->assertTableActionHidden('decide', 'checkout');
        $this->post(route('admin.product-operations.features.update', 'checkout'), [])->assertForbidden();
        self::assertSame(0, ProductFeatureFlag::query()->count());
    }

    public function test_unassigned_content_operator_cannot_open_switches(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        $this->get(ProductFeatureSwitches::getUrl())->assertForbidden();
    }
}
