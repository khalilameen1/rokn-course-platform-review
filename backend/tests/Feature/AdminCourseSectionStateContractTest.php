<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\AiInputAttachment;
use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CoursePdf;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CoursePlanAuthoringService;
use DOMDocument;
use DOMXPath;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Server-side contracts of the course studio's section, module and Bunny upload
 * state. The browser half is exercised by scripts/tests:
 * - bunny-upload-modules.test.mjs: resume-record ownership and expiry, stable
 *   terminal codes (never message text), the single re-allocation.
 * - bunny-direct-upload-recovery.browser.mjs: Save continues a completed or
 *   paused upload into exactly one form submission, cancellation never submits
 *   later, a replaced file gets its own allocation, and the public
 *   RoknCourseVideoUpload API (isBusy, resetAfterCommit, setSectionContext).
 * - course-studio-reel-queue.browser.mjs: a 410 bunny_upload_operation_unavailable
 *   fails one reel without stopping the queue, and the studio stays locked meanwhile.
 * - course-studio-authoring / content-order / draft-transition / attachments
 *   .browser.mjs: every studio module mutates through the shared core (PATCH
 *   override, receipt reconciliation, inert studio while a mutation is pending,
 *   one editor coordinator across section and module editors, course details
 *   saved in place without navigation).
 * Course creation is pinned by Tests\Feature\ModeratorCourseWorkspaceContractTest::test_new_course_form_creates_only_the_studio_shell
 * and Tests\Feature\CourseAuthoringApplicationOwnershipTest.
 */
final class AdminCourseSectionStateContractTest extends TestCase
{
    use RefreshDatabase;

    private User $moderator;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->moderator = User::query()->forceCreate([
            'name_ar' => 'محرر المحتوى', 'email' => 'section-state@example.test',
            'role' => 'moderator', 'active' => true,
        ]);
        $this->actingAs($this->moderator, 'web');
    }

    public function test_a_stale_upload_operation_is_refused_with_a_machine_readable_terminal_code(): void
    {
        // The claim twin (422 bunny_video_claim_terminal, renew 410 bunny_upload_claim_unavailable)
        // is pinned by Tests\Feature\CourseReelUploadQueueTest::test_a_claim_allocated_before_the_previous_reel_was_saved_is_refused_with_a_terminal_marker,
        // and the renewed claim expiry by Tests\Feature\BunnyDirectUploadLeaseTest::test_authorization_renewal_slides_the_upload_and_cleanup_leases_together.
        $course = $this->draft(authoringVersion: 3);

        $this->postJson(route('admin.courses.sections.video-uploads.store', $course), [
            'title' => 'المقطع', 'size' => 1024, 'mime' => 'video/mp4',
            'original_name' => 'clip.mp4', 'idempotency_key' => (string) Str::uuid(),
            'authoring_version' => 2,
        ])->assertStatus(410)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'bunny_upload_operation_unavailable')
            ->assertJsonValidationErrors('idempotency_key')
            ->assertJsonMissingPath('errors.bunny_upload_operation_terminal');
        Http::assertNothingSent();
    }

    public function test_the_studio_tells_the_upload_form_when_the_server_rejected_its_claim(): void
    {
        $course = $this->draft();
        $module = $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $studio = route('admin.courses.show', $course);

        self::assertStringContainsString('serverRejectedClaim: false', $this->get($studio)->assertOk()->getContent());

        $rejected = $this->from($studio)->post(route('admin.courses.sections.store', $course), [
            'authoring_request_id' => (string) Str::uuid(),
            'authoring_version' => 1,
            'module_id' => $module->id,
            'section_type' => 'lesson',
            'video_source_type' => 'bunny',
            'bunny_video_claim' => 'not-a-signed-claim',
            'title_ar' => 'مقطع',
        ])->assertRedirect($studio)->assertSessionHasErrors(['bunny_video_claim', 'bunny_video_claim_terminal']);

        self::assertStringContainsString('serverRejectedClaim: true', $this->followRedirects($rejected)->assertOk()->getContent());
        self::assertSame(0, $module->sections()->count());
    }

    public function test_dashboard_video_batches_and_lost_probe_dispatches_have_a_recovery_path(): void
    {
        self::assertContains(
            'throttle:30,1',
            Route::getRoutes()->getByName('admin.courses.sections.video-uploads.store')->gatherMiddleware()
        );
        $recovery = collect(app(Schedule::class)->events())->filter(
            fn (Event $event): bool => str_contains((string) $event->command, 'media:recover-pending --limit=200 --stale-minutes=2')
        );
        self::assertCount(1, $recovery);
        self::assertSame('* * * * *', $recovery->first()->expression);
        // Which media the command re-dispatches (unknown/processing, ready but
        // unreconciled, old drafts) is covered by Tests\Feature\MediaReadinessRecoveryTest::
        // test_pending_recovery_redelivers_only_stale_recoverable_readiness and
        // test_old_draft_media_is_recovered_until_bunny_and_hls_become_ready.
    }

    public function test_course_section_forms_have_one_bunny_only_media_contract(): void
    {
        $course = $this->draft();
        $html = $this->get(route('admin.courses.show', $course))->assertOk()->getContent();
        $xpath = $this->xpath($html);

        $form = '//form[@id="sectionForm"]';
        $sources = $xpath->query("{$form}//*[@name='video_source_type']");
        self::assertSame(1, $sources->length);
        self::assertSame('hidden', $sources->item(0)->getAttribute('type'));
        self::assertSame('bunny', $sources->item(0)->getAttribute('value'));
        self::assertSame(
            route('admin.courses.sections.video-uploads.store', $course),
            $xpath->evaluate("string({$form}/@data-bunny-upload-init)")
        );
        self::assertSame(
            route('admin.courses.sections.video-uploads.renew', $course),
            $xpath->evaluate("string({$form}/@data-bunny-upload-renew)")
        );
        self::assertStringNotContainsStringIgnoringCase('youtube', $xpath->evaluate('string(//*[@id="studioInlineAuthoring"])'));
        self::assertSame(0, $xpath->query('//*[@id="studioInlineAuthoring"]//*[contains(@name, "youtube") or contains(@id, "youtube")]')->length);

        $scripts = $this->scriptPaths($xpath);
        $position = fn (string $file): int|false => array_search(true, array_map(
            static fn (string $src): bool => str_ends_with($src, '/'.$file), $scripts
        ), true);
        self::assertNotFalse($position('course-studio-section-editor.js'));
        self::assertNotFalse($position('course-studio-bunny-upload-form.js'));
        self::assertLessThan($position('course-studio-bunny-upload-form.js'), $position('course-studio-bunny-upload-records.js'));
    }

    public function test_inline_authoring_mutations_return_json_instead_of_redirects(): void
    {
        $course = $this->draft();

        $created = $this->postJson(route('admin.courses.modules.store', $course), [
            'authoring_request_id' => (string) Str::uuid(), 'authoring_version' => 1, 'title_ar' => 'الوحدة الأولى',
        ])->assertOk()->assertJsonPath('authoring_version', 2)->assertJsonPath('module.title', 'الوحدة الأولى');
        $module = CourseModule::query()->findOrFail($created->json('module.id'));
        $section = $this->lesson($course, $module, 1);

        $this->patchJson(route('admin.courses.sections.update', [$course, $section]), [
            'authoring_version' => 2, 'title_ar' => 'عنوان جديد',
        ])->assertOk()->assertJsonPath('authoring_version', 3)
            ->assertJsonPath('section.id', $section->id)->assertJsonPath('section.title', 'عنوان جديد');

        $this->postJson(route('admin.courses.sections.reorder', $course), [
            'authoring_version' => 3,
            'sections' => [['id' => $section->id, 'order' => 1, 'module_id' => $module->id]],
        ])->assertOk()->assertJsonPath('authoring_version', 4)
            ->assertJsonPath('modules.0.id', $module->id)
            ->assertJsonPath('modules.0.sections.0.id', $section->id);
        $this->postJson(route('admin.courses.modules.reorder', $course), [
            'authoring_version' => 4,
            'modules' => [['id' => $module->id, 'order' => 1]],
        ])->assertOk()->assertJsonPath('authoring_version', 5)
            ->assertJsonPath('modules.0.id', $module->id);

        $this->deleteJson(route('admin.courses.sections.destroy', [$course, $section]), ['authoring_version' => 5])
            ->assertOk()->assertJsonPath('deleted_section_id', $section->id)
            ->assertJsonPath('authoring_version', 6);

        $remaining = $this->lesson($course, $module, 1);
        $this->deleteJson(route('admin.courses.modules.destroy', [$course, $module]), ['authoring_version' => 6])
            ->assertOk()->assertJsonPath('deleted_module_id', $module->id)
            ->assertJsonPath('section_ids', [$remaining->id])
            ->assertJsonPath('authoring_version', 7);
        self::assertSame(0, $course->modules()->count());
    }

    public function test_an_unfinished_create_intent_is_reported_as_in_progress_to_json_and_html_callers(): void
    {
        $course = $this->draft();
        $studio = route('admin.courses.show', $course);
        $payload = [
            'authoring_request_id' => (string) Str::uuid(), 'authoring_version' => 1, 'title_ar' => 'وحدة قيد الحفظ',
        ];
        $this->postJson(route('admin.courses.modules.store', $course), $payload)->assertOk();
        // Another worker still holds the same create intent.
        DB::table('admin_authoring_create_intents')->update(['status' => 'processing', 'updated_at' => now()]);

        $this->postJson(route('admin.courses.modules.store', $course), $payload)
            ->assertStatus(409)
            ->assertJsonPath('success', false)
            ->assertJsonPath('code', 'authoring_in_progress');
        $this->from($studio)->post(route('admin.courses.modules.store', $course), $payload)
            ->assertRedirect($studio)
            ->assertSessionHas('error');
        self::assertSame(1, $course->modules()->count());
    }

    public function test_studio_is_one_page_without_frames_or_parallel_editors(): void
    {
        $course = $this->draft();
        foreach (['moderator', 'admin'] as $role) {
            $this->flushSession();
            $actor = $role === 'admin'
                ? User::query()->forceCreate(['name_ar' => 'مالك ركن', 'email' => 'section-state-admin@example.test', 'role' => 'admin', 'active' => true])
                : $this->moderator;
            $xpath = $this->xpath($this->actingAs($actor, 'web')
                ->get(route('admin.courses.show', $course))->assertOk()->getContent());

            self::assertSame(0, $xpath->query('//iframe')->length, $role);
            $links = array_map(
                static fn (\DOMElement $link): string => $link->getAttribute('href'),
                iterator_to_array($xpath->query('//*[@id="courseStudio"]//a[@href]'))
            );
            self::assertNotContains(route('admin.courses.edit', $course), $links, $role);
            self::assertNotContains(route('admin.courses.pdfs.index', $course), $links, $role);
            self::assertSame(1, $xpath->query('//header[contains(@class, "course-workspace")]//a[contains(@class, "is-active")]')->length, $role);
        }
    }

    public function test_module_and_section_deletion_share_one_cleanup_owner(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $course = $this->draft();
        $kept = $course->modules()->create(['title_ar' => 'وحدة باقية', 'order' => 1]);
        $removed = $course->modules()->create(['title_ar' => 'وحدة محذوفة', 'order' => 2]);
        $keptReel = $this->lesson($course, $kept, 1);
        [$sectionProject, $sectionFiles] = $this->projectWithLearnerFiles($course, $kept, 2, 'section');
        $removedReel = $this->lesson($course, $removed, 1);
        [$moduleProject, $moduleFiles] = $this->projectWithLearnerFiles($course, $removed, 2, 'module');
        Storage::disk('public')->put('courses/guide.pdf', 'course guide');
        $pdf = CoursePdf::query()->create([
            'course_id' => $course->id, 'title' => 'دليل الكورس', 'file_path' => 'courses/guide.pdf',
            'storage_disk' => 'public', 'file_size' => 12, 'is_active' => true,
        ]);

        $this->deleteJson(route('admin.courses.sections.destroy', [$course, $sectionProject]), ['authoring_version' => 1])
            ->assertOk()->assertJsonPath('deleted_section_id', $sectionProject->id);
        $this->deleteJson(route('admin.courses.modules.destroy', [$course, $removed]), ['authoring_version' => 2])
            ->assertOk()->assertJsonPath('section_ids', [$removedReel->id, $moduleProject->id]);

        foreach ([[$sectionProject, $sectionFiles], [$moduleProject, $moduleFiles]] as [$section, $files]) {
            self::assertFalse(Project::query()->whereKey($section->sectionable_id)->exists());
            self::assertFalse(ProjectSubmission::query()->where('project_id', $section->sectionable_id)->exists());
            self::assertFalse(AiInputAttachment::query()->whereIn('storage_path', $files)->exists());
            foreach ($files as $file) {
                $deletion = AccountFileDeletion::query()
                    ->where('disk', 'local')->where('path_hash', hash('sha256', $file))->sole();
                app()->call([new DeleteAccountFile((int) $deletion->id), 'handle']);
                Storage::disk('local')->assertMissing($file);
            }
        }
        // Learning content owns its cleanup; course attachments are untouched.
        self::assertTrue(CoursePdf::query()->whereKey($pdf->id)->exists());
        Storage::disk('public')->assertExists('courses/guide.pdf');
        self::assertSame([$keptReel->id], $course->sections()->pluck('id')->all());
    }

    public function test_publishing_a_draft_preserves_the_explicit_catalog_visibility_choice(): void
    {
        foreach ([false, true] as $visible) {
            $course = $this->publishableDraft($visible ? 'كورس ظاهر' : 'كورس مخفي');
            $this->patchJson(route('admin.courses.update', $course), [
                'authoring_version' => (int) $course->authoring_version,
                'publishing_intent' => 'publish',
                'is_catalog_visible' => $visible ? '1' : '0',
            ])->assertOk()->assertJsonPath('saved', true);

            $course->refresh();
            self::assertFalse((bool) $course->is_coming_soon, $course->name_ar);
            self::assertSame($visible, (bool) $course->is_catalog_visible, $course->name_ar);
            self::assertNotNull($course->published_at);
        }
    }

    private function draft(int $authoringVersion = 1): Course
    {
        return Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => 'مسودة', 'price' => 500,
            'is_coming_soon' => true, 'is_catalog_visible' => false, 'published_at' => null,
            'authoring_version' => $authoringVersion, 'last_published_authoring_version' => 0,
            'certificate_text_template_key' => 'completion',
        ]);
    }

    private function lesson(Course $course, CourseModule $module, int $order): CourseSection
    {
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'مقطع',
            'video_source_type' => 'bunny', 'bunny_video_id' => (string) Str::uuid(),
        ]);

        return $course->sections()->create([
            'title_ar' => 'مقطع', 'module_id' => $module->id, 'order' => $order,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
        ]);
    }

    /** @return array{CourseSection, list<string>} */
    private function projectWithLearnerFiles(Course $course, CourseModule $module, int $order, string $label): array
    {
        $project = Project::query()->create(['requirements_text_ar' => 'نفّذ', 'submission_text_enabled' => true]);
        $section = $course->sections()->create([
            'title_ar' => 'مشروع', 'module_id' => $module->id, 'order' => $order,
            'section_type' => 'project', 'sectionable_type' => Project::class, 'sectionable_id' => $project->id,
        ]);
        $learner = User::query()->forceCreate([
            'name_ar' => 'طالب', 'email' => Str::uuid().'@example.test', 'role' => 'client', 'active' => true,
        ]);
        $submissionFile = "project-submissions/{$label}/work.pdf";
        $attachmentFile = "ai-inputs/{$label}/sketch.png";
        Storage::disk('local')->put($submissionFile, 'learner work');
        Storage::disk('local')->put($attachmentFile, 'learner sketch');
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $learner->id, 'project_id' => $project->id,
            'idempotency_key' => (string) Str::uuid(), 'submission_text' => 'محاولة',
            'submission_file' => $submissionFile, 'submission_metadata' => [],
            'review_status' => 'passed', 'submitted_at' => now(),
        ]);
        AiInputAttachment::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $learner->id, 'course_id' => $course->id,
            'client_upload_id' => (string) Str::uuid(), 'purpose' => 'project_submission',
            'owner_type' => AiInputAttachment::OWNER_PROJECT_SUBMISSION, 'owner_id' => $submission->id,
            'storage_disk' => 'local', 'storage_path' => $attachmentFile, 'original_file_name' => 'sketch.png',
            'mime_type' => 'image/png', 'size_bytes' => 14, 'sha256' => hash('sha256', 'learner sketch'),
            'status' => 'ready',
        ]);

        return [$section, [$submissionFile, $attachmentFile]];
    }

    private function publishableDraft(string $name): Course
    {
        Storage::fake('public');
        $teacher = User::query()->forceCreate([
            'name_ar' => 'المحاضر', 'email' => Str::uuid().'@example.test', 'role' => 'teacher', 'active' => true,
        ]);
        $course = Course::query()->forceCreate([
            'tenant_id' => 1, 'name_ar' => $name, 'description_ar' => 'وصف الكورس',
            'price' => 800, 'is_coming_soon' => true, 'is_catalog_visible' => false, 'is_main_course' => false,
            'attachment_prompt_enabled' => false, 'authoring_version' => 1, 'last_published_authoring_version' => 0,
            'certificate_text_template_key' => 'completion',
        ]);
        $course->teachers()->attach($teacher->id);
        $course->classifications()->attach(
            Classification::query()->create(['name_ar' => 'تصنيف '.$name, 'name_en' => 'Category '.$name])->id
        );
        Storage::disk('public')->put('courses/cover-'.$course->id.'.jpg', 'cover');
        $course->allPhotos()->create(['path' => 'courses/cover-'.$course->id.'.jpg', 'type' => 'featured']);
        app(CoursePlanAuthoringService::class)->createDefaults($course);
        $module = $course->modules()->create(['title_ar' => 'الوحدة', 'order' => 1]);
        $guid = (string) Str::uuid();
        $lesson = Lesson::query()->create([
            'list_id' => $course->id, 'title_ar' => 'المقطع', 'video_source_type' => 'bunny', 'bunny_video_id' => $guid,
        ]);
        LessonMediaState::query()->create([
            'lesson_id' => $lesson->id, 'provider' => 'bunny', 'provider_media_id' => $guid, 'status' => 'ready',
            'duration_seconds' => 60, 'integrity_status' => 'healthy', 'last_reconciled_at' => now(),
        ]);
        $course->sections()->create([
            'title_ar' => 'المقطع', 'module_id' => $module->id, 'order' => 1,
            'section_type' => 'lesson', 'sectionable_type' => Lesson::class, 'sectionable_id' => $lesson->id,
        ]);

        return $course->fresh();
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

        return new DOMXPath($document);
    }

    /** @return list<string> */
    private function scriptPaths(DOMXPath $xpath): array
    {
        return array_values(array_map(
            static fn (\DOMElement $script): string => (string) parse_url($script->getAttribute('src'), PHP_URL_PATH),
            iterator_to_array($xpath->query('//script[@src]'))
        ));
    }
}
