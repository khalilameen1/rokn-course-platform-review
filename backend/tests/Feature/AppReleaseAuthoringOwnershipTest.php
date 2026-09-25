<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AppVersionController;
use App\Models\AppVersion;
use App\Services\AppReleaseAuthoringService;
use App\Support\AppVersionEditorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AppReleaseAuthoringOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('app_links.android_package', 'com.rokn');
        Http::preventStrayRequests();
    }

    public function test_creation_shares_build_identity_across_channels_and_completes_receipt_atomically(): void
    {
        $this->forbidController();
        $before = DB::transactionLevel();
        $completed = [];
        $writer = app(AppReleaseAuthoringService::class);
        $play = $writer->create($this->payload(), static function (AppVersion $version) use (&$completed, $before): void {
            self::assertSame($before + 1, DB::transactionLevel());
            self::assertNotNull($version->fresh());
            $completed[] = $version->id;
        });
        self::assertTrue($writer->bootstrapDirect('1.0.60', 61, 'https://rokn.app/downloads/Rokn.apk'));
        self::assertSame([$play->id], $completed);
        self::assertSame(2, AppVersion::query()->where('version_code', 61)->count());
        self::assertSame(['1.0.60'], AppVersion::query()->pluck('version_name')->unique()->values()->all());
        self::assertTrue(DB::table('admin_singleton_locks')->where('lock_key', 'app-release:android')->exists());
        Http::assertNothingSent();
    }

    public function test_new_build_rules_apply_to_dashboard_and_bootstrap_without_http(): void
    {
        $this->forbidController();
        $writer = app(AppReleaseAuthoringService::class);
        $writer->create($this->payload(), static function (): void {});
        $attempts = [
            'duplicate channel' => ['version_code', fn () => $writer->create($this->payload(), static function (): void {})],
            'older other channel' => ['version_code', fn () => $writer->bootstrapDirect('1.0.59', 60, 'https://rokn.app/downloads/Rokn.apk')],
            'same build different name' => ['version_name', fn () => $writer->bootstrapDirect('1.0.61', 61, 'https://rokn.app/downloads/Rokn.apk')],
        ];
        foreach ($attempts as $label => [$field, $attempt]) {
            try {
                $attempt();
                self::fail($label.' must be rejected.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey($field, $error->errors(), $label);
            }
        }
        self::assertSame(1, AppVersion::query()->count());
    }

    public function test_update_preserves_identity_and_all_mutations_reject_stale_editors(): void
    {
        $this->forbidController();
        $writer = app(AppReleaseAuthoringService::class);
        $version = $writer->create($this->payload(), static function (): void {});
        $editor = AppVersionEditorVersion::for($version->fresh());
        try {
            $writer->update((int) $version->id, [...$this->payload(), 'version_code' => 62], $editor);
            self::fail('A persisted release identity must not be rewritten.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('version_name', $error->errors());
        }
        $writer->update((int) $version->id, [...$this->payload(), 'release_notes_ar' => 'إصلاحات جديدة'], $editor);
        foreach (['update', 'delete', 'toggle'] as $operation) {
            try {
                match ($operation) {
                    'update' => $writer->update((int) $version->id, $this->payload(), $editor),
                    'delete' => $writer->delete((int) $version->id, $editor),
                    'toggle' => $writer->toggleActive((int) $version->id, $editor),
                };
                self::fail('Stale '.$operation.' must not overwrite the current release.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('editor_version', $error->errors());
            }
        }
        self::assertSame(61, $version->fresh()->version_code);
        self::assertSame('إصلاحات جديدة', $version->fresh()->release_notes_ar);
        self::assertTrue($version->fresh()->is_active);
    }

    public function test_activation_and_deletion_require_usable_and_inactive_release_respectively(): void
    {
        $this->forbidController();
        $writer = app(AppReleaseAuthoringService::class);
        $version = $writer->create([...$this->payload(), 'is_active' => false, 'download_url' => null], static function (): void {});
        self::assertFalse($writer->toggleActive((int) $version->id, AppVersionEditorVersion::for($version->fresh())));
        self::assertFalse($version->fresh()->is_active);
        $writer->update((int) $version->id, [...$this->payload(), 'is_active' => false], AppVersionEditorVersion::for($version->fresh()));
        self::assertTrue($writer->toggleActive((int) $version->id, AppVersionEditorVersion::for($version->fresh())));
        self::assertFalse($writer->delete((int) $version->id, AppVersionEditorVersion::for($version->fresh())));
        self::assertTrue($writer->toggleActive((int) $version->id, AppVersionEditorVersion::for($version->fresh())));
        self::assertTrue($writer->delete((int) $version->id, AppVersionEditorVersion::for($version->fresh())));
        self::assertNull(AppVersion::find($version->id));

        $legacy = AppVersion::query()->create([...$this->payload(), 'distribution_channel' => null, 'is_active' => false]);
        self::assertFalse($writer->toggleActive((int) $legacy->id, AppVersionEditorVersion::for($legacy->fresh())));
    }

    public function test_receipt_failure_rolls_back_release_and_platform_lock_creation(): void
    {
        $this->forbidController();
        try {
            app(AppReleaseAuthoringService::class)->create($this->payload(), static function (): never {
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'release-receipt']);
                throw new \RuntimeException('Receipt failed');
            });
            self::fail('The release must not survive a failed receipt.');
        } catch (\RuntimeException $error) {
            self::assertSame('Receipt failed', $error->getMessage());
        }
        self::assertSame(0, AppVersion::query()->count());
        self::assertFalse(DB::table('admin_singleton_locks')->whereIn('lock_key', ['release-receipt', 'app-release:android'])->exists());
    }

    public function test_bootstrap_is_idempotent_but_never_reactivates_an_existing_release(): void
    {
        $this->forbidController();
        $writer = app(AppReleaseAuthoringService::class);
        self::assertTrue($writer->bootstrapDirect('1.0.60', 61, 'https://rokn.app/downloads/Rokn.apk'));
        self::assertFalse($writer->bootstrapDirect('1.0.60', 61, 'https://rokn.app/downloads/Rokn.apk'));
        $version = AppVersion::query()->sole();
        $writer->toggleActive((int) $version->id, AppVersionEditorVersion::for($version));
        try {
            $writer->bootstrapDirect('1.0.60', 61, 'https://rokn.app/downloads/Rokn.apk');
            self::fail('Bootstrap cannot override an explicit deactivation.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('version_code', $error->errors());
        }
        self::assertSame(1, AppVersion::query()->count());
        self::assertFalse($version->fresh()->is_active);
    }

    public function test_ios_build_sequence_is_independent_and_broken_forced_update_is_rejected(): void
    {
        $this->forbidController();
        $writer = app(AppReleaseAuthoringService::class);
        $writer->create($this->payload(), static function (): void {});
        $ios = [...$this->payload(), 'platform' => 'ios', 'distribution_channel' => 'appstore',
            'version_code' => null, 'build_number' => 1, 'download_url' => 'https://apps.apple.com/app/id123456789'];
        $version = $writer->create($ios, static function (): void {});
        self::assertSame(1, $version->fresh()->build_number);
        self::assertNull($version->fresh()->version_code);
        try {
            $writer->update((int) $version->id, [...$ios, 'is_force_update' => true, 'download_url' => null], AppVersionEditorVersion::for($version->fresh()));
            self::fail('A forced update must have a usable store link.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('download_url', $error->errors());
        }
        self::assertFalse($version->fresh()->is_force_update);
        self::assertSame($ios['download_url'], $version->fresh()->download_url);
    }

    public function test_dashboard_normalizes_platform_fields_and_delegates_identity_rules(): void
    {
        $this->withoutMiddleware();
        $this->post(route('admin.app-versions.store'), [...$this->payload(),
            'authoring_request_id' => (string) Str::uuid(), 'build_number' => 99,
        ])->assertRedirect(route('admin.app-versions.index'))->assertSessionHasNoErrors();
        $version = AppVersion::query()->sole();
        self::assertNull($version->build_number);
        $this->put(route('admin.app-versions.update', $version->id), [...$this->payload(),
            'editor_version' => AppVersionEditorVersion::for($version), 'version_code' => 62,
        ])->assertSessionHasErrors('version_name');
        self::assertSame(61, $version->fresh()->version_code);
    }

    private function payload(): array
    {
        return ['platform' => 'android', 'distribution_channel' => 'play', 'version_name' => '1.0.60',
            'version_code' => 61, 'build_number' => null, 'is_active' => true, 'is_force_update' => false,
            'download_url' => 'https://play.google.com/store/apps/details?id=com.rokn'];
    }

    private function forbidController(): void
    {
        $this->app->bind(AppVersionController::class, static function (): never {
            throw new \LogicException('Release authoring must not resolve its HTTP adapter.');
        });
    }
}
