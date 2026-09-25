<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Lesson;
use App\Models\Project;
use App\Services\CourseCompletionService;
use App\Services\CourseSectionAccessService;
use App\Services\CoursePublishingService;
use App\Services\CoursePlanPublicationService;
use App\Services\CourseRevisionGraphService;
use App\Services\CourseRevisionLineageService;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\CourseRevisionResolver;
use App\Services\CourseStagedAuthoringService;
use App\Services\SavedLibraryService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class CourseRevisionResolverTest extends TestCase
{
    use RefreshDatabase;

    public function test_reads_resolve_existing_revisions_without_authoring_dependencies_or_writes(): void
    {
        Http::preventStrayRequests();
        $canonical = $this->course('Published course');
        $resolver = app(CourseRevisionResolver::class);
        self::assertNull($resolver->activeDraftFor($canonical));
        self::assertSame(1, Course::query()->count(), 'A read must not create a working copy.');

        $draft = $this->course('Working copy');
        $revision = $this->revision($canonical, $draft, CourseAuthoringRevision::DRAFT, 1);
        DB::table('course_authoring_revision_entities')->insert([
            'course_authoring_revision_id' => $revision->id,
            'entity_type' => CourseAuthoringRevision::HERO_SELECTION_MARKER,
            'source_entity_id' => $canonical->id,
            'revision_entity_id' => $draft->id,
        ]);
        foreach ([
            CourseStagedAuthoringService::class,
            CoursePublishingService::class,
            CoursePlanPublicationService::class,
            CourseRevisionGraphService::class,
            CourseRevisionLineageService::class,
        ] as $writer) {
            app()->bind($writer, static function (): never {
                throw new \LogicException('Read-side services must not resolve an authoring writer.');
            });
        }
        $writes = [];
        DB::listen(static function (QueryExecuted $query) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        foreach ([CourseRevisionLearnerReadService::class, CourseSectionAccessService::class, CourseCompletionService::class, SavedLibraryService::class] as $reader) {
            self::assertInstanceOf($reader, app($reader));
        }
        self::assertSame($canonical->id, $resolver->canonicalFor($draft)->id);
        self::assertSame($draft->id, $resolver->activeDraftFor($canonical)->id);
        self::assertTrue($resolver->isManagedDraft($draft));
        self::assertFalse($resolver->isManagedDraft($canonical));
        self::assertFalse($resolver->explicitHeroSelection($draft));
        self::assertNull($resolver->explicitHeroSelection($canonical));
        self::assertNull($resolver->activeArchiveForCourse($draft));
        self::assertSame([], $writes);
        Http::assertNothingSent();
    }

    public function test_identity_reads_follow_published_continuity_but_not_drafts_or_deleted_entities(): void
    {
        $canonical = $this->course('Published course');
        $first = $this->revision($canonical, $this->course('First archive'), CourseAuthoringRevision::ARCHIVED, 2);
        $second = $this->revision($canonical, $this->course('Second archive'), CourseAuthoringRevision::ARCHIVED, 3);
        $draft = $this->revision($canonical, $this->course('Working copy'), CourseAuthoringRevision::DRAFT, 3);
        foreach ([
            [$first, Lesson::class, 10, 20, true],
            [$second, Lesson::class, 20, 30, true],
            [$draft, Lesson::class, 30, 40, true],
            [$second, Lesson::class, 50, 60, false],
            [$second, Project::class, 20, 80, true],
        ] as [$revision, $type, $source, $target, $survives]) {
            DB::table('course_authoring_revision_entities')->insert([
                'course_authoring_revision_id' => $revision->id,
                'entity_type' => $type,
                'source_entity_id' => $source,
                'revision_entity_id' => $target,
                'survives_publish' => $survives,
                'carries_learner_state' => $survives,
                'learner_root_entity_id' => $type === Lesson::class && $survives ? 10 : $source,
            ]);
        }
        $resolver = app(CourseRevisionResolver::class);

        self::assertSame(30, $resolver->currentEntityId(Lesson::class, 10));
        self::assertNull($resolver->currentEntityId(Lesson::class, 30), 'Draft mappings must not leak into learner reads.');
        self::assertNull($resolver->currentEntityId(Lesson::class, 50));
        self::assertSame(80, $resolver->currentEntityId(Project::class, 20));
        self::assertSame([10 => 30, 20 => 30, 30 => 30, 50 => 50], $resolver->currentLearnerEntityMap(Lesson::class, [10, 20, 30, 50]));
        $aliases = $resolver->equivalentEntityIds(Lesson::class, 30);
        self::assertSame(30, $aliases[0]);
        self::assertEqualsCanonicalizing([10, 20, 30], $aliases);
        self::assertSame([50], $resolver->equivalentEntityIds(Lesson::class, 50));
        self::assertSame([], $resolver->currentLearnerEntityMap(Lesson::class, []));
        self::assertSame([], $resolver->equivalentEntityMap(Lesson::class, []));
    }

    private function course(string $name): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => $name, 'is_coming_soon' => true,
            'is_main_course' => false, 'authoring_version' => 1,
        ])->save();

        return $course;
    }

    private function revision(Course $canonical, Course $copy, string $status, int $version): CourseAuthoringRevision
    {
        return CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id,
            'revision_course_id' => $copy->id,
            'base_authoring_version' => $version - 1,
            'published_authoring_version' => $status === CourseAuthoringRevision::ARCHIVED ? $version : null,
            'status' => $status,
            'active_slot' => $status === CourseAuthoringRevision::DRAFT ? CourseAuthoringRevision::draftSlot((int) $canonical->id) : null,
            'clone_key' => (string) Str::uuid(),
        ]);
    }
}
