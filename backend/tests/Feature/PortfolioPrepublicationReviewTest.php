<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\User;
use App\Services\BunnyService;
use App\Services\BunnyDeliveryService;
use App\Services\PortfolioModerationService;
use App\Services\PublicPortfolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PortfolioPrepublicationReviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->mock(BunnyDeliveryService::class)->shouldReceive('storageUrl')
            ->andReturnUsing(fn (string $path) => 'https://cdn.rokn.test/'.$path);
    }

    public function test_pending_is_explicit_even_when_empty_and_private_work_remains_owned(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner, 'api')->getJson('/api/v1/portfolio-profile')->assertOk()
            ->assertJsonPath('data.sharing_status', 'pending')
            ->assertJsonPath('data.sharing_revision', 1)
            ->assertJsonPath('data.sharing_suspended', false)
            ->assertJsonPath('data.sharing_rejection_reason', null)
            ->assertJsonPath('data.public_url', null);
        $item = $this->work($owner);
        $this->getJson('/api/v1/portfolio')->assertOk()->assertJsonPath('data.0.id', $item->id);
        $this->getJson('/api/v1/user/profile')->assertOk()->assertJsonPath('data.portfolio_url', null);
        self::assertNull($owner->fresh()->profile_deeplink);
        $this->assertNotPublic($owner, $item);
        $this->post(route('portfolio.report', $owner->portfolio_slug), ['message' => 'محتوى غير مناسب'])->assertNotFound();
    }

    public function test_pending_queue_only_contains_owners_with_selected_work(): void
    {
        $empty = $this->owner();
        $private = $this->owner();
        $this->work($private, false);
        $submitted = $this->owner();
        $this->work($submitted);

        $this->actingAs($this->owner('admin'), 'web')
            ->get(route('admin.portfolio-reviews.index'))
            ->assertOk()
            ->assertSee($submitted->name)
            ->assertDontSee($empty->name)
            ->assertDontSee($private->name);
    }

    public function test_only_admin_can_review_and_review_consumes_the_exact_preview_revision(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $preview = app(PublicPortfolioService::class)->adminPreview($owner);
        $decision = $this->decision($preview);
        $route = route('admin.portfolio-reviews.decide', $owner);
        $this->post($route, $decision)->assertRedirect();
        foreach (['client', 'moderator'] as $role) {
            $this->flushSession();
            $this->actingAs($this->owner($role), 'web')->post($route, $decision)->assertForbidden();
            $this->get(route('admin.portfolio-reviews.index'))->assertForbidden();
        }
        $this->flushSession();
        $admin = $this->owner('admin');
        $this->actingAs($admin, 'web')->get(route('admin.portfolio-reviews.index'))->assertOk()->assertSee($owner->name);
        $this->get(route('admin.portfolio-preview.show', $owner))->assertOk()->assertSee('اعتماد هذه النسخة للمشاركة');
        $this->post($route, $decision)->assertRedirect()->assertSessionHas('success');
        $this->post($route, $decision + ['reason' => 'سبب متأخر'])->assertStatus(409);
        self::assertSame($admin->id, $owner->fresh()->portfolio_reviewed_by);
        $public = app(PublicPortfolioService::class)->find($owner->portfolio_slug);
        self::assertNotNull($public);
        self::assertArrayNotHasKey('review', $public);
        $url = $public['projects'][0]['media'][0]['image_url'];
        self::assertStringContainsString('?revision=', $url);
        $this->get($url)->assertRedirect('https://cdn.rokn.test/portfolio/original.jpg')
            ->assertHeader('Referrer-Policy', 'no-referrer');
        $this->get(route('portfolio.media', [$owner->portfolio_slug, $item->mediaFiles->first()->public_id]))->assertNotFound();
    }

    public function test_edits_revoke_approval_and_old_media_routes_do_not_serve_a_replacement(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $this->approve($owner);
        $oldUrl = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'][0]['media'][0]['image_url'];
        $oldPreview = app(PublicPortfolioService::class)->adminPreview($owner);
        $oldAdminMedia = $oldPreview['projects'][0]['media'][0]['image_url'];
        $item->mediaFiles->first()->update(['file_path' => 'portfolio/replacement.jpg', 'content_sha256' => str_repeat('b', 64)]);
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);
        $this->get($oldUrl)->assertNotFound();
        $this->get($oldAdminMedia)->assertNotFound();
        $this->post(route('admin.portfolio-reviews.decide', $owner), $this->decision($oldPreview))->assertStatus(409);
        $this->assertNotPublic($owner, $item);
        $this->approve($owner);
        $newUrl = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'][0]['media'][0]['image_url'];
        self::assertNotSame($oldUrl, $newUrl);
        $this->get($oldUrl)->assertNotFound();
        $this->get($newUrl)->assertRedirect('https://cdn.rokn.test/portfolio/replacement.jpg');
        self::assertNotNull($item->fresh());
        self::assertSame(1, $item->mediaFiles()->count());
    }

    public function test_rejection_is_visible_to_owner_and_suspension_cannot_be_lifted_into_unapproved_content(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $this->approve($owner);
        $review = app(PublicPortfolioService::class)->adminPreview($owner);
        $this->post(route('admin.portfolio-reviews.decide', $owner), [
            ...$this->decision($review), 'decision' => 'rejected', 'reason' => 'أزل بيانات الاتصال الخاصة بغيرك',
        ])->assertRedirect();
        $this->actingAs($owner->fresh(), 'api')->getJson('/api/v1/portfolio-profile')->assertOk()
            ->assertJsonPath('data.sharing_status', 'rejected')
            ->assertJsonPath('data.sharing_rejection_reason', 'أزل بيانات الاتصال الخاصة بغيرك')
            ->assertJsonPath('data.public_url', null);
        $owner->forceFill(['portfolio_sharing_suspended_at' => now()])->save();
        $this->getJson('/api/v1/portfolio-profile')->assertJsonPath('data.sharing_status', 'suspended');
        $owner->forceFill(['portfolio_sharing_suspended_at' => null])->save();
        $this->assertNotPublic($owner, $item);
        $item->update(['description' => 'وصف معدل للمراجعة']);
        $this->getJson('/api/v1/portfolio-profile')->assertJsonPath('data.sharing_status', 'pending')
            ->assertJsonPath('data.sharing_rejection_reason', null);
        $this->getJson('/api/v1/portfolio')->assertOk()->assertJsonPath('data.0.id', $item->id);
    }

    public function test_profile_and_selected_item_changes_invalidate_but_private_drafts_and_noops_do_not(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $this->approve($owner);
        $private = $this->work($owner, false);
        $private->update(['title' => 'Draft only']);
        $item->update(['title' => $item->title]);
        self::assertSame('approved', app(PortfolioModerationService::class)->reconcileOwnerState($owner)['sharing_status']);
        $this->actingAs($owner->fresh(), 'api')->putJson('/api/v1/portfolio-profile', ['portfolio_headline' => 'عنوان جديد'])
            ->assertOk()->assertJsonPath('data.sharing_status', 'pending')->assertJsonPath('data.public_url', null);
        $this->approve($owner);
        $owner->fresh()->update(['name' => 'Changed account identity']);
        self::assertNull($owner->fresh()->profile_deeplink);
        $this->approve($owner);
        $item->update(['is_featured' => true]);
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);
    }

    public function test_out_of_band_write_and_deleted_media_fail_closed_without_exposing_other_owners(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $other = $this->owner();
        $otherItem = $this->work($other);
        $this->approve($owner);
        $preview = app(PublicPortfolioService::class)->adminPreview($owner);
        self::assertNull(app(PublicPortfolioService::class)->mediaForPortfolio($owner->portfolio_slug,
            $otherItem->mediaFiles->first()->public_id, (string) $preview['review']['revision'], $preview['review']['snapshot_hash']));
        DB::table('portfolio_items')->where('id', $item->id)->update(['description' => 'Unreviewed bulk edit']);
        $this->assertNotPublic($owner, $item);
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);
        $this->approve($owner);
        $url = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'][0]['media'][0]['image_url'];
        $item->mediaFiles->first()->delete();
        $this->get($url)->assertNotFound();
        self::assertNotNull($item->fresh());
    }

    public function test_migration_pauses_existing_links_without_removing_private_work(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $migration = require database_path('migrations/2026_09_13_180000_add_portfolio_prepublication_review.php');
        $migration->down();
        self::assertSame($owner->portfolio_slug, DB::table('users')->where('id', $owner->id)->value('portfolio_slug'));
        $migration->up();
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);
        self::assertSame(1, $owner->fresh()->portfolio_sharing_revision);
        self::assertNotNull($item->fresh());
        self::assertSame(1, $item->mediaFiles()->count());
        $this->assertNotPublic($owner, $item);
    }

    public function test_permanent_portfolio_qr_target_cannot_bypass_review_or_edit_revocation(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $certificate = new \App\Models\Certificate([
            'public_id' => (string) Str::uuid(), 'user_id' => $owner->id,
            'certificate_text_template_key' => 'projects',
        ]);
        $certificate->setRelation('user', $owner);
        $qr = app(\App\Services\CertificateQrDestinationService::class)->for($certificate);
        self::assertSame('portfolio', $qr['type']);
        $permanentQrTarget = $qr['url'];
        $this->get($permanentQrTarget)->assertNotFound()->assertDontSee($item->title);
        $this->approve($owner);
        $this->get($permanentQrTarget)->assertOk()->assertSee($item->title);
        $item->update(['title' => 'New unreviewed version']);
        $this->get($permanentQrTarget)->assertNotFound()->assertDontSee('New unreviewed version');
        $this->approve($owner);
        $this->get($permanentQrTarget)->assertOk()->assertSee('New unreviewed version');
        self::assertSame($permanentQrTarget, app(\App\Services\CertificateQrDestinationService::class)->for($certificate)['url']);
    }

    public function test_clients_cannot_approve_or_edit_other_owners_and_unready_files_cannot_be_approved(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner);
        $this->actingAs($owner, 'api')->putJson('/api/v1/portfolio-profile', [
            'portfolio_sharing_status' => 'approved', 'portfolio_approved_hash' => str_repeat('a', 64),
        ])->assertOk()->assertJsonPath('data.sharing_status', 'pending')->assertJsonPath('data.public_url', null);
        $this->actingAs($this->owner(), 'api')->postJson('/api/v1/portfolio/'.$item->id, ['title' => 'Other account edit'])->assertNotFound();
        $item->mediaFiles->first()->update(['file_type' => 'video', 'file_path' => (string) Str::uuid()]);
        $this->mock(BunnyService::class)->shouldReceive('inspectRemoteVideo')
            ->once()->andReturn(['state' => 'unavailable']);
        $this->actingAs($this->owner('admin'), 'web');
        $preview = app(PublicPortfolioService::class)->adminPreview($owner);
        $this->postJson(route('admin.portfolio-reviews.decide', $owner), $this->decision($preview))
            ->assertUnprocessable();
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);
    }

    private function decision(array $preview): array
    {
        return ['revision' => $preview['review']['revision'], 'snapshot_hash' => $preview['review']['snapshot_hash'], 'decision' => 'approved'];
    }

    private function approve(User $owner): void
    {
        $this->flushSession();
        $this->actingAs($this->owner('admin'), 'web');
        $preview = app(PublicPortfolioService::class)->adminPreview($owner);
        $this->post(route('admin.portfolio-reviews.decide', $owner), $this->decision($preview))
            ->assertRedirect()->assertSessionHas('success');
    }

    private function assertNotPublic(User $owner, PortfolioItem $item): void
    {
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertNotFound();
        $this->getJson('/api/v1/public/portfolios/'.$owner->portfolio_slug)->assertNotFound();
        $this->get(route('portfolio.media', [$owner->portfolio_slug, $item->mediaFiles->first()->public_id]))->assertNotFound();
    }

    private function owner(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Owner '.Str::uuid(), 'email' => Str::uuid().'@rokn.test',
            'role' => $role, 'active' => true, 'portfolio_slug' => 'rokn-'.strtolower(Str::random(24)),
        ]);
    }

    private function work(User $owner, bool $public = true): PortfolioItem
    {
        $item = PortfolioItem::query()->create(['user_id' => $owner->id, 'title' => 'Selected work', 'is_public' => $public]);
        $item->mediaFiles()->create(['file_path' => 'portfolio/original.jpg', 'file_type' => 'image', 'content_sha256' => str_repeat('a', 64)]);
        return $item->fresh(['mediaFiles']);
    }
}
