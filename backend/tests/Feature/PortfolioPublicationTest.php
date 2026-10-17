<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Certificate;
use App\Models\Course;
use App\Models\PortfolioItem;
use App\Models\PortfolioMedia;
use App\Models\Project;
use App\Models\User;
use App\Services\BunnyDeliveryService;
use App\Services\PortfolioModerationService;
use App\Services\PortfolioReviewReadService;
use App\Services\PublicPortfolioService;
use App\Support\RoknPublicUrl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Draft/published portfolio, share previews, project pages, badges. Learner contact links are not shown. */
final class PortfolioPublicationTest extends TestCase
{
    use RefreshDatabase;

    public function test_approved_version_stays_public_while_an_edit_and_a_new_image_wait_for_review(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner, 'Approved title');
        $this->approve($owner);

        $item->update(['title' => 'Edited title']);
        $added = $item->mediaFiles()->create([
            'file_path' => 'portfolio/added.jpg', 'file_type' => 'image', 'content_sha256' => str_repeat('c', 64),
        ]);
        $item->forceFill(['is_public' => false])->save(); // appendImage un-finalizes the draft

        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()
            ->assertSee('Approved title')->assertDontSee('Edited title')
            ->assertDontSee($added->public_id);
        self::assertSame('pending', $owner->fresh()->portfolio_sharing_status);
        $this->actingAs($owner->fresh(), 'api')->getJson('/api/v1/portfolio-profile')->assertOk()
            ->assertJsonPath('data.sharing_status', 'pending')
            ->assertJsonPath('data.published', true)
            ->assertJsonPath('data.has_unpublished_changes', true)
            ->assertJsonPath('data.public_url', RoknPublicUrl::portfolio($owner->portfolio_slug));
        $publication = app(PortfolioReviewReadService::class)->publication($owner->fresh());
        self::assertNull(app(PublicPortfolioService::class)->mediaForPortfolio(
            $owner->portfolio_slug, $added->public_id, (string) $publication['revision'], $publication['hash']
        ));

        $item->forceFill(['is_public' => true])->save(); // finalize
        $this->approve($owner);
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()
            ->assertSee('Edited title')->assertDontSee('Approved title');
        self::assertCount(2, app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'][0]['media']);
    }

    public function test_hiding_and_deleting_a_project_apply_immediately_without_another_review(): void
    {
        $owner = $this->owner();
        $hidden = $this->work($owner, 'Project to hide');
        $kept = $this->work($owner, 'Project to keep');
        $this->approve($owner);
        $hiddenPage = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'];
        $hiddenPage = collect($hiddenPage)->firstWhere('title', 'Project to hide');
        $mediaUrl = $hiddenPage['media'][0]['image_url'];

        $this->actingAs($owner, 'api')->postJson('/api/v1/portfolio/'.$hidden->id, ['is_public' => false])->assertOk()
            ->assertJsonPath('data.is_public', false)->assertJsonPath('data.is_hidden', true)
            ->assertJsonPath('data.upload_state', 'ready');
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()
            ->assertDontSee('Project to hide')->assertSee('Project to keep');
        $this->get($hiddenPage['url'])->assertNotFound();
        $this->get($mediaUrl)->assertNotFound();
        $this->getJson('/api/v1/portfolio-profile')->assertJsonPath('data.sharing_status', 'approved')
            ->assertJsonPath('data.has_unpublished_changes', false);

        // Unhiding restores already-approved content without a new review.
        $this->postJson('/api/v1/portfolio/'.$hidden->id, ['is_public' => true])->assertOk()
            ->assertJsonPath('data.is_public', true);
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertSee('Project to hide');
        $this->getJson('/api/v1/portfolio-profile')->assertJsonPath('data.sharing_status', 'approved');

        $this->deleteJson('/api/v1/portfolio/'.$hidden->id)->assertOk();
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()
            ->assertDontSee('Project to hide')->assertSee('Project to keep');
        $this->getJson('/api/v1/portfolio-profile')->assertJsonPath('data.sharing_status', 'approved');
        self::assertSame('approved', $owner->fresh()->portfolio_sharing_status);
        self::assertNotNull($kept->fresh());
    }

    public function test_share_previews_have_absolute_open_graph_and_twitter_tags_and_stay_noindex(): void
    {
        $owner = $this->owner(['portfolio_headline' => 'مصمم سوشيال ميديا']);
        $item = $this->work($owner, 'Poster series', ['width' => 1080, 'height' => 1920]);
        $this->approve($owner);

        $response = $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->assertSee('<meta name="robots" content="noindex,nofollow,noarchive">', false)
            ->assertSee('<meta property="og:type" content="profile">', false)
            ->assertSee('<meta property="og:url" content="'.e(RoknPublicUrl::portfolio($owner->portfolio_slug)).'">', false)
            ->assertSee('<meta property="og:description" content="مصمم سوشيال ميديا">', false)
            ->assertSee('<meta name="twitter:card" content="summary_large_image">', false)
            ->assertSee('<meta property="og:image:width" content="1080">', false)
            ->assertSee('width="1080" height="1920"', false)
            ->assertDontSee('aspect-ratio:16/9;object-fit:cover', false);
        $image = $this->metaContent((string) $response->getContent(), 'og:image');
        self::assertStringStartsWith(RoknPublicUrl::portfolioMedia($owner->portfolio_slug, $item->mediaFiles->first()->public_id).'?', $image);

        $this->mock(BunnyDeliveryService::class)->shouldReceive('storageUrl')
            ->with('portfolio/original.jpg', 600, 3600)->andReturn('https://cdn.rokn.test/original.jpg');
        $this->get($image)->assertRedirect('https://cdn.rokn.test/original.jpg');

        $project = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'][0];
        $this->get($project['url'])->assertOk()
            ->assertSee('<meta property="og:type" content="article">', false)
            ->assertSee('<meta property="og:url" content="'.e($project['url']).'">', false)
            ->assertSee('<meta property="og:image" content="'.e($image).'">', false);
    }

    public function test_project_page_is_shareable_only_for_approved_visible_projects_and_lazy_loads_video(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner, 'Brand identity', ['width' => 800, 'height' => 600]);
        $item->update(['description' => "سطر أول\nسطر <b>ثاني</b>", 'role' => 'مصمم', 'tools' => ['Figma'], 'external_url' => 'https://example.com/work']);
        $item->mediaFiles()->create(['file_path' => (string) Str::uuid(), 'file_type' => 'video', 'content_sha256' => str_repeat('d', 64)]);
        $projectUrl = RoknPublicUrl::portfolioProject($owner->portfolio_slug, $item->fresh()->public_id);
        $this->get($projectUrl)->assertNotFound(); // never approved

        $this->approveWithReadyVideo($owner);
        $this->get($projectUrl)->assertOk()
            ->assertSee('Brand identity')->assertSee('مصمم')->assertSee('Figma')
            ->assertSee('https://example.com/work')
            ->assertSee('سطر أول<br />', false)->assertSee('&lt;b&gt;ثاني&lt;/b&gt;', false)
            ->assertSee('width="800" height="600"', false)
            ->assertSee('data-video-src=', false)->assertSee('variant=poster', false)
            ->assertDontSee('<iframe', false);
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertSee(e($projectUrl), false);

        $later = $this->work($owner, 'Added after approval');
        $this->get(RoknPublicUrl::portfolioProject($owner->portfolio_slug, $later->public_id))->assertNotFound();
        $other = $this->work($this->owner(), 'Other owner work');
        $this->get(RoknPublicUrl::portfolioProject($owner->portfolio_slug, $other->public_id))->assertNotFound();
        $this->get(RoknPublicUrl::portfolioProject($owner->portfolio_slug, (string) Str::uuid()))->assertNotFound();
        $item->update(['hidden_at' => now()]);
        $this->get($projectUrl)->assertNotFound();
        $item->update(['hidden_at' => null]);
        $this->get($projectUrl)->assertOk();
        $owner->forceFill(['portfolio_sharing_suspended_at' => now()])->save();
        $this->get($projectUrl)->assertNotFound();
    }

    public function test_media_redirect_is_publicly_cacheable_cookie_free_and_poster_uses_the_stream_thumbnail(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner, 'Reel');
        $guid = (string) Str::uuid();
        $item->mediaFiles()->create(['file_path' => $guid, 'file_type' => 'video', 'content_sha256' => str_repeat('e', 64)]);
        $this->approveWithReadyVideo($owner);
        $media = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'][0]['media'];
        $delivery = $this->mock(BunnyDeliveryService::class);
        $delivery->shouldReceive('storageUrl')->with('portfolio/original.jpg', 600, 3600)
            ->andReturn('https://cdn.rokn.test/original.jpg');
        $delivery->shouldReceive('videoThumbnail')->once()->with($guid, 'thumbnail.jpg', 3600)
            ->andReturn(['url' => 'https://stream.rokn.test/thumb.jpg']);

        $image = $this->get($media[0]['image_url'])->assertRedirect('https://cdn.rokn.test/original.jpg');
        self::assertMatchesRegularExpression('/^(max-age=\d+, public|public, max-age=\d+)$/', (string) $image->headers->get('Cache-Control'));
        self::assertSame([], $image->headers->getCookies());
        $this->get($media[1]['poster_url'])->assertRedirect('https://stream.rokn.test/thumb.jpg');
        $this->get($media[0]['image_url'].'&variant=original')->assertNotFound();
        $this->get(route('portfolio.media', [$owner->portfolio_slug, $item->mediaFiles->first()->public_id]))->assertNotFound();
    }

    public function test_reviewed_in_rokn_badge_only_for_passed_projects_with_a_valid_certificate_link(): void
    {
        $owner = $this->owner();
        $course = new Course();
        $course->forceFill(['tenant_id' => 1, 'name_ar' => 'كورس التصميم', 'name_en' => 'Design'])->save();
        $passedProject = Project::factory()->create();
        $pendingProject = Project::factory()->create();
        $this->submission($owner, $passedProject, 'passed');
        $this->submission($owner, $pendingProject, 'pending');
        $verified = $this->work($owner, 'Passed project');
        $verified->forceFill(['source_project_id' => $passedProject->id, 'course_id' => $course->id])->save();
        $unverified = $this->work($owner, 'Pending project');
        $unverified->forceFill(['source_project_id' => $pendingProject->id, 'course_id' => $course->id])->save();
        $this->work($owner, 'Personal project');
        $certificate = Certificate::create([
            'public_id' => (string) Str::uuid(), 'user_id' => $owner->id, 'course_id' => $course->id,
            'holder_name' => 'Owner', 'course_name' => 'كورس التصميم', 'certificate_text_template_key' => 'applied',
            'certificate_text' => 'نص', 'image_path' => 'certificates/x.png', 'generated_at' => now(),
            'status' => 'active', 'verification_level' => 'reviewed_project',
        ]);
        $this->approve($owner);

        $projects = collect(app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'])->keyBy('title');
        self::assertTrue($projects['Passed project']['verified']);
        self::assertSame(RoknPublicUrl::certificate($certificate->public_id), $projects['Passed project']['certificate_url']);
        self::assertFalse($projects['Pending project']['verified']);
        self::assertNull($projects['Pending project']['certificate_url']);
        self::assertFalse($projects['Personal project']['verified']);
        $html = (string) $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()->getContent();
        self::assertSame(1, substr_count($html, 'مشروع اتراجع في ركن'));
        self::assertStringContainsString('href="'.e(RoknPublicUrl::certificate($certificate->public_id)).'"', $html);

        $certificate->forceFill(['status' => 'revoked', 'revoked_at' => now()])->save();
        $projects = collect(app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'])->keyBy('title');
        self::assertTrue($projects['Passed project']['verified']);
        self::assertNull($projects['Passed project']['certificate_url']);
    }

    public function test_public_pages_show_no_learner_contact_links(): void
    {
        $owner = $this->owner();
        $this->work($owner, 'Work');
        $this->actingAs($owner, 'api')->putJson('/api/v1/portfolio-profile', ['portfolio_links' => [
            ['label' => 'Behance', 'url' => 'https://www.behance.net/someone'],
            ['label' => 'WhatsApp', 'url' => 'https://wa.me/201001234567'],
        ]])->assertOk();
        $this->approve($owner);

        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()
            ->assertDontSee('behance.net/someone')
            ->assertDontSee('wa.me/201001234567');
    }

    public function test_cover_featured_order_and_skills_alias_follow_the_owner(): void
    {
        $owner = $this->owner();
        $first = $this->work($owner, 'First project');
        $second = $this->work($owner, 'Second project');
        $coverMedia = $second->mediaFiles()->create([
            'file_path' => 'portfolio/cover.jpg', 'file_type' => 'image', 'content_sha256' => str_repeat('f', 64),
        ]);
        $this->actingAs($owner, 'api')->postJson('/api/v1/portfolio/'.$second->id, [
            'cover_media_id' => $first->mediaFiles->first()->id,
        ])->assertUnprocessable();
        $this->postJson('/api/v1/portfolio/'.$second->id, [
            'cover_media_id' => $coverMedia->id, 'skills' => ['Figma', 'Photoshop'],
        ])->assertOk()
            ->assertJsonPath('data.cover_media_id', $coverMedia->id)
            ->assertJsonPath('data.tools', ['Figma', 'Photoshop'])
            ->assertJsonPath('data.skills', ['Figma', 'Photoshop'])
            ->assertJsonPath('data.public_id', $second->public_id);
        $this->approve($owner);

        $projects = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'];
        self::assertSame('Second project', $projects[0]['title']);
        self::assertStringContainsString($coverMedia->public_id, $projects[0]['cover']['image_url']);
        self::assertSame(['Figma', 'Photoshop'], $projects[0]['tools']);

        // Featuring reorders approved work immediately; nothing new needs review.
        $this->postJson('/api/v1/portfolio/'.$first->id, ['is_featured' => true])->assertOk();
        $projects = app(PublicPortfolioService::class)->find($owner->portfolio_slug)['projects'];
        self::assertSame('First project', $projects[0]['title']);
        self::assertTrue($projects[0]['is_featured']);
        self::assertSame('approved', $owner->fresh()->portfolio_sharing_status);
    }

    public function test_a_pre_publication_approval_survives_the_first_edit(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner, 'Approved before publications');
        $snapshot = app(PortfolioReviewReadService::class)->snapshot($owner->fresh());
        DB::table('users')->where('id', $owner->id)->update([
            'portfolio_sharing_status' => 'approved', 'portfolio_approved_hash' => $snapshot['hash'],
        ]);
        self::assertSame(0, DB::table('portfolio_publications')->count());

        $item->update(['title' => 'Edited before any public read']);

        self::assertSame(1, DB::table('portfolio_publications')->count());
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()
            ->assertSee('Approved before publications')->assertDontSee('Edited before any public read');
        $stored = DB::table('portfolio_publications')->where('user_id', $owner->id)->first();
        self::assertSame($snapshot['hash'], PortfolioReviewReadService::hashContent(json_decode($stored->content, true)));
    }

    public function test_rejecting_a_draft_keeps_the_published_version_but_rejecting_live_content_takes_it_down(): void
    {
        $owner = $this->owner();
        $item = $this->work($owner, 'Live work');
        $this->approve($owner);
        $item->update(['title' => 'Rejected edit']);
        $this->decide($owner, 'rejected', 'لا يناسب');
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertOk()->assertSee('Live work');
        $this->actingAs($owner->fresh(), 'api')->getJson('/api/v1/portfolio-profile')
            ->assertJsonPath('data.sharing_status', 'rejected')->assertJsonPath('data.published', true);

        $item->update(['title' => 'Live work']);
        self::assertSame('approved', app(PortfolioModerationService::class)->reconcileOwnerState($owner)['sharing_status']);
        $this->decide($owner, 'rejected', 'مخالفة');
        $this->get(route('portfolio.public', $owner->portfolio_slug))->assertNotFound();
    }

    public function test_migration_backfills_project_public_ids_and_resumes(): void
    {
        $owner = $this->owner();
        $migration = require database_path('migrations/2026_10_17_140500_add_portfolio_publications.php');
        $migration->down();
        $id = DB::table('portfolio_items')->insertGetId([
            'user_id' => $owner->id, 'title' => 'Legacy row', 'is_public' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $migration->up();
        $migration->up();
        self::assertTrue(Str::isUuid((string) DB::table('portfolio_items')->where('id', $id)->value('public_id')));
        self::assertNull(DB::table('portfolio_items')->where('id', $id)->value('hidden_at'));
        self::assertSame(0, DB::table('portfolio_publications')->count());
    }

    private function metaContent(string $html, string $property): string
    {
        self::assertSame(1, preg_match('/<meta property="'.preg_quote($property, '/').'" content="([^"]+)">/', $html, $match));

        return html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5);
    }

    private function submission(User $owner, Project $project, string $status): void
    {
        DB::table('project_submissions')->insert([
            'public_id' => (string) Str::uuid(), 'user_id' => $owner->id, 'project_id' => $project->id,
            'idempotency_key' => (string) Str::uuid(), 'review_status' => $status,
            'submitted_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function approve(User $owner): void
    {
        $this->decide($owner, 'approved');
    }

    /** Video readiness comes from Bunny's API; fake a playable provider status. */
    private function approveWithReadyVideo(User $owner): void
    {
        \Illuminate\Support\Facades\Cache::flush();
        $this->mock(\App\Services\BunnyService::class)->shouldReceive('inspectRemoteVideo')
            ->andReturn(['state' => 'ok', 'details' => ['status' => 4], 'http_status' => 200]);
        $delivery = $this->mock(BunnyDeliveryService::class);
        $delivery->shouldReceive('storageUrl')->andReturn('https://cdn.rokn.test/ready.jpg');
        $delivery->shouldReceive('videoEmbed')->andReturn(['url' => 'https://iframe.rokn.test/embed', 'expires_at' => null]);
        $delivery->shouldReceive('videoPlayback')->andReturn(['url' => 'https://stream.rokn.test/play.m3u8', 'expires_at' => null]);
        $snapshot = app(PortfolioReviewReadService::class)->snapshot($owner->fresh());
        app(PortfolioModerationService::class)->decide(
            $owner->fresh(), $this->owner(['role' => 'admin']), $snapshot['revision'], $snapshot['hash'], 'approved', null
        );
        $this->app->forgetInstance(BunnyDeliveryService::class);
        $this->app->forgetInstance(\App\Services\BunnyService::class);
    }

    private function decide(User $owner, string $decision, ?string $reason = null): void
    {
        $snapshot = app(PortfolioReviewReadService::class)->snapshot($owner->fresh());
        $this->mock(BunnyDeliveryService::class)->shouldReceive('storageUrl')
            ->andReturn('https://cdn.rokn.test/ready.jpg');
        app(PortfolioModerationService::class)->decide(
            $owner->fresh(), $this->owner(['role' => 'admin']), $snapshot['revision'], $snapshot['hash'], $decision, $reason
        );
        $this->app->forgetInstance(BunnyDeliveryService::class);
    }

    private function owner(array $attributes = []): User
    {
        return User::query()->forceCreate($attributes + [
            'name' => 'Owner '.Str::uuid(), 'email' => Str::uuid().'@rokn.test',
            'role' => 'client', 'active' => true, 'portfolio_slug' => 'rokn-'.strtolower(Str::random(24)),
        ]);
    }

    private function work(User $owner, string $title, array $media = []): PortfolioItem
    {
        $item = PortfolioItem::query()->create(['user_id' => $owner->id, 'title' => $title, 'is_public' => true]);
        $item->mediaFiles()->create($media + [
            'file_path' => 'portfolio/original.jpg', 'file_type' => 'image', 'content_sha256' => str_repeat('a', 64),
        ]);

        return $item->fresh(['mediaFiles']);
    }
}
