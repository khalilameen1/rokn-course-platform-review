<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\AppVersionResource;
use App\Filament\Resources\AppVersionResource\Pages\CreateAppVersion;
use App\Filament\Resources\AppVersionResource\Pages\EditAppVersion;
use App\Filament\Resources\AppVersionResource\Pages\ListAppVersions;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AppVersion;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentAppVersionWorkflowTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        $user = new User;
        $user->forceFill(['role' => 'admin', 'active' => true])->save();
        $this->actingAs($user, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_creation_preserves_each_platform_identity_and_separate_channels(): void
    {
        foreach ([['play', 71], ['direct', 71], ['appstore', 55]] as [$channel, $number]) {
            Livewire::test(CreateAppVersion::class)->fillForm($this->payload($channel, $number))
                ->call('create')->assertHasNoFormErrors();
        }
        self::assertSame(3, AppVersion::query()->count());
        $ios = AppVersion::query()->where('distribution_channel', 'appstore')->sole();
        self::assertSame('ios', $ios->platform);
        self::assertNull($ios->version_code);
        self::assertSame(55, $ios->build_number);
        $this->postJson('/api/v1/app/check-version', ['platform' => 'android', 'version' => 70, 'distribution_channel' => 'play'])
            ->assertOk()->assertJsonPath('data.latest_version_code', 71)
            ->assertJsonPath('data.download_url', 'https://play.google.com/store/apps/details?id=com.rokn');
    }

    public function test_invalid_store_links_and_duplicate_channel_builds_are_visible_field_errors(): void
    {
        Livewire::test(CreateAppVersion::class)->fillForm([...$this->payload('play', 71), 'download_url' => 'https://rokn.app/app.apk'])
            ->call('create')->assertHasFormErrors(['download_url']);
        self::assertSame(0, AppVersion::query()->count());
        $this->release();
        Livewire::test(CreateAppVersion::class)->fillForm($this->payload('play', 71))
            ->call('create')->assertHasFormErrors(['version_code' => 'unique']);
    }

    public function test_draft_may_omit_link_but_cannot_activate_until_ready_or_be_deleted_while_active(): void
    {
        Livewire::test(CreateAppVersion::class)->fillForm([...$this->payload('play', 71), 'is_active' => false, 'download_url' => null])
            ->call('create')->assertHasNoFormErrors();
        $release = AppVersion::query()->sole();
        Livewire::test(ListAppVersions::class)->callTableAction('toggleActive', $release);
        self::assertFalse($release->fresh()->is_active);
        Livewire::test(EditAppVersion::class, ['record' => $release->id])->fillForm([
            'download_url' => $this->payload('play', 71)['download_url'], 'is_active' => true,
        ])->call('save')->assertHasNoFormErrors();
        self::assertTrue($release->fresh()->is_active);
        Livewire::test(ListAppVersions::class)->callTableAction('remove', $release);
        self::assertTrue(AppVersion::query()->whereKey($release->id)->exists());
        Livewire::test(ListAppVersions::class)->callTableAction('toggleActive', $release);
        self::assertFalse($release->fresh()->is_active);
        Livewire::test(ListAppVersions::class)->callTableAction('remove', $release->fresh());
        self::assertFalse(AppVersion::query()->whereKey($release->id)->exists());
    }

    public function test_stale_editor_and_stale_confirmation_do_not_overwrite_newer_release(): void
    {
        $release = $this->release();
        $editor = Livewire::test(EditAppVersion::class, ['record' => $release->id]);
        $list = Livewire::test(ListAppVersions::class)->mountTableAction('toggleActive', $release);
        $release->update(['update_message_ar' => 'الأحدث']);
        $editor->fillForm(['update_message_ar' => 'قديم'])->call('save');
        self::assertSame('الأحدث', $release->fresh()->update_message_ar);
        $list->callMountedTableAction();
        self::assertTrue($release->fresh()->is_active);
    }

    public function test_monotonic_identity_remains_enforced_by_the_owner(): void
    {
        $release = $this->release();
        Livewire::test(CreateAppVersion::class)->fillForm($this->payload('direct', 70))
            ->call('create')->assertHasFormErrors(['version_code']);
        Livewire::test(EditAppVersion::class, ['record' => $release->id])
            ->fillForm(['version_name' => '9.9.9'])->call('save')->assertHasFormErrors(['version_name']);
        self::assertSame('1.0.71', $release->fresh()->version_name);
    }

    public function test_legacy_row_can_only_be_deactivated_and_moderator_has_no_release_access(): void
    {
        $legacy = $this->release(['distribution_channel' => null]);
        Livewire::test(EditAppVersion::class, ['record' => $legacy->id])->assertForbidden();
        Livewire::test(ListAppVersions::class)->callTableAction('toggleActive', $legacy);
        self::assertFalse($legacy->fresh()->is_active);
        Livewire::test(ListAppVersions::class)->callTableAction('toggleActive', $legacy->fresh());
        self::assertFalse($legacy->fresh()->is_active);
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(ListAppVersions::class)->assertForbidden();
        Livewire::test(CreateAppVersion::class)->assertForbidden();
    }

    public function test_legacy_bookmarks_redirect_to_native_pages(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $release = $this->release();
        $this->get(route('admin.app-versions.index'))->assertRedirect(AppVersionResource::getUrl());
        $this->get(route('admin.app-versions.create'))->assertRedirect(AppVersionResource::getUrl('create'));
        $this->get(route('admin.app-versions.edit', $release))->assertRedirect(AppVersionResource::getUrl('edit', ['record' => $release->id]));
    }

    private function payload(string $channel, int $number): array
    {
        return ['distribution_channel' => $channel, 'version_name' => '1.0.71', 'version_code' => $number, 'build_number' => $number,
            'is_active' => true, 'is_force_update' => false, 'download_url' => match ($channel) {
                'appstore' => 'https://apps.apple.com/app/id1234567890', 'direct' => 'https://rokn.app/releases/rokn.apk',
                default => 'https://play.google.com/store/apps/details?id=com.rokn',
            }];
    }

    private function release(array $overrides = []): AppVersion
    {
        return AppVersion::query()->create([...AppVersionResource::authoringData($this->payload('play', 71)), ...$overrides]);
    }
}
