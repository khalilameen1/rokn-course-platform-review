<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CourseAuthoringEdit;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Path;
use App\Services\AdminCourseAuthoringService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CoursePathPublicationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public static function pathChanges(): array
    {
        return [
            'new live move' => [0, 1, 0, 1],
            'draft move' => [0, 0, 1, 1],
            'both select the same path' => [0, 1, 1, 1],
            'live removes the path' => [0, null, 0, null],
            'draft removes the path' => [0, 0, null, null],
            'initially unassigned and live assigns' => [null, 1, null, 1],
            'initially unassigned and draft assigns' => [null, null, 1, 1],
        ];
    }

    #[DataProvider('pathChanges')]
    public function test_publication_merges_only_the_changed_path_and_archives_the_previous_live_choice(
        ?int $base, ?int $live, ?int $draftChoice, ?int $expected
    ): void {
        $paths = $this->paths();
        $id = static fn (?int $index): ?int => $index === null ? null : (int) $paths[$index]->id;
        $course = $this->course($id($base));
        $service = $this->publisher();
        $draft = $service->draftFor($course);
        $course->update(['path_id' => $id($live)]);
        $draft->update(['path_id' => $id($draftChoice)]);

        $published = $service->publish($draft->fresh(), (int) $draft->authoring_version, true);

        self::assertSame($id($expected), $this->pathId($published['course']));
        self::assertSame($id($live), $this->pathId($published['archive']));
        Http::assertNothingSent();
    }

    public function test_conflicting_live_and_draft_moves_require_a_reviewed_selection(): void
    {
        [$base, $live, $chosen] = $this->paths();
        $course = $this->course((int) $base->id);
        $publisher = $this->publisher();
        $draft = $publisher->draftFor($course);
        $draft->update(['path_id' => $chosen->id]);
        $course->update(['path_id' => $live->id]);
        try {
            $publisher->publish($draft->fresh(), (int) $draft->authoring_version, true);
            self::fail('Conflicting choices cannot silently replace either editor.');
        } catch (ValidationException $error) {
            self::assertSame(409, $error->status);
            self::assertArrayHasKey('authoring_version', $error->errors());
        }
        self::assertSame((int) $live->id, $this->pathId($course->fresh()));
        self::assertSame(CourseAuthoringRevision::DRAFT,
            CourseAuthoringRevision::query()->where('revision_course_id', $draft->id)->sole()->status);

        // The real course writer records the explicitly reviewed choice even
        // when the editor keeps its existing draft selection after a conflict.
        $this->savePath($draft, (int) $chosen->id);
        $published = $publisher->publish($draft->fresh(), (int) $draft->fresh()->authoring_version, true);
        self::assertSame((int) $chosen->id, $this->pathId($published['course']));
        self::assertSame((int) $live->id, $this->pathId($published['archive']));
    }

    public function test_unchanged_path_in_a_full_course_save_does_not_undo_a_newer_live_move(): void
    {
        [$base, $live] = $this->paths();
        $course = $this->course((int) $base->id);
        $publisher = $this->publisher();
        $draft = $publisher->draftFor($course);
        $course->update(['path_id' => $live->id]);
        $this->savePath($draft, (int) $base->id);

        $published = $publisher->publish($draft->fresh(), (int) $draft->fresh()->authoring_version, true);
        self::assertSame((int) $live->id, $this->pathId($published['course']));
    }

    public function test_legacy_different_paths_are_blocked_until_the_real_editor_records_a_selection(): void
    {
        [$base, $live] = $this->paths();
        $course = $this->course((int) $base->id);
        $publisher = $this->publisher();
        $draft = $publisher->draftFor($course);
        $revision = CourseAuthoringRevision::query()->where('revision_course_id', $draft->id)->sole();
        DB::table('course_authoring_revision_entities')->where('course_authoring_revision_id', $revision->id)
            ->where('entity_type', CourseAuthoringRevision::PATH_SNAPSHOT)->delete();
        $course->update(['path_id' => $live->id]);
        try {
            $publisher->publish($draft, (int) $draft->authoring_version, true);
            self::fail('A legacy draft has no evidence that it may overwrite a different live path.');
        } catch (ValidationException $error) {
            self::assertSame(409, $error->status);
        }
        $this->savePath($draft, null);
        $published = $publisher->publish($draft->fresh(), (int) $draft->fresh()->authoring_version, true);
        self::assertNull($published['course']->path_id);
        self::assertSame((int) $live->id, $this->pathId($published['archive']));
    }

    private function savePath(Course $draft, ?int $pathId): void
    {
        app(AdminCourseAuthoringService::class)->update(CourseAuthoringEdit::fromValidated([
            'path_id' => $pathId,
            'authoring_version' => (int) $draft->fresh()->authoring_version,
        ]), $draft->fresh(), true, true);
    }

    private function paths(): array
    {
        return array_map(static fn (string $name): Path => Path::query()->create([
            'title_ar' => $name, 'title_en' => $name,
        ]), ['base', 'live', 'draft']);
    }

    private function course(?int $pathId): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس المسار', 'description_ar' => 'وصف', 'price' => 100,
            'path_id' => $pathId, 'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ]);
    }

    private function publisher(): CourseStagedAuthoringService
    {
        $audit = Mockery::mock(CoursePublishingService::class);
        $audit->shouldReceive('audit')->andReturn(['ready' => true, 'issues' => []]);

        return $this->app->makeWith(CourseStagedAuthoringService::class, ['publishing' => $audit]);
    }

    private function pathId(Course $course): ?int
    {
        return $course->path_id === null ? null : (int) $course->path_id;
    }
}
