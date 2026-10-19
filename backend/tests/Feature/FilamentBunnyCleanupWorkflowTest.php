<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\BunnyCleanupResource;
use App\Filament\Resources\BunnyCleanupResource\Pages\ListBunnyCleanup;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentBunnyCleanupWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المدير', 'role' => 'admin', 'active' => true]), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
    }

    public function test_queue_filters_and_approval_keep_retention_and_do_not_delete_remote_bytes(): void
    {
        $pending = $this->candidate('pending');
        $closed = $this->candidate('closed', ['remote_deleted_at' => now()]);
        $retention = $pending->eligible_after->toISOString();
        $page = Livewire::test(ListBunnyCleanup::class)->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$closed]);
        $page->callTableAction('approve', $pending)->assertHasNoTableActionErrors();
        self::assertNotNull($pending->fresh()->reviewed_at);
        self::assertNull($pending->fresh()->remote_deleted_at);
        self::assertSame($retention, $pending->fresh()->eligible_after->toISOString());
        self::assertSame(auth('web')->id(), $pending->fresh()->reviewed_by);
        $page->filterTable('review', 'approved')->assertCanSeeTableRecords([$pending])->assertCanNotSeeTableRecords([$closed]);
        $page->filterTable('review', 'deleted')->assertCanSeeTableRecords([$closed])->assertCanNotSeeTableRecords([$pending]);
        Http::assertNothingSent();
    }

    public function test_bulk_approval_is_idempotent_and_does_not_touch_rows_outside_the_selection(): void
    {
        $selected = $this->candidate('selected');
        $outside = $this->candidate('outside');
        Livewire::test(ListBunnyCleanup::class)->callTableBulkAction('approve', [$selected])->assertHasNoTableBulkActionErrors();
        $snapshot = $selected->fresh()->getRawOriginal();
        BunnyCleanupResource::approve([$selected->id], true);
        self::assertSame($snapshot, $selected->fresh()->getRawOriginal());
        self::assertNull($outside->fresh()->reviewed_at);
        self::assertFalse(BunnyCleanupResource::canCreate());
        self::assertFalse(BunnyCleanupResource::canDelete($selected));
        Http::assertNothingSent();
    }

    public function test_moderator_cannot_access_or_approve_the_queue(): void
    {
        $this->actingAs(User::query()->forceCreate(['name_ar' => 'المحرر', 'role' => 'moderator', 'active' => true]), 'web');
        $this->get(BunnyCleanupResource::getUrl())->assertForbidden();
        self::assertFalse(BunnyCleanupResource::allowsApproval());
        self::assertFalse(BunnyCleanupResource::allowsApproval(true));
    }

    private function candidate(string $guid, array $data = []): BunnyVideoCleanupCandidate
    {
        return BunnyVideoCleanupCandidate::query()->create(['video_guid' => $guid, 'reason' => 'superseded_video',
            'eligible_after' => now()->addDay(), 'requires_review' => true, ...$data]);
    }
}
