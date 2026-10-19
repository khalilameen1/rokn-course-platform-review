<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\NotificationCampaignResource;
use App\Filament\Resources\TeacherResource;
use App\Filament\Resources\TeacherResource\Pages\CreateTeacher;
use App\Filament\Resources\TeacherResource\Pages\EditTeacher;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Level;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Support\RendersAdminPages;
use Tests\TestCase;

final class AdminCourseOperationsViewTest extends TestCase
{
    use RefreshDatabase;
    use RendersAdminPages;

    // Inline styles on these pages: AdminInlineStyleContractTest::test_rendered_admin_pages_have_no_inline_styles.
    public function test_course_operations_pages_render_inside_the_shared_admin_page(): void
    {
        $admin = $this->dashboardUser('admin');
        $teacher = $this->dashboardUser('teacher');
        $classification = Classification::query()->create(['name_ar' => 'تصنيف', 'name_en' => 'Row']);
        $level = Level::query()->create(['name_ar' => 'مستوى', 'name_en' => 'Level', 'order' => 1]);

        $this->adminGet($admin, route('admin.teachers.show', $teacher))->assertRedirect(TeacherResource::getUrl('view', ['record' => $teacher->id]));
        $this->adminGet($admin, TeacherResource::getUrl('view', ['record' => $teacher->id]))->assertOk()->assertSee('ملف المحاضر');
        $this->adminGet($admin, route('admin.notifications.index'))->assertRedirect(NotificationCampaignResource::getUrl());
        $this->adminGet($admin, route('admin.notifications.create'))->assertRedirect(NotificationCampaignResource::getUrl('create'));
    }

    public function test_course_module_and_section_authoring_has_no_parallel_page(): void
    {
        $admin = $this->dashboardUser('admin');
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'كورس', 'price' => 100,
            'is_coming_soon' => true, 'is_catalog_visible' => false, 'authoring_version' => 1,
        ]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'المقطع']);
        $section = CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'المقطع',
            'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id, 'order' => 1,
        ]);
        $studio = route('admin.courses.show', $course);

        foreach ([
            route('admin.courses.modules.create', $course),
            route('admin.courses.modules.edit', [$course, $module]),
            route('admin.courses.sections.create', $course),
            route('admin.courses.sections.edit', [$course, $section]),
        ] as $url) {
            $this->adminGet($admin, $url)->assertRedirect($studio);
        }
    }

    public function test_teacher_editor_keeps_account_and_profile_fields(): void
    {
        $admin = $this->dashboardUser('admin');
        $teacher = $this->dashboardUser('teacher');

        $this->actingAs($admin, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        foreach ([CreateTeacher::class => [],
            EditTeacher::class => ['record' => $teacher->id]] as $page => $parameters) {
            $editor = Livewire::test($page, $parameters)->fillForm(['manage_credentials' => true]);
            foreach (['name_ar', 'email', 'phone', 'password', 'password_confirmation', 'bio_ar', 'image', 'active'] as $field) {
                $editor->assertFormFieldIsVisible($field);
            }
        }
    }
}
