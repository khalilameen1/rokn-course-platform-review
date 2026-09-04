<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseModule;
use App\Models\Lesson;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class OrphanedCourseSectionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_orphaned_learning_sections_are_grouped_in_order_and_projects_end_modules(): void
    {
        $migration = require database_path(
            'migrations/2026_09_04_000020_attach_orphaned_course_sections_to_modules.php'
        );
        $migration->down();

        $courseWithoutModules = $this->course();
        $mixedCourse = $this->course();
        $existingModule = CourseModule::query()->create([
            'course_id' => $mixedCourse->id,
            'title_ar' => 'وحدة موجودة',
            'order' => 2,
        ]);

        $firstIds = $this->insertOrphans($courseWithoutModules, [
            [Lesson::class, 4],
            [Project::class, 7],
            [Lesson::class, 9],
        ]);
        $mixedIds = $this->insertOrphans($mixedCourse, [
            [Lesson::class, 3],
            [Project::class, 5],
        ]);

        $migration->up();

        $firstModules = CourseModule::query()
            ->where('course_id', $courseWithoutModules->id)
            ->orderBy('order')
            ->get();
        self::assertCount(2, $firstModules);
        self::assertSame([1, 2], $firstModules->pluck('order')->map(fn ($order): int => (int) $order)->all());
        self::assertSame(
            [$firstIds[0], $firstIds[1]],
            DB::table('course_sections')->where('module_id', $firstModules[0]->id)
                ->orderBy('order')->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
        self::assertSame(
            [$firstIds[2]],
            DB::table('course_sections')->where('module_id', $firstModules[1]->id)
                ->orderBy('order')->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );

        $mixedModules = CourseModule::query()
            ->where('course_id', $mixedCourse->id)
            ->orderBy('order')
            ->get();
        self::assertCount(2, $mixedModules);
        self::assertSame((int) $existingModule->id, (int) $mixedModules[0]->id);
        self::assertSame(3, (int) $mixedModules[1]->order);
        self::assertSame(
            $mixedIds,
            DB::table('course_sections')->where('module_id', $mixedModules[1]->id)
                ->orderBy('order')->pluck('id')->map(fn ($id): int => (int) $id)->all()
        );
        self::assertSame(0, DB::table('course_sections')->whereNull('module_id')->count());
    }

    private function course(): Course
    {
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1])->save();

        return $course;
    }

    /** @param array<int,array{class-string,int}> $sections @return array<int,int> */
    private function insertOrphans(Course $course, array $sections): array
    {
        $ids = [];
        foreach ($sections as $index => [$type, $order]) {
            $ids[] = (int) DB::table('course_sections')->insertGetId([
                'course_id' => $course->id,
                'module_id' => null,
                'title' => 'عنصر '.($index + 1),
                'title_ar' => 'عنصر '.($index + 1),
                'title_en' => null,
                'section_type' => $type === Project::class ? 'project' : 'lesson',
                'order' => $order,
                'sectionable_type' => $type,
                'sectionable_id' => $index + 1,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => null,
            ]);
        }

        return $ids;
    }
}
