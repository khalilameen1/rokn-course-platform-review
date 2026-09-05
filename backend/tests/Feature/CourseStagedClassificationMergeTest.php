<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\User;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class CourseStagedClassificationMergeTest extends TestCase
{
    use RefreshDatabase;

    public function test_publish_merges_course_draft_and_newer_home_row_membership(): void
    {
        [$base, $draftAddition, $homeAddition] = $this->classifications();
        $canonical = $this->publishedCourse('كورس بصفوف متزامنة');
        $canonical->classifications()->sync([$base->id]);
        $service = $this->serviceWithPassingAudit();
        $draft = $service->draftFor($canonical);

        // The course editor adds a classification while home curation adds a
        // different row to the already published course.
        $draft->classifications()->sync([$base->id, $draftAddition->id]);
        $canonical->classifications()->sync([$base->id, $homeAddition->id]);

        $published = $service->publish($draft, (int) $draft->authoring_version, true);

        self::assertEqualsCanonicalizing(
            [$base->id, $draftAddition->id, $homeAddition->id],
            $published['course']->classifications()->pluck('classifications.id')->all()
        );
        self::assertEqualsCanonicalizing(
            [$base->id, $homeAddition->id],
            $published['archive']->classifications()->pluck('classifications.id')->all()
        );
    }

    public function test_publish_keeps_a_newer_home_row_removal_when_the_draft_did_not_touch_it(): void
    {
        [$kept, $removed] = array_slice($this->classifications(), 0, 2);
        $canonical = $this->publishedCourse('كورس بحذف صف أحدث');
        $canonical->classifications()->sync([$kept->id, $removed->id]);
        $service = $this->serviceWithPassingAudit();
        $draft = $service->draftFor($canonical);

        // Home curation removes one row after the draft was opened. The draft
        // still matches its base, so publishing must not resurrect that row.
        $canonical->classifications()->sync([$kept->id]);

        $published = $service->publish($draft, (int) $draft->authoring_version, true);

        self::assertSame(
            [$kept->id],
            $published['course']->classifications()->pluck('classifications.id')->all()
        );
        self::assertSame(
            [$kept->id],
            $published['archive']->classifications()->pluck('classifications.id')->all()
        );
    }

    public function test_dashboard_row_removal_survives_publishing_an_unchanged_course_draft(): void
    {
        [$row] = $this->classifications();
        $canonical = $this->publishedCourse('كورس وصف رئيسي يتغير أثناء المسودة');
        $canonical->classifications()->sync([$row->id]);
        $service = $this->serviceWithPassingAudit();
        $draft = $service->draftFor($canonical);

        $administrator = new User();
        $administrator->forceFill([
            'name_ar' => 'مدير الرئيسية',
            'email' => 'classification-publish-race@example.test',
            'role' => 'admin',
            'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($administrator, 'web');

        $edit = $this->get(route('admin.classifications.edit', $row))->assertOk();
        $this->put(route('admin.classifications.update', $row), [
            'name_ar' => $row->name_ar,
            'name_en' => $row->name_en,
            'show_on_home' => '1',
            'home_order' => (int) $row->home_order,
            'course_ids' => [],
            'editor_version' => (string) $edit->original->getData()['editorVersion'],
        ])->assertRedirect(route('admin.classifications.index'));

        self::assertFalse($canonical->fresh()->classifications()->whereKey($row->id)->exists());
        self::assertTrue(
            $draft->fresh()->classifications()->whereKey($row->id)->exists(),
            'Home curation must not erase the hidden snapshot owned by the open draft.'
        );

        $published = $service->publish($draft->fresh(), (int) $draft->fresh()->authoring_version, true);

        self::assertFalse($published['course']->classifications()->whereKey($row->id)->exists());
        self::assertFalse(
            $published['archive']->classifications()->whereKey($row->id)->exists(),
            'The archive must receive the exact live membership that existed immediately before publish.'
        );
    }

    public function test_an_empty_clone_time_membership_is_still_a_valid_merge_base(): void
    {
        [$fromDraft, $fromHome] = array_slice($this->classifications(), 0, 2);
        $canonical = $this->publishedCourse('كورس بدأ بلا صفوف');
        $service = $this->serviceWithPassingAudit();
        $draft = $service->draftFor($canonical);

        $draft->classifications()->sync([$fromDraft->id]);
        $canonical->classifications()->sync([$fromHome->id]);

        $published = $service->publish($draft, (int) $draft->authoring_version, true);

        self::assertEqualsCanonicalizing(
            [$fromDraft->id, $fromHome->id],
            $published['course']->classifications()->pluck('classifications.id')->all()
        );
    }

    public function test_explicit_studio_selection_upgrades_an_ambiguous_legacy_draft(): void
    {
        [$base, $newHomeRow, $draftRow] = $this->classifications();
        $canonical = $this->publishedCourse('مسودة قديمة');
        $canonical->classifications()->sync([$base->id]);
        $service = app(CourseStagedAuthoringService::class);
        $draft = $service->draftFor($canonical);
        $revisionId = CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->value('id');
        DB::table('course_authoring_revision_entities')
            ->where('course_authoring_revision_id', $revisionId)
            ->where('entity_type', 'like', 'authoring:classification%')
            ->delete();
        $canonical->classifications()->sync([$base->id, $newHomeRow->id]);

        try {
            $this->serviceWithPassingAudit()->publish(
                $draft,
                (int) $draft->authoring_version,
                true
            );
            self::fail('The ambiguous legacy draft should require an explicit selection.');
        } catch (ValidationException $exception) {
            self::assertSame(409, $exception->status);
        }

        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'legacy-draft@example.test',
            'role' => 'moderator',
            'active' => true,
        ])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web')->patch(route('admin.courses.update', $draft), [
            'authoring_version' => (int) $draft->authoring_version,
            'classification_ids_present' => '1',
            'classification_ids' => [$base->id, $newHomeRow->id, $draftRow->id],
        ])->assertRedirect(route('admin.courses.show', $draft));

        $publishingService = $this->serviceWithPassingAudit();
        $published = $publishingService->publish(
            $draft->fresh(),
            (int) $draft->fresh()->authoring_version,
            true
        );

        self::assertEqualsCanonicalizing(
            [$base->id, $newHomeRow->id, $draftRow->id],
            $published['course']->classifications()->pluck('classifications.id')->all()
        );
    }

    /** @return array{Classification,Classification,Classification} */
    private function classifications(): array
    {
        return collect(['أساسي', 'من المسودة', 'من الرئيسية'])
            ->map(fn (string $name): Classification => Classification::query()->create([
                'name_ar' => $name,
                'name_en' => $name,
                'show_on_home' => true,
                'home_order' => 1,
            ]))
            ->all();
    }

    private function publishedCourse(string $name): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'description_ar' => 'وصف الكورس',
            'price' => 800,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 4,
            'last_published_authoring_version' => 4,
            'published_at' => now(),
        ])->save();

        return $course;
    }

    private function serviceWithPassingAudit(): CourseStagedAuthoringService
    {
        $publishing = Mockery::mock(CoursePublishingService::class);
        $publishing->shouldReceive('audit')->once()->andReturn([
            'ready' => true,
            'issues' => [],
        ]);

        return new CourseStagedAuthoringService($publishing);
    }
}
