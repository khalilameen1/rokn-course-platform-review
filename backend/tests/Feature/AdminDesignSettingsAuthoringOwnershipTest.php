<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\DesignSettingController;
use App\Models\AccountFileDeletion;
use App\Models\DesignSetting;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminDesignSettingsAuthoringService;
use App\Services\PublicAppSettingsService;
use App\Support\DesignSettingsEditorVersion;
use App\Support\PublicDiskUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminDesignSettingsAuthoringOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        config(['app.url' => 'https://localhost', 'filesystems.disks.public.url' => 'https://localhost/storage']);
        Storage::fake('public', ['url' => 'https://localhost/storage']);
        Http::preventStrayRequests();
        Queue::fake();
        foreach ([DesignSettingController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Identity authoring cannot resolve HTTP adapters.');
            });
        }
    }

    public function test_singleton_creation_images_and_receipt_commit_together(): void
    {
        $settings = app(AdminDesignSettingsAuthoringService::class)->save($this->fields(), [
            'logo_file' => UploadedFile::fake()->image('logo.png'),
            'coin_image_file' => UploadedFile::fake()->image('coin.png'),
            'badge_junior_image_file' => UploadedFile::fake()->image('junior.png'),
        ], $this->version(), static function (DesignSetting $settings): void {
            self::assertSame(1, DB::transactionLevel());
            self::assertSame(1, DesignSetting::query()->count());
            self::assertNotNull($settings->logo_url);
            self::assertNotNull($settings->coin_image_url);
            self::assertNotNull($settings->badge_junior_image_url);
            DB::table('admin_singleton_locks')->insert(['lock_key' => 'design-receipt']);
        });
        self::assertSame('رُكن', $settings->name_ar);
        self::assertTrue(DB::table('admin_singleton_locks')->where('lock_key', 'design-receipt')->exists());
        foreach (['logo_url', 'coin_image_url', 'badge_junior_image_url'] as $attribute) {
            Storage::disk('public')->assertExists(PublicDiskUrl::pathFrom($settings->{$attribute}));
        }
        Http::assertNothingSent();
    }

    public function test_failed_first_receipt_leaves_no_settings_and_retry_uses_a_fresh_upload(): void
    {
        $writer = app(AdminDesignSettingsAuthoringService::class);
        $version = $this->version();
        $file = UploadedFile::fake()->image('coin.png');
        try {
            $writer->save($this->fields(), ['coin_image_file' => $file], $version, static function (): never {
                throw new \RuntimeException('receipt failed');
            });
            self::fail('The singleton cannot commit without its receipt.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame(0, DesignSetting::query()->count());
        $orphan = AccountFileDeletion::query()->sole();
        $saved = $writer->save($this->fields(), ['coin_image_file' => $file], $version, static function (): void {});
        self::assertSame(1, DesignSetting::query()->count());
        self::assertNotSame($orphan->path, PublicDiskUrl::pathFrom($saved->coin_image_url));
    }

    public function test_replacement_and_old_file_cleanup_roll_back_with_a_failed_receipt(): void
    {
        $path = 'design-settings/artwork/old.png';
        Storage::disk('public')->put($path, 'existing artwork');
        $settings = DesignSetting::query()->create($this->fields() + ['coin_image_url' => PublicDiskUrl::from($path)]);
        try {
            app(AdminDesignSettingsAuthoringService::class)->save($this->fields(), [
                'coin_image_file' => UploadedFile::fake()->image('new.png'),
            ], $this->version(), static function () use ($path): never {
                self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->exists(),
                    'Released-file cleanup must already be durable in the same transaction.');
                throw new \RuntimeException('receipt failed');
            });
            self::fail('A receipt failure must restore the old artwork reference.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame(PublicDiskUrl::from($path), $settings->fresh()->coin_image_url);
        self::assertFalse(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->exists());
        self::assertSame(1, AccountFileDeletion::query()->count(), 'Only the failed new attempt is tracked.');
        Storage::disk('public')->assertExists($path);
    }

    public function test_shared_artwork_survives_until_its_last_reference_is_replaced_and_raw_urls_are_not_editable(): void
    {
        $path = 'design-settings/shared.png';
        Storage::disk('public')->put($path, 'shared artwork');
        $settings = DesignSetting::query()->create($this->fields() + [
            'coin_image_url' => PublicDiskUrl::from($path), 'logo_url' => PublicDiskUrl::from($path),
            'badge_senior_image_url' => 'https://example.test/retained.png',
        ]);
        $writer = app(AdminDesignSettingsAuthoringService::class);
        $writer->save($this->fields() + ['logo_url' => 'https://example.test/injected.png'], [
            'coin_image_file' => UploadedFile::fake()->image('coin.png'),
        ], $this->version(), static function (): void {});
        self::assertSame(PublicDiskUrl::from($path), $settings->fresh()->logo_url);
        self::assertFalse(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->exists());
        $writer->save($this->fields(), ['logo_file' => UploadedFile::fake()->image('logo.png')], $this->version(), static function (): void {});
        self::assertTrue(AccountFileDeletion::query()->where('disk', 'public')->where('path_hash', hash('sha256', $path))->exists());
        self::assertSame('https://example.test/retained.png', $settings->fresh()->badge_senior_image_url);
        Storage::disk('public')->assertExists($path);
    }

    public function test_same_second_stale_editor_cannot_replace_newer_text_or_artwork(): void
    {
        $this->freezeTime();
        $settings = DesignSetting::query()->create($this->fields());
        $stale = $this->version();
        $settings->update(['name_ar' => 'هوية أحدث']);
        try {
            app(AdminDesignSettingsAuthoringService::class)->save($this->fields(), [
                'coin_image_file' => UploadedFile::fake()->image('stale.png'),
            ], $stale, static function (): never { self::fail('A stale editor cannot complete a receipt.'); });
            self::fail('Stale identity editors must fail before updating the row.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame('هوية أحدث', $settings->fresh()->name_ar);
        self::assertNull($settings->fresh()->coin_image_url);
        self::assertSame(1, DesignSetting::query()->count());
    }

    public function test_video_visibility_and_provider_normalization_do_not_depend_on_http_validation(): void
    {
        $writer = app(AdminDesignSettingsAuthoringService::class);
        foreach ([
            ['show_how_platform_works' => true],
            ['show_how_platform_works' => true, 'how_platform_works_video_link' => 'https://example.test/video'],
        ] as $invalid) {
            try {
                $writer->save($this->fields() + $invalid, [], $this->version(), static function (): void {});
                self::fail('A visible introduction requires a supported video.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('how_platform_works_video_link', $error->errors());
            }
        }
        self::assertSame(0, DesignSetting::query()->count());
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';
        $saved = $writer->save($this->fields() + [
            'show_how_platform_works' => true, 'how_platform_works_video_link' => $url,
        ], [], $this->version(), static function (): void {});
        self::assertTrue($saved->show_how_platform_works);
        self::assertSame(app(PublicAppSettingsService::class)->embedVideoUrl($url), $saved->how_platform_works_video_link);
        self::assertNotNull($saved->how_platform_works_video_link);
        Http::assertNothingSent();
    }

    private function version(): string
    {
        return DesignSettingsEditorVersion::for(DesignSetting::getDefaultSettings());
    }

    private function fields(): array
    {
        return [
            'name_ar' => 'رُكن', 'name_en' => 'Rokn', 'color_1' => '#005EFF',
            'color_2' => '#FFFFFF', 'color_3' => '#101318', 'color_4' => '#818896',
        ];
    }
}
