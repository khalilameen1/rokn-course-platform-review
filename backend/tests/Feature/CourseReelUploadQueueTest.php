<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\BunnyDirectUpload;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\User;
use App\Services\BunnyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * The studio's multi-reel queue saves each uploaded video through the existing
 * section store, exactly as the inline form does. These tests pin the server
 * contract it depends on: saves run one after another, and a claim issued
 * before another reel was saved is refused, which is why the queue uploads
 * one reel at a time.
 */
final class CourseReelUploadQueueTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->moderator = User::query()->forceCreate([
            'name_ar' => 'محرر المحتوى', 'email' => 'reel-queue@example.test',
            'role' => 'moderator', 'active' => true,
        ]);
        $this->actingAs($this->moderator, 'web');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $bunny = Mockery::mock(BunnyService::class)->makePartial();
        $bunny->shouldReceive('verifyDirectUpload')->andReturn(true);
        $this->app->instance(BunnyService::class, $bunny);
    }

    public function test_queued_reels_are_saved_one_after_another_at_the_end_of_the_module(): void
    {
        [$course, $module] = $this->course();

        $first = $this->queueSave($course, $module, 'الريل الأول', $this->claim($course, 1), 1, 1)
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('authoring_version', 2)
            ->assertJsonPath('section.module_id', $module->id)
            ->assertJsonPath('section.type', 'lesson');
        $second = $this->queueSave($course, $module, 'الريل الثاني', $this->claim($course, 2), 2, 2)
            ->assertOk()
            ->assertJsonPath('authoring_version', 3);

        self::assertSame(
            ['الريل الأول', 'الريل الثاني'],
            $module->sections()->orderBy('order')->pluck('title_ar')->all()
        );
        self::assertNotSame($first->json('section.id'), $second->json('section.id'));
        self::assertSame(2, BunnyDirectUpload::query()->where('status', 'attached')->count());
    }

    public function test_a_claim_allocated_before_the_previous_reel_was_saved_is_refused_with_a_terminal_marker(): void
    {
        [$course, $module] = $this->course();
        $parallelClaim = $this->claim($course, 1);
        $this->queueSave($course, $module, 'الريل الأول', $this->claim($course, 1), 1, 1)->assertOk();

        $this->queueSave($course, $module, 'الريل الثاني', $parallelClaim, 2, 2)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['bunny_video_claim', 'bunny_video_claim_terminal']);
        $this->postJson(route('admin.courses.sections.video-uploads.renew', $course), ['claim' => $parallelClaim])
            ->assertStatus(410)
            ->assertJsonPath('code', 'bunny_upload_claim_unavailable');

        self::assertSame(1, $module->sections()->count());
    }

    public function test_draft_studio_renders_the_queue_entry_points_and_loads_tus_before_the_transfer(): void
    {
        [$course, $module] = $this->course();
        $admin = User::query()->forceCreate([
            'name_ar' => 'مدير', 'email' => 'reel-queue-admin@example.test', 'role' => 'admin', 'active' => true,
        ]);

        $html = $this->actingAs($admin, 'web')->get(route('admin.courses.show', $course))->assertOk()->getContent();
        $document = new \DOMDocument();
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
        $xpath = new \DOMXPath($document);

        self::assertSame(1, $xpath->query(sprintf(
            '//*[@data-module-actions="%d"]/button[@data-reel-queue-open and @data-module-id="%d"]',
            $module->id,
            $module->id
        ))->length);
        $picker = $xpath->query('//*[@id="studioReelQueue"]//input[@id="studioReelQueueInput"]')->item(0);
        self::assertInstanceOf(\DOMElement::class, $picker);
        self::assertTrue($picker->hasAttribute('multiple'));
        self::assertSame('video/mp4,video/quicktime,video/x-msvideo,video/webm', $picker->getAttribute('accept'));
        self::assertSame(2, $xpath->query('//*[@id="studioReelQueue"]//button[@data-upload-escape]')->length);

        $scripts = array_map(
            fn (\DOMElement $script): string => (string) parse_url($script->getAttribute('src'), PHP_URL_PATH),
            iterator_to_array($xpath->query('//script[@src]'))
        );
        $position = fn (string $suffix): int|false => array_search(true, array_map(
            fn (string $src): bool => str_ends_with($src, $suffix),
            $scripts
        ), true);
        self::assertNotFalse($position('/vendor/tus-js-client/tus.min.js'));
        self::assertLessThan(
            $position('/course-studio-bunny-upload-transfer.js'),
            $position('/vendor/tus-js-client/tus.min.js')
        );
        self::assertNotFalse($position('/course-studio-bunny-upload-queue.js'));
        self::assertNotFalse($position('/course-studio-reel-queue.js'));
        self::assertStringContainsString(sprintf('ownerId: "%d"', $admin->id), $html);
    }

    private function queueSave(Course $course, CourseModule $module, string $title, string $claim, int $version, int $order)
    {
        // The exact fields course-studio-section-editor.js createLesson sends.
        return $this->postJson(route('admin.courses.sections.store', $course), [
            'authoring_request_id' => (string) Str::uuid(),
            'authoring_version' => $version,
            'module_id' => $module->id,
            'order' => $order,
            'section_type' => 'lesson',
            'video_source_type' => 'bunny',
            'bunny_video_claim' => $claim,
            'title_ar' => $title,
            'is_opened' => '0',
        ]);
    }

    private function claim(Course $course, int $authoringVersion): string
    {
        $guid = (string) Str::uuid();
        $upload = BunnyDirectUpload::query()->create([
            'user_id' => $this->moderator->id, 'course_id' => $course->id,
            'idempotency_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', $guid),
            'video_guid' => $guid, 'status' => 'pending', 'expires_at' => now()->addHour(),
        ]);
        BunnyVideoCleanupCandidate::query()->create([
            'video_guid' => $guid, 'reason' => 'direct_upload_pending', 'eligible_after' => now()->addHour(),
        ]);

        return Crypt::encryptString(json_encode([
            'v' => 2, 'upload_id' => $upload->id, 'video_id' => $guid,
            'course_id' => $course->id, 'section_id' => null, 'admin_id' => $this->moderator->id,
            'size' => 1024, 'mime' => 'video/mp4', 'title' => 'ريل',
            'expires_at' => now()->addHour()->getTimestamp(), 'authoring_version' => $authoringVersion,
        ], JSON_THROW_ON_ERROR));
    }

    /** @return array{Course, CourseModule} */
    private function course(): array
    {
        $course = Course::factory()->make();
        $course->forceFill([
            'tenant_id' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false,
            'published_at' => null, 'last_published_authoring_version' => 0, 'authoring_version' => 1,
        ])->save();

        return [$course, $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1])];
    }
}
