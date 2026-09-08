<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Models\User;
use App\Services\AdminCourseModuleApplicationService;
use App\Services\AdminCourseOutlinePresenter;
use App\Services\CourseSectionOrderingService;
use App\Services\CourseSectionSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class AdminStudioContentOrderTest extends TestCase
{
    use RefreshDatabase;

    public static function siblingChanges(): array
    {
        return ['insert' => [false], 'delete' => [true]];
    }

    #[DataProvider('siblingChanges')]
    public function test_content_only_http_patch_keeps_current_section_order_after_a_sibling_change(bool $delete): void
    {
        $course = $this->courseAndModerator();
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $first = $this->lesson($course, $module, 1);
        $target = $this->lesson($course, $module, 2);
        $third = $this->lesson($course, $module, 3);
        $project = Project::query()->create(['requirements_text_ar' => 'نفّذ المشروع', 'submission_text_enabled' => true]);
        $gateway = CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'مشروع العبور',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id,
            'section_type' => 'project', 'order' => 4,
        ]);

        if ($delete) {
            $this->deleteJson(route('admin.courses.sections.destroy', [$course, $first]), ['authoring_version' => 1])
                ->assertOk()->assertJsonPath('deleted_section_id', $first->id);
            $expected = [$target->id, $third->id, $gateway->id];
        } else {
            $inserted = $this->lesson($course, $module, 2);
            app(CourseSectionOrderingService::class)->place($course, $inserted, null, 2);
            $expected = [$first->id, $inserted->id, $target->id, $third->id, $gateway->id];
        }
        // This is deliberately the pre-mutation model held by the old editor.
        self::assertSame(2, (int) $target->order);
        self::assertNotSame(2, (int) $target->fresh()->order);
        $lesson = $target->sectionable;
        $video = $lesson->bunny_video_id;

        $this->patchJson(route('admin.courses.sections.update', [$course, $target]), [
            'authoring_version' => (int) $course->fresh()->authoring_version,
            'title_ar' => 'عنوان جديد دون نقل المقطع',
            'lesson_description_ar' => 'شرح المقطع الجديد',
            'is_opened' => false,
        ])->assertOk()->assertJsonPath('section.id', $target->id)
            ->assertJsonPath('section.title', 'عنوان جديد دون نقل المقطع');

        self::assertSame($expected, $module->sections()->orderBy('order')->pluck('id')->all());
        self::assertSame('شرح المقطع الجديد', $lesson->fresh()->description_ar);
        self::assertSame($video, $lesson->fresh()->bunny_video_id);
        self::assertFalse((bool) $lesson->fresh()->is_opened);
        self::assertSame($lesson->id, $target->fresh()->sectionable_id);
        $graph = app(AdminCourseOutlinePresenter::class)->graph($course->fresh());
        self::assertSame($expected, array_column($graph['modules'][0]['sections'], 'id'));
        self::assertSame('project', $graph['modules'][0]['sections'][count($expected) - 1]['type']);
        $loaded = $course->fresh()->load('modules.sections');
        self::assertSame($expected, app(CourseSectionSequenceService::class)->fromModules($loaded->modules)->pluck('id')->all());
        Http::assertNothingSent();
    }

    #[DataProvider('siblingChanges')]
    public function test_rename_http_patch_keeps_current_module_order_after_a_sibling_change(bool $delete): void
    {
        $course = $this->courseAndModerator();
        $first = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الأولى', 'order' => 1]);
        $target = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الثانية', 'order' => 2]);
        $third = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الثالثة', 'order' => 3]);
        $lesson = $this->lesson($course, $target, 1);
        if ($delete) {
            $this->deleteJson(route('admin.courses.modules.destroy', [$course, $first]), ['authoring_version' => 1])
                ->assertOk()->assertJsonPath('deleted_module_id', $first->id);
            $expected = [$target->id, $third->id];
        } else {
            $inserted = app(AdminCourseModuleApplicationService::class)->store($course, [
                'title_ar' => 'الوحدة المدرجة', 'order' => 2,
            ], 1, static function (): void {});
            $expected = [$first->id, $inserted['module']['id'], $target->id, $third->id];
        }
        self::assertSame(2, (int) $target->order);
        self::assertNotSame(2, (int) $target->fresh()->order);

        $this->patchJson(route('admin.courses.modules.update', [$course, $target]), [
            'authoring_version' => (int) $course->fresh()->authoring_version,
            'title_ar' => 'عنوان جديد دون نقل الوحدة',
        ])->assertOk()->assertJsonPath('module.id', $target->id)
            ->assertJsonPath('module.title', 'عنوان جديد دون نقل الوحدة');

        self::assertSame($expected, $course->modules()->orderBy('order')->pluck('id')->all());
        self::assertSame($expected, array_column(app(AdminCourseOutlinePresenter::class)->graph($course->fresh())['modules'], 'id'));
        self::assertSame([$lesson->id], $target->sections()->pluck('id')->all());
        Http::assertNothingSent();
    }

    private function courseAndModerator(): Course
    {
        Http::preventStrayRequests();
        Queue::fake();
        $moderator = new User();
        $moderator->forceFill(['name_ar' => 'محرر المحتوى', 'email' => 'content-order@example.test',
            'role' => 'moderator', 'active' => true])->save();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web');
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false,
            'published_at' => null, 'last_published_authoring_version' => 0, 'authoring_version' => 1])->save();

        return $course;
    }

    private function lesson(Course $course, CourseModule $module, int $order): CourseSection
    {
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'مقطع',
            'description_ar' => 'الوصف المحفوظ', 'video_source_type' => 'bunny',
            'bunny_video_id' => '00000000-0000-4000-8000-000000000001', 'is_opened' => true]);

        return CourseSection::query()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'title_ar' => 'مقطع', 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
            'section_type' => 'lesson', 'order' => $order]);
    }
}
