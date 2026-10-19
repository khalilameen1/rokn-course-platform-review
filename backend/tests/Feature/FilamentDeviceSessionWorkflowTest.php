<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\DeviceSessions;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\ApiToken;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\AdminDeviceSessionReadService;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentDeviceSessionWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->actingAs($this->user('admin'), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_read_projection_never_selects_or_serializes_bearers_and_only_lists_active_identified_sessions(): void
    {
        $student = $this->user();
        $session = $this->deviceSession($student);
        $expired = $this->deviceSession($student, ['expired_at' => now()->subMinute()]);
        $revoked = $this->deviceSession($student, ['revoked_at' => now()]);
        $this->deviceSession($student, ['session_id' => null]);
        $reader = app(AdminDeviceSessionReadService::class);
        $rows = $reader->paginate(null, null, 50, 1);
        self::assertSame(1, $rows->total());
        self::assertArrayNotHasKey('token', $rows->first());
        self::assertSame($session->session_id, $rows->first()['session_id']);
        self::assertSame(1, array_sum($reader->summary()));
        Livewire::test(DeviceSessions::class)->assertCanSeeTableRecords([$session->session_id])
            ->assertCanNotSeeTableRecords([$expired->session_id, $revoked->session_id])->assertDontSee($session->token, false);
        Livewire::test(DeviceSessions::class)->mountTableAction('details', $session->session_id)
            ->assertActionMounted(TestAction::make('details')->table($session->session_id))->assertActionDataSet(['app_version' => '1.0.68', 'email' => $student->email]);
        self::assertNull($session->fresh()->revoked_at);
    }

    public function test_native_search_and_platform_filters_preserve_scope_and_literal_wildcards(): void
    {
        $student = $this->user();
        $student->update(['name' => 'طالب 50%']);
        $android = $this->deviceSession($student);
        $ios = $this->deviceSession($this->user(), ['platform' => 'ios']);
        Livewire::test(DeviceSessions::class)->searchTable('50%')->assertCanSeeTableRecords([$android->session_id])->assertCanNotSeeTableRecords([$ios->session_id]);
        Livewire::test(DeviceSessions::class)->filterTable('platform', 'ios')->assertCanSeeTableRecords([$ios->session_id])->assertCanNotSeeTableRecords([$android->session_id]);
    }

    public function test_platform_summary_combines_missing_and_other_labels_without_losing_a_group(): void
    {
        $student = $this->user();
        foreach (['android', 'ios', null, 'other'] as $platform) {
            $this->deviceSession($student, ['platform' => $platform]);
        }
        $reader = app(AdminDeviceSessionReadService::class);
        self::assertSame(4, array_sum($reader->summary()));
        self::assertSame(2, (int) $reader->summary()['other']);
        self::assertSame(2, $reader->paginate(null, 'other', 50, 1)->total());
    }

    public function test_native_revoke_closes_only_the_selected_session_and_removes_its_device_push_registration(): void
    {
        $student = $this->user();
        $chosen = $this->deviceSession($student);
        $kept = $this->deviceSession($student);
        foreach ([$chosen, $kept] as $session) {
            UserDeviceToken::query()->create(['user_id' => $student->id, 'device_token' => Str::random(32),
                'device_type' => 'android', 'device_os' => 'android', 'device_id' => $session->device_id]);
        }
        Livewire::test(DeviceSessions::class)->callTableAction('revoke', $chosen->session_id)
            ->assertCanNotSeeTableRecords([$chosen->session_id])->assertCanSeeTableRecords([$kept->session_id]);
        self::assertNotNull($chosen->fresh()->revoked_at);
        self::assertNull($kept->fresh()->revoked_at);
        self::assertFalse(UserDeviceToken::query()->where('device_id', $chosen->device_id)->exists());
        self::assertTrue(UserDeviceToken::query()->where('device_id', $kept->device_id)->exists());
        Http::assertNothingSent();
    }

    public function test_pagination_stays_in_the_database_and_native_keys_are_public_session_ids(): void
    {
        $student = $this->user();
        for ($i = 0; $i < 51; $i++) {
            $this->deviceSession($student);
        }
        $reader = app(AdminDeviceSessionReadService::class);
        self::assertCount(50, $reader->paginate(null, null, 50, 1));
        self::assertCount(1, $reader->paginate(null, null, 50, 2));
        Livewire::test(DeviceSessions::class)->assertCountTableRecords(51)->call('gotoPage', 2)->assertSee($student->email);
    }

    public function test_unmigrated_session_metadata_has_an_explicit_empty_state_without_a_broken_query(): void
    {
        Schema::table((new ApiToken)->getTable(), function (Blueprint $table): void {
            $table->dropUnique(['session_id']);
            $table->dropColumn('session_id');
        });
        Livewire::test(DeviceSessions::class)->assertSee('شغّل migrations لتفعيل سجل الجلسات')->assertCountTableRecords(0);
    }

    public function test_old_bookmark_redirects_but_moderators_cannot_open_the_device_controls(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->get(route('admin.user-sessions.index'))->assertRedirect(DeviceSessions::getUrl());
        auth('web')->user()->forceFill(['role' => 'moderator'])->save();
        Livewire::test(DeviceSessions::class)->assertForbidden();
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate(['name_ar' => 'طالب للجلسات', 'email' => Str::uuid().'@sessions.test', 'role' => $role, 'active' => true]);
    }

    private function deviceSession(User $user, array $overrides = []): ApiToken
    {
        return ApiToken::query()->create([...[
            'token' => hash('sha256', Str::random(48)), 'user_id' => $user->id,
            'session_id' => (string) Str::uuid(), 'device_id' => (string) Str::uuid(), 'platform' => 'android', 'device_class' => 'phone',
            'app_version' => '1.0.68', 'app_build' => '68', 'auth_provider' => 'google',
            'issued_at' => now(), 'last_used_at' => now(), 'expired_at' => now()->addDay(),
        ], ...$overrides]);
    }
}
