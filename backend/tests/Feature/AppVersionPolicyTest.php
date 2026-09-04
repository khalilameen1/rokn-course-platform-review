<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AppReleasePolicyService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class AppVersionPolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware();

        Schema::create('app_versions', function (Blueprint $table): void {
            $table->id();
            $table->string('platform');
            $table->string('distribution_channel')->nullable();
            $table->string('version_name');
            $table->integer('version_code')->nullable();
            $table->integer('build_number')->nullable();
            $table->boolean('is_force_update')->default(false);
            $table->boolean('is_active')->default(true);
            $table->text('update_message_ar')->nullable();
            $table->text('update_message_en')->nullable();
            $table->string('download_url')->nullable();
            $table->text('release_notes_ar')->nullable();
            $table->text('release_notes_en')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('app_versions');
        parent::tearDown();
    }

    public function test_no_active_version_is_a_valid_no_update_response(): void
    {
        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 10,
        ])->assertOk()
            ->assertJsonPath('data.update_required', false)
            ->assertJsonPath('data.is_force_update', false)
            ->assertJsonPath('data.policy_configured', false)
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_android_uses_numeric_version_code_and_detects_any_forced_hop(): void
    {
        $this->insertVersion('android', '1.1.0', 11, null, true, 'play');
        $this->insertVersion('android', '1.2.0', 12, null, false, 'play');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 10,
            'distribution_channel' => 'play',
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', true)
            ->assertJsonPath('data.latest_version_code', 12)
            ->assertJsonPath('data.latest_version', '1.2.0');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 12,
            'distribution_channel' => 'play',
        ])->assertOk()->assertJsonPath('data.update_required', false);
    }

    public function test_ios_build_number_is_authoritative_even_when_marketing_versions_disagree(): void
    {
        $this->insertVersion('ios', '9.0.0', null, 39, false, 'appstore');
        $this->insertVersion('ios', '1.9.0', null, 40, false, 'appstore');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'ios',
            'version' => '9.0.0',
            'build_number' => 39,
            'distribution_channel' => 'appstore',
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', false)
            ->assertJsonPath('data.latest_build_number', 40)
            ->assertJsonPath('data.latest_version', '1.9.0');
    }

    public function test_ios_old_clients_fall_back_to_semantic_marketing_version(): void
    {
        $this->insertVersion('ios', '1.2.0', null, 20, true);

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'ios',
            'version' => '1.1.0',
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', true);
    }

    public function test_invalid_platform_specific_versions_are_rejected(): void
    {
        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => '1.2.3',
        ])->assertUnprocessable()->assertJsonValidationErrors('version');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'ios',
            'version' => 'latest',
            'build_number' => 0,
        ])->assertUnprocessable()->assertJsonValidationErrors(['version', 'build_number']);
    }

    public function test_android_channels_never_leak_release_urls_into_each_other(): void
    {
        $this->insertVersion('android', '4.0.0', 40, null, false, 'play');
        $this->insertVersion('android', '5.0.0', 50, null, true, 'direct');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 39,
            'distribution_channel' => 'play',
        ])->assertOk()
            ->assertJsonPath('data.latest_version_code', 40)
            ->assertJsonPath('data.distribution_channel', 'play')
            ->assertJsonPath(
                'data.download_url',
                'https://play.google.com/store/apps/details?id=com.rokn',
            );

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 39,
            'distribution_channel' => 'direct',
        ])->assertOk()
            ->assertJsonPath('data.latest_version_code', 50)
            ->assertJsonPath('data.distribution_channel', 'direct')
            ->assertJsonPath('data.download_url', 'https://rokn.app/downloads/Rokn.apk');
    }

    public function test_android_client_without_channel_prefers_legacy_then_safe_play_fallback(): void
    {
        $this->insertVersion('android', '3.0.0', 30, null, false, 'play');
        $this->insertVersion('android', '4.0.0', 40, null, true, 'direct');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 20,
        ])->assertOk()
            ->assertJsonPath('data.latest_version_code', 30)
            ->assertJsonPath('data.distribution_channel', 'play')
            ->assertJsonPath('data.download_url', 'https://play.google.com/store/apps/details?id=com.rokn');

        $this->insertVersion('android', '2.5.0', 25, null, false, null);

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 20,
        ])->assertOk()
            ->assertJsonPath('data.latest_version_code', 25)
            ->assertJsonPath('data.distribution_channel', null);
    }

    public function test_contract_incompatibility_forces_only_an_actionable_newer_release(): void
    {
        config()->set('mobile_contract.minimum_supported_version', 2);
        config()->set('mobile_contract.required_capabilities', ['app_update_policy_v2']);
        $this->insertVersion('android', '3.1.0', 31, null, false, 'play');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 30,
            'distribution_channel' => 'play',
            'api_contract_version' => 1,
            'capabilities' => [],
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', true)
            ->assertJsonPath('data.client_compatible', false)
            ->assertJsonPath('data.api_contract.client_version', 1)
            ->assertJsonPath('data.api_contract.missing_capabilities.0', 'app_update_policy_v2');

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 31,
            'distribution_channel' => 'play',
            'api_contract_version' => 1,
            'capabilities' => [],
        ])->assertOk()
            ->assertJsonPath('data.update_required', false)
            ->assertJsonPath('data.is_force_update', false)
            ->assertJsonPath('data.client_compatible', false);
    }

    public function test_invalid_active_release_can_never_trap_clients_behind_a_broken_update_link(): void
    {
        $this->insertVersion('android', '3.1.0', 31, null, false, 'play');
        DB::table('app_versions')->insert([
            'platform' => 'android',
            'distribution_channel' => 'play',
            'version_name' => '9.0.0',
            'version_code' => 90,
            'build_number' => null,
            'is_force_update' => true,
            'is_active' => true,
            'download_url' => 'https://example.invalid/not-rokn',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 30,
            'distribution_channel' => 'play',
        ])->assertOk()
            ->assertJsonPath('data.update_required', true)
            ->assertJsonPath('data.is_force_update', false)
            ->assertJsonPath('data.latest_version_code', 31)
            ->assertJsonPath(
                'data.download_url',
                'https://play.google.com/store/apps/details?id=com.rokn',
            );

        DB::table('app_versions')->where('version_code', 31)->delete();

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 30,
            'distribution_channel' => 'play',
        ])->assertOk()
            ->assertJsonPath('data.update_required', false)
            ->assertJsonPath('data.is_force_update', false)
            ->assertJsonPath('data.policy_configured', false)
            ->assertJsonMissingPath('data.download_url');
    }

    public function test_invalid_channel_row_falls_back_to_a_valid_legacy_release(): void
    {
        DB::table('app_versions')->insert([
            [
                'platform' => 'android',
                'distribution_channel' => 'play',
                'version_name' => '9.0.0',
                'version_code' => 90,
                'build_number' => null,
                'is_force_update' => true,
                'is_active' => true,
                'download_url' => 'https://example.invalid/not-rokn',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'platform' => 'android',
                'distribution_channel' => null,
                'version_name' => '3.2.0',
                'version_code' => 32,
                'build_number' => null,
                'is_force_update' => false,
                'is_active' => true,
                'download_url' => 'https://play.google.com/store/apps/details?id=com.rokn',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->postJson('/api/v1/app/check-version', [
            'platform' => 'android',
            'version' => 30,
            'distribution_channel' => 'play',
        ])->assertOk()
            ->assertJsonPath('data.latest_version_code', 32)
            ->assertJsonPath('data.is_force_update', false)
            ->assertJsonPath('data.download_url', 'https://play.google.com/store/apps/details?id=com.rokn');
    }

    public function test_launch_readiness_requires_an_active_valid_release_for_each_declared_channel(): void
    {
        config()->set('mobile_contract.launch_channels', ['direct']);
        $policy = app(AppReleasePolicyService::class);

        $this->assertFalse($policy->launchReadiness()['ready']);
        $this->insertVersion('android', '3.1.0', 31, null, false, 'direct');

        $readiness = $policy->launchReadiness();
        $this->assertTrue($readiness['ready']);
        $this->assertTrue($readiness['channels']['direct']['ready']);
    }

    public function test_direct_release_bootstrap_is_validated_active_and_idempotent(): void
    {
        config([
            'app.env' => 'production',
            'mobile_contract.launch_channels' => ['direct'],
        ]);
        $arguments = [
            '--version-name' => '1.0.0',
            '--version-code' => 1,
            '--download-url' => 'https://rokn.app/downloads/Rokn-direct.apk',
            '--activate' => true,
        ];

        $this->artisan('app-release:bootstrap-direct', $arguments)->assertSuccessful();
        $this->artisan('app-release:bootstrap-direct', $arguments)->assertSuccessful();

        $this->assertDatabaseCount('app_versions', 1);
        $this->assertDatabaseHas('app_versions', [
            'platform' => 'android',
            'distribution_channel' => 'direct',
            'version_name' => '1.0.0',
            'version_code' => 1,
            'is_active' => true,
            'is_force_update' => false,
            'download_url' => 'https://rokn.app/downloads/Rokn-direct.apk',
        ]);
        self::assertTrue(app(AppReleasePolicyService::class)->launchReadiness()['ready']);
    }

    public function test_direct_release_bootstrap_never_overwrites_or_downgrades(): void
    {
        config([
            'app.env' => 'production',
            'mobile_contract.launch_channels' => ['direct'],
        ]);
        $this->insertVersion('android', '2.0.0', 20, null, false, 'direct');

        $this->artisan('app-release:bootstrap-direct', [
            '--version-name' => '1.9.0',
            '--version-code' => 19,
            '--download-url' => 'https://rokn.app/downloads/Rokn-old.apk',
            '--activate' => true,
        ])->assertExitCode(1);
        $this->artisan('app-release:bootstrap-direct', [
            '--version-name' => '2.1.0',
            '--version-code' => 21,
            '--download-url' => 'https://example.com/Rokn.apk',
            '--activate' => true,
        ])->assertExitCode(2);

        $this->assertDatabaseCount('app_versions', 1);
        $this->assertDatabaseHas('app_versions', [
            'distribution_channel' => 'direct',
            'version_code' => 20,
            'version_name' => '2.0.0',
        ]);
    }

    private function insertVersion(
        string $platform,
        string $name,
        ?int $code,
        ?int $build,
        bool $force,
        ?string $channel = null,
    ): void {
        DB::table('app_versions')->insert([
            'platform' => $platform,
            'distribution_channel' => $channel,
            'version_name' => $name,
            'version_code' => $code,
            'build_number' => $build,
            'is_force_update' => $force,
            'is_active' => true,
            'download_url' => match ($channel) {
                'appstore' => 'https://apps.apple.com/app/id123456789',
                'direct' => 'https://rokn.app/downloads/Rokn.apk',
                default => $platform === 'ios'
                    ? 'https://apps.apple.com/app/id123456789'
                    : 'https://play.google.com/store/apps/details?id=com.rokn',
            },
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
