<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\LessonWatchEvidence;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CourseCompletionService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use App\Services\ProjectSubmissionOrchestrator;
use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectRevisionLineageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        config()->set('product_features.definitions.project_uploads.default_enabled', true);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public static function publications(): array
    {
        return [
            'survives one publication' => [1, 'keep'],
            'survives two publications' => [2, 'keep'],
            'deleted in first publication' => [1, 'delete'],
            'deleted after an earlier surviving publication' => [2, 'delete'],
            'retyped in first publication' => [1, 'retype'],
            'retyped after an earlier surviving publication' => [2, 'retype'],
        ];
    }

    #[DataProvider('publications')]
    public function test_old_project_http_actions_identify_only_the_surviving_published_project(
        int $publications, string $lastChange
    ): void {
        [$student, $course, $source] = $this->fixture();
        $currentSection = null;
        for ($revision = 1; $revision <= $publications; $revision++) {
            $currentSection = $this->publish($course, $revision === $publications ? $lastChange : 'keep');
        }
        $currentProjectId = $lastChange === 'keep' ? (int) $currentSection->sectionable_id : null;
        $currentSectionId = $lastChange === 'keep' ? (int) $currentSection->id : null;

        $this->actingAs($student, 'api');
        $post = $this->postJson('/api/v1/projects/'.$source->id.'/submissions', [
            'submission_text' => 'نفذت المشروع وأريد مراجعة المتطلبات قبل تسليمه',
            'client_submission_id' => 'unsent-project-'.Str::uuid(),
        ]);
        $post->assertJsonPath('data.submission_admission_closed', true);
        foreach ([$post, $this->getJson('/api/v1/projects/'.$source->id)] as $response) {
            $response->assertStatus(409)->assertJsonPath('code', 'course_revision_changed')
                ->assertJsonStructure(['data' => ['source_project_id', 'current_project_id', 'current_section_id']])
                ->assertJsonPath('data.source_project_id', (int) $source->id)
                ->assertJsonPath('data.current_project_id', $currentProjectId)
                ->assertJsonPath('data.current_section_id', $currentSectionId)
                ->assertJsonPath('data.course_id', (int) $course->id)
                ->assertJsonPath('data.published_revision', (int) $course->fresh()->last_published_authoring_version)
                ->assertJsonPath('data.reload_endpoint', '/api/v1/courses/'.$course->id.'/details')
                ->assertJsonMissingPath('data.requirements_text');
        }
        if ($currentProjectId !== null) {
            $this->getJson('/api/v1/projects/'.$currentProjectId)->assertOk()
                ->assertJsonPath('data.id', $currentProjectId)
                ->assertJsonPath('data.requirements_text', 'متطلبات النسخة المنشورة '.$publications);
        }
        $this->getJson('/api/v1/projects/'.$source->id)->assertJsonMissingPath('data.submission_admission_closed');
        $this->assertNoSubmissionWork();
    }

    public static function unavailableEnrollments(): array
    {
        return ['revoked' => ['is_active', false], 'expired' => ['expires_at', '2000-01-01 00:00:00']];
    }

    #[DataProvider('unavailableEnrollments')]
    public function test_lineage_is_not_a_grant_to_read_or_submit_the_current_project(string $field, mixed $value): void
    {
        [$student, $course, $source, $enrollment] = $this->fixture();
        $currentSection = $this->publish($course);
        $currentProjectId = (int) $currentSection->sectionable_id;
        $enrollment->forceFill([$field => $value])->save();
        $this->actingAs($student, 'api');

        $this->getJson('/api/v1/projects/'.$source->id)->assertStatus(409)
            ->assertJsonPath('data.current_project_id', $currentProjectId)
            ->assertJsonMissingPath('data.requirements_text');
        $this->getJson('/api/v1/projects/'.$currentProjectId)->assertForbidden();
        $this->postJson('/api/v1/projects/'.$currentProjectId.'/submissions', [
            'submission_text' => 'نفذت المشروع وهذه ملاحظاتي على الحل النهائي',
            'client_submission_id' => 'unavailable-project-'.Str::uuid(),
        ])->assertForbidden()->assertJsonPath('data', null)
            ->assertJsonMissingPath('data.submission_admission_closed');
        $this->assertNoSubmissionWork();
    }

    public function test_publication_refusal_does_not_deny_an_already_accepted_receipt_or_repeat_its_work(): void
    {
        [$student, $course, $source] = $this->fixture();
        $input = [
            'submission_text' => 'نفذت جميع خطوات المشروع وأرفقت وصفًا واضحًا للنتيجة وطريقة التنفيذ',
            'client_submission_id' => 'original-project-'.Str::uuid(),
        ];
        $this->actingAs($student, 'api');
        $accepted = $this->postJson('/api/v1/projects/'.$source->id.'/submissions', $input)->assertAccepted();
        $submission = ProjectSubmission::query()->sole();
        self::assertSame($accepted->json('data.id'), $submission->public_id);
        $before = $submission->getRawOriginal();
        $currentSection = $this->publish($course);
        Bus::fake();

        // The old POST is now refused before admission/replay. That refusal
        // concerns this POST only; the exact-key read receipt remains valid.
        $this->postJson('/api/v1/projects/'.$source->id.'/submissions', $input)->assertStatus(409)
            ->assertJsonPath('data.source_project_id', (int) $source->id)
            ->assertJsonPath('data.current_project_id', (int) $currentSection->sectionable_id);
        $this->getJson('/api/v1/projects/'.$source->id.'/submissions/lookup?'.http_build_query([
            'client_submission_id' => $input['client_submission_id'],
        ]))->assertOk()->assertJsonPath('data.id', $submission->public_id)
            ->assertJsonPath('data.project_id', (int) $source->id)
            ->assertJsonPath('data.client_submission_id', $input['client_submission_id']);
        $replay = app(ProjectSubmissionOrchestrator::class)->submit(
            $student, $source->fresh(), $input['submission_text'], [], $input['client_submission_id'], []
        );
        self::assertSame('submitted', $replay['state']);
        self::assertSame($submission->id, $replay['submission']->id);
        self::assertSame($before, $submission->fresh()->getRawOriginal());
        self::assertSame(1, ProjectSubmission::query()->count());
        self::assertSame(0, AiUsageEvent::query()->count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_guests_cannot_read_revision_lineage_or_submit(): void
    {
        [, $course, $source] = $this->fixture();
        $this->publish($course);
        $this->getJson('/api/v1/projects/'.$source->id)->assertUnauthorized();
        $this->postJson('/api/v1/projects/'.$source->id.'/submissions', [
            'submission_text' => 'نص المشروع محفوظ على الجهاز',
        ])->assertUnauthorized();
        $this->assertNoSubmissionWork();
    }

    public static function lineageReadActions(): array
    {
        return ['explicit refresh' => ['GET'], 'refused upload' => ['POST']];
    }

    #[DataProvider('lineageReadActions')]
    public function test_lineage_read_cannot_mistake_an_interleaved_publication_for_project_deletion(string $method): void
    {
        [$student, $course, $source] = $this->fixture();
        $firstCurrentSection = $this->publish($course);
        $locks = [];
        $this->observeSelects(function (Builder $query) use (&$locks): void {
            if ($query->lock !== null) $locks[] = [$query->from, $query->lock];
        });
        $injected = false;
        $nextSection = null;
        Event::listen('eloquent.retrieved: '.Project::class, function (Project $project) use (
            $course, $firstCurrentSection, &$locks, &$injected, &$nextSection
        ): void {
            if ($injected || (int) $project->id !== (int) $firstCurrentSection->sectionable_id) return;
            $injected = true;
            // Mapping selected project 42 but its section is not loaded yet.
            // A publisher without a conflicting read lock can move 42 into an
            // archive here. With the lock, schedule publication after the read.
            if (!in_array(['courses', false], $locks, true)) $nextSection = $this->publish($course);
        });
        $this->actingAs($student, 'api');
        $url = '/api/v1/projects/'.$source->id;
        $response = $method === 'GET'
            ? $this->getJson($url)
            : $this->postJson($url.'/submissions', ['submission_text' => 'نفذت المشروع وهذه ملاحظاتي']);
        self::assertTrue($injected);
        $expectedSection = $nextSection ?? $firstCurrentSection;
        $response->assertStatus(409)
            ->assertJsonPath('data.current_project_id', (int) $expectedSection->sectionable_id)
            ->assertJsonPath('data.current_section_id', (int) $expectedSection->id)
            ->assertJsonPath('data.published_revision', (int) $course->fresh()->last_published_authoring_version);
        self::assertSame([['courses', false]], $locks, 'A lineage read needs only the short shared course lock, never a learner lock.');
        if ($method === 'POST') $response->assertJsonPath('data.submission_admission_closed', true);
        else $response->assertJsonMissingPath('data.submission_admission_closed');

        $nextSection ??= $this->publish($course);
        $this->getJson($url)->assertStatus(409)
            ->assertJsonPath('data.current_project_id', (int) $nextSection->sectionable_id)
            ->assertJsonPath('data.current_section_id', (int) $nextSection->id)
            ->assertJsonPath('data.published_revision', (int) $course->fresh()->last_published_authoring_version);
        $this->assertNoSubmissionWork();
    }

    public function test_publication_cannot_report_a_missing_receipt_while_old_admission_can_still_commit(): void
    {
        [$student, $course, $source] = $this->fixture();
        $input = [
            'submission_text' => 'نفذت جميع خطوات المشروع وأوضحت طريقة التنفيذ والنتيجة النهائية',
            'client_submission_id' => 'late-original-'.Str::uuid(),
        ];
        $locks = [];
        $this->observeSelects(function (Builder $query) use (&$locks): void {
            if ($query->lock !== null) $locks[] = [$query->from, $query->lock];
        });
        $interleaved = false;
        $lookupAtPublication = null;
        $publishAndLookup = function () use ($course, $source, $input, &$lookupAtPublication): void {
            $this->publish($course);
            $this->postJson('/api/v1/projects/'.$source->id.'/submissions', $input)
                ->assertStatus(409)->assertJsonPath('code', 'course_revision_changed');
            $lookupAtPublication = $this->getJson('/api/v1/projects/'.$source->id.'/submissions/lookup?'.http_build_query([
                'client_submission_id' => $input['client_submission_id'],
            ]))->status();
        };
        Event::listen('eloquent.retrieved: '.CourseSection::class, function (CourseSection $section) use (
            $source, &$locks, &$interleaved, $publishAndLookup
        ): void {
            if ($interleaved || (int) $section->sectionable_id !== (int) $source->id
                || $section->sectionable_type !== Project::class
                || !in_array(['users', true], $locks, true)) return;
            $interleaved = true;
            // SQLite does not enforce row locks. This controlled scheduler
            // publishes here only when no requested course lock excludes it;
            // otherwise publication waits until the HTTP admission completes.
            if (!in_array(['courses', false], $locks, true)) $publishAndLookup();
        });

        $this->actingAs($student, 'api');
        $original = $this->postJson('/api/v1/projects/'.$source->id.'/submissions', $input);
        if ($lookupAtPublication === null) $publishAndLookup();
        self::assertTrue($interleaved);
        self::assertFalse(
            $lookupAtPublication === 404 && $original->status() === 202,
            'A revision refusal plus missing receipt must not be followed by late acceptance of the original POST.'
        );
        $original->assertAccepted();
        self::assertSame(200, $lookupAtPublication);
        self::assertSame([['users', true], ['courses', false]], array_slice($locks, 0, 2));
        self::assertSame(1, ProjectSubmission::query()->count());
        self::assertSame(0, AiUsageEvent::query()->count());
        Http::assertNothingSent();
    }

    public function test_publication_winning_before_the_admission_lock_rejects_the_captured_old_project(): void
    {
        [$student, $course, $source] = $this->fixture();
        $published = false;
        $captured = false;
        Event::listen('eloquent.retrieved: '.Project::class, function (Project $project) use ($source, &$captured): void {
            if ((int) $project->id === (int) $source->id) $captured = true;
        });
        DB::connection()->beforeStartingTransaction(function () use ($course, &$published, &$captured): void {
            if (!$published && $captured) {
                $published = true;
                // The upload/controller already captured the old project.
                // Publish before admission begins, not inside its rollback
                // savepoint: this models a separately committed publication.
                $this->publish($course);
            }
        });
        $this->actingAs($student, 'api')->postJson('/api/v1/projects/'.$source->id.'/submissions', [
            'submission_text' => 'نفذت جميع خطوات المشروع وأوضحت طريقة التنفيذ والنتيجة النهائية',
            'client_submission_id' => 'publication-first-'.Str::uuid(),
        ])->assertStatus(409)->assertJsonPath('code', 'course_revision_changed')
            ->assertJsonPath('data.source_project_id', (int) $source->id)
            ->assertJsonPath('data.current_project_id', (int) $course->fresh()->sections()->firstOrFail()->sectionable_id)
            ->assertJsonPath('data.submission_admission_closed', true);
        self::assertTrue($published);
        $this->assertNoSubmissionWork();
    }

    public function test_completion_takes_the_same_learner_then_course_order_without_completing_a_project(): void
    {
        [$student, $course, $source] = $this->fixture();
        $locks = [];
        $this->observeSelects(function (Builder $query) use (&$locks): void {
            if ($query->lock !== null) $locks[] = [$query->from, $query->lock];
        });
        $result = app(CourseCompletionService::class)->complete($student, (int) $course->id, (int) $source->section->id);
        self::assertSame(409, $result['status']);
        self::assertSame('project_submission_required', $result['code']);
        self::assertSame([['users', true], ['courses', true]], array_slice($locks, 0, 2));
        self::assertSame(0, DB::table('student_section_progress')->count());
        $this->assertNoSubmissionWork();
    }

    public function test_completion_still_requires_evidence_and_replays_success_in_the_same_lock_order(): void
    {
        [$student, $course, $project] = $this->fixture();
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'name_ar' => 'الدرس']);
        $section = CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $project->section->module_id,
            'title_ar' => 'الدرس', 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id, 'order' => 0,
        ]);
        LessonMediaState::query()->create(['lesson_id' => $lesson->id, 'duration_seconds' => 60, 'status' => 'ready']);
        $locks = [];
        $this->observeSelects(function (Builder $query) use (&$locks): void {
            if ($query->lock !== null) $locks[] = [$query->from, $query->lock];
        });
        $this->actingAs($student, 'api');
        $url = '/api/v1/courses/'.$course->id.'/sections/'.$section->id.'/complete';
        $this->postJson($url)->assertStatus(409)->assertJsonPath('code', 'verified_watch_required');
        self::assertSame([['users', true], ['courses', true]], array_slice($locks, 0, 2));
        self::assertSame(0, DB::table('student_section_progress')->count());
        LessonWatchEvidence::query()->create([
            'user_id' => $student->id, 'lesson_id' => $lesson->id, 'course_section_id' => $section->id,
            'duration_seconds' => 60, 'verified_seconds' => 60, 'completed_at' => now(),
        ]);
        foreach ([1, 2] as $attempt) {
            $locks = [];
            $this->postJson($url)->assertOk()->assertJsonPath('data.section.is_completed', true);
            self::assertSame([['users', true], ['courses', true]], array_slice($locks, 0, 2));
            self::assertSame(1, DB::table('student_section_progress')->count());
        }
        $this->assertNoSubmissionWork();
    }

    private function observeSelects(\Closure $observe): void
    {
        $connection = DB::connection();
        $connection->setQueryGrammar(new class($connection, $observe) extends SQLiteGrammar {
            public function __construct(Connection $connection, private \Closure $observe)
            {
                parent::__construct($connection);
            }

            public function compileSelect(Builder $query)
            {
                ($this->observe)($query);
                return parent::compileSelect($query);
            }
        });
    }

    private function assertNoSubmissionWork(): void
    {
        self::assertSame(0, ProjectSubmission::query()->count());
        self::assertSame(0, AiUsageEvent::query()->count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    /** @return array{User,Course,Project,CourseEnrollment} */
    private function fixture(): array
    {
        $student = new User();
        $student->forceFill([
            'name_ar' => 'طالب المشروع', 'email' => Str::uuid().'@example.test',
            'role' => 'client', 'active' => true,
        ])->save();
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس المشروع', 'description_ar' => 'وصف الكورس',
            'price' => 800, 'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 4, 'last_published_authoring_version' => 4, 'published_at' => now(),
        ])->save();
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $project = Project::query()->create([
            'requirements_text_ar' => 'متطلبات النسخة الأصلية', 'submission_text_enabled' => true,
            'submission_allowed_mime_types' => ['image/jpeg'],
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'المشروع',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1,
        ]);
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill([
            'tenant_id' => 1, 'user_id' => $student->id, 'course_id' => $course->id, 'is_active' => true,
            'enrolled_at' => now(), 'access_granted_at' => now(),
        ])->save();
        return [$student, $course, $project, $enrollment];
    }

    private function publish(Course $course, string $change = 'keep'): CourseSection
    {
        // Exercise the real clone, graph swap and surviving-entity mapping.
        // Video/cover readiness is independent of this HTTP lineage contract.
        $audit = Mockery::mock(CoursePublishingService::class);
        $audit->shouldReceive('audit')->once()->andReturn(['ready' => true, 'issues' => []]);
        $revisions = new CourseStagedAuthoringService($audit);
        $draft = $revisions->draftFor($course->fresh());
        $section = $draft->sections()->where('sectionable_type', Project::class)->firstOrFail();
        $project = $section->sectionable;
        if ($change === 'delete') {
            $section->delete();
        } elseif ($change === 'retype') {
            $lesson = Lesson::query()->create(['list_id' => $draft->id, 'name_ar' => 'محتوى بديل']);
            $section->forceFill(['sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id])->save();
        } else {
            $project->forceFill([
                'requirements_text_ar' => 'متطلبات النسخة المنشورة '.((int) $course->fresh()->last_published_authoring_version - 3),
            ])->save();
        }
        $revisions->publish($draft->fresh(), (int) $draft->fresh()->authoring_version, true);
        return $section;
    }
}
