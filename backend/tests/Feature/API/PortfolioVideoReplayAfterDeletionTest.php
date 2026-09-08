<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\PortfolioDeletedUpload;
use App\Models\PortfolioItem;
use App\Models\PortfolioVideoUpload;
use App\Models\User;
use App\Services\BunnyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class PortfolioVideoReplayAfterDeletionTest extends TestCase
{
    use RefreshDatabase;

    private const REQUEST_ID = '11111111-1111-4111-8111-111111111111';
    private const VIDEO_ID = '22222222-2222-4222-8222-222222222222';

    private PortfolioItem $item;
    private \Mockery\MockInterface $bunny;

    protected function setUp(): void
    {
        parent::setUp();
        $user = new User(['name' => 'Learner', 'email' => 'video-replay@example.test', 'password' => 'unused']);
        $user->forceFill(['role' => 'student', 'active' => true])->save();
        $this->actingAs($user, 'api');
        $this->item = PortfolioItem::query()->create([
            'user_id' => $user->id,
            'title' => 'مشروع الفيديو',
            'expected_media_count' => 1,
            'is_public' => false,
        ]);
        $this->bunny = Mockery::mock(BunnyService::class);
        $this->bunny->shouldReceive('createVideo')->andReturn(
            ['guid' => self::VIDEO_ID],
            ['guid' => '44444444-4444-4444-8444-444444444444']
        );
        $this->bunny->shouldReceive('directUploadAuthorization')->andReturnUsing(fn (string $guid): array => [
            'headers' => ['VideoId' => $guid],
            'authorization_expires_at' => now()->addMinutes(30)->toIso8601String(),
            'authorization_expires_in_seconds' => 1800,
        ]);
        $this->bunny->shouldReceive('queueVideoCleanup')->andReturnUsing(
            fn (string $guid, $lessonId, string $reason, int $hours): BunnyVideoCleanupCandidate =>
                BunnyVideoCleanupCandidate::query()->updateOrCreate(['video_guid' => $guid], [
                    'reason' => $reason,
                    'eligible_after' => now()->addHours($hours),
                    'requires_review' => false,
                    'reviewed_at' => now(),
                ])
        );
        $this->bunny->shouldReceive('verifyDirectUpload')->andReturnTrue();
        $this->bunny->shouldReceive('inspectRemoteVideo')->andReturn([
            'state' => 'ok',
            'details' => ['status' => 3, 'encodeProgress' => 100],
        ]);
        $this->bunny->shouldReceive('getSignedEmbedUrl')->andReturn(['url' => 'https://video.example.test/embed']);
        $this->bunny->shouldReceive('getSignedPlayUrl')->andReturn(['url' => 'https://video.example.test/play.m3u8']);
        $this->app->instance(BunnyService::class, $this->bunny);
    }

    #[DataProvider('replayOperations')]
    public function test_deleted_accepted_video_is_terminal_for_every_old_upload_operation(string $operation, bool $legacyDeletion): void
    {
        $claim = $this->issue()->assertOk()->json('data.claim');
        $mediaId = $this->claim($claim)->assertOk()->json('data.id');
        $this->postJson('/api/v1/portfolio/'.$this->item->id.'/finalize')
            ->assertOk()->assertJsonPath('data.is_public', true);
        $this->deleteJson('/api/v1/portfolio/'.$this->item->id.'/media/'.$mediaId)->assertOk();
        if ($legacyDeletion) {
            // Before deletion identities were recorded, the attached upload
            // receipt still survived the media deletion and lease expiry.
            PortfolioDeletedUpload::query()->where('portfolio_item_id', $this->item->id)->delete();
            $this->travel(25)->hours();
        }

        $response = match ($operation) {
            'issue' => $this->issue(),
            'renew' => $this->renew($claim),
            'claim' => $this->claim($claim),
        };

        $response->assertStatus(422)->assertJsonPath('success', false)->assertJsonPath('code', 'media_deleted');
        self::assertSame(0, $this->item->mediaFiles()->count());
        self::assertSame('attached', PortfolioVideoUpload::query()->sole()->status);
        $this->bunny->shouldHaveReceived('createVideo')->once();
        $this->bunny->shouldHaveReceived('verifyDirectUpload')->once();
        $this->bunny->shouldHaveReceived('directUploadAuthorization')->once();
    }

    public static function replayOperations(): array
    {
        return [
            ['issue', false], ['renew', false], ['claim', false],
            ['issue', true], ['renew', true], ['claim', true],
        ];
    }

    public function test_a_deleted_image_request_cannot_be_reused_to_allocate_a_video(): void
    {
        $this->bunny->shouldReceive('uploadFileToStorage')->once()->andReturn('portfolio/accepted-image.jpg');
        $this->bunny->shouldReceive('consumeStorageCleanupCandidate')->andReturnNull();
        $this->bunny->shouldReceive('generateBunnySignedUrl')->andReturn('https://cdn.example.test/accepted-image.jpg');
        $this->bunny->shouldReceive('queueStorageCleanup')->andReturnTrue();
        $mediaId = $this->post('/api/v1/portfolio/'.$this->item->id.'/media', [
            'client_request_id' => self::REQUEST_ID,
            'file' => UploadedFile::fake()->image('work.jpg', 10, 10)->size(2),
            'file_type' => 'image',
        ])->assertOk()->json('data.id');
        $this->deleteJson('/api/v1/portfolio/'.$this->item->id.'/media/'.$mediaId)->assertOk();

        $this->issue()->assertStatus(422)->assertJsonPath('code', 'media_deleted');

        self::assertSame(0, PortfolioVideoUpload::query()->count());
        self::assertSame(0, $this->item->mediaFiles()->count());
        $this->bunny->shouldNotHaveReceived('createVideo');
        $this->bunny->shouldNotHaveReceived('directUploadAuthorization');
    }

    public function test_existing_accepted_video_still_replays_after_its_upload_lease_expires(): void
    {
        $claim = $this->issue()->assertOk()->json('data.claim');
        $mediaId = $this->claim($claim)->assertOk()->json('data.id');
        $this->postJson('/api/v1/portfolio/'.$this->item->id.'/finalize')->assertOk();
        $this->travel(25)->hours();

        $reissued = $this->issue()->assertOk()->assertJsonPath('data.attached', true)->json('data.claim');
        $this->renew($claim)->assertOk()->assertJsonPath('data.attached', true);
        $this->claim($claim)->assertOk()->assertJsonPath('data.id', $mediaId)->assertJsonPath('replayed', true);
        $this->claim($reissued)->assertOk()->assertJsonPath('data.id', $mediaId);
        $this->claim($claim, 'different caption')->assertStatus(409);

        self::assertSame(1, $this->item->mediaFiles()->count());
        self::assertTrue((bool) $this->item->fresh()->is_public);
        $this->bunny->shouldHaveReceived('createVideo')->once();
        $this->bunny->shouldHaveReceived('verifyDirectUpload')->once();
        $this->bunny->shouldHaveReceived('directUploadAuthorization')->once();
    }

    public function test_pending_video_can_resume_and_renew_but_expired_pending_claims_remain_expired(): void
    {
        $claim = $this->issue()->assertOk()->assertJsonPath('data.attached', false)->json('data.claim');
        $oldExpiry = PortfolioVideoUpload::query()->sole()->expires_at;
        $this->issue()->assertOk()->assertJsonPath('data.video_id', self::VIDEO_ID)->assertJsonPath('data.attached', false);
        $this->travel(1)->hours();
        $this->renew($claim)->assertOk()->assertJsonPath('data.attached', false);
        self::assertTrue(PortfolioVideoUpload::query()->sole()->expires_at->greaterThan($oldExpiry));
        self::assertSame('pending', PortfolioVideoUpload::query()->sole()->status);
        $this->travel(25)->hours();
        $this->renew($claim)->assertStatus(410);
        $this->claim($claim)->assertStatus(410);

        self::assertSame(0, $this->item->mediaFiles()->count());
        $this->bunny->shouldHaveReceived('createVideo')->once();
        $this->bunny->shouldHaveReceived('directUploadAuthorization')->times(3);
        $this->bunny->shouldNotHaveReceived('verifyDirectUpload');
    }

    public function test_an_explicit_new_request_can_upload_the_same_video_after_deletion(): void
    {
        $claim = $this->issue()->assertOk()->json('data.claim');
        $oldMediaId = $this->claim($claim)->assertOk()->json('data.id');
        $this->deleteJson('/api/v1/portfolio/'.$this->item->id.'/media/'.$oldMediaId)->assertOk();

        $newId = (string) Str::uuid();
        $newClaim = $this->issue($newId)->assertOk()->assertJsonPath('data.attached', false)->json('data.claim');
        $newMediaId = $this->claim($newClaim)->assertOk()->json('data.id');
        $this->issue()->assertStatus(422)->assertJsonPath('code', 'media_deleted');

        self::assertNotSame($oldMediaId, $newMediaId);
        self::assertSame(1, $this->item->mediaFiles()->count());
        self::assertSame($newId, $this->item->mediaFiles()->sole()->client_request_id);
        self::assertSame(2, PortfolioVideoUpload::query()->count());
        $this->bunny->shouldHaveReceived('createVideo')->twice();
        $this->bunny->shouldHaveReceived('verifyDirectUpload')->twice();
    }

    private function issue(string $requestId = self::REQUEST_ID): TestResponse
    {
        return $this->postJson('/api/v1/portfolio/'.$this->item->id.'/media/video-uploads', [
            'idempotency_key' => $requestId,
            'size' => 1024,
            'mime' => 'video/mp4',
            'original_name' => 'work.mp4',
            'sha256' => str_repeat('a', 64),
        ], ['Idempotency-Key' => $requestId]);
    }

    private function renew(string $claim): TestResponse
    {
        return $this->postJson('/api/v1/portfolio/'.$this->item->id.'/media/video-uploads/renew', ['claim' => $claim]);
    }

    private function claim(string $claim, ?string $caption = null): TestResponse
    {
        return $this->postJson('/api/v1/portfolio/'.$this->item->id.'/media/video-uploads/claim', [
            'claim' => $claim,
            'caption' => $caption,
        ]);
    }
}
