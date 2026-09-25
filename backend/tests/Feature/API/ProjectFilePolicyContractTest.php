<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Http\Resources\CourseResource;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class ProjectFilePolicyContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_project_and_course_payloads_share_the_effective_submission_types(): void
    {
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        Http::preventStrayRequests();
        config([
            'product_features.definitions.project_uploads.default_enabled' => true,
            'projects.allowed_mime_types' => ['text/plain'],
        ]);
        $student = new User();
        $student->forceFill([
            'name_ar' => 'طالب المشروع', 'email' => 'project-policy@example.test',
            'role' => 'client', 'active' => true,
        ])->save();
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1, 'name_ar' => 'كورس المشروع',
            'price' => 800, 'is_coming_soon' => false, 'is_catalog_visible' => true,
            'authoring_version' => 1, 'last_published_authoring_version' => 1, 'published_at' => now(),
        ])->save();
        $module = CourseModule::query()->create([
            'course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1,
        ]);
        $project = Project::query()->create([
            'requirements_text_ar' => 'متطلبات المشروع', 'submission_text_enabled' => true,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id, 'module_id' => $module->id, 'title_ar' => 'المشروع',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1,
        ]);
        $enrollment = new CourseEnrollment();
        $enrollment->forceFill([
            'tenant_id' => 1, 'user_id' => $student->id, 'course_id' => $course->id,
            'is_active' => true, 'enrolled_at' => now(), 'access_granted_at' => now(),
        ])->save();
        $this->actingAs($student, 'api');

        foreach ([null, ['image/png', 'text/plain'], []] as $authoredTypes) {
            $project->update(['submission_allowed_mime_types' => $authoredTypes]);
            $expectedTypes = $authoredTypes === [] ? [] : ['text/plain'];
            $this->getJson('/api/v1/projects/'.$project->id)
                ->assertOk()
                ->assertJsonPath('data.submission_allowed_mime_types', $expectedTypes)
                ->assertJsonPath('data.submission_files_enabled', $expectedTypes !== []);

            $payload = (new CourseResource($course->fresh()->load('modules.sections.sectionable')))
                ->withLearningContext($student, collect(), ['has_learning_access' => true], $enrollment)
                ->resolve(request());
            self::assertSame($expectedTypes, data_get($payload, 'modules.0.sections.0.content.submission_allowed_mime_types'));
            self::assertSame($expectedTypes !== [], data_get($payload, 'modules.0.sections.0.content.submission_files_enabled'));
        }
        Http::assertNothingSent();
    }
}
