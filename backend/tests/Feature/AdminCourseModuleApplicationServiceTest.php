<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Services\AdminCourseModuleApplicationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AdminCourseModuleApplicationServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_module_mutations_share_one_versioned_ordering_and_deletion_owner(): void
    {
        $course = Course::factory()->make();
        $course->forceFill([
            'tenant_id' => 1,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'published_at' => null,
            'last_published_authoring_version' => 0,
            'authoring_version' => 1,
        ])->save();
        $service = app(AdminCourseModuleApplicationService::class);
        $completed = [];
        $complete = static function (
            Course $lockedCourse,
            CourseModule $module,
            array $payload
        ) use (&$completed): void {
            $completed[] = [$lockedCourse->id, $module->id, $payload['authoring_version']];
        };

        $first = $service->store($course, [
            'title_ar' => 'الوحدة الأولى',
            'title_en' => null,
        ], 1, $complete);
        $second = $service->store($course, [
            'title_ar' => 'الوحدة الثانية',
            'title_en' => null,
            'order' => 1,
        ], 2, $complete);

        self::assertSame(2, $first['authoring_version']);
        self::assertSame(3, $second['authoring_version']);
        self::assertCount(2, $completed);
        self::assertSame(
            [$second['module']['id'], $first['module']['id']],
            CourseModule::query()->orderBy('order')->pluck('id')->all()
        );

        $firstModule = CourseModule::query()->findOrFail($first['module']['id']);
        $updated = $service->update($course, $firstModule, [
            'title_ar' => 'الوحدة الأولى المحدثة',
            'title_en' => null,
            'order' => 1,
        ], 3);
        self::assertSame(4, $updated['authoring_version']);
        self::assertSame('الوحدة الأولى المحدثة', $updated['module']['title']);

        $partial = $service->update($course, $firstModule->fresh(), [
            'order' => 2,
        ], 4);
        self::assertSame(5, $partial['authoring_version']);
        self::assertSame('الوحدة الأولى المحدثة', $firstModule->fresh()->title_ar);

        $reordered = $service->reorder($course, [
            ['id' => (int) $second['module']['id'], 'order' => 1],
            ['id' => (int) $first['module']['id'], 'order' => 2],
        ], 5);
        self::assertSame(6, $reordered['authoring_version']);
        self::assertSame(
            [$second['module']['id'], $first['module']['id']],
            array_column($reordered['modules'], 'id')
        );

        $deleted = $service->destroy(
            $course,
            CourseModule::query()->findOrFail($second['module']['id']),
            6
        );
        self::assertSame(7, $deleted['authoring_version']);
        self::assertSame($second['module']['id'], $deleted['deleted_module_id']);
        self::assertSame([], $deleted['section_ids']);
        self::assertSame(
            [1],
            CourseModule::query()->orderBy('order')->pluck('order')->all()
        );
    }
}
