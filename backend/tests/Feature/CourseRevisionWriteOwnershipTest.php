<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseCode;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Services\CoursePlanAuthoringService;
use App\Services\CoursePlanPublicationService;
use App\Services\CoursePublishingService;
use App\Services\CourseRevisionGraphService;
use App\Services\CourseRevisionLineageService;
use App\Services\CourseRevisionResolver;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

final class CourseRevisionWriteOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // These contracts must run without RefreshDatabase's outer transaction.
        self::assertSame('testing', app()->environment());
        self::assertSame('sqlite', DB::connection()->getDriverName());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_revision_writers_refuse_to_commit_fragments_outside_the_authoring_transaction(): void
    {
        [$course] = $this->publishedFixture();
        $before = $this->snapshot();
        $graph = app(CourseRevisionGraphService::class);
        $revision = new CourseAuthoringRevision();
        $operations = [
            'clone' => fn () => $graph->cloneWithinTransaction($course),
            'swap' => fn () => $graph->swapWithinTransaction($revision, $course, new Course()),
            'offers' => fn () => app(CoursePlanPublicationService::class)
                ->publishWithinTransaction($course, new Course()),
            'lineage' => fn () => app(CourseRevisionLineageService::class)
                ->finalizeWithinTransaction($revision),
        ];

        foreach ($operations as $name => $operation) {
            self::assertSame(0, DB::transactionLevel());
            try {
                $operation();
                self::fail($name.' must require the caller-owned transaction.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('authoring transaction', $exception->getMessage(), $name);
            }
            self::assertSame($before, $this->snapshot(), $name.' must not write before rejecting.');
        }
    }

    public function test_content_copy_is_independent_and_rolls_back_with_its_caller(): void
    {
        [$course, $lesson] = $this->publishedFixture();
        $before = $this->snapshot();
        // The graph owner must not depend on publication orchestration or reads.
        foreach ([CourseStagedAuthoringService::class, CourseRevisionResolver::class] as $unrelated) {
            $this->app->bind($unrelated, static function (): never {
                throw new \LogicException('Graph copying must not resolve an orchestrator or learner reader.');
            });
        }

        try {
            DB::transaction(function () use ($course, $lesson): void {
                [$draft, $mappings] = app(CourseRevisionGraphService::class)
                    ->cloneWithinTransaction($course);
                $copy = $draft->sections()->firstOrFail()->sectionable;
                self::assertNotSame($lesson->id, $copy->id);
                self::assertSame((int) $draft->id, (int) $copy->list_id);
                self::assertSame('ready', $copy->mediaState->status);
                self::assertSame($lesson->mediaState->provider_media_id, $copy->mediaState->provider_media_id);
                self::assertContains([Lesson::class, (int) $lesson->id, (int) $copy->id], $mappings);
                self::assertSame(3, $draft->accessPlans()->count());
                self::assertSame(1, $draft->pdfs()->count());
                self::assertFalse((bool) $draft->is_catalog_visible);
                $copy->update(['title_ar' => 'تعديل المسودة']);
                self::assertSame('الدرس الأصلي', $lesson->fresh()->title_ar);
                throw new \RuntimeException('Caller aborted draft creation.');
            });
            self::fail('The caller must be able to roll back every copied entity.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Caller aborted draft creation.', $exception->getMessage());
        }

        self::assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_late_publication_failure_rolls_back_content_offers_lineage_and_grant_pointers_together(): void
    {
        [$course, $lesson, $code] = $this->publishedFixture();
        $audit = Mockery::mock(CoursePublishingService::class);
        $audit->shouldReceive('audit')->once()->ordered()->andReturn(['ready' => true, 'issues' => []]);
        $audit->shouldReceive('audit')->once()->ordered()->andReturnUsing(function () use ($course, $lesson, $code): never {
            // Notification preparation is after all three writers have run.
            self::assertNotSame((int) $course->id, (int) $lesson->fresh()->list_id);
            self::assertNotSame((int) $lesson->id, (int) $code->fresh()->lesson_id);
            self::assertGreaterThan(0, DB::table('course_authoring_revision_entities')
                ->where('carries_learner_state', true)->count());
            self::assertSame(777, (int) $course->accessPlans()->where('code', 'basic')->value('price_coins'));
            throw new \RuntimeException('Notification preparation interrupted publication.');
        });
        $this->app->instance(CoursePublishingService::class, $audit);
        $service = app(CourseStagedAuthoringService::class);
        $draft = $service->draftFor($course);
        $draft->sections()->firstOrFail()->sectionable->update(['title_ar' => 'الدرس المعدل']);
        $draft->accessPlans()->where('code', 'basic')->update(['price_coins' => 777]);
        $before = $this->snapshot();

        try {
            $service->publish($draft, (int) $draft->authoring_version, true);
            self::fail('A late failure must not leave a partially published course.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Notification preparation interrupted publication.', $exception->getMessage());
        }

        self::assertSame(0, DB::transactionLevel());
        self::assertSame($before, $this->snapshot());
        self::assertTrue(app(CourseRevisionResolver::class)->isManagedDraft($draft));
        Http::assertNothingSent();
    }

    public function test_repeated_publication_keeps_the_learner_root_and_updates_only_surviving_lesson_grants(): void
    {
        [$course, $lesson, $code] = $this->publishedFixture();
        $rootId = (int) $lesson->id;
        $audit = Mockery::mock(CoursePublishingService::class);
        $audit->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);
        // Only readiness is substituted; graph, offer and lineage writers are real.
        $service = $this->app->makeWith(CourseStagedAuthoringService::class, ['publishing' => $audit]);
        foreach ([1, 2] as $publication) {
            $draft = $service->draftFor($course->fresh());
            $currentLesson = $draft->sections()->firstOrFail()->sectionable;
            $service->publish($draft, (int) $draft->authoring_version, true);
            self::assertSame((int) $currentLesson->id, (int) $code->fresh()->lesson_id);
            self::assertTrue($code->fresh()->is_active);
            $mapping = DB::table('course_authoring_revision_entities')
                ->where('entity_type', Lesson::class)
                ->where('revision_entity_id', $currentLesson->id)->first();
            self::assertSame($rootId, (int) $mapping->learner_root_entity_id);
            self::assertTrue((bool) $mapping->carries_learner_state);
        }

        $draft = $service->draftFor($course->fresh());
        $draft->sections()->firstOrFail()->delete();
        $service->publish($draft, (int) $draft->authoring_version, true);
        self::assertNull($code->fresh()->lesson_id);
        self::assertFalse($code->fresh()->is_active, 'A removed lesson must not become a whole-course grant.');
        self::assertSame(3, CourseAuthoringRevision::query()->where('status', CourseAuthoringRevision::ARCHIVED)->count());
        Http::assertNothingSent();
    }

    /** @return array{Course, Lesson, CourseCode} */
    private function publishedFixture(): array
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'الكورس المنشور', 'description_ar' => 'وصف الكورس',
            'price' => 400, 'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 4, 'last_published_authoring_version' => 4, 'published_at' => now(),
        ]);
        app(CoursePlanAuthoringService::class)->createDefaults($course);
        $module = $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'الدرس الأصلي']);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id, 'provider_media_id' => 'disposable-fixture-video',
            'status' => 'ready', 'duration_seconds' => 60,
        ]);
        $course->sections()->create([
            'module_id' => $module->id, 'section_type' => 'lesson',
            'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
        $course->pdfs()->create([
            'title' => 'ملف الدرس', 'source_type' => 'external', 'file_path' => '',
            'external_url' => 'https://files.example.test/lesson.pdf', 'is_active' => true,
        ]);
        $code = CourseCode::query()->forceCreate([
            'tenant_id' => 1, 'code' => 'REVISION-LESSON', 'type' => 'lesson',
            'course_id' => $course->id, 'lesson_id' => $lesson->id, 'is_active' => true,
        ]);

        return [$course, $lesson, $code];
    }

    private function snapshot(): array
    {
        return collect([
            'courses', 'course_modules', 'course_sections', 'lessons', 'lesson_media_states',
            'course_pdfs', 'course_access_plans', 'course_authoring_revisions',
            'course_authoring_revision_entities', 'course_codes', 'notification_campaigns',
        ])->mapWithKeys(fn (string $table): array => [
            $table => DB::table($table)->get()->map(fn ($row): array => (array) $row)
                ->sortBy(fn (array $row): string => json_encode($row, JSON_THROW_ON_ERROR))->values()->all(),
        ])->all();
    }
}
