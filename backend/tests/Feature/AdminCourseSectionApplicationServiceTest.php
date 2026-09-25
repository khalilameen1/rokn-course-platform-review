<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CourseSectionEdit;
use App\Http\Controllers\Admin\CourseSectionController;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminCourseSectionApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class AdminCourseSectionApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        foreach ([CourseSectionController::class, AdminAuthoringCreateIntentService::class] as $adapter) {
            $this->app->bind($adapter, static function (): never {
                throw new \LogicException('Section application logic must not resolve HTTP adapters.');
            });
        }
    }

    public function test_content_order_version_and_exact_receipt_payload_are_one_operation(): void
    {
        [$course, $module] = $this->course();
        $lesson = $this->lesson($course, $module);
        $receipt = null;
        $baseline = DB::transactionLevel();
        $result = app(AdminCourseSectionApplicationService::class)->store(
            $course, $this->projectEdit($module), null,
            static function (Course $locked, CourseSection $section, array $payload) use (&$receipt, $baseline): void {
                self::assertGreaterThan($baseline, DB::transactionLevel());
                self::assertSame((int) $locked->authoring_version, $payload['authoring_version']);
                self::assertSame((int) $section->id, $payload['section']['id']);
                self::assertInstanceOf(Project::class, $section->sectionable);
                $receipt = $payload;
            }
        );

        self::assertSame($result, $receipt);
        self::assertSame(2, $result['authoring_version']);
        self::assertSame(2, $result['section']['order'], 'A crossing project stays after the lesson even when order 1 is requested.');
        self::assertSame('نفذ المشروع', $result['section']['project_requirements_ar']);
        self::assertSame(['text'], $result['section']['project_submission_types']);
        self::assertSame(1, (int) $lesson->fresh()->order);
        Http::assertNothingSent();
    }

    public function test_receipt_failure_rolls_back_content_placement_and_course_version(): void
    {
        [$course, $module] = $this->course();
        $before = Project::query()->count();
        try {
            app(AdminCourseSectionApplicationService::class)->store(
                $course, $this->projectEdit($module), null,
                static function (): never {
                    DB::table('admin_singleton_locks')->insert(['lock_key' => 'section-receipt']);
                    throw new \RuntimeException('receipt failed');
                }
            );
            self::fail('Receipt failure must roll back the complete section write.');
        } catch (\RuntimeException $error) {
            self::assertSame('receipt failed', $error->getMessage());
        }
        self::assertSame(0, $course->sections()->count());
        self::assertSame($before, Project::query()->count());
        self::assertSame(1, (int) $course->fresh()->authoring_version);
        self::assertFalse(DB::table('admin_singleton_locks')->where('lock_key', 'section-receipt')->exists());
        Queue::assertNothingPushed();
    }

    public function test_stale_or_cross_course_create_cannot_leave_an_orphan_project(): void
    {
        [$course, $module] = $this->course();
        [, $foreignModule] = $this->course();
        $writer = app(AdminCourseSectionApplicationService::class);
        $before = Project::query()->count();
        foreach ([[$module, 99, 'authoring_version'], [$foreignModule, 1, 'module_id']] as [$target, $version, $field]) {
            try {
                $writer->store($course, $this->projectEdit($target, $version), null,
                    static function (): never { self::fail('Invalid writes cannot complete a receipt.'); });
                self::fail('Invalid version or module must be rejected.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey($field, $error->errors());
            }
        }
        self::assertSame($before, Project::query()->count());
        self::assertSame(0, $course->sections()->count());
        self::assertSame(1, (int) $course->fresh()->authoring_version);
    }

    public function test_type_change_returns_the_new_project_content_not_a_cached_deleted_lesson(): void
    {
        [$course, $module] = $this->course();
        $section = $this->lesson($course, $module);
        $lesson = $section->sectionable;
        $result = app(AdminCourseSectionApplicationService::class)->update(
            $course, $section, $this->projectEdit($module), null
        );

        self::assertSame('project', $result['section']['type']);
        self::assertSame('نفذ المشروع', $result['section']['project_requirements_ar']);
        self::assertSame(['text'], $result['section']['project_submission_types']);
        self::assertNull($result['section']['lesson_duration_minutes']);
        self::assertFalse($result['section']['has_video']);
        self::assertNull(Lesson::query()->find($lesson->id));
        self::assertInstanceOf(Project::class, $section->fresh()->sectionable);
    }

    public function test_move_keeps_both_modules_contiguous_and_rejects_a_second_crossing_project(): void
    {
        [$course, $first] = $this->course();
        $second = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'ثانية', 'order' => 2]);
        $writer = app(AdminCourseSectionApplicationService::class);
        $created = $writer->store($course, $this->projectEdit($first), null, static function (): void {});
        $section = CourseSection::query()->findOrFail($created['section']['id']);
        $this->lesson($course, $first);
        $writer->update($course->fresh(), $section, $this->projectEdit($second, 2), null);
        self::assertSame((int) $second->id, (int) $section->fresh()->module_id);
        self::assertSame([1], $first->sections()->pluck('order')->map(fn ($order): int => (int) $order)->all());
        try {
            $writer->store($course->fresh(), $this->projectEdit($second, 3), null, static function (): void {});
            self::fail('A module may not contain two crossing projects.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('module_id', $error->errors());
        }
        self::assertSame(1, $second->sections()->count());
        self::assertSame(3, (int) $course->fresh()->authoring_version);
    }

    public function test_reorder_revalidates_section_and_module_ownership_inside_the_course_transaction(): void
    {
        [$course, $module] = $this->course();
        $section = $this->lesson($course, $module);
        [$other, $foreignModule] = $this->course();
        $foreignSection = $this->lesson($other, $foreignModule);
        $writer = app(AdminCourseSectionApplicationService::class);
        foreach ([
            [['id' => $foreignSection->id, 'order' => 1]],
            [['id' => $section->id, 'order' => 1, 'module_id' => $foreignModule->id]],
            [['id' => $section->id, 'order' => 1], ['id' => $section->id, 'order' => 2]],
        ] as $layout) {
            try {
                $writer->reorder($course, $layout, 1);
                self::fail('Invalid layout ownership must not be silently ignored.');
            } catch (ValidationException $error) {
                self::assertArrayHasKey('sections', $error->errors());
            }
        }
        self::assertSame(1, (int) $course->fresh()->authoring_version);
        self::assertSame((int) $module->id, (int) $section->fresh()->module_id);
        $result = $writer->reorder($course, [['id' => $section->id, 'order' => 8]], 1);
        self::assertSame(2, $result['authoring_version']);
        self::assertSame(1, $result['modules'][0]['sections'][0]['order']);
    }

    public function test_delete_and_course_version_roll_back_together_with_the_callers_transaction(): void
    {
        [$course, $module] = $this->course();
        $writer = app(AdminCourseSectionApplicationService::class);
        $created = $writer->store($course, $this->projectEdit($module), null, static function (): void {});
        $section = CourseSection::query()->findOrFail($created['section']['id']);
        $projectId = $section->sectionable_id;
        try {
            DB::transaction(function () use ($writer, $course, $section): void {
                $writer->delete($course->fresh(), $section, 2);
                throw new \RuntimeException('caller rollback');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('caller rollback', $error->getMessage());
        }
        self::assertNotNull(CourseSection::query()->find($section->id));
        self::assertNotNull(Project::query()->find($projectId));
        self::assertSame(2, (int) $course->fresh()->authoring_version);
        $result = $writer->delete($course->fresh(), $section, 2);
        self::assertSame((int) $section->id, $result['deleted_section_id']);
        self::assertSame(3, $result['authoring_version']);
        self::assertNull(CourseSection::query()->find($section->id));
        self::assertNull(Project::query()->find($projectId));
    }

    private function projectEdit(CourseModule $module, int $version = 1): CourseSectionEdit
    {
        return new CourseSectionEdit(
            type: 'project', moduleId: (int) $module->id, titleAr: 'مشروع عبور',
            expectedVersion: $version, order: 1,
            projectChanges: ['requirements_text_ar' => 'نفذ المشروع'], projectSubmissionTypes: ['text']
        );
    }

    private function course(): array
    {
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100,
            'is_coming_soon' => true, 'is_catalog_visible' => false, 'published_at' => null,
            'last_published_authoring_version' => 0, 'authoring_version' => 1,
        ]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'وحدة', 'order' => 1]);
        return [$course, $module];
    }

    private function lesson(Course $course, CourseModule $module): CourseSection
    {
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'درس سابق', 'video_source_type' => 'bunny', 'duration_minutes' => 4,
        ]);
        return CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'درس سابق',
            'order' => 1, 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
        ]);
    }
}
