<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminCourseSectionCreateReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_receipt_returns_current_section_without_reposting_and_rejects_a_moved_resource(): void
    {
        $moderator = new User();
        $moderator->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => 'section-receipt@example.test',
            'role' => 'moderator',
            'active' => true,
        ])->save();
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'مسودة الكورس',
            'price' => 0,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'authoring_version' => 8,
            'last_published_authoring_version' => 0,
        ])->save();
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        $otherModule = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الثانية',
            'order' => 2,
        ]);
        $project = Project::query()->create([
            'requirements_text_ar' => 'نفذ المشروع',
            'submission_text_enabled' => true,
            'submission_max_files' => 1,
            'is_graduation_project' => false,
        ]);
        $section = CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'section_type' => 'project',
            'title_ar' => 'الاسم الحالي للمشروع',
            'order' => 1,
        ]);
        $intent = (string) Str::uuid();
        $original = [
            'success' => true,
            'authoring_version' => 7,
            'section' => [
                'id' => $section->id,
                'module_id' => $module->id,
                'type' => 'project',
                'title' => 'الاسم وقت الحفظ',
            ],
        ];
        DB::table('admin_authoring_create_intents')->insert([
            'actor_id' => $moderator->id,
            'route_name' => 'admin.courses.sections.store',
            'parent_scope' => hash('sha256', json_encode(['course' => (string) $course->id])),
            'intent_id' => $intent,
            'request_fingerprint' => hash('sha256', 'multipart-with-thumbnail'),
            'status' => 'completed',
            'response_kind' => 'json',
            'response_body' => json_encode($original, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'response_content_type' => 'application/json',
            'response_status' => 200,
            'resource_type' => CourseSection::class,
            'resource_id' => (string) $section->id,
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($moderator, 'web');

        $this->getJson(route('admin.courses.sections.create-intents.show', [$course, $intent]))
            ->assertOk()
            ->assertJsonPath('state', 'completed')
            ->assertJsonPath('section.id', $section->id)
            ->assertJsonPath('section.title', 'الاسم الحالي للمشروع')
            ->assertJsonPath('receipt_authoring_version', 7)
            ->assertJsonPath('authoring_version', 8);

        $section->forceFill(['module_id' => $otherModule->id])->save();

        $this->getJson(route('admin.courses.sections.create-intents.show', [$course, $intent]))
            ->assertOk()
            ->assertJsonPath('state', 'superseded')
            ->assertJsonMissingPath('section');
    }

    public function test_receipt_is_not_visible_to_another_actor(): void
    {
        $owner = $this->moderator('section-owner@example.test');
        $other = $this->moderator('section-other@example.test');
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'مسودة أخرى',
            'price' => 0,
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
            'authoring_version' => 3,
            'last_published_authoring_version' => 0,
        ])->save();
        $intent = (string) Str::uuid();
        DB::table('admin_authoring_create_intents')->insert([
            'actor_id' => $owner->id,
            'route_name' => 'admin.courses.sections.store',
            'parent_scope' => hash('sha256', json_encode(['course' => (string) $course->id])),
            'intent_id' => $intent,
            'request_fingerprint' => hash('sha256', 'request'),
            'status' => 'processing',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($other, 'web');

        $this->getJson(route('admin.courses.sections.create-intents.show', [$course, $intent]))
            ->assertOk()
            ->assertJsonPath('state', 'absent')
            ->assertJsonPath('authoring_version', 3);
    }

    private function moderator(string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => 'محرر المحتوى',
            'email' => $email,
            'role' => 'moderator',
            'active' => true,
        ])->save();

        return $user;
    }
}
