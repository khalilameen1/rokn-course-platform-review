<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\ClassificationController;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Services\AdminClassificationAuthoringService;
use App\Services\AdminClassificationReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminClassificationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        $this->app->bind(ClassificationController::class, static function (): never {
            throw new \LogicException('Home curation owners must not resolve the HTTP adapter.');
        });
    }

    public function test_reader_excludes_snapshots_and_hidden_choices_without_resolving_writer_or_writing(): void
    {
        $visible = $this->course(true);
        $hidden = $this->course(false);
        $revision = $this->revision($visible);
        $row = Classification::query()->create($this->payload());
        $row->courses()->attach([$visible->id, $hidden->id, $revision->id]);
        $this->app->bind(AdminClassificationAuthoringService::class, static function (): never {
            throw new \LogicException('Home-row reads must not resolve the writer.');
        });
        $read = app(AdminClassificationReadService::class);
        DB::flushQueryLog();
        DB::enableQueryLog();
        try {
            self::assertSame([$visible->id], $read->homeCourseOptions()->pluck('id')->all());
            self::assertSame([$visible->id], $read->visibleCanonicalCourseIds($row));
            self::assertSame([$visible->id, $hidden->id], $read->canonicalCourseIds($row));
            self::assertSame(1, (int) $read->rows()->sole()->home_courses_count);
            self::assertSame(hash('sha256', json_encode([
                $row->name_ar, $row->name_en, true, 10, [$visible->id, $hidden->id],
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $read->editorVersion($row));
            foreach (DB::getQueryLog() as $query) {
                self::assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query['query']);
            }
        } finally {
            DB::disableQueryLog();
        }
        Http::assertNothingSent();
    }

    public function test_create_receipt_observes_membership_and_failure_rolls_back_both(): void
    {
        $course = $this->course(true);
        $before = DB::transactionLevel();
        $writer = app(AdminClassificationAuthoringService::class);
        try {
            $writer->create($this->payload(), [$course->id], static function (Classification $row) use ($before, $course): never {
                self::assertSame($before + 1, DB::transactionLevel());
                self::assertSame([$course->id], $row->courses()->pluck('courses.id')->all());
                DB::table('admin_singleton_locks')->insert(['lock_key' => 'classification-receipt']);
                throw new \RuntimeException('Receipt failed');
            });
            self::fail('A receipt failure must not leave a home row or its memberships.');
        } catch (\RuntimeException $error) {
            self::assertSame('Receipt failed', $error->getMessage());
        }
        self::assertSame(0, Classification::query()->count());
        self::assertSame(0, DB::table('classification_course')->count());
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'classification-receipt')->exists());
        $row = $writer->create($this->payload(), [$course->id], static function (): void {});
        self::assertSame([$course->id], $row->courses()->pluck('courses.id')->all());
    }

    public function test_update_changes_visible_selection_only_and_rejects_old_membership_version(): void
    {
        $visible = $this->course(true);
        $next = $this->course(true);
        $hidden = $this->course(false);
        $revision = $this->revision($visible);
        $row = Classification::query()->create($this->payload());
        $row->courses()->attach([$visible->id, $hidden->id, $revision->id]);
        $read = app(AdminClassificationReadService::class);
        $version = $read->editorVersion($row);
        $writer = app(AdminClassificationAuthoringService::class);
        $writer->update($row, $this->payload(), [$next->id], $version);
        self::assertEqualsCanonicalizing([$next->id, $hidden->id, $revision->id], $row->courses()->pluck('courses.id')->all());
        try {
            $writer->update($row, [...$this->payload(), 'name_ar' => 'لا يحفظ'], [$visible->id], $version);
            self::fail('A stale editor must not reverse newer membership.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('editor_version', $error->errors());
        }
        self::assertSame($this->payload()['name_ar'], $row->fresh()->name_ar);
        self::assertSame([$next->id], $read->visibleCanonicalCourseIds($row));
    }

    public function test_create_rechecks_course_visibility_after_taking_publish_locks(): void
    {
        $course = $this->course(true);
        $changed = false;
        DB::listen(static function ($query) use ($course, &$changed): void {
            $sql = strtolower($query->sql);
            if (!$changed && str_contains($sql, 'from "courses"') && str_contains($sql, 'order by "id" asc')) {
                $changed = true;
                DB::table('courses')->where('id', $course->id)->update(['is_catalog_visible' => false]);
            }
        });
        try {
            app(AdminClassificationAuthoringService::class)->create($this->payload(), [$course->id], static function (): never {
                self::fail('A hidden selection must not reach the receipt.');
            });
            self::fail('Creation must recheck eligibility after waiting for publish locks.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('course_ids', $error->errors());
        }
        self::assertTrue($changed);
        self::assertSame(0, Classification::query()->count());
        self::assertSame(0, DB::table('classification_course')->count());
    }

    public function test_new_selection_cannot_include_hidden_courses_or_revision_snapshots(): void
    {
        $canonical = $this->course(true);
        $hidden = $this->course(false);
        $revision = $this->revision($canonical);
        foreach ([$hidden, $revision] as $course) {
            try {
                app(AdminClassificationAuthoringService::class)->create($this->payload(), [$course->id], static function (): void {});
                self::fail('Only visible canonical courses can be selected.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('course_ids', $error->errors());
            }
        }
        self::assertSame(0, Classification::query()->count());
    }

    public function test_hidden_canonical_membership_blocks_deletion_but_revision_only_row_can_be_removed(): void
    {
        $hidden = $this->course(false);
        $revision = $this->revision($hidden);
        $row = Classification::query()->create($this->payload());
        $row->courses()->attach([$hidden->id, $revision->id]);
        $writer = app(AdminClassificationAuthoringService::class);
        self::assertFalse($writer->delete($row));
        self::assertNotNull($row->fresh());
        $row->courses()->detach($hidden->id);
        self::assertTrue($writer->delete($row));
        self::assertNull(Classification::find($row->id));
        self::assertFalse(DB::table('classification_course')->where('classification_id', $row->id)->exists());
    }

    public function test_membership_and_row_fields_roll_back_with_the_enclosing_operation(): void
    {
        $course = $this->course(true);
        $row = Classification::query()->create($this->payload());
        $row->courses()->attach($course->id);
        try {
            DB::transaction(function () use ($row): never {
                app(AdminClassificationAuthoringService::class)->update(
                    $row, [...$this->payload(), 'name_ar' => 'تعديل'], [],
                    app(AdminClassificationReadService::class)->editorVersion($row)
                );
                self::assertFalse($row->courses()->exists());
                throw new \RuntimeException('Outer operation failed');
            });
            self::fail('The enclosing transaction must retain ownership of rollback.');
        } catch (\RuntimeException $error) {
            self::assertSame('Outer operation failed', $error->getMessage());
        }
        self::assertSame($this->payload()['name_ar'], $row->fresh()->name_ar);
        self::assertSame([$course->id], $row->courses()->pluck('courses.id')->all());
    }

    private function payload(): array
    {
        return ['name_ar' => 'اختيارات رُكن', 'name_en' => 'Rokn picks', 'show_on_home' => true, 'home_order' => 10];
    }

    private function course(bool $visible): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس', 'name_en' => 'Course', 'price' => 100,
            'is_catalog_visible' => $visible, 'is_coming_soon' => true, 'home_sort_order' => 10,
            'authoring_version' => 1,
        ]);
    }

    private function revision(Course $canonical): Course
    {
        $copy = $this->course(true);
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id, 'revision_course_id' => $copy->id,
            'base_authoring_version' => 1, 'status' => CourseAuthoringRevision::ARCHIVED,
            'clone_key' => (string) Str::uuid(),
        ]);

        return $copy;
    }
}
