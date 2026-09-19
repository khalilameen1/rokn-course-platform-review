<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Certificate;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\User;
use App\Services\CertificateArtworkRenderer;
use App\Services\CertificateIssuanceSnapshotService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class AdminCourseCertificatePreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_preview_is_a_read_only_authoring_route_protected_by_mfa(): void
    {
        $route = Route::getRoutes()->getByName('admin.courses.certificate-preview');
        self::assertNotNull($route);
        self::assertSame(['GET', 'HEAD'], $route->methods());
        self::assertContains('admin', $route->gatherMiddleware());
        self::assertContains('admin.mfa', $route->gatherMiddleware());
        self::assertNotContains('course.draft', $route->gatherMiddleware());
        $permissions = app(AdminPermissionMatrix::class);
        self::assertTrue($permissions->allows('moderator', $route->getName(), 'GET'));
        self::assertFalse($permissions->allows('moderator', $route->getName(), 'POST'));
        self::assertFalse($permissions->allows('client', $route->getName(), 'GET'));
    }

    public function test_guest_cannot_load_preview_or_artwork(): void
    {
        $course = $this->course('كورس خاص', 'completion');
        foreach ([0, 1] as $image) {
            $this->get(route('admin.courses.certificate-preview', [$course, 'image' => $image]))
                ->assertRedirect(route('login'));
        }
    }

    public function test_student_cannot_load_preview_or_artwork(): void
    {
        $course = $this->course('كورس خاص', 'completion');
        $this->actingAs($this->user('client'), 'web');
        foreach ([0, 1] as $image) {
            $this->get(route('admin.courses.certificate-preview', [$course, 'image' => $image]))
                ->assertForbidden();
        }
    }

    public function test_moderator_still_requires_mfa_before_preview(): void
    {
        $course = $this->course('كورس خاص', 'completion');
        $this->actingAs($this->user('moderator'), 'web')
            ->get(route('admin.courses.certificate-preview', $course))
            ->assertRedirect(route('admin.mfa.setup'));
    }

    public function test_saved_staged_graph_is_previewed_without_creating_another_draft_or_credential(): void
    {
        $canonical = $this->course('الكورس المنشور', 'applied');
        $draft = $this->course('المسودة المحفوظة', 'skills', true);
        $this->project($draft, false);
        CourseAuthoringRevision::query()->create([
            'canonical_course_id' => $canonical->id,
            'revision_course_id' => $draft->id,
            'base_authoring_version' => 4,
            'status' => CourseAuthoringRevision::DRAFT,
            'active_slot' => 'course-draft:'.$canonical->id,
            'clone_key' => (string) Str::uuid(),
        ]);
        $this->asModerator();
        $response = $this->get(route('admin.courses.certificate-preview', $canonical))
            ->assertOk()
            ->assertViewHas('previewCourse', fn (Course $value): bool => $value->id === $draft->id)
            ->assertViewHas('hasPassageProjects', true)
            ->assertSee('المسودة المحفوظة')
            ->assertSee('معاينة فقط')
            ->assertSee('هكذا تظهر بعد إتمام الكورس واجتياز مشروعات العبور')
            ->assertSee(route('admin.courses.certificate-preview', [$draft, 'image' => 1]), false);

        self::assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
        self::assertSame(2, Course::query()->count());
        self::assertSame(1, CourseAuthoringRevision::query()->count());
        self::assertSame(0, Certificate::query()->count());
        self::assertSame('applied', $canonical->fresh()->certificate_text_template_key);
        self::assertSame('skills', $draft->fresh()->certificate_text_template_key);
        self::assertSame(4, (int) $canonical->fresh()->authoring_version);
    }

    public function test_practical_legacy_key_and_graduation_alone_do_not_imply_passage_projects(): void
    {
        $course = $this->course('كورس بلا مشروعات عبور', 'projects');
        $this->project($course, true);
        $this->asModerator();

        $this->get(route('admin.courses.certificate-preview', $course))
            ->assertOk()
            ->assertViewHas('hasPassageProjects', false)
            ->assertDontSee('واجتياز مشروعات العبور');

        self::assertSame(0, CourseAuthoringRevision::query()->count());
        self::assertSame('projects', $course->fresh()->certificate_text_template_key);
    }

    public function test_unified_editor_preserves_each_saved_key_and_only_defaults_a_missing_key(): void
    {
        foreach (['completion', 'knowledge', 'applied', 'skills', 'projects', null] as $key) {
            $course = new Course();
            $course->forceFill(['id' => 37, 'certificate_text_template_key' => $key]);
            $html = view('admin.courses.partials.certificate-text-template', [
                'course' => $course,
                'errors' => new ViewErrorBag(),
            ])->render();

            self::assertStringContainsString(
                'name="certificate_text_template_key" value="'.($key ?? 'completion').'"',
                $html
            );
            self::assertStringNotContainsString('type="radio"', $html);
            self::assertStringNotContainsString('اختر الصياغة', $html);
            self::assertStringContainsString('معاينة الشهادة', $html);
            self::assertStringContainsString('المعاينة من آخر نسخة محفوظة', $html);
        }
    }

    public function test_invalid_image_parameter_is_rejected_without_issuing_a_certificate(): void
    {
        $course = $this->course('كورس خاص', 'completion');
        $this->asModerator();
        $this->getJson(route('admin.courses.certificate-preview', [$course, 'image' => 'invalid']))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('image');
        self::assertSame(0, Certificate::query()->count());
    }

    public function test_png_uses_the_actual_renderer_without_persisting_an_artifact_or_learner_data(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-15 12:00:00'));
        Storage::fake('public');
        $course = $this->course('مونتاج الريلز', 'knowledge');
        $this->project($course, false);
        $this->asModerator();
        $beforeUserCount = User::query()->count();
        $snapshot = app(CertificateIssuanceSnapshotService::class)->forPreview($course);
        $sample = new Certificate();
        $sample->forceFill(array_merge($snapshot, [
            'public_id' => '00000000-0000-0000-0000-000000000000',
            'course_id' => $course->id,
            'holder_name' => 'اسم المتعلم',
            'course_name' => 'مونتاج الريلز',
            'certificate_text_template_key' => 'knowledge',
            'generated_at' => now(),
        ]));
        $expected = app(CertificateArtworkRenderer::class)->render($sample, [
            'url' => 'https://preview.invalid/certificate',
            'type' => 'certificate',
            'title' => 'التحقق من الشهادة',
            'hint' => 'امسح الرمز للتحقق',
        ]);

        $response = $this->get(route('admin.courses.certificate-preview', [$course, 'image' => 1]))
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
            ->assertHeader('X-Content-Type-Options', 'nosniff');
        self::assertSame(hash('sha256', $expected), hash('sha256', $response->getContent()));
        $dimensions = getimagesizefromstring($response->getContent());
        self::assertSame(CertificateArtworkRenderer::WIDTH, $dimensions[0]);
        self::assertSame(CertificateArtworkRenderer::HEIGHT, $dimensions[1]);
        self::assertSame(0, Certificate::query()->count());
        self::assertSame(0, CourseAuthoringRevision::query()->count());
        self::assertSame($beforeUserCount, User::query()->count());
        self::assertSame([], Storage::disk('public')->allFiles());
        self::assertSame('knowledge', $course->fresh()->certificate_text_template_key);
    }

    private function asModerator(): void
    {
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($this->user('moderator'), 'web');
    }

    private function user(string $role): User
    {
        $user = new User();
        $user->forceFill([
            'name_ar' => 'مستخدم المعاينة',
            'email' => $role.'-certificate-preview@example.test',
            'role' => $role,
            'active' => true,
        ])->save();

        return $user;
    }

    private function course(string $name, string $key, bool $draft = false): Course
    {
        $course = new Course();
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => $name,
            'price' => 500,
            'is_coming_soon' => $draft,
            'is_catalog_visible' => !$draft,
            'authoring_version' => 4,
            'last_published_authoring_version' => $draft ? null : 4,
            'published_at' => $draft ? null : now(),
            'certificate_text_template_key' => $key,
        ])->save();

        return $course;
    }

    private function project(Course $course, bool $graduation): void
    {
        $module = CourseModule::query()->firstOrCreate(
            ['course_id' => $course->id], ['title_ar' => 'الوحدة', 'order' => 1]
        );
        $project = Project::query()->create([
            'requirements_text_ar' => 'نفذ المشروع',
            'is_graduation_project' => $graduation,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'title_ar' => 'المشروع',
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'order' => 1,
        ]);
    }
}
