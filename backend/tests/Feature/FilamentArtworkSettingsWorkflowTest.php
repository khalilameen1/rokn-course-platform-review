<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\ArtworkSettings;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\DesignSetting;
use App\Models\Level;
use App\Models\User;
use App\Services\AppArtworkService;
use App\Services\PublicAppSettingsService;
use App\Support\PublicDiskUrl;
use Filament\Facades\Filament;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentArtworkSettingsWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        config(['app.url' => 'https://localhost', 'filesystems.disks.public.url' => 'https://localhost/storage']);
        Storage::fake('public', ['url' => 'https://localhost/storage']);
        Queue::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_page_shows_every_approved_default_without_creating_settings_on_read(): void
    {
        $queries = [];
        DB::listen(static function (QueryExecuted $query) use (&$queries): void {
            $queries[] = $query->sql;
        });
        $page = Livewire::test(ArtworkSettings::class);
        foreach (AppArtworkService::ASSETS as $key => $asset) {
            $page->assertSee($asset['label'])->assertSet('images.'.$key, asset('assets/app-artwork/v1/'.$asset['file']));
        }
        self::assertSame(0, DesignSetting::query()->count());
        foreach ($queries as $sql) {
            self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
        $this->get(route('admin.design-settings.index'))->assertRedirect(ArtworkSettings::getUrl());
    }

    public function test_all_artwork_uploads_reach_public_settings_and_level_fallbacks_and_preserve_unrelated_links(): void
    {
        $settings = DesignSetting::getDefaultSettings();
        $settings->forceFill(['instagram_url' => 'https://instagram.com/rokn.app'])->save();
        $before = app(PublicAppSettingsService::class)->snapshot();
        $files = [];
        foreach (array_keys(AppArtworkService::ASSETS) as $key) {
            $files[$key.'_image_file'] = UploadedFile::fake()->image($key.'.png', 20, 20);
        }
        $page = Livewire::test(ArtworkSettings::class)->fillForm($files)->call('save')->assertHasNoFormErrors()->assertNotified('تم الحفظ');
        $after = app(PublicAppSettingsService::class)->snapshot();
        self::assertNotSame($before['revision'], $after['revision']);
        foreach (array_keys(AppArtworkService::ASSETS) as $key) {
            $url = DesignSetting::query()->sole()->getAttribute($key.'_image_url');
            self::assertSame($url, $after['artwork'][$key]);
            Storage::disk('public')->assertExists(PublicDiskUrl::pathFrom($url));
            $page->assertSet('images.'.$key, $url);
        }
        foreach ([1 => 'badge_junior', 2 => 'badge_mid', 3 => 'badge_senior'] as $order => $key) {
            self::assertSame($after['artwork'][$key], (new Level(['order' => $order]))->badge_image_url);
        }
        self::assertSame('https://instagram.com/rokn.app', DesignSetting::query()->sole()->instagram_url);
        Http::assertNothingSent();
    }

    public function test_each_successful_save_rotates_its_receipt_and_repeated_edits_keep_one_settings_record(): void
    {
        $page = Livewire::test(ArtworkSettings::class);
        $firstIntent = $page->get('creationIntent');
        $firstVersion = $page->get('editorVersion');
        $page->fillForm(['slogan_1_ar' => 'كورسات هتكملها'])->call('save')->assertHasNoFormErrors();
        self::assertNotSame($firstIntent, $page->get('creationIntent'));
        self::assertNotSame($firstVersion, $page->get('editorVersion'));
        $page->assertDispatched('authoring-draft-saved', fn ($event, array $params): bool => $params['receipt'] === $page->get('creationIntent'));
        $page->fillForm(['slogan_1_ar' => 'النص المعتمد'])->call('save')->assertHasNoFormErrors();
        self::assertSame(1, DesignSetting::query()->count());
        self::assertSame('النص المعتمد', DesignSetting::query()->sole()->slogan_1_ar);
        self::assertSame(2, DB::table('admin_authoring_create_intents')->where('route_name', 'filament.design-settings.store')->where('status', 'completed')->count());
    }

    public function test_stale_settings_cannot_replace_new_copy_or_artwork_and_conflicting_drafts_do_not_restore(): void
    {
        $page = Livewire::test(ArtworkSettings::class);
        $stale = $page->get('editorVersion');
        $settings = DesignSetting::getDefaultSettings();
        $settings->forceFill(['name_ar' => 'الاسم الأحدث', 'coin_image_url' => 'https://localhost/storage/current.png'])->save();
        $page->fillForm(['name_ar' => 'نسخة قديمة', 'coin_image_file' => UploadedFile::fake()->image('stale.png')])
            ->call('save')->assertNotified('تغيّرت إعدادات التصميم');
        self::assertSame('الاسم الأحدث', $settings->fresh()->name_ar);
        self::assertSame('https://localhost/storage/current.png', $settings->fresh()->coin_image_url);
        Livewire::test(ArtworkSettings::class)->call('restoreAuthoringDraft', ['version' => $stale, 'receipt' => (string) Str::uuid(),
            'values' => ['name_ar' => 'قديم']])->assertReturned(['state' => 'conflict'])->assertSet('data.name_ar', 'الاسم الأحدث');
    }

    public function test_draft_recovery_never_restores_urls_files_or_authorization_fields(): void
    {
        $page = Livewire::test(ArtworkSettings::class);
        $receipt = (string) Str::uuid();
        $page->call('restoreAuthoringDraft', ['version' => $page->get('editorVersion'), 'receipt' => $receipt,
            'values' => ['name_ar' => 'اسم مستعاد', 'coin_image_url' => 'https://other.example/image.png',
                'coin_image_file' => 'livewire-file:untrusted', 'role' => 'admin']])->assertReturned(['state' => 'restored'])
            ->assertSet('creationIntent', $receipt)->assertSet('data.name_ar', 'اسم مستعاد');
        self::assertEmpty($page->get('data.coin_image_file'));
        self::assertArrayNotHasKey('coin_image_url', $page->get('data'));
        self::assertSame(0, DesignSetting::query()->count());
    }

    public function test_native_rules_and_owned_intro_video_refusals_remain_visible_and_permissions_hold(): void
    {
        Livewire::test(ArtworkSettings::class)->fillForm(['color_1' => 'red'])->call('save')->assertHasFormErrors(['color_1']);
        Livewire::test(ArtworkSettings::class)->fillForm(['show_how_platform_works' => true])->call('save')->assertHasFormErrors(['how_platform_works_video_link']);
        Livewire::test(ArtworkSettings::class)->fillForm(['show_how_platform_works' => true, 'how_platform_works_video_link' => 'https://other.example/video'])
            ->call('save')->assertHasFormErrors(['how_platform_works_video_link']);
        Livewire::test(ArtworkSettings::class)->fillForm(['coin_image_file' => UploadedFile::fake()->create('coin.txt', 1, 'text/plain')])
            ->call('save')->assertHasFormErrors(['coin_image_file']);
        Livewire::test(ArtworkSettings::class)->fillForm(['coin_image_file' => UploadedFile::fake()->image('large.png', 4097, 1)])
            ->call('save')->assertHasFormErrors(['coin_image_file']);
        self::assertSame(0, DesignSetting::query()->count());
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ArtworkSettings::class)->assertForbidden();
    }
}
