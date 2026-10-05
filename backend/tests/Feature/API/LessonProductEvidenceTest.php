<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\LessonWatchEvidence;
use App\Models\OutboxEvent;
use App\Models\PlaybackSession;
use App\Models\ProductEvent;
use App\Services\LearningEvidenceService;
use App\Services\LessonCompletionReportService;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

/** Authoritative evidence and event writer are real; no provider calls. */
final class LessonProductEvidenceTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->travelTo(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));
    }

    public function test_zero_credit_and_seek_do_not_start_a_journey_and_first_credit_is_recorded_once(): void
    {
        $lesson = $this->lesson();
        $service = app(LearningEvidenceService::class);
        $service->recordHeartbeat($this->user, $lesson, 0, 25);
        $service->recordHeartbeat($this->user, $lesson, 25, 25);
        $service->recordHeartbeat($this->user, $lesson, 0, 25);
        self::assertSame(0, ProductEvent::count());
        $this->travel(10)->seconds();
        $first = $service->recordHeartbeat($this->user, $lesson, 10, 25);
        self::assertSame(10, $first['credited_seconds']);
        $started = ProductEvent::query()->sole();
        self::assertSame('lesson_started', $started->event_name);
        self::assertSame('server', $started->source);
        self::assertSame($this->courseId, $started->course_id);
        self::assertSame(10, $started->lesson_id);
        self::assertNull($started->session_key);
        self::assertTrue($started->occurred_at->equalTo(now()));
        $this->travel(5)->seconds();
        $service->recordHeartbeat($this->user, $lesson, 15, 25);
        self::assertSame(1, ProductEvent::count());
    }

    public function test_verified_completion_and_retries_emit_one_pair_and_one_outbox_event_per_stage(): void
    {
        $lesson = $this->lesson();
        $service = app(LearningEvidenceService::class);
        $service->recordHeartbeat($this->user, $lesson, 0, 25);
        $this->travel(10)->seconds();
        $service->recordHeartbeat($this->user, $lesson, 10, 25);
        $this->travel(10)->seconds();
        $completed = $service->recordHeartbeat($this->user, $lesson, 20, 25);
        self::assertTrue($completed['eligible_for_completion']);
        self::assertSame(['lesson_started', 'lesson_completed'], ProductEvent::orderBy('id')->pluck('event_name')->all());
        $this->travel(5)->seconds();
        $service->recordHeartbeat($this->user, $lesson, 25, 25);
        self::assertSame(2, ProductEvent::count());
        self::assertSame(2, OutboxEvent::where('aggregate_type', 'product_event')->count());
        self::assertTrue(ProductEvent::where('event_name', 'lesson_completed')->sole()->occurred_at
            ->equalTo(LessonWatchEvidence::query()->sole()->completed_at));
        // Actual server events feed the report, without hand-inserting counts.
        $this->travel(1)->seconds();
        $row = app(LessonCompletionReportService::class)->report($this->courseId, ReportPeriod::fromKey('30d'))['rows']->sole();
        self::assertSame(1, $row['starts']);
        self::assertSame(1, $row['completions']);
        self::assertSame(100.0, $row['completion_rate']);
    }

    public function test_event_failure_rolls_back_evidence_and_the_same_heartbeat_can_be_retried(): void
    {
        $lesson = $this->lesson();
        $service = app(LearningEvidenceService::class);
        $service->recordHeartbeat($this->user, $lesson, 0, 25);
        $original = LessonWatchEvidence::query()->sole();
        $this->travel(10)->seconds();
        $key = config('app.key');
        config(['app.key' => '']);
        try {
            $service->recordHeartbeat($this->user, $lesson, 20, 25);
            self::fail('A failed event writer must not commit learning evidence.');
        } catch (\RuntimeException $exception) {
            self::assertSame('APP_KEY is required for product-event pseudonyms.', $exception->getMessage());
        } finally {
            config(['app.key' => $key]);
        }
        $rolledBack = $original->fresh();
        self::assertSame(0, $rolledBack->verified_seconds);
        self::assertSame(0, $rolledBack->last_position_seconds);
        self::assertNull($rolledBack->completed_at);
        self::assertTrue($rolledBack->last_heartbeat_at->equalTo($original->last_heartbeat_at));
        self::assertSame(0, ProductEvent::count());
        self::assertSame(0, OutboxEvent::count());
        $result = $service->recordHeartbeat($this->user, $lesson, 20, 25);
        self::assertTrue($result['eligible_for_completion']);
        self::assertSame(['lesson_started', 'lesson_completed'], ProductEvent::orderBy('id')->pluck('event_name')->all());
        self::assertSame(2, OutboxEvent::count());
    }

    public function test_legacy_partial_evidence_does_not_invent_a_new_start_on_completion(): void
    {
        $lesson = $this->lesson();
        LessonWatchEvidence::create(['user_id' => $this->user->id, 'lesson_id' => 10,
            'course_section_id' => $this->sectionId, 'duration_seconds' => 25,
            'verified_seconds' => 10, 'last_position_seconds' => 10,
            'last_heartbeat_at' => now(), 'created_at' => now()->subDay()]);
        $this->travel(10)->seconds();
        app(LearningEvidenceService::class)->recordHeartbeat($this->user, $lesson, 20, 25);
        self::assertSame(['lesson_completed'], ProductEvent::pluck('event_name')->all());
        $this->travel(1)->seconds();
        self::assertTrue(app(LessonCompletionReportService::class)
            ->report($this->courseId, ReportPeriod::fromKey('30d'))['rows']->isEmpty());
    }

    public function test_carried_completion_does_not_create_a_new_watch_or_a_start_when_revisited(): void
    {
        $source = $this->lesson();
        DB::table('lessons')->insert(['id' => 22, 'list_id' => $this->courseId, 'title' => 'Current lesson']);
        $section = CourseSection::create(['course_id' => $this->courseId,
            'sectionable_type' => Lesson::class, 'sectionable_id' => 22,
            'section_type' => 'lesson', 'title' => 'Current lesson', 'order' => 2]);
        $target = Lesson::findOrFail(22);
        $target->setRelation('courseSection', $section);
        $target->setRelation('mediaState', new LessonMediaState(['duration_seconds' => 25]));
        $service = app(LearningEvidenceService::class);
        $service->carryCompletedRevisionForward($this->user, $source, $target,
            ['eligible_for_completion' => true, 'required_seconds' => 20]);
        $this->travel(10)->seconds();
        $service->recordHeartbeat($this->user, $target, 25, 25);
        self::assertSame(0, ProductEvent::count());
    }

    public function test_the_actual_watch_endpoint_keeps_academic_tracking_when_resume_history_is_disabled(): void
    {
        $this->lesson();
        DB::table('course_enrollments')->insert(['user_id' => $this->user->id,
            'course_id' => $this->courseId, 'is_active' => true, 'enrolled_at' => now(),
            'created_at' => now(), 'updated_at' => now()]);
        $this->user->forceFill(['watch_history_enabled' => false])->save();
        $session = $this->playbackSession();
        $this->actingAs($this->user, 'api');
        $this->sample($session, 1, 0)->assertOk();
        self::assertSame(0, ProductEvent::count());
        $this->travel(10)->seconds();
        $this->sample($session, 2, 10)->assertOk();
        self::assertSame(['lesson_started'], ProductEvent::pluck('event_name')->all());
        $this->sample($session, 2, 10)->assertOk();
        self::assertSame(1, ProductEvent::count());
        self::assertSame(0, DB::table('watching_logs')->count());
    }

    public function test_an_unsubscribed_preview_does_not_emit_academic_events(): void
    {
        $this->lesson();
        DB::table('lessons')->where('id', 10)->update(['is_opened' => true]);
        $session = $this->playbackSession();
        $this->actingAs($this->user, 'api');
        $this->sample($session, 1, 0)->assertOk()->assertJsonPath('data.preview', true);
        $this->travel(10)->seconds();
        $this->sample($session, 2, 10)->assertOk()->assertJsonPath('data.preview', true);
        self::assertSame(0, ProductEvent::count());
        self::assertSame(0, LessonWatchEvidence::count());
    }

    private function lesson(): Lesson
    {
        DB::table('course_sections')->where('id', $this->sectionId)->update([
            'sectionable_type' => Lesson::class, 'sectionable_id' => 10, 'section_type' => 'lesson']);
        DB::table('lesson_media_states')->insert(['lesson_id' => 10, 'provider' => 'bunny',
            'status' => 'ready', 'duration_seconds' => 25, 'created_at' => now(), 'updated_at' => now()]);
        return Lesson::with('courseSection', 'mediaState')->findOrFail(10);
    }

    private function playbackSession(): PlaybackSession
    {
        return PlaybackSession::create(['id' => (string) Str::uuid(), 'user_id' => $this->user->id,
            'lesson_id' => 10, 'course_section_id' => $this->sectionId, 'started_at' => now(),
            'last_sequence' => 0, 'last_position_seconds' => 0, 'source_protocol' => 'hls']);
    }

    private function sample(PlaybackSession $session, int $sequence, int $position): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/user/watch-history', ['lesson_id' => 10,
            'playback_session_id' => $session->id, 'sequence' => $sequence,
            'position_seconds' => $position, 'duration_seconds' => 25, 'event_type' => 'heartbeat']);
    }
}
