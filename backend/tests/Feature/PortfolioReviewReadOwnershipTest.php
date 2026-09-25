<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PortfolioItem;
use App\Models\User;
use App\Services\BunnyDeliveryService;
use App\Services\CertificateQrDestinationService;
use App\Services\PortfolioModerationService;
use App\Services\PortfolioReviewReadService;
use App\Services\PublicPortfolioService;
use App\Support\RoknPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioReviewReadOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mock(BunnyDeliveryService::class)->shouldReceive('storageUrl')
            ->andReturnUsing(fn (string $path) => 'https://cdn.rokn.test/'.$path);
    }

    public function test_readers_deny_changed_content_without_mutating_review_or_resolving_the_writer(): void
    {
        [$owner, $item] = $this->approvedWork();
        $before = $owner->getAttributes();
        DB::table('portfolio_items')->where('id', $item->id)->update(['title' => 'Changed outside the editor']);
        $this->forbidWriterResolution();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader = app(PortfolioReviewReadService::class);
        self::assertSame('pending', $reader->status($owner));
        self::assertNull($reader->publicUrlFor($owner));
        self::assertNull($owner->profile_deeplink);
        self::assertSame('certificate', app(CertificateQrDestinationService::class)
            ->forIssuance($owner, (string) Str::uuid())['type']);
        $this->assertOnlyReadQueries();
        self::assertSame($before, $owner->fresh()->getAttributes());
    }

    public function test_explicit_reconciliation_revokes_drift_once_and_preserves_the_work(): void
    {
        [$owner, $item] = $this->approvedWork();
        $revision = (int) $owner->portfolio_sharing_revision;
        DB::table('portfolio_items')->where('id', $item->id)->update(['description' => 'Bulk edit']);
        $moderation = app(PortfolioModerationService::class);

        self::assertSame('pending', $moderation->reconcile($owner));
        self::assertSame($revision + 1, (int) $owner->portfolio_sharing_revision);
        self::assertNull($owner->portfolio_approved_hash);
        self::assertSame('pending', $moderation->reconcile($owner));
        self::assertSame($revision + 1, (int) $owner->fresh()->portfolio_sharing_revision);
        self::assertNotNull($item->fresh());
        self::assertSame(1, $item->mediaFiles()->count());
    }

    public function test_stale_reconciliation_cannot_revoke_a_newer_review_decision(): void
    {
        [$owner, $item] = $this->approvedWork();
        $stale = $owner->fresh();
        $item->update(['title' => 'New version approved after the old read']);
        $this->approve($owner->fresh());
        $approved = $owner->fresh()->getAttributes();

        self::assertSame('pending', app(PortfolioModerationService::class)->reconcile($stale));
        self::assertSame($approved, $owner->fresh()->getAttributes());
        self::assertSame('approved', app(PortfolioReviewReadService::class)->status($owner->fresh()));
    }

    public function test_accessor_uses_current_approved_slug_not_a_stale_models_slug(): void
    {
        [$owner] = $this->approvedWork();
        $stale = $owner->fresh();
        $newSlug = 'rokn-'.strtolower(Str::random(24));
        $owner->update(['portfolio_slug' => $newSlug]);
        $this->approve($owner->fresh());
        $this->forbidWriterResolution();

        DB::enableQueryLog();
        DB::flushQueryLog();
        self::assertSame(RoknPublicUrl::portfolio($newSlug), $stale->profile_deeplink);
        $this->assertOnlyReadQueries();

        $owner->delete();
        self::assertNull($stale->profile_deeplink);
        self::assertNull((new User())->profile_deeplink);
    }

    public function test_valid_approval_reads_and_certificate_issuance_do_not_consume_a_revision(): void
    {
        [$owner] = $this->approvedWork();
        $before = $owner->getAttributes();
        $this->forbidWriterResolution();

        DB::enableQueryLog();
        DB::flushQueryLog();
        $reader = app(PortfolioReviewReadService::class);
        self::assertSame($owner->portfolio_approved_hash, $reader->snapshot($owner)['hash']);
        self::assertSame('approved', $reader->status($owner));
        self::assertSame(RoknPublicUrl::portfolio($owner->portfolio_slug), $owner->profile_deeplink);
        self::assertSame('portfolio', app(CertificateQrDestinationService::class)
            ->forIssuance($owner, (string) Str::uuid())['type']);
        $this->assertOnlyReadQueries();
        self::assertSame($before, $owner->fresh()->getAttributes());
    }

    public function test_public_read_explicitly_requeues_drift_and_admin_preview_uses_the_new_revision(): void
    {
        [$owner, $item] = $this->approvedWork();
        $revision = (int) $owner->portfolio_sharing_revision;
        DB::table('portfolio_items')->where('id', $item->id)->update(['description' => 'Needs another review']);
        $public = app(PublicPortfolioService::class);

        self::assertNull($public->find($owner->portfolio_slug));
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);
        $preview = $public->adminPreview($owner);
        self::assertSame('pending', $preview['review']['status']);
        self::assertSame($revision + 1, $preview['review']['revision']);
        self::assertSame($revision + 1, (int) $owner->fresh()->portfolio_sharing_revision);
    }

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
        $this->approve($owner->fresh());

        return [$owner->fresh(), $item->fresh()];
    }

    private function approve(User $owner): void
    {
        $snapshot = app(PortfolioReviewReadService::class)->snapshot($owner);
        app(PortfolioModerationService::class)->decide(
            $owner, $this->owner('admin'), $snapshot['revision'], $snapshot['hash'], 'approved', null
        );
    }

    private function owner(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Owner '.Str::uuid(), 'email' => Str::uuid().'@rokn.test',
            'role' => $role, 'active' => true, 'portfolio_slug' => 'rokn-'.strtolower(Str::random(24)),
        ]);
    }

    private function forbidWriterResolution(): void
    {
        $this->app->bind(PortfolioModerationService::class, static function (): never {
            throw new \LogicException('Read-only consumers must not resolve portfolio moderation');
        });
    }

    private function assertOnlyReadQueries(): void
    {
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        self::assertNotEmpty($queries);
        foreach ($queries as $query) {
            self::assertMatchesRegularExpression('/^\s*(select|pragma)\b/i', $query['query']);
        }
    }
}
