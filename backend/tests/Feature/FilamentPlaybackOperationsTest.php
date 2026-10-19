<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\PlaybackOperations;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\PlaybackSession;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentPlaybackOperationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_native_monitor_keeps_privacy_safe_projection_and_ends_only_one_stale_session(): void
    {
        $stale = $this->playbackSession(true);
        $active = $this->playbackSession(false);
        $page = Livewire::test(PlaybackOperations::class)->assertOk()->assertCanSeeTableRecords([$stale->id, $active->id])
            ->assertDontSee('PRIVATE-DEVICE-OR-URL')->assertDontSee('PRIVATE-SENSITIVE-DIAGNOSTIC');
        $page->mountAction(TestAction::make('details')->table($stale->id))->assertSee('عالقة')->assertSee('لم يُرصد')->unmountAction();
        $page->callAction(TestAction::make('terminate')->table($stale->id))->assertNotified('تم إنهاء الجلسة العالقة وتسجيل العملية');
        self::assertSame('admin_stale_termination', $stale->fresh()->end_reason);
        self::assertNull($active->fresh()->ended_at);
    }

    public function test_fresh_heartbeat_after_confirmation_prevents_termination(): void
    {
        $stale = $this->playbackSession(true);
        $page = Livewire::test(PlaybackOperations::class)->mountAction(TestAction::make('terminate')->table($stale->id));
        $stale->forceFill(['last_heartbeat_at' => now()])->save();
        $page->callMountedAction();
        self::assertNull($stale->fresh()->ended_at);
    }

    public function test_content_operator_cannot_open_the_native_monitor(): void
    {
        $this->actingAs(User::query()->forceCreate(['role' => 'moderator', 'active' => true]), 'web');
        $this->get(PlaybackOperations::getUrl())->assertForbidden();
    }

    private function playbackSession(bool $stale): PlaybackSession
    {
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس التشغيل', 'is_coming_soon' => true]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'مقطع الجلسة']);

        return PlaybackSession::query()->create([
            'id' => (string) Str::uuid(), 'user_id' => auth('web')->id(), 'lesson_id' => $lesson->id,
            'started_at' => now()->subHour(), 'last_heartbeat_at' => $stale ? now()->subHour() : now(),
            'source_host' => 'PRIVATE-DEVICE-OR-URL', 'diagnostics' => ['private' => 'PRIVATE-SENSITIVE-DIAGNOSTIC'],
            'last_position_seconds' => 42, 'duration_seconds' => 75, 'effective_quality' => '720p',
        ]);
    }
}
