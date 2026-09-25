<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ProductOperationsController;
use App\Models\Course;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\OperationalIncident;
use App\Models\PlaybackSession;
use App\Models\ProductFeatureFlag;
use App\Models\User;
use App\Services\AdminProductOperationsReadService;
use App\Services\CoursePublishingService;
use App\Services\MediaReconciliationService;
use App\Services\OutboxService;
use App\Support\BusinessClock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminProductOperationsReadServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->freezeTime();
        foreach ([ProductOperationsController::class, OutboxService::class,
            MediaReconciliationService::class, CoursePublishingService::class] as $writer) {
            $this->app->bind($writer, static function (): never {
                throw new \LogicException('An operations report must not resolve command owners.');
            });
        }
    }

    public function test_report_is_request_free_read_only_and_does_not_reconcile_incidents_or_dispatch_work(): void
    {
        $incident = OperationalIncident::query()->create([
            'code' => 'test-existing-incident', 'category' => 'queue', 'severity' => 'critical',
            'status' => 'open', 'summary' => 'Existing unresolved incident',
            'first_seen_at' => now()->subHour(), 'last_seen_at' => now()->subHour(),
        ]);
        $flag = ProductFeatureFlag::query()->create([
            'key' => 'checkout', 'enabled' => false, 'rollout_percentage' => 0,
            'owner' => 'operations', 'reason' => 'Keep checkout closed',
        ]);
        $incidentBefore = $incident->fresh()->getAttributes();
        $flagBefore = $flag->fresh()->getAttributes();
        DB::enableQueryLog();
        DB::flushQueryLog();
        $report = app(AdminProductOperationsReadService::class)->report();
        $writes = array_filter(DB::getQueryLog(), static fn (array $query): bool =>
            preg_match('/^\s*(insert|update|delete|replace|create|alter|drop)\b/i', $query['query']) === 1);
        DB::disableQueryLog();
        self::assertSame([], $writes);
        self::assertSame($incidentBefore, $incident->fresh()->getAttributes());
        self::assertSame($flagBefore, $flag->fresh()->getAttributes());
        self::assertSame([$incident->id], $report['operationalIncidents']->modelKeys());
        self::assertFalse($report['featureFlags']['checkout']['enabled']);
        self::assertSame('Keep checkout closed', $report['featureFlags']['checkout']['reason']);
        self::assertEqualsCanonicalizing([
            'courses', 'settings', 'readiness', 'counts', 'finance', 'capabilityReport', 'mediaAttention',
            'playbackOperations', 'mediaReconcileStatus', 'backupReadiness', 'featureFlags',
            'financialAnomalies', 'paymentChannelReport', 'storeNotificationReviews', 'providerEvidence',
            'runtime', 'operationalIncidents', 'runtimeHeartbeatFailures', 'launchReady',
            'mobileReleaseCapability', 'recentClientFailures', 'clientFailuresLastDay',
        ], array_keys($report));
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_media_report_distinguishes_playable_metadata_warnings_from_blocking_failures(): void
    {
        $course = $this->course();
        $normal = $this->lesson($course, 'Normal');
        $metadata = $this->lesson($course, 'Metadata warning', [
            'integrity_status' => 'attention',
            'integrity_issues' => [['code' => 'course_cover_missing']],
        ]);
        $blocking = $this->lesson($course, 'Broken manifest', [
            'integrity_status' => 'attention',
            'integrity_issues' => [['code' => 'manifest_invalid'], ['code' => 'manifest_invalid']],
        ]);
        $stale = $this->lesson($course, 'Stale generation', ['provider_media_id' => (string) Str::uuid()]);
        $processing = $this->lesson($course, 'Processing', ['status' => 'processing', 'last_reconciled_at' => null]);
        $quarantined = $this->lesson($course, 'Quarantined', ['quarantined_at' => now()]);
        $unconfigured = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'No secure source']);
        $statesBefore = DB::table('lesson_media_states')->orderBy('id')->get()->toJson();

        $report = app(AdminProductOperationsReadService::class)->report();

        self::assertSame(2, $report['counts']['media_ready']);
        self::assertSame(6, $report['counts']['media_attention']);
        $attention = $report['mediaAttention']->keyBy('id');
        self::assertFalse($attention->has($normal->id));
        self::assertSame('ready', $attention[$metadata->id]->operations_playback_status);
        self::assertSame(['غلاف الكورس غير مكتمل'], $attention[$metadata->id]->operations_attention_reasons);
        self::assertSame('not_ready', $attention[$blocking->id]->operations_playback_status);
        self::assertSame(['ملف تشغيل الفيديو غير صالح'], $attention[$blocking->id]->operations_attention_reasons);
        self::assertSame('not_ready', $attention[$stale->id]->operations_playback_status);
        self::assertSame('processing', $attention[$processing->id]->operations_playback_status);
        self::assertSame('quarantined', $attention[$quarantined->id]->operations_playback_status);
        self::assertSame('misconfigured', $attention[$unconfigured->id]->operations_playback_status);
        self::assertSame($statesBefore, DB::table('lesson_media_states')->orderBy('id')->get()->toJson());
        Bus::assertNothingDispatched();
    }

    public function test_certificate_counts_do_not_treat_revoked_or_pending_images_as_issued(): void
    {
        $course = $this->course();
        foreach ([
            ['active', 'certificates/issued.png', null],
            ['active', 'pending', null],
            ['revoked', 'certificates/revoked.png', now()],
            ['active', 'pending', now()],
        ] as $index => [$status, $path, $revokedAt]) {
            $user = User::query()->forceCreate(['name' => 'Learner '.$index,
                'email' => 'operations-'.$index.'@example.test', 'role' => 'client']);
            DB::table('certificates')->insert([
                'user_id' => $user->id, 'course_id' => $course->id, 'image_path' => $path,
                'generated_at' => now(), 'status' => $status, 'revoked_at' => $revokedAt,
            ]);
        }
        $before = DB::table('certificates')->orderBy('id')->get()->toJson();
        $report = app(AdminProductOperationsReadService::class)->report();
        self::assertSame(1, $report['counts']['certificates']);
        self::assertSame(1, $report['counts']['certificates_pending']);
        self::assertSame(2, $report['counts']['certificates_revoked']);
        self::assertSame($before, DB::table('certificates')->orderBy('id')->get()->toJson());
        Bus::assertNothingDispatched();
    }

    public function test_today_playback_count_uses_the_business_day_with_an_exclusive_end(): void
    {
        config(['app.business_timezone' => 'Africa/Cairo']);
        $this->travelTo(Carbon::parse('2026-09-25 12:00:00', 'UTC'));
        [$start, $end] = BusinessClock::localDayRangeUtc('2026-09-25');
        $lesson = $this->lesson($this->course(), 'Day boundary');
        $user = User::query()->forceCreate(['name' => 'Viewer', 'email' => 'viewer@example.test', 'role' => 'client']);
        foreach ([$start->subSecond(), $start, $end->subSecond(), $end] as $at) {
            PlaybackSession::query()->create([
                'id' => (string) Str::uuid(), 'user_id' => $user->id, 'lesson_id' => $lesson->id,
                'started_at' => $at, 'last_heartbeat_at' => $at,
            ]);
        }
        $report = app(AdminProductOperationsReadService::class)->report();
        self::assertSame(2, $report['counts']['playback_sessions_today']);
        self::assertSame(4, PlaybackSession::query()->count());
    }

    private function course(): Course
    {
        return Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'Operations fixture',
            'is_coming_soon' => true, 'authoring_version' => 1]);
    }

    private function lesson(Course $course, string $title, array $state = []): Lesson
    {
        $guid = (string) Str::uuid();
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => $title,
            'video_source_type' => 'bunny', 'bunny_video_id' => $guid]);
        LessonMediaState::query()->create(array_replace([
            'lesson_id' => $lesson->id, 'provider' => 'bunny', 'provider_media_id' => $guid,
            'status' => 'ready', 'protocol' => 'hls', 'duration_seconds' => 75,
            'available_qualities' => ['auto', '720p'], 'integrity_status' => 'healthy',
            'last_probe_at' => now(), 'last_reconciled_at' => now(),
        ], $state));
        return $lesson;
    }
}
