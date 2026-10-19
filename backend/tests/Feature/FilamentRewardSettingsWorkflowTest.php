<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\RewardSettings;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\StaffMember;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FilamentRewardSettingsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_save_preserves_legacy_copy_and_unrelated_settings_and_refreshes_public_projection(): void
    {
        $setting = Setting::query()->firstOrCreate([]);
        $setting->update(['how_to_use_coins_ar' => 'النص القديم', 'site_name_ar' => 'اسم ركن المعتمد',
            'bunny_api_key_secret' => 'private-test-only']);
        Setting::runtimeConfig();
        $page = Livewire::test(RewardSettings::class)->fillForm(['rewards_help_ar' => 'شرح المكافآت الجديد',
            'recommended_provider_bonus_coins' => 5, 'recommended_provider_badge_ar' => 'تسجيل أسرع'])->call('save')->assertHasNoFormErrors();
        $setting->refresh();
        self::assertSame('شرح المكافآت الجديد', $setting->rewards_help_ar);
        self::assertSame('النص القديم', $setting->how_to_use_coins_ar);
        self::assertSame('اسم ركن المعتمد', $setting->site_name_ar);
        self::assertSame('private-test-only', $setting->bunny_api_key_secret);
        self::assertArrayNotHasKey('bunny_api_key_secret', $page->get('data'));
        self::assertSame(5, Setting::runtimeConfig()['recommended_provider_bonus_coins']);
        self::assertSame('شرح المكافآت الجديد', Setting::runtimeConfig()['rewards_help_ar']);
        $page->assertDontSee('private-test-only');
    }

    public function test_missing_settings_are_read_without_writing_then_saved_through_the_singleton_owner(): void
    {
        Setting::query()->delete();
        $page = Livewire::test(RewardSettings::class);
        self::assertSame(0, Setting::query()->count());
        $page->fillForm(['rewards_help_ar' => 'شرح جديد'])->call('save')->assertHasNoFormErrors();
        self::assertSame('شرح جديد', Setting::query()->sole()->rewards_help_ar);
    }

    public function test_validation_caps_and_stale_settings_fail_visibly_without_overwriting(): void
    {
        $setting = Setting::query()->firstOrCreate([]);
        $page = Livewire::test(RewardSettings::class)->fillForm(['rewards_help_ar' => str_repeat('أ', 601)])
            ->call('save')->assertHasFormErrors(['rewards_help_ar' => 'max']);
        $page->fillForm(['rewards_help_ar' => 'مقبول', 'max_course_promotion_percent' => 21])
            ->call('save')->assertHasFormErrors(['max_course_promotion_percent' => 'max']);
        $stale = Livewire::test(RewardSettings::class);
        $setting->update(['rewards_help_ar' => 'النص الأحدث']);
        $stale->fillForm(['rewards_help_ar' => 'قديم'])->call('save');
        self::assertSame('النص الأحدث', $setting->fresh()->rewards_help_ar);
    }

    public function test_balance_cap_must_fund_the_active_welcome_offer_and_tasks(): void
    {
        $welcome = RewardRule::query()->where('event_key', 'welcome_bonus')->firstOrFail();
        $welcome->update(['coins_amount' => 60, 'is_active' => true]);
        Livewire::test(RewardSettings::class)->fillForm(['reward_balance_cap' => 70, 'recommended_provider_bonus_coins' => 20])
            ->call('save')->assertHasFormErrors(['recommended_provider_bonus_coins']);
        RewardRule::query()->update(['is_active' => false]);
        CoinEarningMethod::query()->update(['is_active' => false]);
        CoinEarningMethod::query()->create(['title_ar' => 'مهمة قائمة', 'title_en' => 'Live task', 'coins_amount' => 50,
            'action_key' => 'settings_test', 'is_active' => true, 'requires_external_visit' => false]);
        Livewire::test(RewardSettings::class)->fillForm(['reward_balance_cap' => 40])
            ->call('save')->assertHasFormErrors(['reward_balance_cap']);
    }

    public function test_legacy_bookmark_and_moderator_authorization(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->get(route('admin.coin-earning-methods.index'))->assertRedirect(RewardSettings::getUrl());
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(RewardSettings::class)->assertForbidden();
    }

    public function test_read_only_reward_operator_cannot_submit_settings(): void
    {
        $reader = User::query()->forceCreate(['role' => 'moderator', 'active' => true]);
        $member = StaffMember::query()->create(['user_id' => $reader->id]);
        $member->givePermissionTo(Permission::findOrCreate('rewards.read', 'web'));
        $this->actingAs($reader, 'web');
        $page = Livewire::test(RewardSettings::class)->assertOk()->assertDontSee('حفظ التغييرات');
        $page->call('save')->assertForbidden();
    }
}
