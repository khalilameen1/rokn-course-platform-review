<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\DesignSetting;
use App\Models\Level;
use App\Models\User;
use App\Services\AppArtworkService;
use App\Services\PublicAppSettingsService;
use App\Services\StoredFileReferenceService;
use App\Support\PublicDiskUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AppArtworkManagementTest extends TestCase
{
    private array $imageFixtures = [];
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        config(['app.url' => 'https://localhost', 'filesystems.disks.public.url' => 'https://localhost/storage']);
        URL::forceScheme('https');
        Storage::fake('public', ['url' => 'https://localhost/storage']);
        $admin = new User();
        $admin->forceFill(['name_ar' => 'مدير', 'email' => 'artwork@example.test', 'role' => 'admin', 'active' => true])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web');
    }

    public function test_approved_defaults_exist_and_are_visible_without_seeding(): void
    {
        $editor = $this->get(route('admin.design-settings.index'))->assertOk();
        $snapshot = app(PublicAppSettingsService::class)->snapshot();
        foreach (AppArtworkService::ASSETS as $key => $asset) {
            self::assertFileExists(public_path('assets/app-artwork/v1/'.$asset['file']));
            $editor->assertSee($key.'_image_file')->assertSee($snapshot['artwork'][$key]);
            self::assertStringEndsWith('/assets/app-artwork/v1/'.$asset['file'], $snapshot['artwork'][$key]);
        }
        self::assertSame(0, DesignSetting::count());
    }

    public function test_dashboard_uploads_reach_public_settings_and_level_fallbacks(): void
    {
        $before = app(PublicAppSettingsService::class)->snapshot();
        $data = $this->payload();
        foreach (array_keys(AppArtworkService::ASSETS) as $key) {
            $data[$key.'_image_file'] = $this->image($key.'.png');
        }
        $this->post(route('admin.design-settings.store'), $data)->assertSessionHasNoErrors()->assertRedirect(route('admin.design-settings.index'));
        $settings = DesignSetting::sole();
        $after = app(PublicAppSettingsService::class)->snapshot();
        self::assertNotSame($before['revision'], $after['revision']);
        foreach (array_keys(AppArtworkService::ASSETS) as $key) {
            $url = $settings->getAttribute($key.'_image_url');
            self::assertSame($url, $after['artwork'][$key]);
            $path = PublicDiskUrl::pathFrom($url);
            Storage::disk('public')->assertExists($path);
            self::assertTrue(app(StoredFileReferenceService::class)->isReferenced('public', $path));
        }
        foreach ([1 => 'badge_junior', 2 => 'badge_mid', 3 => 'badge_senior'] as $order => $key) {
            $level = new Level(['order' => $order, 'badge_image' => 'assets/img/badges/junior.png']);
            self::assertSame($after['artwork'][$key], $level->badge_image_url);
        }
        Http::assertNothingSent();
    }

    public function test_custom_level_upload_wins_and_default_reads_do_not_query_per_level(): void
    {
        $settings = DesignSetting::create(['name_ar' => 'رُكن', 'name_en' => 'Rokn']);
        $service = app(AppArtworkService::class);
        DB::enableQueryLog();
        $service->levelDefault(1);
        $service->levelDefault(2);
        $service->levelDefault(3);
        self::assertCount(1, array_filter(DB::getQueryLog(), fn (array $query) => str_contains($query['query'], 'design_settings')));
        DB::disableQueryLog();
        $settings->update(['badge_senior_image_url' => 'https://localhost/storage/new.png']);
        self::assertSame('https://localhost/storage/new.png', $service->levelDefault(3));
        $level = new Level(['order' => 3, 'badge_image' => 'https://localhost/storage/custom.png']);
        self::assertSame('https://localhost/storage/custom.png', $level->badge_image_url);
    }

    public function test_invalid_image_and_stale_editor_cannot_overwrite_artwork(): void
    {
        $invalid = UploadedFile::fake()->create('coin.txt', 1, 'text/plain');
        $this->post(route('admin.design-settings.store'), [
            ...$this->payload(), 'coin_image_file' => new UploadedFile($invalid->getPathname(), 'coin.txt', 'text/plain', null, true),
        ])->assertSessionHasErrors('coin_image_file');
        self::assertSame(0, DesignSetting::count());
        $this->post(route('admin.design-settings.store'), [
            ...$this->payload(), 'editor_version' => str_repeat('0', 64),
            'coin_image_file' => $this->image('coin.png'),
        ])->assertSessionHasErrors('editor_version');
        self::assertSame(0, DesignSetting::count());
    }

    public function test_same_second_edit_cannot_replace_a_newer_image_and_preserves_other_images(): void
    {
        $this->freezeTime();
        $settings = DesignSetting::create(['name_ar' => 'رُكن', 'name_en' => 'Rokn',
            'coin_stack_image_url' => 'https://localhost/storage/retained-stack.png']);
        $stale = $this->payload();
        $timestamp = $settings->updated_at->toDateTimeString();
        $settings->update(['coin_image_url' => 'https://localhost/storage/newer.png']);
        self::assertSame($timestamp, $settings->fresh()->updated_at->toDateTimeString());
        $this->post(route('admin.design-settings.store'), [...$stale,
            'coin_image_file' => $this->image('stale.png'),
        ])->assertSessionHasErrors('editor_version');
        self::assertSame('https://localhost/storage/newer.png', $settings->fresh()->coin_image_url);
        $this->post(route('admin.design-settings.store'), [...$this->payload(),
            'coin_image_file' => $this->image('fresh.png'),
        ])->assertSessionHasNoErrors()->assertRedirect(route('admin.design-settings.index'));
        self::assertSame('https://localhost/storage/retained-stack.png', $settings->fresh()->coin_stack_image_url);
        self::assertNotSame('https://localhost/storage/newer.png', $settings->fresh()->coin_image_url);
    }

    private function image(string $name): UploadedFile
    {
        $fixture = UploadedFile::fake()->image($name, 16, 16);
        $this->imageFixtures[] = $fixture;
        return new UploadedFile($fixture->getPathname(), $name, 'image/png', null, true);
    }

    private function payload(): array
    {
        $editor = $this->get(route('admin.design-settings.index'))->assertOk();
        return [
            'name_ar' => 'رُكن', 'name_en' => 'Rokn',
            'color_1' => '#005EFF', 'color_2' => '#FFFFFF', 'color_3' => '#101318', 'color_4' => '#818896',
            'editor_version' => $editor->viewData('editorVersion'),
            'authoring_request_id' => (string) Str::uuid(),
        ];
    }
}
