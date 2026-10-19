<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\AppSettings;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\DesignSetting;
use App\Models\Setting;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentAppSettingsWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Http::preventStrayRequests();
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_read_does_not_create_singletons_or_expose_saved_secrets_and_old_editor_redirects(): void
    {
        Livewire::test(AppSettings::class)->assertFormFieldExists('youtube_url')->assertSet('data.bunny_api_key', null);
        self::assertSame(0, Setting::query()->count());
        self::assertSame(0, DesignSetting::query()->count());
        $setting = Setting::query()->create(['bunny_api_key_secret' => 'private-test-key']);
        $page = Livewire::test(AppSettings::class)->assertSet('data.bunny_api_key', null)->assertDontSee('private-test-key');
        self::assertStringNotContainsString('private-test-key', json_encode($page->get('data')));
        self::assertSame('private-test-key', $setting->fresh()->bunny_api_key_secret);
        $this->get(route('admin.settings'))->assertRedirect(AppSettings::getUrl());
    }

    public function test_save_normalizes_links_preserves_blank_keys_and_rotates_version_for_a_second_save(): void
    {
        $setting = Setting::query()->create(['bunny_api_key_secret' => 'original-key']);
        $page = Livewire::test(AppSettings::class);
        $version = $page->get('editorVersion');
        $page->fillForm(['site_name_ar' => 'رُكن', 'youtube_url' => 'https://www.youtube.com/@rokn', 'support_whatsapp_url' => '+201001234567',
            'bunny_api_key' => '', 'bunny_storage_password' => 'replacement-key'])->call('save')->assertHasNoFormErrors()->assertNotified('تم الحفظ');
        self::assertNotSame($version, $page->get('editorVersion'));
        self::assertSame('original-key', $setting->fresh()->bunny_api_key_secret);
        self::assertSame('replacement-key', $setting->fresh()->bunny_storage_password_secret);
        $page->assertSet('data.bunny_storage_password', null)->fillForm(['phone' => '+201234567890'])->call('save')->assertHasNoFormErrors();
        self::assertSame('+201234567890', $setting->fresh()->phone);
        self::assertSame('https://wa.me/201001234567', $setting->fresh()->support_whatsapp_url);
        self::assertStringContainsString('youtube.com/@rokn', DesignSetting::query()->firstOrFail()->youtube_url);
        Http::assertNothingSent();
    }

    public function test_stale_settings_and_domain_link_errors_are_visible_without_partial_save(): void
    {
        $page = Livewire::test(AppSettings::class)->fillForm(['youtube_url' => 'https://example.test/not-youtube']);
        $page->call('save')->assertHasFormErrors(['youtube_url']);
        self::assertSame(0, Setting::query()->count());
        $page->fillForm(['youtube_url' => null]);
        Setting::query()->create(['site_name_ar' => 'نسخة أحدث']);
        $page->call('save')->assertNotified('تغيّرت إعدادات التطبيق');
        self::assertSame('نسخة أحدث', Setting::query()->firstOrFail()->site_name_ar);
    }

    public function test_tier_ceilings_and_semantic_chat_rules_are_not_bypassed_by_the_native_form(): void
    {
        $page = Livewire::test(AppSettings::class)->fillForm(['ai_plan_policy.guided.chat_message_limit' => 999]);
        $page->call('save')->assertHasFormErrors(['ai_plan_policy.guided.chat_message_limit' => 'max']);
        $page->fillForm(['ai_plan_policy.guided.chat_message_limit' => 0, 'ai_plan_policy.guided.chat_enabled' => true]);
        $page->call('save')->assertHasFormErrors(['ai_plan_policy.guided.chat_message_limit']);
        self::assertSame(0, Setting::query()->count());
    }

    public function test_explicit_connection_test_uses_saved_key_without_returning_it_or_changing_settings(): void
    {
        config(['bunny.stream_api_key' => null, 'bunny.library_id' => null]);
        $setting = Setting::query()->create(['bunny_api_key_secret' => 'private-test-key', 'bunny_library_id' => '123']);
        $before = $setting->fresh()->getRawOriginal();
        Http::fake(['https://video.bunnycdn.com/library/123' => Http::response(['Name' => 'Test library'])]);
        Livewire::test(AppSettings::class)->callAction('testBunny', [])->assertHasNoActionErrors()->assertNotified('تم الاتصال بنجاح')
            ->assertSet('data.bunny_api_key', null)->assertDontSee('private-test-key');
        Http::assertSent(fn ($request): bool => $request->hasHeader('AccessKey', 'private-test-key'));
        self::assertSame($before, $setting->fresh()->getRawOriginal());
    }

    public function test_moderator_is_denied_both_settings_and_connection_test(): void
    {
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المحرر', 'role' => 'moderator', 'active' => true]), 'web');
        $this->get(AppSettings::getUrl())->assertForbidden();
        self::assertFalse(AppSettings::canAccess());
    }
}
