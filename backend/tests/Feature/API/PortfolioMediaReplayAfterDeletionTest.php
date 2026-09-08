<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\PortfolioDeletedUpload;
use App\Models\User;
use App\Services\BunnyService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Mockery;

final class PortfolioMediaReplayAfterDeletionTest extends ApiTestCase
{
    public function test_replay_after_finalization_keeps_the_original_media_and_publication(): void
    {
        $bunny = $this->fakeBunny();
        $file = UploadedFile::fake()->image('work.jpg', 10, 10)->size(2);
        $mediaId = $this->append($file)->assertOk()->json('data.id');

        $this->postJson('/api/v1/portfolio/1/finalize')
            ->assertOk()->assertJsonPath('data.is_public', true);
        $this->append($file)->assertOk()
            ->assertJsonPath('data.id', $mediaId)
            ->assertJsonPath('replayed', true);

        self::assertSame(1, DB::table('portfolio_media')->count());
        self::assertTrue((bool) DB::table('portfolio_items')->where('id', 1)->value('is_public'));
        $bunny->shouldHaveReceived('uploadFileToStorage')->once();
    }

    public function test_replay_of_a_deleted_accepted_image_cannot_resurrect_it(): void
    {
        $bunny = $this->fakeBunny();
        $file = UploadedFile::fake()->image('work.jpg', 10, 10)->size(2);
        $mediaId = $this->append($file)->assertOk()->json('data.id');
        $this->postJson('/api/v1/portfolio/1/finalize')->assertOk();
        $this->deleteJson('/api/v1/portfolio/1/media/'.$mediaId)->assertOk();
        self::assertSame(0, DB::table('portfolio_media')->count());

        // The phone can retain this accepted request ID when its local receipt
        // cleanup fails. Replaying it must not undo a later explicit deletion.
        $this->append($file)->assertStatus(422)->assertJsonPath('code', 'media_deleted');

        self::assertSame(0, DB::table('portfolio_media')->count(), 'A deleted accepted upload was recreated by its old request ID.');
        $bunny->shouldHaveReceived('uploadFileToStorage')->once();
    }

    public function test_a_new_request_can_intentionally_upload_the_same_deleted_image(): void
    {
        $bunny = $this->fakeBunny();
        $file = UploadedFile::fake()->image('work.jpg', 10, 10)->size(2);
        $oldId = $this->append($file)->assertOk()->json('data.id');
        $this->deleteJson('/api/v1/portfolio/1/media/'.$oldId)->assertOk();

        $newId = $this->append($file, '44444444-4444-4444-8444-444444444444')
            ->assertOk()->assertJsonPath('replayed', false)->json('data.id');

        self::assertNotSame($oldId, $newId);
        self::assertSame(1, DB::table('portfolio_media')->count());
        self::assertSame(1, DB::table('portfolio_deleted_uploads')->count());
        $bunny->shouldHaveReceived('uploadFileToStorage')->twice();
    }

    public function test_deleted_upload_receipts_are_item_scoped_and_owner_protected(): void
    {
        $bunny = $this->fakeBunny();
        $file = UploadedFile::fake()->image('work.jpg', 10, 10)->size(2);
        $oldId = $this->append($file)->assertOk()->json('data.id');
        $this->deleteJson('/api/v1/portfolio/1/media/'.$oldId)->assertOk();
        $other = new User();
        $other->forceFill([
            'name' => 'Other learner', 'email' => 'media-other@rokn.test',
            'phone' => '01000000002', 'password' => bcrypt('password123'), 'active' => true,
        ])->save();
        $this->actingAs($other, 'api');
        $this->append($file)->assertNotFound();

        $this->actingAs($this->user, 'api');
        $itemId = DB::table('portfolio_items')->insertGetId([
            'user_id' => $this->user->id, 'title' => 'Another work',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->append($file, itemId: $itemId)->assertOk()->assertJsonPath('replayed', false);
        self::assertSame(1, DB::table('portfolio_media')->where('portfolio_item_id', $itemId)->count());
        self::assertSame(0, DB::table('portfolio_media')->where('portfolio_item_id', 1)->count());
        $bunny->shouldHaveReceived('uploadFileToStorage')->twice();

        $this->deleteJson('/api/v1/portfolio/1')->assertOk();
        self::assertSame(0, DB::table('portfolio_deleted_uploads')->count());
    }

    public function test_failed_cleanup_rolls_back_the_deleted_upload_receipt_and_keeps_replay_valid(): void
    {
        $bunny = $this->fakeBunny();
        $file = UploadedFile::fake()->image('work.jpg', 10, 10)->size(2);
        $mediaId = $this->append($file)->assertOk()->json('data.id');
        $bunny->shouldReceive('queueStorageCleanup')->once()->andReturnFalse();
        $this->deleteJson('/api/v1/portfolio/1/media/'.$mediaId)->assertStatus(500);

        self::assertSame(0, DB::table('portfolio_deleted_uploads')->count());
        $this->append($file)->assertOk()->assertJsonPath('data.id', $mediaId)
            ->assertJsonPath('replayed', true);
        self::assertNull(DB::table('portfolio_media')->where('id', $mediaId)->value('deletion_lease_id'));
        $bunny->shouldHaveReceived('uploadFileToStorage')->once();
    }

    public function test_deleted_request_id_matching_is_case_insensitive(): void
    {
        $bunny = $this->fakeBunny();
        $file = UploadedFile::fake()->image('work.jpg', 10, 10)->size(2);
        $key = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';
        $mediaId = $this->append($file, strtoupper($key))->assertOk()->json('data.id');
        $this->deleteJson('/api/v1/portfolio/1/media/'.$mediaId)->assertOk();
        $this->append($file, $key)->assertStatus(422)->assertJsonPath('code', 'media_deleted');
        self::assertSame($key, DB::table('portfolio_deleted_uploads')->value('client_request_id'));
        $bunny->shouldHaveReceived('uploadFileToStorage')->once();
    }

    public function test_post_upload_transaction_rechecks_a_deletion_recorded_during_remote_io(): void
    {
        $bunny = $this->fakeBunny();
        $file = UploadedFile::fake()->image('work.jpg', 10, 10)->size(2);
        $bunny->shouldReceive('uploadFileToStorage')->once()->andReturnUsing(function (): string {
            // Controlled interleaving at the remote IO boundary, not a claim
            // that this SQLite fixture reproduces concurrent MySQL locks.
            PortfolioDeletedUpload::query()->create([
                'portfolio_item_id' => 1,
                'client_request_id' => '33333333-3333-4333-8333-333333333333',
            ]);

            return 'portfolio/late-upload.jpg';
        });
        $bunny->shouldReceive('queueStorageCleanup')->once()
            ->with('portfolio/late-upload.jpg', 'portfolio_rollback', 5)->andReturnTrue();
        $this->append($file)->assertStatus(422)->assertJsonPath('code', 'media_deleted');
        self::assertSame(0, DB::table('portfolio_media')->count());
        $bunny->shouldNotHaveReceived('consumeStorageCleanupCandidate');
    }

    private function append(
        UploadedFile $file,
        string $requestId = '33333333-3333-4333-8333-333333333333',
        int $itemId = 1
    ): \Illuminate\Testing\TestResponse
    {
        return $this->post('/api/v1/portfolio/'.$itemId.'/media', [
            'client_request_id' => $requestId,
            'file' => $file,
            'file_type' => 'image',
        ]);
    }

    private function fakeBunny(): \Mockery\MockInterface
    {
        $this->actingAs($this->user, 'api');
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('uploadFileToStorage')->andReturn('portfolio/accepted-image.jpg')->byDefault();
        $bunny->shouldReceive('consumeStorageCleanupCandidate')->andReturnNull();
        $bunny->shouldReceive('generateBunnySignedUrl')->andReturn('https://cdn.example.test/accepted-image.jpg');
        $bunny->shouldReceive('queueStorageCleanup')->andReturnTrue()->byDefault();
        $this->app->instance(BunnyService::class, $bunny);

        return $bunny;
    }
}
