<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\PathController;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Path;
use App\Services\AdminPathAuthoringService;
use App\Services\AdminPathReadService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminPathAuthoringOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app->bind(PathController::class, static function (): never {
            throw new \LogicException('Path services cannot resolve their HTTP adapter.');
        });
    }

    public function test_form_and_version_ignore_revision_rows_but_include_unpublished_canonical_courses(): void
    {
        $path = Path::query()->create($this->titles());
        $course = $this->course($path);
        $unpublished = $this->course();
        $unpublished->update(['is_coming_soon' => true, 'is_catalog_visible' => false]);
        $reader = app(AdminPathReadService::class);
        $before = $reader->editorVersion($path);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        self::assertSame($before, $reader->editorVersion($path->fresh()));
        $form = $reader->form((int) $path->id);
        self::assertEqualsCanonicalizing([$course->id, $unpublished->id], $form['courses']->modelKeys());
        self::assertSame([$course->id], $form['path']->courses->modelKeys());
        $draft->update(['path_id' => null]);
        self::assertSame($before, $reader->editorVersion($path->fresh()));
        CourseAuthoringRevision::query()->where('revision_course_id', $draft->id)
            ->update(['status' => CourseAuthoringRevision::ARCHIVED, 'active_slot' => null]);
        self::assertSame($before, $reader->editorVersion($path->fresh()));
    }

    public function test_create_assigns_canonical_courses_and_receipt_in_one_transaction(): void
    {
        $course = $this->course();
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $interest = Classification::query()->create(['name_ar' => 'تصنيف', 'name_en' => 'Classification']);
        $baseline = DB::transactionLevel();
        $path = app(AdminPathAuthoringService::class)->create(
            $this->titles() + ['course_ids' => [$course->id], 'interest_ids' => [$interest->id]],
            static function (Path $path) use ($course, $interest, $baseline): void {
                self::assertGreaterThan($baseline, DB::transactionLevel());
                self::assertSame((int) $path->id, (int) $course->fresh()->path_id);
                self::assertSame([$interest->id], $path->interests()->pluck('classifications.id')->all());
            }
        );
        self::assertSame((int) $path->id, (int) $course->fresh()->path_id);
        self::assertNull($draft->fresh()->path_id, 'Live path administration must not overwrite a working copy.');
    }

    public function test_receipt_failure_rolls_back_path_membership_and_interests(): void
    {
        $old = Path::query()->create($this->titles());
        $course = $this->course($old);
        $before = Path::query()->count();
        try {
            app(AdminPathAuthoringService::class)->create($this->titles() + ['course_ids' => [$course->id]],
                static function (): never { throw new \RuntimeException('receipt failed'); });
            self::fail('Receipt failure must roll back a moved course and the new path.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame($before, Path::query()->count());
        self::assertSame((int) $old->id, (int) $course->fresh()->path_id);
    }

    public function test_revision_and_deleted_course_ids_are_rejected_before_any_path_is_created(): void
    {
        $course = $this->course();
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $deleted = $this->course();
        $deleted->delete();
        $before = Path::query()->count();
        foreach ([$draft->id, $deleted->id, 999999] as $invalidId) {
            try {
                app(AdminPathAuthoringService::class)->create(
                    $this->titles() + ['course_ids' => [$course->id, $invalidId]],
                    static function (): never { self::fail('Invalid selection cannot complete a receipt.'); }
                );
                self::fail('A hidden revision or missing course is not a path choice.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('course_ids', $error->errors());
            }
        }
        self::assertSame($before, Path::query()->count());
        self::assertNull($course->fresh()->path_id);
    }

    public function test_updates_reassign_only_canonical_members_and_stale_forms_cannot_take_them_back(): void
    {
        $first = Path::query()->create($this->titles());
        $second = Path::query()->create($this->titles());
        $course = $this->course($first);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $reader = app(AdminPathReadService::class);
        $writer = app(AdminPathAuthoringService::class);
        $stale = $reader->editorVersion($first);
        $writer->update((int) $second->id, $this->titles() + ['course_ids' => [$course->id]], $reader->editorVersion($second));
        self::assertSame((int) $second->id, (int) $course->fresh()->path_id);
        self::assertSame((int) $first->id, (int) $draft->fresh()->path_id);
        try {
            $writer->update((int) $first->id, $this->titles() + ['course_ids' => [$course->id]], $stale);
            self::fail('A stale path form cannot undo another path assignment.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        $writer->update((int) $second->id, $this->titles() + ['course_ids' => []], $reader->editorVersion($second->fresh()));
        self::assertNull($course->fresh()->path_id);
        self::assertSame((int) $first->id, (int) $draft->fresh()->path_id);
    }

    public function test_deletion_keeps_course_and_draft_references_and_removes_only_an_unused_path(): void
    {
        $path = Path::query()->create($this->titles());
        $course = $this->course($path);
        $draft = app(CourseStagedAuthoringService::class)->draftFor($course);
        $writer = app(AdminPathAuthoringService::class);
        self::assertFalse($writer->deleteIfUnused((int) $path->id));
        $course->update(['path_id' => null]);
        self::assertFalse($writer->deleteIfUnused((int) $path->id));
        $draft->update(['path_id' => null]);
        self::assertTrue($writer->deleteIfUnused((int) $path->id));
        self::assertNull($path->fresh());
    }

    private function titles(): array
    {
        return ['title_ar' => 'مسار ركن', 'title_en' => 'Rokn path'];
    }

    private function course(?Path $path = null): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100, 'path_id' => $path?->id,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ]);
    }
}
