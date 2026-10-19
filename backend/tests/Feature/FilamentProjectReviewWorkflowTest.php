<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\ProjectSubmissionResource;
use App\Filament\Resources\ProjectSubmissionResource\Pages\ViewProjectSubmission;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\AiInputAttachment;
use App\Models\Course;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectFeedbackMessage;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentProjectReviewWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Bus::fake();
        Http::preventStrayRequests();
        $this->withoutMiddleware(RequireAdminMfa::class);
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->actingAs($this->user('moderator'), 'web');
    }

    public function test_native_rejection_requires_a_reason_and_uses_the_existing_review_owner(): void
    {
        $submission = $this->submission();
        $page = Livewire::test(ViewProjectSubmission::class, ['record' => $submission->public_id])->assertSee('هوية مخفية')
            ->callAction('reject', ['feedback' => ''])->assertHasActionErrors(['feedback' => 'required']);
        self::assertSame('pending', $submission->fresh()->review_status);
        $page->unmountAction()->callAction('reject', ['feedback' => 'أعد رفع نتيجة أوضح'])->assertHasNoActionErrors()->assertNotified('تم تسجيل قرار المراجعة');
        self::assertSame('needs_resubmission', $submission->fresh()->review_status);
        self::assertSame(auth('web')->id(), $submission->fresh()->reviewed_by);
        self::assertSame('admin_manual', $submission->fresh()->review_source);
        $page->assertActionHidden('pass')->assertActionHidden('reject');
        $this->get(route('admin.project-submissions.show', $submission))->assertRedirect(ProjectSubmissionResource::getUrl('view', ['record' => $submission]));
    }

    public function test_native_acceptance_updates_the_canonical_outcome_and_hides_both_decisions(): void
    {
        $submission = $this->submission();
        Livewire::test(ViewProjectSubmission::class, ['record' => $submission->public_id])
            ->callAction('pass', ['feedback' => 'تنفيذ جيد'])->assertHasNoActionErrors()->assertActionHidden('pass')->assertActionHidden('reject');
        self::assertSame('passed', $submission->fresh()->review_status);
        self::assertSame('تنفيذ جيد', $submission->fresh()->feedback);
        self::assertTrue($submission->fresh()->reviewOutcome()['skill_verified']);
    }

    public function test_a_new_attempt_cannot_be_overwritten_from_an_already_open_review_form(): void
    {
        $submission = $this->submission();
        $page = Livewire::test(ViewProjectSubmission::class, ['record' => $submission->public_id])->mountAction('reject');
        $new = $submission->replicate(['public_id', 'idempotency_key']);
        $new->forceFill(['public_id' => (string) Str::uuid(), 'idempotency_key' => (string) Str::uuid()])->save();
        $page->setActionData(['feedback' => 'قرار على المحاولة القديمة'])->callMountedAction();
        self::assertSame('pending', $submission->fresh()->review_status);
        self::assertSame('pending', $new->fresh()->review_status);
        Livewire::test(ViewProjectSubmission::class, ['record' => $submission->public_id])->assertActionHidden('pass')->assertActionHidden('reject')
            ->assertSee('هذه محاولة سابقة — راجع المحاولة الأحدث لاتخاذ القرار');
    }

    public function test_report_keeps_the_initial_message_and_private_file_links_in_the_native_reader(): void
    {
        $submission = $this->submission();
        $project = $submission->project;
        $course = $project->section->course;
        $enrollment = CourseEnrollment::query()->forceCreate(['tenant_id' => 1, 'user_id' => $submission->user_id, 'course_id' => $course->id, 'is_active' => true, 'enrolled_at' => now()]);
        $terms = ['project_feedback_level' => 'report', 'project_feedback_token_budget' => 4000,
            'project_feedback_budget_usd' => '.2', 'project_feedback_reserve_usd' => '.02', 'max_output_tokens' => 320];
        $submission->update(['evaluation_snapshot' => ProjectSubmissionEvaluationSnapshot::capture($project, $project->section, $enrollment, $terms)]);
        $thread = ProjectFeedbackThread::query()->create(['public_id' => (string) Str::uuid(), 'submission_id' => $submission->id,
            'user_id' => $submission->user_id, 'course_id' => $course->id, 'project_id' => $project->id,
            'enrollment_id' => $enrollment->id, 'feedback_level' => 'report', 'can_reply' => false, 'status' => 'ready']);
        ProjectFeedbackMessage::query()->create(['public_id' => (string) Str::uuid(), 'thread_id' => $thread->id, 'role' => 'assistant',
            'status' => 'completed', 'client_request_id' => 'report:'.$submission->public_id, 'body' => 'التقرير الأصلي الذي لا يضيع']);
        foreach (range(1, 62) as $index) {
            ProjectFeedbackMessage::query()->create(['public_id' => (string) Str::uuid(), 'thread_id' => $thread->id, 'role' => 'user',
                'status' => 'completed', 'body' => 'سؤال متابعة '.$index]);
        }
        Storage::fake('local');
        Storage::disk('local')->put('project-test/evidence.txt', 'Private project result');
        $file = AiInputAttachment::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $submission->user_id, 'course_id' => $course->id,
            'client_upload_id' => (string) Str::uuid(), 'purpose' => 'project_submission', 'owner_type' => 'project_submission', 'owner_id' => $submission->id,
            'storage_disk' => 'local', 'storage_path' => 'project-test/evidence.txt', 'original_file_name' => 'evidence.txt', 'mime_type' => 'text/plain',
            'size_bytes' => 22, 'sha256' => hash('sha256', 'Private project result'), 'status' => 'ready']);
        $page = Livewire::test(ViewProjectSubmission::class, ['record' => $submission->public_id])
            ->assertSee('التقرير الأصلي الذي لا يضيع')->assertSee('سؤال متابعة 62')->assertSee('evidence.txt');
        self::assertCount(61, $page->instance()->conversation());
        self::assertSame(route('admin.project-submissions.attachments.download', [$submission, $file]), $page->instance()->files()[0]['url']);
        $this->get(route('admin.project-submissions.attachments.download', [$submission, $file]))->assertOk()->assertDownload('rokn-file.txt');
        Http::assertNothingSent();
    }

    private function submission(): ProjectSubmission
    {
        $student = $this->user('client');
        $course = Course::query()->forceCreate(['tenant_id' => 1, 'name_ar' => 'كورس مراجعة', 'authoring_version' => 1]);
        $project = Project::query()->create(['requirements_text' => 'نفذ المشروع', 'passing_score' => 50]);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        CourseSection::query()->create(['course_id' => $course->id, 'module_id' => $module->id, 'title' => 'مشروع عملي',
            'section_type' => 'project', 'sectionable_type' => Project::class, 'sectionable_id' => $project->id, 'order' => 1]);

        return ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(), 'user_id' => $student->id, 'project_id' => $project->id,
            'idempotency_key' => (string) Str::uuid(), 'submission_text' => 'التنفيذ المرسل', 'review_status' => 'pending', 'effort_status' => 'valid', 'submitted_at' => now()]);
    }

    private function user(string $role): User
    {
        return User::query()->forceCreate(['name_ar' => 'مستخدم '.$role, 'email' => Str::uuid().'@project-review.test', 'role' => $role, 'active' => true]);
    }
}
