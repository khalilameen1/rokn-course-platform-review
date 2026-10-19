<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\ProjectSubmissionReviewService;
use App\Services\StaffRoleService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ModeratorProjectReviewPrivacyTest extends TestCase
{
    use RefreshDatabase;

    public function test_moderator_can_review_projects_without_receiving_student_identity(): void
    {
        [$moderator, $student, $submission] = $this->records();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->get(route('admin.project-submissions.index'))
            ->assertOk()
            ->assertSee($submission->public_id)
            ->assertDontSee($student->name_ar)
            ->assertDontSee($student->email);

        $this->actingAs($moderator)
            ->get(route('admin.project-submissions.show', $submission))
            ->assertOk()
            ->assertSee($submission->public_id)
            ->assertSee('هوية مخفية')
            ->assertDontSee($student->name_ar)
            ->assertDontSee($student->email);

        $this->actingAs($moderator)
            ->get(route('admin.project-submissions.index', ['search' => $student->email]))
            ->assertOk()
            ->assertDontSee($submission->public_id);
    }

    public function test_administrator_direct_request_keeps_identity_search_and_display(): void
    {
        [$moderator, $student, $submission] = $this->records();
        $administrator = $this->dashboardUser('admin', 'owner@example.test', 'مالك ركن');
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($administrator)
            ->get(route('admin.project-submissions.index', ['search' => $student->email]))
            ->assertOk()
            ->assertSee($submission->public_id)
            ->assertSee($student->name_ar)
            ->assertSee($student->email);
    }

    public function test_active_moderator_can_decide_a_submission(): void
    {
        [$moderator, $student, $submission] = $this->records();
        $this->withoutMiddleware(RequireAdminMfa::class);

        $this->actingAs($moderator)
            ->post(route('admin.project-submissions.reject', $submission), [
                'feedback' => 'أعد رفع لقطة واضحة للنتيجة',
            ])
            ->assertRedirect(route('admin.project-submissions.show', $submission));

        $submission->refresh();
        self::assertSame(ProjectSubmission::STATUS_NEEDS_RESUBMISSION, $submission->review_status);
        self::assertSame($moderator->id, $submission->reviewed_by);
    }

    public function test_rejection_requires_feedback_and_cannot_change_the_submission_without_it(): void
    {
        [$moderator, , $submission] = $this->records();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $before = $submission->fresh()->getRawOriginal();
        $this->actingAs($moderator)->post(route('admin.project-submissions.reject', $submission), ['feedback' => ''])
            ->assertSessionHasErrors('feedback');
        self::assertSame($before, $submission->fresh()->getRawOriginal());
    }

    public function test_finance_profile_cannot_use_the_project_review_writer_despite_legacy_admin_flag(): void
    {
        [, , $submission] = $this->records();
        $owner = $this->dashboardUser('admin', 'project-owner@roles.test', 'المالك');
        $finance = $this->dashboardUser('admin', 'project-finance@roles.test', 'المالية');
        $roles = app(StaffRoleService::class);
        $roles->seedDefaults();
        $roles->assign($owner, $finance->id, [(int) StaffRole::findByName('finance', 'web')->id]);
        try {
            app(ProjectSubmissionReviewService::class)->reviewByStaff($submission, $finance, false, 'لا يملك إدارة مراجعة المشاريع');
            self::fail('A finance-only permission profile must not write project review decisions.');
        } catch (AuthorizationException) {
            self::assertSame(ProjectSubmission::STATUS_PENDING, $submission->fresh()->review_status);
        }
    }

    public function test_assigned_content_editor_cannot_read_identity_using_legacy_admin_flag(): void
    {
        [, $student, $submission] = $this->records();
        $owner = $this->dashboardUser('admin', 'editor-owner@roles.test', 'المالك');
        $editor = $this->dashboardUser('admin', 'project-editor@roles.test', 'محرر المحتوى');
        $roles = app(StaffRoleService::class);
        $roles->seedDefaults();
        $roles->assign($owner, $editor->id, [(int) StaffRole::findByName('content_editor', 'web')->id]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($editor, 'web')->get(route('admin.project-submissions.index'))->assertOk()
            ->assertSee($submission->public_id)->assertDontSee($student->email)->assertDontSee($student->name_ar);
        $this->get(route('admin.project-submissions.show', $submission))->assertOk()
            ->assertSee('هوية مخفية')->assertDontSee($student->email)->assertDontSee($student->name_ar);
        $this->get(route('admin.project-submissions.index', ['search' => $student->email]))->assertOk()->assertDontSee($submission->public_id);
    }

    /** @return array{User, User, ProjectSubmission} */
    private function records(): array
    {
        $moderator = $this->dashboardUser('moderator', 'reviewer@example.test', 'مراجع المحتوى');
        $student = $this->dashboardUser('client', 'private.student@example.test', 'اسم طالب خاص');

        $course = new Course;
        $course->forceFill([
            'tenant_id' => 1,
            'name_ar' => 'كورس الاختبار',
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'authoring_version' => 1,
        ])->save();
        $project = Project::query()->create([
            'requirements_text' => 'نفذ المشروع',
            'ai_prompt' => 'قيّم النتيجة',
            'passing_score' => 50,
        ]);
        $module = CourseModule::query()->create([
            'course_id' => $course->id,
            'title_ar' => 'الوحدة الأولى',
            'order' => 1,
        ]);
        CourseSection::query()->create([
            'course_id' => $course->id,
            'module_id' => $module->id,
            'title' => 'المشروع',
            'section_type' => 'project',
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'order' => 1,
        ]);
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $student->id,
            'project_id' => $project->id,
            'idempotency_key' => 'privacy-contract',
            'submission_text' => 'ناتج المشروع',
            'effort_status' => ProjectSubmission::EFFORT_VALID,
            'review_status' => ProjectSubmission::STATUS_PENDING,
            'submitted_at' => now(),
        ]);

        return [$moderator, $student, $submission];
    }

    private function dashboardUser(string $role, string $email, string $name): User
    {
        $user = new User;
        $user->forceFill([
            'name_ar' => $name,
            'email' => $email,
            'role' => $role,
            'active' => true,
            'social_provider' => $role === 'client' ? 'google' : null,
            'social_id' => $role === 'client' ? 'student-social-id' : null,
        ])->save();

        return $user;
    }
}
