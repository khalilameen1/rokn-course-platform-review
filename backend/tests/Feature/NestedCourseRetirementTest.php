<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\StudentSectionProgress;
use App\Models\User;
use App\Services\CourseChatAccessService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class NestedCourseRetirementTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_child_becomes_unlisted_without_losing_direct_access_or_progress(): void
    {
        $migration = require database_path('migrations/2026_09_04_000021_remove_nested_course_runtime.php');
        $migration->down();

        $parent = $this->publishedCourse('الكورس القديم');
        $child = $this->publishedCourse('المحتوى الفرعي');
        DB::table('courses')->where('id', $child->id)->update([
            'parent_id' => $parent->id,
            'is_catalog_visible' => true,
            'is_main_course' => true,
        ]);
        $parentModule = CourseModule::query()->create([
            'course_id' => $parent->id,
            'title_ar' => 'وحدة قديمة',
            'order' => 2,
        ]);
        $nestedSection = CourseSection::query()->create([
            'course_id' => $parent->id,
            'module_id' => $parentModule->id,
            'title_ar' => 'محتوى فرعي قديم',
            'section_type' => 'lesson',
            'sectionable_type' => Course::class,
            'sectionable_id' => $child->id,
            'order' => 1,
        ]);
        $directStudent = $this->student('direct');
        $parentStudent = $this->student('parent');
        $directEnrollment = $this->enroll($directStudent, $child);
        $parentEnrollment = $this->enroll($parentStudent, $parent);
        StudentSectionProgress::query()->create([
            'user_id' => $parentStudent->id,
            'course_section_id' => $nestedSection->id,
            'is_completed' => true,
        ]);

        $migration->up();

        self::assertFalse(Schema::hasColumn('courses', 'parent_id'));
        $retiredChild = Course::query()->findOrFail($child->id);
        self::assertFalse((bool) $retiredChild->is_catalog_visible);
        self::assertFalse((bool) $retiredChild->is_main_course);
        self::assertFalse((bool) $retiredChild->is_coming_soon);
        self::assertNotNull(CourseEnrollment::query()->find($directEnrollment->id));
        self::assertNotNull(CourseEnrollment::query()->find($parentEnrollment->id));
        self::assertNotNull(StudentSectionProgress::query()
            ->where('course_section_id', $nestedSection->id)->first());
        self::assertNotNull(CourseSection::withTrashed()->findOrFail($nestedSection->id)->deleted_at);

        $access = app(CourseChatAccessService::class);
        self::assertTrue($access->hasLearningAccess((int) $directStudent->id, (int) $child->id));
        self::assertFalse($access->hasLearningAccess((int) $parentStudent->id, (int) $child->id));
        self::assertFalse($access->enrollmentGrantsCourse($parentEnrollment, (int) $child->id));
    }

    private function publishedCourse(string $name): Course
    {
        $course = Course::factory()->make();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
        ])->save();
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'المحتوى',
            'order' => 1,
        ]);
        $lesson = Lesson::query()->create([
            'list_id' => $course->id,
            'title_ar' => 'المقطع',
            'duration_minutes' => 1,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'title_ar' => 'المقطع',
            'section_type' => 'lesson',
            'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
            'order' => 1,
        ]);

        return $course;
    }

    private function student(string $suffix): User
    {
        return User::query()->forceCreate([
            'name' => 'Student '.$suffix,
            'email' => $suffix.'-nested-retirement@example.test',
            'role' => 'client',
            'active' => true,
        ]);
    }

    private function enroll(User $user, Course $course): CourseEnrollment
    {
        return CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
        ]);
    }
}
