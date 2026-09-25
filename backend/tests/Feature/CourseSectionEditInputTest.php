<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Data\CourseSectionEdit;
use App\Http\Middleware\RequireAdminMfa;
use App\Http\Requests\Admin\CourseSectionInput;
use App\Models\BunnyDirectUpload;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Models\User;
use App\Services\CourseSectionContentService;
use App\Services\CourseAuthoringConcurrencyService;
use App\Services\BunnyService;
use App\Services\CourseSectionMediaService;
use App\Services\CourseSectionMediaStage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class CourseSectionEditInputTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $moderator = User::query()->forceCreate([
            'name_ar' => 'محرر المحتوى', 'email' => 'section-edit@example.test',
            'role' => 'moderator', 'active' => true,
        ]);
        $this->actingAs($moderator, 'web');
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_nullable_order_does_not_break_a_content_only_http_save(): void
    {
        [$course, $module] = $this->course();
        $this->lesson($course, $module, 1);
        $section = $this->lesson($course, $module, 2);
        $beforeIds = $module->sections()->pluck('id')->all();

        $this->patchJson(route('admin.courses.sections.update', [$course, $section]), [
            'authoring_version' => 1, 'title_ar' => 'العنوان الجديد', 'order' => null,
        ])->assertOk();

        self::assertSame(2, (int) $section->fresh()->order);
        self::assertSame($beforeIds, $module->sections()->pluck('id')->all());
        self::assertSame('العنوان الجديد', $section->fresh()->sectionable->title_ar);
        Http::assertNothingSent();
    }

    public function test_http_lesson_patch_distinguishes_omitted_fields_from_explicit_null_and_false(): void
    {
        [$course, $module] = $this->course();
        $section = $this->lesson($course, $module, 1);
        $lesson = $section->sectionable;

        $this->patchJson(route('admin.courses.sections.update', [$course, $section]), [
            'authoring_version' => 1, 'title_en' => null, 'lesson_description_ar' => null,
            'lesson_duration_minutes' => null, 'is_opened' => false,
        ])->assertOk();

        $lesson->refresh();
        self::assertNull($lesson->title_en);
        self::assertNull($lesson->description_ar);
        self::assertNull($lesson->duration_minutes);
        self::assertFalse((bool) $lesson->is_opened);
        self::assertSame('Original caption', $lesson->description_en);
        self::assertSame('الدرس الأصلي', $lesson->title_ar);
        self::assertSame('existing-video', $lesson->bunny_video_id);
        self::assertSame($lesson->id, $section->fresh()->sectionable_id);
        Http::assertNothingSent();
    }

    public function test_http_project_title_edit_preserves_requirements_and_submission_policy(): void
    {
        [$course, $module] = $this->course();
        $project = Project::query()->create([
            'requirements_text_ar' => 'متطلبات محفوظة', 'requirements_text_en' => 'Requirements',
            'is_graduation_project' => true, 'submission_text_enabled' => true,
            'submission_allowed_mime_types' => ['application/pdf'],
        ]);
        $section = $course->sections()->create([
            'module_id' => $module->id, 'title_ar' => 'المشروع', 'section_type' => 'project',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1,
        ]);

        $this->patchJson(route('admin.courses.sections.update', [$course, $section]), [
            'authoring_version' => 1, 'title_ar' => 'عنوان المشروع الجديد',
        ])->assertOk();

        $project->refresh();
        self::assertSame('متطلبات محفوظة', $project->requirements_text_ar);
        self::assertSame('Requirements', $project->requirements_text_en);
        self::assertTrue($project->is_graduation_project);
        self::assertTrue($project->submission_text_enabled);
        self::assertSame(['application/pdf'], $project->submission_allowed_mime_types);
        self::assertSame($project->id, $section->fresh()->sectionable_id);

        $this->patchJson(route('admin.courses.sections.update', [$course, $section]), [
            'authoring_version' => (int) $course->fresh()->authoring_version,
            'project_requirements_en' => null, 'is_graduation_project' => false,
            'project_submission_types' => ['images'],
        ])->assertOk();
        $project->refresh();
        self::assertSame('متطلبات محفوظة', $project->requirements_text_ar);
        self::assertNull($project->requirements_text_en);
        self::assertFalse($project->is_graduation_project);
        self::assertFalse($project->submission_text_enabled);
        self::assertSame(['image/jpeg', 'image/png', 'image/webp'], $project->submission_allowed_mime_types);
        Http::assertNothingSent();
    }

    public function test_validated_edit_is_a_whitelisted_snapshot_not_a_reference_to_request_state(): void
    {
        [$course, $module] = $this->course();
        $section = $this->lesson($course, $module, 1);
        $request = Request::create('/section', 'PATCH', [
            'authoring_version' => 1, 'lesson_description_ar' => null, 'is_opened' => '0',
            'passing_score' => 1, 'list_id' => 999, 'project_requirements_ar' => 'not lesson input',
        ]);
        $edit = app(CourseSectionInput::class)->validate($request, $course, $section, false);
        $request->merge(['section_type' => 'project', 'is_opened' => true, 'lesson_description_ar' => 'later', 'authoring_version' => 99]);

        self::assertInstanceOf(CourseSectionEdit::class, $edit);
        self::assertSame(1, $edit->expectedVersion);
        self::assertSame('lesson', $edit->type);
        self::assertSame((int) $module->id, $edit->moduleId);
        self::assertSame('الدرس الأصلي', $edit->titleAr);
        self::assertSame(['description_ar' => null, 'is_opened' => false], $edit->lessonChanges);
        self::assertSame([], $edit->projectChanges);
        self::assertNull($edit->projectSubmissionTypes);
    }

    public function test_project_content_can_be_edited_without_an_http_request_or_controller(): void
    {
        [$course, $module] = $this->course();
        $writer = app(CourseSectionContentService::class);
        $media = new CourseSectionMediaStage(null, null, null, false, false);
        $project = $writer->create(new CourseSectionEdit(
            type: 'project', moduleId: (int) $module->id, titleAr: 'المشروع',
            expectedVersion: 1,
            projectChanges: ['requirements_text_ar' => 'نفذ المشروع', 'is_graduation_project' => true],
            projectSubmissionTypes: ['text']
        ), $course, $media);
        $section = $course->sections()->create([
            'module_id' => $module->id, 'title_ar' => 'المشروع', 'section_type' => 'project',
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1,
        ]);

        $writer->update(new CourseSectionEdit(
            type: 'project', moduleId: (int) $module->id, titleAr: 'المشروع',
            expectedVersion: 1,
            projectChanges: ['requirements_text_en' => null, 'is_graduation_project' => false]
        ), $course, $section, $media);

        $project->refresh();
        self::assertSame('نفذ المشروع', $project->requirements_text_ar);
        self::assertNull($project->requirements_text_en);
        self::assertFalse($project->is_graduation_project);
        self::assertTrue($project->submission_text_enabled);
        self::assertSame([], $project->submission_allowed_mime_types);
        Http::assertNothingSent();
    }

    public function test_media_staging_uses_the_validated_file_and_checks_the_explicit_authenticated_actor(): void
    {
        [$course, $module] = $this->course();
        $owner = User::query()->sole();
        $other = User::query()->forceCreate([
            'name_ar' => 'محرر آخر', 'email' => 'section-other@example.test',
            'role' => 'moderator', 'active' => true,
        ]);
        $guid = '11111111-1111-4111-8111-111111111111';
        $intent = (string) Str::uuid();
        $upload = BunnyDirectUpload::query()->create([
            'user_id' => $owner->id, 'course_id' => $course->id,
            'idempotency_key' => $intent, 'request_hash' => hash('sha256', 'fixture'),
            'video_guid' => $guid, 'status' => 'pending', 'expires_at' => now()->addHour(),
        ]);
        BunnyVideoCleanupCandidate::query()->create([
            'video_guid' => $guid, 'reason' => 'test_unattached', 'eligible_after' => now()->addHour(),
        ]);
        $claim = Crypt::encryptString(json_encode([
            'v' => 2, 'upload_id' => $upload->id, 'video_id' => $guid,
            'course_id' => $course->id, 'section_id' => null, 'admin_id' => $owner->id,
            'size' => 1024, 'mime' => 'video/mp4', 'title' => 'المقطع',
            'expires_at' => now()->addHour()->getTimestamp(), 'authoring_version' => 1,
        ], JSON_THROW_ON_ERROR));
        $image = UploadedFile::fake()->image('thumbnail.jpg', 32, 32);
        $request = Request::create('/section', 'POST', [
            'authoring_request_id' => $intent, 'authoring_version' => 1,
            'section_type' => 'lesson', 'module_id' => $module->id,
            'title_ar' => 'المقطع', 'bunny_video_claim' => $claim, 'admin_id' => $other->id,
        ], [], ['lesson_thumbnail' => $image]);
        $edit = app(CourseSectionInput::class)->validate($request, $course, null, true);
        $bunny = Mockery::mock(BunnyService::class);
        $bunny->shouldReceive('verifyDirectUpload')->once()->with($guid, 1024)->andReturn(true);
        $bunny->shouldReceive('uploadFileToStorage')->once()
            ->with($image, 'lessons/thumbnails', $intent, 'section_thumbnail_unpublished')
            ->andReturn('lessons/thumbnails/verified.webp');
        $this->app->instance(BunnyService::class, $bunny);
        $media = app(CourseSectionMediaService::class);

        try {
            $media->stage($edit, $other, $course, null, null);
            self::fail('A different moderator must not attach this claim.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('bunny_video_claim', $exception->errors());
        }
        $stage = $media->stage($edit, $owner, $course, null, null);

        self::assertSame($guid, $stage->videoGuid);
        self::assertSame('lessons/thumbnails/verified.webp', $stage->thumbnailPath);
        self::assertTrue($stage->videoChanged);
        self::assertTrue($stage->thumbnailChanged);
        self::assertSame('pending', $upload->fresh()->status, 'Staging must not consume before the section transaction.');
        Http::assertNothingSent();
    }

    public function test_detached_version_is_checked_against_the_locked_course_after_staging(): void
    {
        [$course, $module] = $this->course();
        $section = $this->lesson($course, $module, 1);
        $request = Request::create('/section', 'PATCH', ['authoring_version' => 1]);
        $edit = app(CourseSectionInput::class)->validate($request, $course, $section, false);
        $concurrency = app(CourseAuthoringConcurrencyService::class);
        $request->merge(['authoring_version' => 99]);
        DB::transaction(function () use ($concurrency, $course, $edit): void {
            $locked = $concurrency->lockExpected($course, $edit->expectedVersion);
            self::assertSame(1, (int) $locked->authoring_version);
            $concurrency->advance($locked);
        });
        $request->merge(['authoring_version' => 2]);
        try {
            DB::transaction(fn () => $concurrency->lockExpected($course, $edit->expectedVersion));
            self::fail('Changing the original request must not turn an old edit into a current revision.');
        } catch (ValidationException $exception) {
            self::assertSame(409, $exception->status);
            self::assertArrayHasKey('authoring_version', $exception->errors());
        }
        self::assertSame(2, (int) $course->fresh()->authoring_version);
        self::assertSame('الدرس الأصلي', $section->fresh()->title_ar);
    }

    public function test_delete_and_reorder_validate_versions_and_reject_stale_edits_before_mutation(): void
    {
        [$course, $module] = $this->course();
        $first = $this->lesson($course, $module, 1);
        $second = $this->lesson($course, $module, 2);
        $course->forceFill(['authoring_version' => 2])->save();
        $delete = route('admin.courses.sections.destroy', [$course, $first]);
        $reorder = route('admin.courses.sections.reorder', $course);
        $sections = [
            ['id' => $first->id, 'order' => 2, 'module_id' => $module->id],
            ['id' => $second->id, 'order' => 1, 'module_id' => $module->id],
        ];

        $this->deleteJson($delete)->assertUnprocessable()->assertJsonValidationErrors('authoring_version');
        $this->postJson($reorder, ['sections' => $sections])
            ->assertUnprocessable()->assertJsonValidationErrors('authoring_version');
        $this->deleteJson($delete, ['authoring_version' => 1])->assertStatus(409);
        $this->postJson($reorder, ['sections' => $sections, 'authoring_version' => 1])->assertStatus(409);
        self::assertSame([$first->id, $second->id], $module->sections()->orderBy('order')->pluck('id')->all());
        self::assertSame(2, (int) $course->fresh()->authoring_version);

        $this->postJson($reorder, ['sections' => $sections, 'authoring_version' => 2])->assertOk();
        self::assertSame(2, (int) $first->fresh()->order);
        self::assertSame(1, (int) $second->fresh()->order);
        $this->deleteJson($delete, ['authoring_version' => 3])->assertOk();
        $this->assertSoftDeleted('course_sections', ['id' => $first->id]);
        self::assertNull(CourseSection::query()->find($first->id));
        self::assertSame(4, (int) $course->fresh()->authoring_version);
        Http::assertNothingSent();
    }

    /** @return array{Course, CourseModule} */
    private function course(): array
    {
        $course = Course::factory()->make();
        $course->forceFill([
            'tenant_id' => 1, 'is_coming_soon' => true, 'is_catalog_visible' => false,
            'published_at' => null, 'last_published_authoring_version' => 0, 'authoring_version' => 1,
        ])->save();

        return [$course, $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1])];
    }

    private function lesson(Course $course, CourseModule $module, int $order): CourseSection
    {
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'الدرس الأصلي', 'title_en' => 'Original lesson',
            'description_ar' => 'الوصف المحفوظ', 'description_en' => 'Original caption',
            'duration_minutes' => 7, 'is_opened' => true,
            'video_source_type' => 'bunny', 'bunny_video_id' => 'existing-video',
        ]);

        return $course->sections()->create([
            'module_id' => $module->id, 'title_ar' => 'الدرس الأصلي', 'title_en' => 'Original lesson',
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class,
            'sectionable_id' => $lesson->id, 'order' => $order,
        ]);
    }
}
