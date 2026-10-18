<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\RequireProductFeature;
use App\Models\Lesson;
use App\Models\Project;
use App\Services\BunnyDeliveryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Tests\Support\RecordsRowLocks;

/** Learner playback, course-map and course-file delivery through the HTTP contract. */
final class LessonMediaDeliveryContractTest extends ApiTestCase
{
    use RecordsRowLocks;

    private const GUID = 'video-guid-10';

    private const SIGNED_URL = 'https://vz-test.b-cdn.net/video-guid-10/playlist.m3u8?token=signed';

    private MockInterface $delivery;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->withoutMiddleware(RequireProductFeature::class);
        (require database_path('migrations/2026_08_11_020000_add_integrity_state_to_lesson_media_states.php'))->up();
        (require database_path('migrations/2026_09_08_000001_add_source_and_platform_to_course_pdfs.php'))->up();
        $this->delivery = Mockery::mock(BunnyDeliveryService::class);
        $this->delivery->shouldReceive('storageUrl')->andReturn(null)->byDefault();
        $this->delivery->shouldReceive('videoThumbnail')->andReturn(null)->byDefault();
        $this->app->instance(BunnyDeliveryService::class, $this->delivery);
        $this->actingAs($this->user, 'api');
    }

    public function test_learner_playback_never_reconstructs_editorial_media_state(): void
    {
        $this->delivery->shouldNotReceive('videoPlayback');
        $this->bunnyLesson();

        // No published generation: fail closed without creating one.
        $this->manifest()->assertStatus(409)->assertJsonPath('code', 'video_unavailable');
        self::assertSame(0, DB::table('lesson_media_states')->count());

        // A generation for an older upload is neither reset nor reused.
        $this->mediaState(['provider_media_id' => 'older-upload-guid']);
        $before = (array) DB::table('lesson_media_states')->where('lesson_id', 10)->first();
        $response = null;
        $locks = $this->recordLocks(function () use (&$response): void {
            $response = $this->manifest();
        });

        $response->assertStatus(409)->assertJsonPath('code', 'video_unavailable');
        self::assertSame($before, (array) DB::table('lesson_media_states')->where('lesson_id', 10)->first());
        self::assertNotContains('lesson_media_states:update', $locks);
        self::assertSame(0, DB::table('playback_sessions')->count());
    }

    public function test_lessons_have_one_secure_stream_delivery_source(): void
    {
        // A legacy public source is never played as a fallback.
        DB::table('lessons')->where('id', 10)->update([
            'video_source_type' => 'youtube',
            'video_link' => 'https://youtube.example.test/watch?v=public',
        ]);
        $this->delivery->shouldNotReceive('videoPlayback');
        $legacy = $this->manifest()->assertStatus(409);
        self::assertStringNotContainsString('youtube.example.test', (string) $legacy->getContent());

        // The signed Bunny Stream source is the only playable URL issued.
        $this->readyLesson();
        $this->delivery = Mockery::mock(BunnyDeliveryService::class);
        $this->delivery->shouldReceive('storageUrl')->andReturn(null);
        $this->delivery->shouldReceive('videoThumbnail')->andReturn(null);
        $this->delivery->shouldReceive('videoPlayback')->once()->with(self::GUID)->andReturn([
            'url' => self::SIGNED_URL,
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);
        $this->app->instance(BunnyDeliveryService::class, $this->delivery);

        $data = $this->manifest()->assertOk()->json('data');
        self::assertSame(self::SIGNED_URL, $data['source_url']);
        self::assertSame('hls', $data['protocol']);
        self::assertArrayNotHasKey('fallback_url', $data);
        self::assertSame([], array_values(array_filter(
            array_keys((array) config('bunny')),
            static fn (string $key): bool => str_contains(strtolower($key), 'fallback')
        )));
        // Unsigned/fallback hosts and legacy preview sources in the delivery
        // service itself: BunnyDeliveryOwnershipTest::test_incomplete_stream_configuration_never_generates_an_unsigned_fallback
        // and ::test_preview_reads_only_the_existing_published_generation ('legacy public source').
    }

    public function test_playback_preserves_purchase_and_project_lock_reasons(): void
    {
        $this->readyLesson();
        DB::table('lessons')->where('id', 10)->update(['is_opened' => 0]);
        DB::table('course_sections')->where('id', $this->sectionId)->update(['is_free' => 0]);
        $this->delivery->shouldNotReceive('videoPlayback');

        $this->manifest()->assertForbidden()->assertJsonPath('code', 'course_purchase_required');

        // An enrolled learner behind an unpassed crossing project.
        $this->enroll();
        $projectId = $this->projectGateBeforeTheLesson();
        $this->manifest()->assertForbidden()->assertJsonPath('code', 'module_project_not_passed');

        // A lesson removed from the published graph is a generic lock.
        DB::table('courses')->where('id', $this->courseId)->update(['is_coming_soon' => 1]);
        $this->manifest()->assertForbidden()->assertJsonPath('code', 'lesson_locked');
        self::assertSame(0, DB::table('playback_sessions')->count());
        self::assertGreaterThan(0, $projectId);
        // `previous_section_incomplete` is still mapped by PlaybackController,
        // but CourseSectionAccessService no longer produces that reason.
    }

    public function test_manifest_allocation_rejects_a_graph_published_while_it_waited_for_the_course(): void
    {
        // Lock order (learner, shared course, shared media row) is asserted by
        // LearnerLockOrderTest::test_manifest_locks_the_learner_first_and_shares_course_and_media_rows.
        $this->readyLesson();
        $this->signedPlayback();
        DB::table('lessons')->insert([
            'id' => 11, 'list_id' => $this->courseId, 'course_id' => $this->courseId,
            'title' => 'Lesson 11', 'is_opened' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        // A publish commits between the pre-check and the shared course lock:
        // it swaps the section to a different lesson once the learner row is locked.
        $published = false;
        DB::listen(function (QueryExecuted $query) use (&$published): void {
            if (! $published && str_contains($query->sql, '"users"') && str_ends_with(rtrim($query->sql), '/* lock:update */')) {
                $published = true;
                DB::table('course_sections')->where('id', $this->sectionId)->update(['sectionable_id' => 11]);
            }
        });

        $response = null;
        $locks = $this->recordLocks(function () use (&$response): void {
            $response = $this->manifest();
        });

        self::assertTrue($published);
        $response->assertStatus(409)->assertJsonPath('code', 'course_revision_changed');
        self::assertContains('courses:shared', $locks);
        self::assertNotContains('courses:update', $locks);
        self::assertSame(0, DB::table('playback_sessions')->count());
    }

    public function test_fresh_players_never_share_an_implicit_playback_session(): void
    {
        $this->readyLesson();
        $this->signedPlayback();

        $first = $this->manifest()->assertOk()->json('data.playback_session_id');
        $second = $this->manifest()->assertOk()->json('data.playback_session_id');
        self::assertNotSame($first, $second);

        // Only an explicitly named live session is refreshed.
        $refreshed = $this->manifest(['playback_session_id' => $first])->assertOk()->json('data.playback_session_id');
        self::assertSame($first, $refreshed);
        self::assertSame(2, DB::table('playback_sessions')->count());
    }

    // Lesson completion locks the learner before sharing the course, and
    // curriculum completion inside it only shares the course row:
    // LearnerLockOrderTest::test_first_completion_locks_learner_before_sharing_the_course
    // and ::test_course_completion_never_upgrades_a_shared_course_lock.
    // Certificate issuance (learner, then shared course):
    // CertificateIssuanceSnapshotTest::test_issuance_locks_the_learner_then_shares_the_course_without_upgrading_it.

    public function test_learner_course_map_exposes_server_completion_beside_server_lock_state(): void
    {
        $this->readyLesson();
        DB::table('lessons')->where('id', 10)->update(['is_opened' => 0]);
        $this->enroll();
        // Module 1: an opening reel, then an unpassed crossing project.
        // Module 2: the reel behind that project.
        $this->projectGateBeforeTheLesson();
        $this->secondLesson(order: 0, priority: 0);
        DB::table('lessons')->where('id', 11)->update(['is_opened' => 0]);
        $openingSectionId = (int) DB::table('course_sections')->where('sectionable_id', 11)
            ->where('section_type', 'lesson')->value('id');
        $projectSectionId = (int) DB::table('course_sections')->where('section_type', 'project')->value('id');

        $sections = $this->mapSections();
        self::assertFalse($sections[$openingSectionId]['is_locked']);
        self::assertFalse($sections[$openingSectionId]['is_completed']);
        self::assertFalse($sections[$projectSectionId]['is_locked']);
        self::assertFalse($sections[$projectSectionId]['is_completed']);
        self::assertTrue($sections[$this->sectionId]['is_locked']);
        self::assertFalse($sections[$this->sectionId]['is_completed']);
        self::assertSame('module_project_not_passed', $sections[$this->sectionId]['lock_reason']);
        self::assertArrayNotHasKey('content', $sections[$this->sectionId]);

        // Server completion evidence is reported beside the server lock state,
        // independently of it: a completed reel behind the gate stays locked.
        foreach ([$openingSectionId, $this->sectionId] as $sectionId) {
            DB::table('student_section_progress')->insert([
                'user_id' => $this->user->id, 'course_section_id' => $sectionId,
                'is_completed' => true, 'completed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $sections = $this->mapSections();
        self::assertTrue($sections[$openingSectionId]['is_completed']);
        self::assertFalse($sections[$openingSectionId]['is_locked']);
        self::assertArrayHasKey('content', $sections[$openingSectionId]);
        self::assertFalse($sections[$projectSectionId]['is_completed']);
        self::assertTrue($sections[$this->sectionId]['is_completed']);
        self::assertTrue($sections[$this->sectionId]['is_locked']);
        self::assertArrayNotHasKey('content', $sections[$this->sectionId]);
    }

    public function test_course_section_order_is_the_only_learner_sequence_contract(): void
    {
        $this->readyLesson();
        DB::table('lessons')->where('id', 10)->update(['priority' => 1]);
        DB::table('course_sections')->where('id', $this->sectionId)->update(['order' => 2]);
        $this->secondLesson(order: 1, priority: 9);
        // Explicit previews carry their signed source in the public map.
        $this->delivery->shouldReceive('videoPlayback')->andReturn([
            'url' => self::SIGNED_URL,
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);

        foreach (['guest', 'learner'] as $viewer) {
            if ($viewer === 'learner') {
                $this->enroll();
                $response = $this->actingAs($this->user, 'api')->getJson("/api/v1/courses/{$this->courseId}/details");
            } else {
                $this->app['auth']->forgetGuards();
                $response = $this->getJson("/api/v1/courses/{$this->courseId}/details");
            }
            $sections = $response->assertOk()->json('data.modules.0.sections');
            self::assertSame([1, 2], array_column($sections, 'order'), $viewer);
            self::assertSame([11, 10], array_column($sections, 'content_id'), $viewer);
            foreach ($sections as $section) {
                self::assertArrayNotHasKey('priority', $section, $viewer);
                self::assertArrayNotHasKey('priority', (array) ($section['content'] ?? []), $viewer);
            }
        }
        // Authoring no longer writes `lessons.priority`; that column has no
        // learner-facing effect, which is what this test proves.
    }

    public function test_course_files_are_download_only_and_renewable(): void
    {
        $this->configureCourseFiles();
        $this->enroll();
        $pdfId = $this->coursePdf('course-files-test', 'courses/files/workbook.pdf', '%PDF-workbook');

        $details = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/details")->assertOk()->json('data');
        $attachment = $details['attachments'][0];
        $refresh = "/api/v1/courses/{$this->courseId}/pdfs/{$pdfId}";
        self::assertSame($pdfId, $attachment['id']);
        self::assertTrue($attachment['download_only']);
        self::assertSame($refresh, $attachment['download_refresh_endpoint']);
        foreach ($details['modules'] as $module) {
            self::assertArrayNotHasKey('attachments_link', $module);
        }

        // The refresh endpoint renews the same download-only contract.
        $renewed = $this->getJson($refresh)->assertOk()->json('data');
        self::assertSame($pdfId, $renewed['id']);
        self::assertTrue($renewed['download_only']);
        self::assertSame($refresh, $renewed['download_refresh_endpoint']);
        self::assertSame($attachment['download_version'], $renewed['download_version']);
        $download = $this->get($renewed['download_url'])->assertOk();
        self::assertSame('%PDF-workbook', $this->downloadedBytes($download));
    }

    public function test_missing_attachment_storage_never_falls_back_to_a_public_disk(): void
    {
        $this->configureCourseFiles();
        Storage::fake('local');
        Storage::fake('public');
        $this->enroll();

        foreach (['local', 'public', 'unconfigured-disk', ''] as $disk) {
            $path = 'courses/files/'.Str::uuid().'.pdf';
            Storage::disk('local')->put($path, '%PDF-private');
            Storage::disk('public')->put($path, '%PDF-public');
            $pdfId = $this->coursePdf($disk, $path, null);
            $url = $this->actingAs($this->user, 'api')
                ->getJson("/api/v1/courses/{$this->courseId}/pdfs/{$pdfId}")
                ->assertOk()->json('data.download_url');

            $this->get($url)->assertNotFound();
        }
        self::assertFalse(class_exists('App\\Models\\Attachment'));
    }

    private function manifest(array $payload = []): TestResponse
    {
        return $this->actingAs($this->user, 'api')
            ->postJson('/api/v1/lessons/10/playback-manifest', $payload);
    }

    /** @return array<int,array<string,mixed>> */
    private function mapSections(): array
    {
        $modules = $this->actingAs($this->user, 'api')
            ->getJson("/api/v1/courses/{$this->courseId}/details")
            ->assertOk()->json('data.modules');

        return collect($modules)->flatMap(fn (array $module): array => $module['sections'])
            ->keyBy('id')->all();
    }

    private function bunnyLesson(): void
    {
        DB::table('lessons')->where('id', 10)->update([
            'video_source_type' => 'bunny',
            'bunny_video_id' => self::GUID,
        ]);
    }

    private function readyLesson(): void
    {
        $this->bunnyLesson();
        $this->mediaState([]);
    }

    private function mediaState(array $overrides): void
    {
        DB::table('lesson_media_states')->updateOrInsert(['lesson_id' => 10], array_replace([
            'provider' => 'bunny',
            'provider_media_id' => self::GUID,
            'status' => 'ready',
            'integrity_status' => 'healthy',
            'last_reconciled_at' => now(),
            'duration_seconds' => 25,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    private function signedPlayback(): void
    {
        $this->delivery->shouldReceive('videoPlayback')->with(self::GUID)->andReturn([
            'url' => self::SIGNED_URL,
            'expires_at' => now()->addHour()->toIso8601String(),
        ]);
    }

    private function enroll(): void
    {
        if (DB::table('course_enrollments')->where('user_id', $this->user->id)->exists()) {
            return;
        }
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** Puts an unpassed crossing project in module 1 and moves the lesson to module 2. */
    private function projectGateBeforeTheLesson(): int
    {
        $projectId = (int) DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $this->courseId, 'module_id' => $this->moduleId, 'title_ar' => 'مشروع العبور',
            'section_type' => 'project', 'sectionable_type' => Project::class, 'sectionable_id' => $projectId,
            'order' => 1, 'sort_order' => 1, 'is_free' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $nextModule = (int) DB::table('course_modules')->insertGetId([
            'course_id' => $this->courseId, 'title_ar' => 'الوحدة الثانية', 'order' => 2,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_sections')->where('id', $this->sectionId)->update(['module_id' => $nextModule]);

        return $projectId;
    }

    private function secondLesson(int $order, int $priority): void
    {
        DB::table('lessons')->insert([
            'id' => 11, 'list_id' => $this->courseId, 'course_id' => $this->courseId, 'title' => 'Lesson 11',
            'is_opened' => 1, 'priority' => $priority, 'video_source_type' => 'bunny', 'bunny_video_id' => 'video-guid-11',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('lesson_media_states')->insert([
            'lesson_id' => 11, 'provider' => 'bunny', 'provider_media_id' => 'video-guid-11', 'status' => 'ready',
            'integrity_status' => 'healthy', 'last_reconciled_at' => now(), 'duration_seconds' => 25,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $this->courseId, 'module_id' => $this->moduleId, 'title_ar' => 'قسم 2',
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class, 'sectionable_id' => 11,
            'order' => $order, 'sort_order' => $order, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function configureCourseFiles(): void
    {
        config([
            'course_pdfs.disk' => 'course-files-test',
            'filesystems.disks.course-files-test' => [
                'driver' => 'local', 'root' => storage_path('app/course-files-test'), 'visibility' => 'private',
            ],
        ]);
        Storage::fake('course-files-test');
    }

    private function coursePdf(string $disk, string $path, ?string $bytes): int
    {
        if ($bytes !== null) {
            Storage::disk($disk)->put($path, $bytes);
        }

        return (int) DB::table('course_pdfs')->insertGetId([
            'course_id' => $this->courseId, 'title' => 'ملف الكورس', 'file_path' => $path,
            'storage_disk' => $disk === '' ? null : $disk, 'original_filename' => 'workbook.pdf',
            'file_size' => 13, 'order' => 1, 'is_active' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function downloadedBytes(TestResponse $response): string
    {
        $base = $response->baseResponse;
        if ($base instanceof BinaryFileResponse) {
            return (string) file_get_contents($base->getFile()->getPathname());
        }

        return (string) $response->streamedContent();
    }
}
