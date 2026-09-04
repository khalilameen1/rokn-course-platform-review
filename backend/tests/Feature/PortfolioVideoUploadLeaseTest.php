<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\BunnyVideoCleanupCandidate;
use App\Models\PortfolioItem;
use App\Models\PortfolioVideoUpload;
use App\Models\User;
use App\Services\BunnyService;
use App\Services\PortfolioVideoUploadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Mockery;
use Tests\TestCase;

final class PortfolioVideoUploadLeaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_renewal_slides_the_upload_and_cleanup_leases_and_returns_a_fresh_claim(): void
    {
        $user = new User([
            'name' => 'Learner',
            'email' => 'learner@example.test',
            'password' => 'unused',
        ]);
        $user->forceFill(['role' => 'student', 'active' => true])->save();
        $item = PortfolioItem::query()->create([
            'user_id' => $user->id,
            'title' => 'مشروع',
            'description' => 'وصف',
            'expected_media_count' => 1,
        ]);
        $guid = '12345678-1234-4234-8234-123456789abc';
        $oldExpiry = now()->addHour();
        $session = PortfolioVideoUpload::query()->create([
            'user_id' => $user->id,
            'portfolio_item_id' => $item->id,
            'idempotency_key' => '96e07193-d6a9-4b62-9976-b652b4e4f8a7',
            'request_hash' => str_repeat('a', 64),
            'content_sha256' => str_repeat('b', 64),
            'size_bytes' => 1024,
            'mime_type' => 'video/mp4',
            'original_name' => 'project.mp4',
            'video_guid' => $guid,
            'status' => 'pending',
            'expires_at' => $oldExpiry,
        ]);
        BunnyVideoCleanupCandidate::query()->create([
            'video_guid' => $guid,
            'reason' => 'portfolio_direct_upload_pending',
            'eligible_after' => $oldExpiry,
            'requires_review' => false,
            'reviewed_at' => now(),
        ]);
        $claim = Crypt::encryptString(json_encode([
            'v' => 1,
            'upload_id' => $session->id,
            'video_id' => $guid,
            'user_id' => $user->id,
            'expires_at' => $oldExpiry->timestamp,
        ], JSON_THROW_ON_ERROR));

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('directUploadAuthorization')->once()->with($guid)->andReturn([
            'headers' => ['VideoId' => $guid],
            'authorization_expires_at' => now()->addMinutes(30)->toIso8601String(),
            'authorization_expires_in_seconds' => 1800,
        ]);

        $result = (new PortfolioVideoUploadService($bunny))->renew($user, $item->id, $claim);
        $renewed = PortfolioVideoUpload::query()->findOrFail($session->id);
        $candidate = BunnyVideoCleanupCandidate::query()->where('video_guid', $guid)->firstOrFail();
        $renewedClaim = json_decode(Crypt::decryptString($result['claim']), true, 16, JSON_THROW_ON_ERROR);

        self::assertTrue($renewed->expires_at->greaterThan($oldExpiry));
        self::assertSame($renewed->expires_at->timestamp, $candidate->eligible_after->timestamp);
        self::assertSame($renewed->expires_at->timestamp, $renewedClaim['expires_at']);
        self::assertFalse($result['attached']);
    }

    public function test_attaching_the_last_expected_video_does_not_publish_the_item(): void
    {
        $user = new User([
            'name' => 'Learner',
            'email' => 'portfolio-learner@example.test',
            'password' => 'unused',
        ]);
        $user->forceFill(['role' => 'student', 'active' => true])->save();
        $item = PortfolioItem::query()->create([
            'user_id' => $user->id,
            'title' => 'مشروع',
            'description' => 'وصف',
            'expected_media_count' => 1,
            'is_public' => false,
        ]);
        $guid = '22345678-1234-4234-8234-123456789abc';
        $expiry = now()->addHour();
        $session = PortfolioVideoUpload::query()->create([
            'user_id' => $user->id,
            'portfolio_item_id' => $item->id,
            'idempotency_key' => '86e07193-d6a9-4b62-9976-b652b4e4f8a7',
            'request_hash' => str_repeat('c', 64),
            'content_sha256' => str_repeat('d', 64),
            'size_bytes' => 1024,
            'mime_type' => 'video/mp4',
            'original_name' => 'project.mp4',
            'video_guid' => $guid,
            'status' => 'pending',
            'expires_at' => $expiry,
        ]);
        BunnyVideoCleanupCandidate::query()->create([
            'video_guid' => $guid,
            'reason' => 'portfolio_direct_upload_pending',
            'eligible_after' => $expiry,
            'requires_review' => false,
            'reviewed_at' => now(),
        ]);
        $claim = Crypt::encryptString(json_encode([
            'v' => 1,
            'upload_id' => $session->id,
            'video_id' => $guid,
            'user_id' => $user->id,
            'expires_at' => $expiry->timestamp,
        ], JSON_THROW_ON_ERROR));

        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('verifyDirectUpload')->once()->with($guid, 1024)->andReturnTrue();

        (new PortfolioVideoUploadService($bunny))->attach($user, $item->id, $claim, null);

        self::assertFalse((bool) $item->fresh()->is_public);
        self::assertSame(1, $item->mediaFiles()->count());
    }
}
