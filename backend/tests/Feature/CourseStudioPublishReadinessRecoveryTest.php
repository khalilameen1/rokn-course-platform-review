<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\User;
use App\Services\CourseAccessPlanService;
use App\Services\CoursePublishingService;
use App\Services\CourseStagedAuthoringService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class CourseStudioPublishReadinessRecoveryTest extends TestCase
{
    use RefreshDatabase;

    public function test_readiness_rejection_reports_the_draft_that_was_actually_saved(): void
    {
        [$canonical, $draft] = $this->readyPublishedCourseAndDraft();
        $version = (int) $draft->authoring_version;

        $response = $this->withHeader('Accept', 'application/json')
            ->post(route('admin.courses.update', $draft), [
                '_method' => 'PATCH',
                'authoring_version' => $version,
                'publishing_intent' => 'publish',
                'name_ar' => 'عنوان المسودة المعدل',
                'description_ar' => '',
            ]);

        // The outer editor save committed even though the publication audit
        // rejected the now-empty description. Learners still own the old graph.
        self::assertSame('عنوان المسودة المعدل', $draft->fresh()->name_ar);
        self::assertSame($version + 1, (int) $draft->fresh()->authoring_version);
        self::assertSame('الكورس المنشور', $canonical->fresh()->name_ar);
        self::assertSame('وصف الكورس المنشور', $canonical->fresh()->description_ar);
        self::assertSame(4, (int) $canonical->fresh()->authoring_version);
        self::assertTrue(CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::DRAFT)->exists());

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('saved', true)
            ->assertJsonPath('published', false)
            ->assertJsonPath('status', 'not_ready')
            ->assertJsonPath('authoring_version', $version + 1)
            ->assertJsonPath('course.id', $draft->id)
            ->assertJsonPath('course.authoring_version', $version + 1)
            ->assertJsonPath('issues', ['أضف وصفًا مختصرًا يوضح نتيجة الكورس.']);
        Http::assertNothingSent();
    }

    public function test_correcting_the_rejected_publish_uses_the_acknowledged_version_without_a_false_conflict(): void
    {
        [$canonical, $draft] = $this->readyPublishedCourseAndDraft();
        $version = (int) $draft->authoring_version;
        $rejected = $this->patchJson(route('admin.courses.update', $draft), [
            'authoring_version' => $version,
            'publishing_intent' => 'publish',
            'description_ar' => '',
        ]);

        // The real studio only advances when its response includes a confirmed
        // version. A plain 422 leaves the previously rendered version in place.
        $visibleVersion = (int) ($rejected->json('authoring_version') ?? $version);
        $this->patchJson(route('admin.courses.update', $draft), [
            'authoring_version' => $visibleVersion,
            'publishing_intent' => 'publish',
            'description_ar' => 'الوصف المصحح قبل النشر',
        ])->assertOk()
            ->assertJsonPath('saved', true)
            ->assertJsonPath('published', true)
            ->assertJsonPath('course.id', $canonical->id);

        self::assertSame('الوصف المصحح قبل النشر', $canonical->fresh()->description_ar);
        self::assertTrue(CourseAuthoringRevision::query()
            ->where('revision_course_id', $draft->id)
            ->where('status', CourseAuthoringRevision::ARCHIVED)->exists());
        Http::assertNothingSent();
    }

    public function test_real_publication_version_conflicts_are_not_reported_as_readiness_warnings(): void
    {
        [$canonical, $draft] = $this->readyPublishedCourseAndDraft();
        $version = (int) $draft->authoring_version;
        $canonical->forceFill(['authoring_version' => 5])->saveQuietly();

        $this->patchJson(route('admin.courses.update', $draft), [
            'authoring_version' => $version,
            'publishing_intent' => 'publish',
            'name_ar' => 'تعديل قبل اكتشاف نسخة منشورة أحدث',
        ])->assertConflict()->assertJsonValidationErrors('authoring_version')
            ->assertJsonMissing(['status' => 'not_ready']);

        // This conflict is genuinely between two publication generations and
        // still requires the editor to reconcile, unlike a readiness failure.
        self::assertSame($version + 1, (int) $draft->fresh()->authoring_version);
        self::assertSame('الكورس المنشور', $canonical->fresh()->name_ar);
    }

    public function test_input_validation_before_save_remains_an_unsaved_422(): void
    {
        [, $draft] = $this->readyPublishedCourseAndDraft();
        $version = (int) $draft->authoring_version;
        $this->patchJson(route('admin.courses.update', $draft), [
            'authoring_version' => $version,
            'publishing_intent' => 'publish',
            'name_ar' => '',
        ])->assertUnprocessable()->assertJsonValidationErrors('name_ar');

        self::assertSame($version, (int) $draft->fresh()->authoring_version);
        self::assertSame('الكورس المنشور', $draft->fresh()->name_ar);
    }

    public function test_html_studio_returns_to_the_saved_draft_with_its_readiness_issues(): void
    {
        [$canonical, $draft] = $this->readyPublishedCourseAndDraft();
        $version = (int) $draft->authoring_version;
        $this->post(route('admin.courses.update', $draft), [
            '_method' => 'PATCH',
            'authoring_version' => $version,
            'publishing_intent' => 'publish',
            'description_ar' => '',
        ])->assertRedirect(route('admin.courses.show', $draft))
            ->assertSessionHas('publishing_issues', ['أضف وصفًا مختصرًا يوضح نتيجة الكورس.']);

        self::assertSame($version + 1, (int) $draft->fresh()->authoring_version);
        self::assertSame('وصف الكورس المنشور', $canonical->fresh()->description_ar);
    }

    /** @return array{Course, Course} */
    private function readyPublishedCourseAndDraft(): array
    {
        Http::preventStrayRequests();
        Http::fake();
        Storage::fake('public');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $moderator = (new User())->forceFill([
            'name_ar' => 'محرر الاستوديو', 'email' => 'studio-readiness@example.test',
            'role' => 'moderator', 'active' => true,
        ]);
        $moderator->save();
        $teacher = (new User())->forceFill([
            'name_ar' => 'المحاضر', 'email' => 'studio-teacher@example.test',
            'role' => 'teacher', 'active' => true,
        ]);
        $teacher->save();
        $canonical = (new Course())->forceFill([
            'tenant_id' => 1, 'name_ar' => 'الكورس المنشور',
            'description_ar' => 'وصف الكورس المنشور', 'price' => 800,
            'is_coming_soon' => false, 'is_catalog_visible' => true,
            'is_main_course' => false, 'attachment_prompt_enabled' => false,
            'authoring_version' => 4, 'last_published_authoring_version' => 4,
            'published_at' => now(), 'certificate_text_template_key' => 'completion',
        ]);
        $canonical->save();
        $canonical->teachers()->attach($teacher->id);
        $classification = Classification::query()->create(['name_ar' => 'التصنيف', 'name_en' => 'Category']);
        $canonical->classifications()->attach($classification->id);
        Storage::disk('public')->put('courses/ready-cover.jpg', 'cover bytes');
        $canonical->allPhotos()->create(['path' => 'courses/ready-cover.jpg', 'type' => 'featured']);
        app(CourseAccessPlanService::class)->createDefaults($canonical);
        $module = $canonical->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $guid = '33333333-3333-4333-8333-333333333333';
        $lesson = Lesson::query()->create([
            'list_id' => $canonical->id, 'title_ar' => 'المقطع',
            'video_source_type' => 'bunny', 'bunny_video_id' => $guid,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id, 'provider' => 'bunny', 'provider_media_id' => $guid,
            'status' => 'ready', 'duration_seconds' => 60,
            'integrity_status' => 'healthy', 'last_reconciled_at' => now(),
        ]);
        $canonical->sections()->create([
            'title_ar' => 'المقطع', 'module_id' => $module->id, 'order' => 1,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id,
        ]);
        $audit = app(CoursePublishingService::class)->audit($canonical->fresh());
        self::assertTrue($audit['ready'], implode('\n', $audit['issues']));
        $draft = app(CourseStagedAuthoringService::class)->draftFor($canonical);
        $audit = app(CoursePublishingService::class)->audit($draft->fresh());
        self::assertTrue($audit['ready'], implode('\n', $audit['issues']));
        $this->actingAs($moderator, 'web');

        return [$canonical, $draft];
    }
}
