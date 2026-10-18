<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InternalSignal;
use App\Models\PortfolioItem;
use App\Models\User;
use App\Services\AdminStudentAuthoringService;
use App\Services\BunnyDeliveryService;
use App\Services\PortfolioModerationService;
use App\Services\PortfolioReviewReadService;
use App\Services\PortfolioShareIdentityService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The owner's portfolio-profile GET reports the effective review state
 * without persisting it (RFC 9110 safe method). Content changes persist it
 * through the 'portfolio.sharing.reconcile' internal signal (outbox).
 */
final class PortfolioOwnerStateReadWritePathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(BunnyDeliveryService::class)->shouldReceive('storageUrl')
            ->andReturnUsing(fn (string $path) => 'https://cdn.rokn.test/'.$path);
    }

    public function test_owner_read_reports_drift_as_pending_without_writing(): void
    {
        [$owner, $item] = $this->approvedWork();
        DB::table('portfolio_items')->where('id', $item->id)->update(['title' => 'Changed outside the editor']);
        $before = (array) DB::table('users')->where('id', $owner->id)->first();

        $queries = $this->recordQueries(fn () => $this->actingAs($owner->fresh(), 'api')
            ->getJson('/api/v1/portfolio-profile')
            ->assertOk()
            ->assertJsonPath('data.sharing_status', 'pending')
            ->assertJsonPath('data.published', true)
            ->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonPath('data.slug', $owner->portfolio_slug));

        self::assertSame([], $this->writes($queries));
        self::assertEquals($before, (array) DB::table('users')->where('id', $owner->id)->first());
    }

    public function test_a_removal_that_leaves_nothing_to_review_is_reconciled_by_the_signal_not_the_read(): void
    {
        [$owner, $item] = $this->approvedWork();
        $extra = PortfolioItem::query()->create([
            'user_id' => $owner->id, 'title' => 'Added after approval', 'is_public' => true,
        ]);
        $extra->mediaFiles()->create([
            'file_path' => 'portfolio/extra.jpg', 'file_type' => 'image',
            'content_sha256' => str_repeat('b', 64),
        ]);
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);

        // Deleting the unapproved addition restores the live content. The
        // change's signal (run inline by the sync test queue) stores that.
        $extra->mediaFiles()->first()->delete();
        $extra->delete();

        $owner = $owner->fresh();
        self::assertSame('approved', $owner->portfolio_sharing_status);
        $signal = InternalSignal::query()
            ->where('type', PortfolioModerationService::RECONCILE_SIGNAL)
            ->where('aggregate_id', (string) $owner->id)
            ->latest('id')->first();
        self::assertNotNull($signal);
        self::assertSame(InternalSignal::STATUS_HANDLED, $signal->status);

        $queries = $this->recordQueries(fn () => $this->actingAs($owner, 'api')
            ->getJson('/api/v1/portfolio-profile')
            ->assertOk()
            ->assertJsonPath('data.sharing_status', 'approved')
            ->assertJsonPath('data.has_unpublished_changes', false));
        self::assertSame([], $this->writes($queries));
        self::assertNotNull($item->fresh());
    }

    public function test_a_change_without_a_publication_records_no_reconcile_signal(): void
    {
        $owner = $this->owner();
        PortfolioItem::query()->create(['user_id' => $owner->id, 'title' => 'Draft', 'is_public' => true]);

        self::assertSame(0, InternalSignal::query()->where('type', PortfolioModerationService::RECONCILE_SIGNAL)->count());
        self::assertSame('pending', app(PortfolioReviewReadService::class)->ownerState($owner)['sharing_status']);
    }

    public function test_dashboard_registration_creates_the_portfolio_slug(): void
    {
        $user = app(AdminStudentAuthoringService::class)->create(
            ['name' => 'Dashboard Student', 'email' => 'dashboard-student@rokn.test', 'phone' => '01000000001'],
            (string) Str::uuid(),
            null,
            static function (User $user): void {}
        );

        self::assertTrue(app(PortfolioShareIdentityService::class)
            ->isValidUnlistedSlug((string) $user->fresh()->portfolio_slug));
    }

    /** @return array{User, PortfolioItem} */
    private function approvedWork(): array
    {
        $owner = $this->owner();
        $item = PortfolioItem::query()->create([
            'user_id' => $owner->id, 'title' => 'Selected work', 'is_public' => true,
        ]);
        $item->mediaFiles()->create([
            'file_path' => 'portfolio/original.jpg', 'file_type' => 'image',
            'content_sha256' => str_repeat('a', 64),
        ]);
        $snapshot = app(PortfolioReviewReadService::class)->snapshot($owner->fresh());
        app(PortfolioModerationService::class)->decide(
            $owner->fresh(), $this->owner('admin'), $snapshot['revision'], $snapshot['hash'], 'approved', null
        );

        return [$owner->fresh(), $item->fresh()];
    }

    private function owner(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Owner '.Str::uuid(), 'email' => Str::uuid().'@rokn.test',
            'role' => $role, 'active' => true, 'portfolio_slug' => 'rokn-'.strtolower(Str::random(24)),
        ]);
    }

    /** @return list<string> */
    private function recordQueries(callable $callback): array
    {
        $queries = [];
        $recording = true;
        DB::listen(function (QueryExecuted $query) use (&$queries, &$recording): void {
            if ($recording) {
                $queries[] = $query->sql;
            }
        });
        try {
            $callback();
        } finally {
            $recording = false;
        }

        return $queries;
    }

    /** @param list<string> $queries @return list<string> */
    private function writes(array $queries): array
    {
        return array_values(array_filter(
            $queries,
            fn (string $sql): bool => preg_match('/^\s*(insert|update|delete|replace)\b/i', $sql) === 1
        ));
    }
}
