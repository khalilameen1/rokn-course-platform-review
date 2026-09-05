<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\User;
use App\Services\BunnyService;
use App\Support\RoknPublicUrl;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Mockery;

/**
 * Feature tests covering Portfolio API endpoints:
 * listing user portfolio items, creating new entries, viewing details, and deletion.
 */
class PortfolioEndpointTest extends ApiTestCase
{
    public function test_can_list_portfolio_items(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/portfolio');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_portfolio_list_supports_bounded_pages_without_changing_item_contract(): void
    {
        DB::table('portfolio_items')->insert([
            'user_id' => $this->user->id,
            'title' => 'Second item',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/portfolio?summary=1&page=1&per_page=1')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('pagination.per_page', 1)
            ->assertJsonPath('pagination.total', 2);
    }

    public function test_can_create_portfolio_item(): void
    {
        $response = $this->actingAs($this->user, 'api')->postJson('/api/v1/portfolio', [
            'title' => 'My Portfolio Item',
            'description' => 'Description here',
            'file_type' => 'text',
            // Client publication flags never bypass the explicit finalize
            // action after at least one media file is durable.
            'is_public' => false,
        ]);
        $response->assertOk()->assertJsonPath('data.is_public', false);
        $this->assertDatabaseHas('portfolio_items', [
            'user_id' => $this->user->id,
            'title' => 'My Portfolio Item',
            'is_public' => false,
        ]);
    }

    public function test_empty_portfolio_item_cannot_be_published(): void
    {
        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/portfolio/1/finalize')
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('portfolio_items', [
            'id' => 1,
            'user_id' => $this->user->id,
            'is_public' => false,
        ]);
    }

    public function test_processing_video_cannot_be_published(): void
    {
        DB::table('portfolio_media')->insert([
            'portfolio_item_id' => 1,
            'file_type' => 'video',
            'file_path' => 'processing-video-guid',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->once()->andReturn([
            'state' => 'ok',
            'details' => [
                'status' => 2,
                'encodeProgress' => 50,
                'availableResolutions' => '',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldNotReceive('getSignedEmbedUrl');
        $bunny->shouldNotReceive('getSignedPlayUrl');
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/portfolio/1/finalize')
            ->assertStatus(409)
            ->assertJsonPath('success', false);

        $this->assertDatabaseHas('portfolio_items', [
            'id' => 1,
            'is_public' => false,
        ]);
    }

    public function test_ready_media_is_published_only_by_finalize(): void
    {
        DB::table('portfolio_media')->insert([
            'portfolio_item_id' => 1,
            'file_type' => 'image',
            'file_path' => 'portfolio/ready-image.webp',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('generateBunnySignedUrl')
            ->twice()
            ->with('portfolio/ready-image.webp', 300)
            ->andReturn('https://cdn.example.test/ready-image');
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/portfolio/1/finalize')
            ->assertOk()
            ->assertJsonPath('data.is_public', true)
            ->assertJsonPath('data.upload_state', 'ready');

        $this->assertDatabaseHas('portfolio_items', [
            'id' => 1,
            'is_public' => true,
            'expected_media_count' => 1,
        ]);
    }

    public function test_appending_new_media_unpublishes_the_previous_share(): void
    {
        DB::table('portfolio_items')->where('id', 1)->update(['is_public' => true]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadFileToStorage')->once()->andReturn('portfolio/new-image.webp');
        $bunny->shouldReceive('consumeStorageCleanupCandidate')->once()->with('portfolio/new-image.webp');
        $bunny->shouldReceive('generateBunnySignedUrl')
            ->once()
            ->with('portfolio/new-image.webp', 300)
            ->andReturn('https://cdn.example.test/new-image');
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->post('/api/v1/portfolio/1/media', [
                'client_request_id' => '33333333-3333-4333-8333-333333333333',
                'file' => UploadedFile::fake()->image('work.jpg', 10, 10)->size(2),
                'file_type' => 'image',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'ready');

        $this->assertDatabaseHas('portfolio_items', [
            'id' => 1,
            'is_public' => false,
        ]);
    }

    public function test_can_view_portfolio_item(): void
    {
        $response = $this->actingAs($this->user, 'api')->getJson('/api/v1/portfolio/1');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_can_delete_portfolio_item(): void
    {
        $response = $this->actingAs($this->user, 'api')->deleteJson('/api/v1/portfolio/1');
        $this->assertNotEquals(404, $response->status());
    }

    public function test_portfolio_mutations_never_cross_account_ownership(): void
    {
        $other = new User();
        $other->name = 'Other Learner';
        $other->phone = '01000000002';
        $other->email = 'other-portfolio@rokn.test';
        $other->password = bcrypt('password123');
        $other->active = true;
        $other->save();

        $this->actingAs($other, 'api')
            ->getJson('/api/v1/portfolio/1')
            ->assertNotFound();
        $this->actingAs($other, 'api')
            ->postJson('/api/v1/portfolio/1', ['title' => 'Taken over'])
            ->assertNotFound();
        $this->actingAs($other, 'api')
            ->postJson('/api/v1/portfolio/1/finalize')
            ->assertNotFound();
        $this->actingAs($other, 'api')
            ->post('/api/v1/portfolio/1/media')
            ->assertNotFound();
        $this->actingAs($other, 'api')
            ->deleteJson('/api/v1/portfolio/1')
            ->assertOk()
            ->assertJsonPath('data.already_deleted', true);

        $this->assertDatabaseHas('portfolio_items', [
            'id' => 1,
            'user_id' => $this->user->id,
            'title' => 'Sample Portfolio Item',
        ]);
    }

    public function test_item_creation_rejects_the_retired_inline_media_contract(): void
    {
        $itemCountBefore = DB::table('portfolio_items')->count();
        $mediaCountBefore = DB::table('portfolio_media')->count();
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldNotReceive('uploadFileToStorage');
        $bunny->shouldNotReceive('uploadVerifiedVideo');
        $this->app->instance(BunnyService::class, $bunny);

        $response = $this->actingAs($this->user, 'api')->post('/api/v1/portfolio', [
            'title' => 'مشروع قابل لإعادة المحاولة',
            'files' => [UploadedFile::fake()->image('first.jpg', 10, 10)->size(2)],
            'file_types' => ['image'],
        ]);

        $response->assertUnprocessable()->assertJsonValidationErrors(['files', 'file_types']);
        self::assertSame($itemCountBefore, DB::table('portfolio_items')->count());
        self::assertSame($mediaCountBefore, DB::table('portfolio_media')->count());
    }

    public function test_portfolio_creation_rejects_conflicting_idempotency_keys(): void
    {
        $countBefore = DB::table('portfolio_items')->count();

        $this->actingAs($this->user, 'api')
            ->withHeader('Idempotency-Key', '11111111-1111-4111-8111-111111111111')
            ->postJson('/api/v1/portfolio', [
                'client_request_id' => '22222222-2222-4222-8222-222222222222',
                'title' => 'Conflicting request',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('client_request_id');

        self::assertSame($countBefore, DB::table('portfolio_items')->count());
    }

    public function test_video_cannot_bypass_the_resumable_direct_upload_contract(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldNotReceive('uploadVerifiedVideo');
        $bunny->shouldNotReceive('uploadFileToStorage');
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->post('/api/v1/portfolio/1/media', [
                'file' => UploadedFile::fake()->create('sample.mp4', 10, 'video/mp4'),
                'file_type' => 'video',
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['file', 'file_type']);

        self::assertSame(0, DB::table('portfolio_media')->where('file_path', 'orphan-guid')->count());
    }

    public function test_failed_image_append_leaves_no_media_row(): void
    {
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadFileToStorage')
            ->once()
            ->with(
                Mockery::type(UploadedFile::class),
                'portfolio',
                Mockery::type('string'),
                'portfolio_upload_unpublished'
            )
            ->andReturnNull();
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->post('/api/v1/portfolio/1/media', [
                'file' => UploadedFile::fake()->image('work.jpg', 10, 10)->size(2),
                'file_type' => 'image',
            ])
            ->assertStatus(503)
            ->assertJsonPath('success', false);

        self::assertSame(0, DB::table('portfolio_media')->where('portfolio_item_id', 1)->count());
    }

    public function test_remote_cleanup_failure_keeps_portfolio_metadata_retryable(): void
    {
        DB::table('portfolio_items')->where('id', 1)->update([
            'is_public' => true,
            'expected_media_count' => 0,
        ]);
        $mediaId = (int) DB::table('portfolio_media')->insertGetId([
            'portfolio_item_id' => 1,
            'file_type' => 'video',
            'file_path' => 'remote-guid',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('queueVideoCleanup')
            ->once()
            ->with('remote-guid', null, 'portfolio_media_deleted', 1, false)
            ->andReturn(new BunnyVideoCleanupCandidate(['video_guid' => 'remote-guid']));
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->deleteJson("/api/v1/portfolio/1/media/{$mediaId}")
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('portfolio_media', ['id' => $mediaId]);
        $this->assertDatabaseHas('portfolio_items', [
            'id' => 1,
            'is_public' => false,
        ]);
    }

    public function test_portfolio_contract_never_exposes_private_storage_identifiers(): void
    {
        DB::table('portfolio_media')->insert([
            'portfolio_item_id' => 1,
            'file_type' => 'image',
            'file_path' => 'portfolio/private-object.jpg',
            'thumbnail_path' => 'portfolio/private-thumbnail.jpg',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('generateBunnySignedUrl')
            ->once()
            ->with('portfolio/private-object.jpg', 300)
            ->andReturn('https://cdn.example/signed-image');
        $this->app->instance(BunnyService::class, $bunny);

        $this->actingAs($this->user, 'api')
            ->getJson('/api/v1/portfolio')
            ->assertOk()
            ->assertJsonPath('data.0.media.0.image_url', 'https://cdn.example/signed-image')
            ->assertJsonMissingPath('data.0.media.0.file_path')
            ->assertJsonMissingPath('data.0.media.0.thumbnail_path');
    }

    public function test_public_share_contains_only_public_portfolio_identity_and_works(): void
    {
        $slug = 'rokn-aaaaaaaaaaaaaaaaaaaaaaaa';
        $mediaPublicId = (string) Str::uuid();
        $certificatePublicId = (string) Str::uuid();
        $this->user->forceFill([
            'portfolio_slug' => $slug,
            'portfolio_headline' => null,
            'job_title' => 'وظيفة الحساب الخاصة',
            'bio' => 'نبذة الحساب الخاصة',
        ])->save();
        DB::table('portfolio_items')->where('id', 1)->update([
            'is_public' => true,
            'expected_media_count' => 1,
        ]);
        DB::table('portfolio_media')->insert([
            'portfolio_item_id' => 1,
            'public_id' => $mediaPublicId,
            'file_type' => 'image',
            'file_path' => 'portfolio/public-work.webp',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Storage::fake('public');
        Storage::disk('public')->put('certificates/private.png', 'certificate');
        DB::table('certificates')->insert([
            'public_id' => $certificatePublicId,
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'holder_name' => 'اسم حامل الشهادة',
            'course_name' => 'شهادة لا تخص رابط الأعمال',
            'certificate_text_template_key' => 'completion',
            'certificate_text' => 'نص الشهادة الخاص',
            'image_path' => 'certificates/private.png',
            'status' => 'active',
            'verification_level' => 'completion',
            'generated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('generateBunnySignedUrl')
            ->once()
            ->with('portfolio/public-work.webp', 300)
            ->andReturn('https://cdn.example.test/public-work');
        $this->app->instance(BunnyService::class, $bunny);

        $response = $this->getJson("/api/v1/public/portfolios/{$slug}");
        $response
            ->assertOk()
            ->assertJsonPath('data.profile.name', 'API Test User')
            ->assertJsonPath('data.profile.headline', null)
            ->assertJsonPath(
                'data.projects.0.media.0.image_url',
                RoknPublicUrl::portfolioMedia($slug, $mediaPublicId)
            )
            ->assertJsonMissingPath('data.profile.bio')
            ->assertJsonMissingPath('data.profile.is_public')
            ->assertJsonMissingPath('data.certificates')
            ->assertJsonMissingPath('data.highlighted_certificate')
            ->assertJsonMissingPath('data.badges')
            ->assertJsonMissingPath('data.projects.0.id')
            ->assertJsonMissingPath('data.projects.0.slug')
            ->assertJsonMissingPath('data.projects.0.source_project_id')
            ->assertJsonMissingPath('data.projects.0.upload_state')
            ->assertJsonMissingPath('data.projects.0.expected_media_count')
            ->assertJsonMissingPath('data.projects.0.media.0.id')
            ->assertJsonMissingPath('data.projects.0.media.0.public_id')
            ->assertJsonMissingPath('data.projects.0.media.0.status');

        $this->get("/@{$slug}")
            ->assertOk()
            ->assertSee('Sample Portfolio Item')
            ->assertDontSee('وظيفة الحساب الخاصة')
            ->assertDontSee('نبذة الحساب الخاصة')
            ->assertDontSee('شهادة لا تخص رابط الأعمال')
            ->assertDontSee('الشارات المهنية');

        $mediaResponse = $this->get(
            RoknPublicUrl::portfolioMedia($slug, $mediaPublicId)
        );
        $mediaResponse->assertRedirect('https://cdn.example.test/public-work');
        self::assertStringContainsString(
            'no-store',
            (string) $mediaResponse->headers->get('Cache-Control')
        );
    }
}
