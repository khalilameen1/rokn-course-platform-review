<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\DesignSetting;
use App\Models\Setting;
use App\Models\User;
use App\Services\AdminAppSettingsAuthoringService;
use App\Services\DeviceLoginService;
use App\Support\AppSettingsEditorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminAppSettingsAuthoringOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_writer_normalizes_public_links_and_preserves_empty_submitted_secrets(): void
    {
        $this->forbidController();
        $settings = Setting::query()->firstOrCreate([]);
        $settings->forceFill(['bunny_api_key_secret' => 'existing-test-key'])->save();
        app(AdminAppSettingsAuthoringService::class)->update([
            'editor_version' => $this->version(),
            'site_name_ar' => 'رُكن',
            'support_whatsapp_url' => '+201001234567',
            'youtube_url' => 'https://www.youtube.com/@rokn',
            'bunny_api_key' => '',
            'bunny_storage_password' => 'new-test-storage-key',
        ]);
        $settings->refresh();
        self::assertSame('رُكن', $settings->site_name_ar);
        self::assertSame('https://wa.me/201001234567', $settings->support_whatsapp_url);
        self::assertSame('existing-test-key', $settings->bunny_api_key_secret);
        self::assertSame('new-test-storage-key', $settings->bunny_storage_password_secret);
        self::assertNotSame('new-test-storage-key', $settings->getRawOriginal('bunny_storage_password_secret'));
        self::assertStringContainsString('youtube.com/@rokn', DesignSetting::query()->firstOrFail()->youtube_url);
        Http::assertNothingSent();
    }

    public function test_stale_editor_cannot_overwrite_new_settings_or_design_fields(): void
    {
        $this->forbidController();
        $stale = $this->version();
        $writer = app(AdminAppSettingsAuthoringService::class);
        $writer->update(['editor_version' => $stale, 'site_name_ar' => 'اسم حديث', 'youtube_url' => 'https://www.youtube.com/@rokn']);
        try {
            $writer->update(['editor_version' => $stale, 'site_name_ar' => 'اسم قديم', 'youtube_url' => null]);
            self::fail('Stale settings must not overwrite either singleton.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame('اسم حديث', Setting::query()->firstOrFail()->site_name_ar);
        self::assertNotNull(DesignSetting::query()->firstOrFail()->youtube_url);
    }

    public function test_outer_rollback_restores_settings_design_and_device_locks_together(): void
    {
        $this->forbidController();
        $settings = Setting::query()->firstOrCreate([]);
        $settings->forceFill(['device_login_policy' => 'single_device'])->save();
        $user = User::query()->forceCreate([
            'name' => 'Student', 'email' => 'settings-device@rokn.test', 'role' => 'client',
            'active' => true, 'password' => 'test-only', 'locked_device_id' => 'device-one',
        ]);
        $version = $this->version();
        $beforeDesign = DesignSetting::query()->count();
        try {
            DB::transaction(function () use ($user, $version): void {
                app(AdminAppSettingsAuthoringService::class)->update([
                    'editor_version' => $version,
                    'device_login_policy' => DeviceLoginService::POLICY_MULTIPLE,
                    'youtube_url' => 'https://www.youtube.com/@rokn',
                ]);
                self::assertNull($user->fresh()->locked_device_id);
                throw new \RuntimeException('outer operation failed');
            });
            self::fail('The enclosing operation must roll back.');
        } catch (\RuntimeException $error) {
            self::assertSame('outer operation failed', $error->getMessage());
        }
        self::assertSame('device-one', $user->fresh()->locked_device_id);
        self::assertSame('single_device', $settings->fresh()->device_login_policy);
        self::assertSame($beforeDesign, DesignSetting::query()->count());
        self::assertSame($version, $this->version());
    }

    public function test_incomplete_ai_policy_is_a_validation_error_without_partial_save(): void
    {
        $this->forbidController();
        $version = $this->version();
        try {
            app(AdminAppSettingsAuthoringService::class)->update([
                'editor_version' => $version, 'site_name_ar' => 'لا يحفظ', 'ai_plan_policy' => [],
            ]);
            self::fail('The policy must contain every subscription tier.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('ai_plan_policy', $error->errors());
        }
        self::assertSame($version, $this->version());
    }

    public function test_domain_validation_errors_do_not_flash_provider_keys_into_old_input(): void
    {
        $admin = User::query()->forceCreate([
            'name' => 'Admin', 'email' => 'settings-owner@rokn.test', 'role' => 'admin',
            'active' => true, 'password' => 'test-only',
        ]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin)->from(route('admin.settings'))->post(route('admin.settings.update'), [
            'editor_version' => $this->version(), 'direct_checkout_discount_percent' => 10,
            'youtube_url' => 'https://example.test/not-a-youtube-account',
            'bunny_api_key' => 'test-key-must-not-flash',
            'bunny_storage_password' => 'test-password-must-not-flash',
            'bunny_security_key' => 'test-security-key-must-not-flash',
        ])->assertRedirect(route('admin.settings'))->assertSessionHasErrors('youtube_url');

        $old = (array) session()->get('_old_input', []);
        self::assertArrayNotHasKey('bunny_api_key', $old);
        self::assertArrayNotHasKey('bunny_storage_password', $old);
        self::assertArrayNotHasKey('bunny_security_key', $old);
        self::assertSame('https://example.test/not-a-youtube-account', $old['youtube_url']);
    }

    private function version(): string
    {
        return AppSettingsEditorVersion::for(Setting::query()->first() ?? new Setting(), DesignSetting::getDefaultSettings());
    }

    private function forbidController(): void
    {
        Http::preventStrayRequests();
        $this->app->bind(SettingsController::class, static function (): never {
            throw new \LogicException('Settings writer must not resolve the HTTP adapter.');
        });
    }
}
