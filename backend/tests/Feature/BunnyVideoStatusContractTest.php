<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\BunnyStreamWebhookController;
use App\Jobs\ProbeLessonMedia;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Services\BunnyService;
use App\Services\MediaHealthService;
use App\Services\MediaReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BunnyVideoStatusContractTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('videoStatuses')]
    public function test_health_and_read_only_reconciliation_use_get_video_statuses(int $status, string $expected): void
    {
        Http::preventStrayRequests();
        $lesson = $this->lesson();
        $guid = (string) $lesson->bunny_video_id;
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('inspectRemoteVideo')->twice()->with($guid)->andReturn([
            'state' => 'ok',
            'details' => [
                'guid' => $guid,
                'videoLibraryId' => 123,
                'status' => $status,
                'length' => 75,
                'encodeProgress' => 100,
                'availableResolutions' => '720p,480p',
                'thumbnailFileName' => 'thumbnail.jpg',
            ],
            'http_status' => 200,
        ]);
        $bunny->shouldReceive('getVideo')->once()->with($guid)->andReturn([
            'url' => 'https://media.example.test/playlist.m3u8',
        ]);
        $health = new MediaHealthService($bunny);
        $result = (new MediaReconciliationService($bunny, $health))
            ->reconcileLesson($lesson, false, false);

        self::assertSame($expected, $result['playback_status']);
        self::assertSame(0, $lesson->mediaState()->count(), 'Read-only reconciliation must not persist a status.');

        $state = $health->probe($lesson);
        self::assertSame($expected, $state->status);
        self::assertSame($status, $state->manifest['status']);
        self::assertSame($expected === 'failed' ? 'provider_encode_failed' : null, $state->last_error_code);
        Http::assertNothingSent();
    }

    public static function videoStatuses(): array
    {
        return [
            'created' => [0, 'processing'],
            'uploaded' => [1, 'processing'],
            'processing' => [2, 'processing'],
            'transcoding' => [3, 'processing'],
            'finished' => [4, 'ready'],
            'error' => [5, 'failed'],
            'upload failed' => [6, 'failed'],
            'JIT segmenting' => [7, 'processing'],
            'JIT playlists created' => [8, 'processing'],
            'captions event is not a video status' => [9, 'processing'],
            'title event is not a video status' => [10, 'processing'],
            'unknown' => [-1, 'processing'],
        ];
    }

    #[DataProvider('webhookEvents')]
    public function test_webhook_events_only_schedule_an_authoritative_get_probe(int $event): void
    {
        Http::preventStrayRequests();
        Bus::fake();
        config(['bunny.library_id' => '123', 'bunny.webhook_secret' => 'test-webhook-secret']);
        $lesson = $this->lesson();
        $module = CourseModule::query()->create([
            'course_id' => $lesson->list_id,
            'title_ar' => 'Status contract module',
            'order' => 1,
        ]);
        $lesson->courseSection()->create([
            'course_id' => $lesson->list_id,
            'module_id' => $module->id,
            'title_ar' => 'Status contract section',
            'order' => 1,
        ]);
        $state = $lesson->mediaState()->create([
            'provider' => 'bunny',
            'provider_media_id' => $lesson->bunny_video_id,
            'status' => 'processing',
            'protocol' => 'hls',
            'manifest' => ['status' => 2],
        ]);
        $body = json_encode([
            'VideoLibraryId' => 123,
            'VideoGuid' => $lesson->bunny_video_id,
            'Status' => $event,
        ], JSON_THROW_ON_ERROR);
        $request = Request::create('/api/integrations/bunny/stream', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_BUNNYSTREAM_SIGNATURE_VERSION' => 'v1',
            'HTTP_X_BUNNYSTREAM_SIGNATURE_ALGORITHM' => 'hmac-sha256',
            'HTTP_X_BUNNYSTREAM_SIGNATURE' => hash_hmac('sha256', $body, 'test-webhook-secret'),
        ], $body);

        $response = (new BunnyStreamWebhookController())($request);

        self::assertSame(202, $response->getStatusCode());
        self::assertSame('processing', $state->fresh()->status);
        self::assertSame(['status' => 2], $state->fresh()->manifest);
        Bus::assertDispatched(ProbeLessonMedia::class, fn (ProbeLessonMedia $job): bool =>
            $job->lessonId === (int) $lesson->id && $job->expectedVideoGuid === $lesson->bunny_video_id
        );
        Http::assertNothingSent();
    }

    public static function webhookEvents(): array
    {
        return [
            'finished' => [3],
            'resolution finished' => [4],
            'presigned upload started' => [6],
            'presigned upload failed' => [8],
            'captions generated' => [9],
            'title generated' => [10],
        ];
    }

    public function test_direct_upload_verification_accepts_received_jit_bytes_without_confusing_event_eight(): void
    {
        config(['bunny.library_id' => '123']);
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36791';
        $bunny = Mockery::mock(BunnyService::class)->makePartial();
        $bunny->shouldReceive('getRemoteVideoDetails')->once()->with($guid)->andReturn([
            'guid' => $guid,
            'videoLibraryId' => 123,
            'status' => 8,
            'storageSize' => 0,
        ]);

        self::assertTrue($bunny->verifyDirectUpload($guid, 5 * 1024 * 1024));
    }

    public function test_direct_upload_verification_rejects_get_upload_failed_even_when_byte_count_matches(): void
    {
        config(['bunny.library_id' => '123']);
        $guid = 'a3cc17a0-4b61-4e59-a4dc-947eabf36791';
        $bunny = Mockery::mock(BunnyService::class)->makePartial();
        $bunny->shouldReceive('getRemoteVideoDetails')->times(4)->with($guid)->andReturn([
            'guid' => $guid,
            'videoLibraryId' => 123,
            'status' => 6,
            'storageSize' => 5 * 1024 * 1024,
        ]);

        self::assertFalse($bunny->verifyDirectUpload($guid, 5 * 1024 * 1024));
    }

    private function lesson(): Lesson
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'Bunny status contract',
            'image' => 'legacy-cover.jpg',
            'is_coming_soon' => true,
            'authoring_version' => 1,
        ])->save();

        return Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'Status contract lesson',
            'video_source_type' => 'bunny',
            'bunny_video_id' => 'a3cc17a0-4b61-4e59-a4dc-947eabf36791',
        ]);
    }
}
