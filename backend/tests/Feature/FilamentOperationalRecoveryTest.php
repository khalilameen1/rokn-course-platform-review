<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Pages\OperationalRecovery;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\OutboxEvent;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\OperationalRuntimeService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class FilamentOperationalRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Bus::fake();
        $this->actingAs(User::query()->forceCreate(['email' => 'recovery@rokn.test', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_projection_is_bounded_read_only_and_does_not_expose_payloads(): void
    {
        $event = $this->event();
        $this->job();
        $records = app(OperationalRuntimeService::class)->recoveryRecords();
        self::assertCount(2, $records);
        self::assertStringNotContainsString('private-secret', json_encode($records, JSON_THROW_ON_ERROR));
        $this->get(OperationalRecovery::getUrl())->assertOk()->assertDontSee('private-secret');
        self::assertSame(OutboxEvent::STATUS_FAILED, $event->fresh()->status);
        Bus::assertNothingDispatched();
    }

    public function test_retry_preserves_event_identity_and_does_not_touch_other_failures(): void
    {
        $event = $this->event();
        $other = $this->event();
        Livewire::test(OperationalRecovery::class)->callTableAction('retry', 'event:'.$event->id, ['reason' => 'Provider recovered and verified'])
            ->assertHasNoActionErrors()->assertNotified('تم تسجيل إجراء الاستعادة');
        self::assertSame(OutboxEvent::STATUS_PENDING, $event->fresh()->status);
        self::assertSame(OutboxEvent::STATUS_FAILED, $other->fresh()->status);
        self::assertSame(2, OutboxEvent::query()->count());
    }

    public function test_skip_and_acknowledge_are_separate_confirmed_actions(): void
    {
        $event = $this->event();
        $job = $this->job();
        $other = $this->job();
        Livewire::test(OperationalRecovery::class)->callTableAction('skip', 'event:'.$event->id, ['reason' => 'Obsolete event confirmed by owner'])
            ->assertHasNoActionErrors();
        self::assertSame(OutboxEvent::STATUS_SKIPPED, $event->fresh()->status);
        Livewire::test(OperationalRecovery::class)->callTableAction('acknowledge', 'job:'.$job, ['reason' => 'Issue resolved without job replay'])
            ->assertHasNoActionErrors();
        self::assertFalse(DB::table('failed_jobs')->where('id', $job)->exists());
        self::assertTrue(DB::table('failed_jobs')->where('id', $other)->exists());
        Bus::assertNothingDispatched();
    }

    public function test_reason_is_required_and_readers_cannot_recover(): void
    {
        $event = $this->event();
        Livewire::test(OperationalRecovery::class)->callTableAction('skip', 'event:'.$event->id, ['reason' => 'short'])
            ->assertHasActionErrors(['reason']);
        self::assertSame(OutboxEvent::STATUS_FAILED, $event->fresh()->status);
        $reader = User::query()->forceCreate(['role' => 'moderator', 'active' => true]);
        $member = StaffMember::query()->create(['user_id' => $reader->id]);
        $member->givePermissionTo(Permission::findOrCreate('operations.read', 'web'));
        $this->actingAs($reader, 'web');
        Livewire::test(OperationalRecovery::class)->assertOk()->assertTableActionHidden('retry', 'event:'.$event->id)
            ->assertTableActionHidden('skip', 'event:'.$event->id);
    }

    public function test_already_recovered_event_is_not_replayed_from_an_open_modal(): void
    {
        $event = $this->event();
        $page = Livewire::test(OperationalRecovery::class)->mountTableAction('retry', 'event:'.$event->id);
        $event->update(['status' => OutboxEvent::STATUS_DELIVERED]);
        $page->setTableActionData(['reason' => 'An older open recovery modal'])->callMountedTableAction();
        self::assertSame(OutboxEvent::STATUS_DELIVERED, $event->fresh()->status);
        Bus::assertNothingDispatched();
    }

    private function event(): OutboxEvent
    {
        return OutboxEvent::query()->create(['event_key' => (string) Str::uuid(), 'topic' => 'test.operational-recovery',
            'aggregate_type' => 'course', 'aggregate_id' => '1', 'payload' => ['secret' => 'private-secret'],
            'status' => OutboxEvent::STATUS_FAILED, 'attempts' => 3]);
    }

    private function job(): int
    {
        return DB::table('failed_jobs')->insertGetId(['connection' => 'database', 'queue' => 'default',
            'payload' => 'private-secret', 'exception' => 'private-secret', 'failed_at' => now()]);
    }
}
