<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\CourseCompleted;
use App\Jobs\GenerateProjectFeedback;
use App\Http\Requests\Admin\CourseRequest;
use App\Http\Resources\BaseCourseResource;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Listeners\AwardLevelBadge;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseCode;
use App\Models\CourseChatTurn;
use App\Models\AiUsageEvent;
use App\Models\AiInputAttachment;
use App\Models\Contact;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\ProjectSubmissionService;
use App\Services\AiEntitlementBudgetService;
use App\Services\AiInputAttachmentService;
use App\Services\CourseChatAccessService;
use App\Services\CourseChatTurnService;
use App\Services\PaidAiCallExecutionService;
use App\Services\WalletService;
use App\Support\CourseAccessPlanSnapshot;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\AdminEditorVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class BackendHardeningTest extends TestCase
{
    /** @var list<string> */
    private array $tables = [
        'course_authoring_revision_entities', 'course_authoring_revisions',
        'student_section_progress', 'internal_signals', 'ai_input_attachments', 'account_file_deletions',
        'social_oauth_attempts',
        'product_feature_flags',
        'contacts', 'user_level', 'levels', 'user_project_evaluations',
        'project_submission_review_decisions', 'project_submissions',
        'course_grant_claims', 'course_code_usages', 'course_codes',
        'projects', 'wallet_transactions', 'ai_usage_events', 'ai_entitlement_usages',
        'course_enrollments', 'orders', 'course_access_plans',
        'classification_course', 'classifications', 'course_teacher', 'photos',
        'course_ratings', 'grades',
        'course_chat_turns', 'lesson_media_states', 'lessons', 'course_sections', 'course_modules', 'courses', 'paths', 'settings', 'users',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->createSchema();
        DB::table('product_feature_flags')->insert([
            'key' => 'ai_chat',
            'enabled' => true,
            'rollout_percentage' => 100,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Cache::flush();
        $this->withoutMiddleware(WebsiteVisitorCount::class);
    }

    protected function tearDown(): void
    {
        foreach ($this->tables as $table) {
            Schema::dropIfExists($table);
        }
        parent::tearDown();
    }

    public function test_wallet_is_idempotent_and_rejects_conflicting_replays(): void
    {
        $user = $this->user(['wallet_coins' => 200, 'wallet_purchased_coins' => 100, 'wallet_reward_coins' => 100]);
        $wallet = app(WalletService::class);

        $credit = $wallet->credit(
            $user->id,
            50,
            'test_credit',
            'wallet-test-credit',
            null,
            [],
            WalletTransaction::BUCKET_PAID
        );
        $replay = $wallet->credit(
            $user->id,
            50,
            'test_credit',
            'wallet-test-credit',
            null,
            [],
            WalletTransaction::BUCKET_PAID
        );

        self::assertSame($credit->id, $replay->id);
        self::assertSame(2, WalletTransaction::query()->count());

        $this->expectException(\UnexpectedValueException::class);
        $wallet->credit(
            $user->id,
            51,
            'test_credit',
            'wallet-test-credit',
            null,
            [],
            WalletTransaction::BUCKET_PAID
        );
    }

    public function test_wallet_keeps_paid_and_reward_attribution_on_debit(): void
    {
        $user = $this->user(['wallet_coins' => 200, 'wallet_purchased_coins' => 100, 'wallet_reward_coins' => 100]);

        $transaction = app(WalletService::class)->debit(
            $user->id,
            120,
            'course_purchase',
            'wallet-test-debit',
            null,
            [],
            40
        );

        self::assertSame(80, $transaction->paid_amount);
        self::assertSame(40, $transaction->reward_amount);
        self::assertSame(80, $transaction->balance_after);
        self::assertSame(20, $transaction->paid_balance_after);
        self::assertSame(60, $transaction->reward_balance_after);
    }

    public function test_wallet_rejects_a_projection_that_disagrees_with_its_ledger_tail(): void
    {
        $user = $this->user([
            'wallet_coins' => 100,
            'wallet_purchased_coins' => 40,
            'wallet_reward_coins' => 60,
        ]);
        $wallet = app(WalletService::class);
        $wallet->debit($user->id, 10, 'course_purchase', 'wallet-ledger-tail');

        $user->forceFill([
            'wallet_coins' => 95,
            'wallet_reward_coins' => 55,
        ])->save();

        $this->expectException(\LogicException::class);
        $wallet->credit(
            $user->id,
            1,
            'test_credit',
            'wallet-ledger-tail-next',
            null,
            [],
            WalletTransaction::BUCKET_REWARD
        );
    }

    public function test_wallet_requires_a_non_empty_idempotency_key(): void
    {
        $user = $this->user();

        $this->expectException(\InvalidArgumentException::class);
        app(WalletService::class)->credit(
            $user->id,
            1,
            'test_credit',
            '   ',
            null,
            [],
            WalletTransaction::BUCKET_REWARD
        );
    }

    public function test_wallet_refund_replay_returns_the_original_credit_after_allocation_is_consumed(): void
    {
        $user = $this->user([
            'wallet_coins' => 100,
            'wallet_purchased_coins' => 40,
            'wallet_reward_coins' => 60,
        ]);
        $wallet = app(WalletService::class);
        $debit = $wallet->debit(
            $user->id,
            100,
            'course_purchase',
            'wallet-refund-original-debit'
        );

        $refund = $wallet->refundDebit(
            $user->id,
            100,
            'course_purchase_refund',
            'wallet-refund-replay',
            $debit
        );
        $replay = $wallet->refundDebit(
            $user->id,
            100,
            'course_purchase_refund',
            'wallet-refund-replay',
            $debit
        );

        self::assertSame($refund->id, $replay->id);
        self::assertSame(60, (int) $refund->reward_amount);
        self::assertSame(40, (int) $refund->paid_amount);
        self::assertSame(100, (int) $user->fresh()->wallet_coins);
        self::assertSame(3, WalletTransaction::query()->count());
    }

    public function test_wallet_refund_cannot_exceed_the_unrefunded_debit_allocation(): void
    {
        $user = $this->user([
            'wallet_coins' => 100,
            'wallet_purchased_coins' => 40,
            'wallet_reward_coins' => 60,
        ]);
        $wallet = app(WalletService::class);
        $debit = $wallet->debit(
            $user->id,
            100,
            'course_purchase',
            'wallet-refund-partial-debit'
        );
        $wallet->refundDebit(
            $user->id,
            70,
            'course_purchase_refund',
            'wallet-refund-partial-first',
            $debit
        );

        $this->expectException(\InvalidArgumentException::class);
        $wallet->refundDebit(
            $user->id,
            31,
            'course_purchase_refund',
            'wallet-refund-partial-overrun',
            $debit
        );
    }

    public function test_wallet_refund_requires_a_persisted_original_debit(): void
    {
        $user = $this->user([
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
        $forgedDebit = new WalletTransaction([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'direction' => WalletTransaction::DIRECTION_DEBIT,
            'amount' => 100,
            'paid_amount' => 100,
            'reward_amount' => 0,
        ]);

        $this->expectException(\InvalidArgumentException::class);
        app(WalletService::class)->refundDebit(
            $user->id,
            100,
            'course_purchase_refund',
            'wallet-refund-forged-debit',
            $forgedDebit
        );
    }

    public function test_project_pending_is_authoritative_and_idempotency_cannot_change_content(): void
    {
        $user = $this->user();
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $this->grantProjectAccess($user, $project);
        $service = app(ProjectSubmissionService::class);

        $submission = $service->submit(
            $user,
            $project,
            'هذه محاولة حقيقية قابلة للمراجعة',
            null,
            'project-test-key'
        );
        $replay = $service->submit(
            $user,
            $project,
            'هذه محاولة حقيقية قابلة للمراجعة',
            null,
            'project-test-key'
        );

        self::assertSame(ProjectSubmission::STATUS_PENDING, $submission->review_status);
        self::assertSame($submission->id, $replay->id);
        $this->assertDatabaseMissing('user_project_evaluations', [
            'user_id' => $user->id,
            'project_id' => $project->id,
        ]);

        $this->expectException(\UnexpectedValueException::class);
        $service->submit(
            $user,
            $project,
            'محتوى مختلف بنفس المفتاح',
            null,
            'project-test-key'
        );
    }

    public function test_project_submission_freezes_published_requirements_without_legacy_ai_controls(): void
    {
        $user = $this->user();
        $project = Project::query()->create([
            'requirements_text' => 'نفذ النسخة الأصلية',
            'ai_prompt' => 'قيّم المتطلبات الأصلية فقط',
            'ai_model_type' => 'openai/original-model',
            'temperature' => .2,
            'tokens_number' => 240,
            'passing_score' => 65,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
        ]);
        $this->grantProjectAccess($user, $project);
        $submission = app(ProjectSubmissionService::class)->submit(
            $user,
            $project,
            'هذه محاولة حقيقية مرتبطة بالمتطلبات الأصلية',
            null,
            'frozen-project-review-policy'
        );

        $project->forceFill([
            'requirements_text' => 'متطلبات جديدة لا تخص هذه المحاولة',
            'ai_prompt' => 'تعليمات جديدة',
            'ai_model_type' => 'openai/new-model',
            'temperature' => .9,
            'tokens_number' => 900,
            'passing_score' => 90,
        ])->save();

        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission->fresh());
        self::assertNotNull($snapshot);
        self::assertSame('نفذ النسخة الأصلية', data_get($snapshot, 'project.requirements_text'));
        self::assertArrayNotHasKey('ai_prompt', $snapshot['project']);
        self::assertArrayNotHasKey('ai_model_type', $snapshot['project']);
        self::assertArrayNotHasKey('tokens_number', $snapshot['project']);
        self::assertArrayNotHasKey('passing_score', $snapshot['project']);

        $legacySnapshot = ProjectSubmissionEvaluationSnapshot::capture(
            $project,
            null,
            null,
            null
        );
        $legacySnapshot['version'] = 1;
        $legacySnapshot['project']['ai_prompt'] = 'تعليمات قديمة';
        $legacySnapshot['project']['temperature'] = .2;
        $legacySnapshot['project']['tokens_number'] = 240;
        $legacySnapshot['project']['passing_score'] = 65;
        unset(
            $legacySnapshot['course'],
            $legacySnapshot['project']['title'],
            $legacySnapshot['project']['title_ar'],
            $legacySnapshot['project']['title_en'],
            $legacySnapshot['fingerprint']
        );
        $legacySnapshot['fingerprint'] = hash('sha256', json_encode(
            $legacySnapshot,
            JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        ));
        $submission->forceFill(['evaluation_snapshot' => $legacySnapshot]);
        self::assertNull(ProjectSubmissionEvaluationSnapshot::fromSubmission($submission));
    }

    public function test_project_submission_service_rechecks_course_access_inside_its_transaction(): void
    {
        $user = $this->user();
        $course = $this->course();
        $project = Project::query()->create([
            'requirements_text' => 'مشروع داخل كورس لا يملكه الطالب',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $course->id,
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'section_type' => 'project',
            'title_ar' => 'مشروع محمي',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        try {
            app(ProjectSubmissionService::class)->submit(
                $user,
                $project,
                'محاولة لا ينبغي قبولها بلا فئة نشطة',
                null,
                'missing-course-entitlement'
            );
            self::fail('The service accepted a course project without an active enrollment.');
        } catch (AuthorizationException) {
            self::assertSame(0, ProjectSubmission::query()->count());
        }
    }

    public function test_effort_guard_writes_the_only_project_result(): void
    {
        $user = $this->user();
        $project = Project::query()->create([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
        ]);
        $this->grantProjectAccess($user, $project);
        $submission = app(ProjectSubmissionService::class)->submit(
            $user,
            $project,
            'قصير',
            null,
            'effort-guard-audit'
        );
        self::assertSame(ProjectSubmission::STATUS_NEEDS_RESUBMISSION, $submission->review_status);
        self::assertSame('effort_guard', $submission->review_source);
        self::assertSame(0, $submission->score);
        self::assertNotSame('', trim((string) $submission->feedback));
        self::assertNotNull($submission->reviewed_at);
    }

    public function test_submission_copies_the_access_plan_receipt_instead_of_following_later_changes(): void
    {
        $user = $this->user();
        $course = $this->course();
        $plan = $this->paidPlanTerms($course);
        $enrollment = \App\Models\CourseEnrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => $plan['snapshot'],
            'is_active' => true,
            'enrolled_at' => now(),
        ]);
        $project = Project::query()->create([
            'requirements_text' => 'مشروع له فئة ثابتة وقت التسليم',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
        ]);
        $sectionId = DB::table('course_sections')->insertGetId([
            'course_id' => $course->id,
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'section_type' => 'project',
            'title_ar' => 'مشروع التطبيق',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $submission = app(ProjectSubmissionService::class)->submit(
            $user,
            $project,
            'تسليم واضح تحت الفئة التي اشتريتها',
            null,
            'frozen-access-plan-review-policy'
        );
        $changedTerms = $plan['snapshot'];
        $changedTerms['name_ar'] = 'اسم لاحق لا يخص التسليم';
        $enrollment->forceFill(['access_plan_snapshot' => $changedTerms])->save();

        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission->fresh());
        self::assertNotNull($snapshot);
        self::assertSame($course->id, data_get($snapshot, 'course_id'));
        self::assertSame($sectionId, data_get($snapshot, 'section_id'));
        self::assertSame($course->name_ar, data_get($snapshot, 'course.title_ar'));
        self::assertSame('مشروع التطبيق', data_get($snapshot, 'project.title_ar'));
        self::assertSame($enrollment->id, data_get($snapshot, 'access.enrollment_id'));
        self::assertSame($plan['id'], data_get($snapshot, 'access.access_plan_id'));
        self::assertSame(
            $plan['snapshot']['name_ar'],
            data_get($snapshot, 'access.terms.name_ar')
        );
    }

    public function test_captured_project_entitlement_survives_drafting_the_next_course_revision(): void
    {
        $user = $this->user();
        $course = $this->course();
        $plan = $this->paidPlanTerms($course);
        $enrollment = \App\Models\CourseEnrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => $plan['snapshot'],
            'is_active' => true,
            'enrolled_at' => now(),
        ]);

        // Authoring state controls discovery and new admissions. It must not
        // erase an active paid contract captured by an accepted submission.
        $course->forceFill(['is_coming_soon' => true])->save();
        $access = app(\App\Services\CourseChatAccessService::class);

        self::assertNull($access->activeEnrollmentFor($user->id, $course->id));
        self::assertSame(
            $enrollment->id,
            $access->activeCapturedEnrollmentFor(
                $user->id,
                $course->id,
                $enrollment->id
            )?->id
        );

        $enrollment->forceFill(['is_active' => false])->save();
        self::assertNull($access->activeCapturedEnrollmentFor(
            $user->id,
            $course->id,
            $enrollment->id
        ));
    }

    public function test_admin_can_pass_project_submission_on_its_canonical_row(): void
    {
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $this->grantProjectAccess($student, $project);
        $submission = app(ProjectSubmissionService::class)->submit(
            $student,
            $project,
            'هذه محاولة تنتظر قرار المراجع الإداري',
            null,
            'admin-project-pass'
        );

        $this->actingAs($admin)
            ->post(route('admin.project-submissions.pass', $submission), [
                'feedback' => 'تنفيذ واضح ومستوفٍ للمتطلبات.',
            ])
            ->assertRedirect(route('admin.project-submissions.show', $submission));

        $submission->refresh();
        self::assertSame(ProjectSubmission::STATUS_PASSED, $submission->review_status);
        self::assertSame('admin_manual', $submission->review_source);
        self::assertSame(100, $submission->score);
        self::assertSame($admin->id, $submission->reviewed_by);
        self::assertNotNull($submission->reviewed_at);
        self::assertSame('تنفيذ واضح ومستوفٍ للمتطلبات.', $submission->feedback);
        self::assertTrue((bool) data_get($submission->submission_metadata, 'skill_verified'));
        $this->assertDatabaseMissing('user_project_evaluations', [
            'user_id' => $student->id,
            'project_id' => $project->id,
        ]);
    }

    public function test_graceful_project_fallback_grants_progress_without_claiming_a_skill_score(): void
    {
        Bus::fake();
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 30,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $this->grantProjectAccess($student, $project);
        $service = app(ProjectSubmissionService::class);
        $submission = $service->submit(
            $student,
            $project,
            'محاولة واضحة بذل فيها الطالب مجهودًا حقيقيًا',
            null,
            'graceful-participation-test'
        );
        $submission->forceFill(['auto_pass_at' => now()->subSecond()])->save();

        $submission = $service->finalizeIfDue($submission->fresh());

        self::assertSame(ProjectSubmission::STATUS_PASSED, $submission->review_status);
        self::assertSame('graceful_fallback', $submission->review_source);
        self::assertNull($submission->score);
        self::assertSame('participation', data_get($submission->submission_metadata, 'assessment_type'));
        self::assertFalse((bool) data_get($submission->submission_metadata, 'skill_verified'));
        self::assertNull(data_get($submission->submission_metadata, 'ai_feedback'));
        Bus::assertNotDispatched(GenerateProjectFeedback::class);
        $this->assertDatabaseMissing('user_project_evaluations', [
            'user_id' => $student->id,
            'project_id' => $project->id,
        ]);

        $submission = $service->reviewByStaff(
            $submission,
            $admin,
            true,
            'راجعها الفريق واعتمد جودة التنفيذ.'
        );
        self::assertSame('admin_manual', $submission->review_source);
        self::assertSame(100, $submission->score);
        self::assertTrue((bool) data_get($submission->submission_metadata, 'skill_verified'));
        self::assertSame('راجعها الفريق واعتمد جودة التنفيذ.', $submission->feedback);
    }

    public function test_client_duration_cannot_create_or_lower_the_completion_threshold(): void
    {
        $lesson = new \App\Models\Lesson(['duration_minutes' => null]);
        config()->set('learning_evidence.minimum_verified_seconds', 20);
        config()->set('learning_evidence.required_fraction', 0.80);

        $service = app(\App\Services\LearningEvidenceService::class);
        self::assertNull($service->requiredSeconds($lesson, 1));

        $lesson->duration_minutes = 1;
        $mediaState = new \App\Models\LessonMediaState([
            'duration_seconds' => 60,
        ]);
        $mediaState->exists = true;
        $lesson->setRelation('mediaState', $mediaState);
        self::assertSame(48, $service->requiredSeconds($lesson, 1));
        self::assertSame(48, $service->requiredSeconds($lesson, 3600));
    }

    public function test_admin_can_reject_pending_project_but_cannot_overwrite_final_decision(): void
    {
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $this->grantProjectAccess($student, $project);
        $service = app(ProjectSubmissionService::class);
        $submission = $service->submit(
            $student,
            $project,
            'هذه محاولة تحتاج إلى توضيح بعض الخطوات',
            null,
            'admin-project-reject'
        );

        $this->actingAs($admin)
            ->post(route('admin.project-submissions.reject', $submission), [
                'feedback' => 'أرفق صورة النتيجة واشرح الخطوة الأخيرة.',
            ])
            ->assertRedirect(route('admin.project-submissions.show', $submission));

        $submission->refresh();
        self::assertSame(ProjectSubmission::STATUS_NEEDS_RESUBMISSION, $submission->review_status);
        self::assertSame(0, $submission->score);
        self::assertSame($admin->id, $submission->reviewed_by);

        $this->expectException(ValidationException::class);
        $service->reviewByStaff($submission, $admin, true, 'محاولة تغيير القرار');
    }

    public function test_project_review_service_rejects_non_staff_reviewer(): void
    {
        $student = $this->user();
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $this->grantProjectAccess($student, $project);
        $service = app(ProjectSubmissionService::class);
        $submission = $service->submit(
            $student,
            $project,
            'محاولة لا يحق للطالب مراجعتها بنفسه',
            null,
            'non-admin-project-review'
        );

        try {
            $service->reviewByStaff($submission, $student, true);
            self::fail('A client was allowed to review a project submission.');
        } catch (AuthorizationException $exception) {
            self::assertSame(ProjectSubmission::STATUS_PENDING, $submission->fresh()->review_status);
        }
    }

    public function test_project_effort_guard_rejects_empty_documents_without_grading_real_work(): void
    {
        $service = app(ProjectSubmissionService::class);
        $detect = new \ReflectionMethod(ProjectSubmissionService::class, 'detectEffort');

        $emptyText = UploadedFile::fake()->createWithContent(
            'empty.txt',
            str_repeat(" \n", 300)
        );
        self::assertSame(
            ProjectSubmission::EFFORT_INVALID,
            $detect->invoke($service, null, [$emptyText])
        );

        $brokenPdf = UploadedFile::fake()->createWithContent(
            'broken.pdf',
            "%PDF-1.7\n".str_repeat('x', 700)."\n%%EOF"
        );
        self::assertSame(
            ProjectSubmission::EFFORT_INVALID,
            $detect->invoke($service, null, [$brokenPdf])
        );

        $realNote = UploadedFile::fake()->createWithContent(
            'work.txt',
            str_repeat('شرحت ما نفذته في المشروع والنتيجة التي وصلت إليها ', 20)
        );
        self::assertSame(
            ProjectSubmission::EFFORT_VALID,
            $detect->invoke($service, null, [$realNote])
        );
    }

    public function test_admin_downloads_project_file_from_private_submission_path(): void
    {
        Storage::fake('local');
        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $this->grantProjectAccess($student, $project);
        $submission = app(ProjectSubmissionService::class)->submit(
            $student,
            $project,
            'المحاولة لها ملف خاص لا يعرض من public storage',
            null,
            'admin-project-download'
        );
        $path = "project_submissions/{$student->id}/{$project->id}/stored-file.pdf";
        Storage::disk('local')->put($path, 'private project payload');
        $submission->update([
            'submission_file' => $path,
            'original_file_name' => '../../answer.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 23,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.project-submissions.download', $submission))
            ->assertOk()
            ->assertDownload('rokn-file.pdf');

        $submission->update(['submission_file' => '../outside-project-submissions.txt']);
        $this->actingAs($admin)
            ->get(route('admin.project-submissions.download', $submission))
            ->assertNotFound();
    }

    public function test_project_submission_keeps_and_reads_the_exact_shared_disk_used_at_upload(): void
    {
        $sharedRoot = sys_get_temp_dir().DIRECTORY_SEPARATOR
            .'rokn-project-test-'.bin2hex(random_bytes(6));
        config()->set('filesystems.disks.project-shared', [
            'driver' => 'local',
            'root' => $sharedRoot,
            'throw' => false,
        ]);
        app('filesystem')->forgetDisk('project-shared');
        config()->set('projects.submission_disk', 'project-shared');

        $student = $this->user();
        $admin = $this->user(['role' => 'admin']);
        $projectId = DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'passing_score' => 50,
            'fallback_review_delay_seconds' => 300,
            'is_graduation_project' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $project = Project::query()->findOrFail($projectId);
        $this->grantProjectAccess($student, $project);
        $file = UploadedFile::fake()->createWithContent(
            'answer.pdf',
            "%PDF-1.4\n1 0 obj\n<< /Type /Page >>\nendobj\n"
                . str_repeat("% learner project evidence\n", 32)
                . "%%EOF"
        );

        $submission = app(ProjectSubmissionService::class)->submit(
            $student,
            $project,
            null,
            [$file],
            'shared-storage-submission'
        );

        self::assertSame(
            'project-shared',
            data_get($submission->submission_metadata, 'storage_disk')
        );
        self::assertIsArray(config('filesystems.disks.project-shared'));
        self::assertSame('project-shared', $submission->submission_disk);
        Storage::disk('project-shared')->assertExists($submission->submission_file);

        // A later process can have a different default and must still read the
        // immutable disk recorded with the submission.
        config()->set('projects.submission_disk', 'local');
        $this->actingAs($admin)
            ->get(route('admin.project-submissions.download', $submission))
            ->assertOk()
            ->assertDownload('rokn-file.pdf');

        Storage::disk('project-shared')->deleteDirectory('');
    }

    public function test_badge_course_requires_an_existing_level(): void
    {
        $rules = (new CourseRequest())->rules();
        $missing = Validator::make([
            'name_ar' => 'كورس شارات',
            'awards_badge' => 1,
            'badge_track' => 'professional',
            'certificate_text_template_key' => 'completion',
        ], $rules);
        self::assertTrue($missing->errors()->has('level_id'));

        $invalid = Validator::make([
            'name_ar' => 'كورس شارات',
            'awards_badge' => 1,
            'badge_track' => 'professional',
            'level_id' => 999999,
            'certificate_text_template_key' => 'completion',
        ], $rules);
        self::assertTrue($invalid->errors()->has('level_id'));

        $levelId = DB::table('levels')->insertGetId([
            'name_ar' => 'مبتدئ',
            'name_en' => 'Beginner',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $valid = Validator::make([
            'name_ar' => 'كورس شارات',
            'awards_badge' => 1,
            'badge_track' => 'professional',
            'level_id' => $levelId,
            'certificate_text_template_key' => 'completion',
        ], $rules);
        self::assertFalse($valid->fails());
    }

    public function test_public_deletion_request_is_typed_and_cannot_spoof_resolution_audit(): void
    {
        $this->post('/account-deletion', [
            'name' => 'Deletion Test',
            'email' => '  Delete.Me@Example.COM ',
            'phone' => '+201000000000',
            'reason' => 'لم أعد أستخدم الحساب',
            'confirm' => '1',
            'resolution_status' => Contact::RESOLUTION_FULFILLED,
            'resolved_by' => 999,
            'resolution_metadata' => ['spoofed' => true],
        ])->assertRedirect(route('account-deletion.show'));

        $contact = Contact::query()->latest('id')->firstOrFail();
        self::assertSame('delete.me@example.com', $contact->email);
        self::assertSame(Contact::TYPE_ACCOUNT_DELETION, $contact->request_type);
        self::assertFalse($contact->read);
        self::assertSame(Contact::RESOLUTION_PENDING, $contact->resolution_status);
        self::assertNull($contact->resolved_by);
        self::assertNull($contact->resolution_metadata);

        $ordinary = Contact::create([
            'name' => 'Ordinary Contact',
            'email' => 'ordinary@example.com',
            'phone' => '201000000001',
            'message' => 'A sufficiently long message',
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
            'resolution_status' => Contact::RESOLUTION_FULFILLED,
        ]);
        self::assertNull($ordinary->request_type);
        self::assertNull($ordinary->resolution_status);
    }

    public function test_admin_cannot_delete_account_deletion_request_audit_record(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $contact = new Contact();
        $contact->forceFill([
            'name' => 'Deletion Test',
            'email' => 'delete.audit@example.com',
            'phone' => '-',
            'message' => "[ACCOUNT_DELETION_REQUEST]\nReference: DEL-TEST",
            'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.contacts.show', $contact))
            ->delete(route('admin.contacts.destroy', $contact), [
                'editor_version' => $this->contactEditorVersion($contact),
            ])
            ->assertRedirect(route('admin.contacts.show', $contact));

        $this->assertDatabaseHas('contacts', [
            'id' => $contact->id,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
        ]);
    }

    public function test_admin_can_process_and_close_deletion_request_without_deleting_account(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $account = $this->user(['email' => 'owner@example.com']);
        $contact = new Contact();
        $contact->forceFill([
            'name' => 'Owner',
            'email' => 'owner@example.com',
            'phone' => '-',
            'message' => "[ACCOUNT_DELETION_REQUEST]\nReference: DEL-WORKFLOW",
            'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
            'resolution_status' => Contact::RESOLUTION_PENDING,
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.contacts.show', $contact))
            ->post(route('admin.contacts.processing', $contact), [
                'editor_version' => $this->contactEditorVersion($contact),
            ])
            ->assertRedirect(route('admin.contacts.show', $contact));
        self::assertSame(Contact::RESOLUTION_PROCESSING, $contact->fresh()->resolution_status);

        $this->actingAs($admin)
            ->from(route('admin.contacts.show', $contact))
            ->post(route('admin.contacts.close-deletion-request', $contact), [
                'editor_version' => $this->contactEditorVersion($contact->fresh()),
                'outcome' => 'duplicate',
                'resolution_note' => 'تم ربطه بالطلب الأصلي.',
                'confirm_close' => '1',
            ])
            ->assertRedirect(route('admin.contacts.show', $contact));

        $contact->refresh();
        self::assertSame(Contact::RESOLUTION_CLOSED, $contact->resolution_status);
        self::assertSame($admin->id, $contact->resolved_by);
        self::assertSame($account->id, $contact->resolved_user_id);
        self::assertSame('duplicate', data_get($contact->resolution_metadata, 'outcome'));
        self::assertNotNull($contact->resolved_at);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'email' => 'owner@example.com']);
    }

    public function test_admin_cannot_claim_self_service_deletion_while_matching_account_exists(): void
    {
        $admin = $this->user(['role' => 'admin']);
        $account = $this->user(['email' => 'still-active@example.com']);
        $contact = new Contact();
        $contact->forceFill([
            'name' => 'Owner',
            'email' => $account->email,
            'phone' => '-',
            'message' => "[ACCOUNT_DELETION_REQUEST]\nReference: DEL-GUARD",
            'read' => false,
            'request_type' => Contact::TYPE_ACCOUNT_DELETION,
            'resolution_status' => Contact::RESOLUTION_PROCESSING,
        ])->save();

        $this->actingAs($admin)
            ->from(route('admin.contacts.show', $contact))
            ->post(route('admin.contacts.close-deletion-request', $contact), [
                'editor_version' => $this->contactEditorVersion($contact),
                'outcome' => 'self_service_completed',
                'confirm_close' => '1',
            ])
            ->assertRedirect(route('admin.contacts.show', $contact));

        self::assertSame(Contact::RESOLUTION_PROCESSING, $contact->fresh()->resolution_status);
        $this->assertDatabaseHas('users', ['id' => $account->id, 'email' => 'still-active@example.com']);
    }

    public function test_course_code_grant_cannot_consume_ai_but_paid_enrollment_can(): void
    {
        $user = $this->user();
        $course = $this->course();
        $grantOrder = $this->order($user, $course, Order::PAYMENT_METHOD_COURSE_CODE, 0, 0);
        $grantCode = CourseCode::query()->create([
            'code' => 'GRANT-CHAT-' . $course->id,
            'type' => 'course',
            'course_id' => $course->id,
            'is_grant' => true,
            'is_active' => true,
            'max_uses' => 10,
        ]);
        $grantOrder->forceFill(['course_code_id' => $grantCode->id])->save();
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $grantOrder->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', ['message' => 'اشرح الفكرة'])
            ->assertStatus(403)
            ->assertJsonPath('code', 'chat_upgrade_required');

        $paidOrder = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $plan = $this->paidPlanTerms($course);
        DB::table('course_enrollments')->where('id', $enrollmentId)->update([
            'order_id' => $paidOrder->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => json_encode($plan['snapshot'], JSON_THROW_ON_ERROR),
        ]);
        config()->set('openrouter.api_key', 'test-key');
        config()->set('openrouter.default_model', 'test/model');
        config()->set('openrouter.allowed_models', ['test/model']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'الإجابة المختصرة']]]], 200),
        ]);

        $requestId = (string) Str::uuid();
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', [
                'message' => 'اشرح الفكرة',
                'client_request_id' => $requestId,
            ])
            ->assertOk()
            ->assertJsonPath('code', 'chat_answer_in_progress');
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', [
                'message' => 'اشرح الفكرة',
                'client_request_id' => $requestId,
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'الإجابة المختصرة');
        Http::assertSentCount(1);
    }

    public function test_course_details_entitlement_marks_grants_and_paid_access_authoritatively(): void
    {
        $user = $this->user();
        $course = $this->course(['ai_model_type' => 'test/model']);
        $grantOrder = $this->order($user, $course, Order::PAYMENT_METHOD_COURSE_CODE, 0, 0);
        $grantCode = CourseCode::query()->create([
            'code' => 'GRANT-DETAILS-' . $course->id,
            'type' => 'course',
            'course_id' => $course->id,
            'is_grant' => true,
            'is_active' => true,
            'max_uses' => 10,
        ]);
        $grantOrder->forceFill(['course_code_id' => $grantCode->id])->save();
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $grantOrder->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $access = app(CourseChatAccessService::class)->entitlementFor($user->id, $course->id);
        self::assertSame('scholarship', $access['access_type']);
        self::assertFalse($access['chat_available']);

        $grantPayload = (new BaseCourseResource($course))
            ->withEntitlement($access['access_type'], $access['chat_available'])
            ->resolve();
        self::assertSame('scholarship', $grantPayload['access_type']);
        self::assertFalse($grantPayload['chat_available']);
        self::assertFalse($grantPayload['metadata']['chat_available']);
        self::assertSame(0, $access['chat_message_limit']);

        $paidOrder = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $plan = $this->paidPlanTerms($course);
        DB::table('course_enrollments')->where('user_id', $user->id)->update([
            'order_id' => $paidOrder->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => json_encode($plan['snapshot'], JSON_THROW_ON_ERROR),
        ]);

        $paidAccess = app(CourseChatAccessService::class)->entitlementFor($user->id, $course->id);
        self::assertSame('paid', $paidAccess['access_type']);
        self::assertTrue($paidAccess['chat_available']);
        self::assertSame(25, $paidAccess['chat_message_limit']);

        $course->update(['ai_chat_enabled' => false]);
        $disabledAccess = app(CourseChatAccessService::class)->entitlementFor($user->id, $course->id);
        self::assertSame('paid', $disabledAccess['access_type']);
        // Legacy per-course switches cannot rewrite an immutable purchased
        // tier. Variable-cost provenance and the plan receipt are authoritative.
        self::assertTrue($disabledAccess['chat_available']);

        $freeUser = $this->user(['email' => 'free@example.com']);
        $freeCourse = $this->course(['price' => 0]);
        $freeOrder = $this->order($freeUser, $freeCourse, Order::PAYMENT_METHOD_WALLET_COINS, 0, 0);
        DB::table('course_enrollments')->insert([
            'user_id' => $freeUser->id,
            'course_id' => $freeCourse->id,
            'order_id' => $freeOrder->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $freeAccess = app(CourseChatAccessService::class)->entitlementFor($freeUser->id, $freeCourse->id);
        self::assertSame('free', $freeAccess['access_type']);
        self::assertFalse($freeAccess['chat_available']);
        self::assertSame(0, $freeAccess['chat_message_limit']);
    }

    public function test_global_ai_policy_rewrites_the_complete_offer_contract_atomically(): void
    {
        $course = $this->course();
        $plan = CourseAccessPlan::query()
            ->where('course_id', $course->id)
            ->where('code', CourseAccessPlan::GUIDED)
            ->firstOrFail();
        $originalPrice = (int) $plan->price_coins;

        app(\App\Services\CourseAccessPlanService::class)->syncGlobalAiPolicy([
            'guided' => [
                'chat_enabled' => false,
                'chat_message_limit' => 0,
                'chat_attachments_enabled' => false,
                'project_feedback_level' => 'report',
                'project_followup_message_limit' => 0,
            ],
        ]);

        $plan->refresh();
        self::assertSame($originalPrice, (int) $plan->price_coins);
        self::assertFalse((bool) $plan->chat_enabled);
        self::assertSame(0, (int) $plan->chat_message_limit);
        self::assertSame(0, (int) $plan->chat_token_budget);
        self::assertSame('0.000000', (string) $plan->ai_budget_usd);
        self::assertSame(CourseAccessPlan::FEEDBACK_REPORT, $plan->project_feedback_level);
        self::assertGreaterThan(0, (int) $plan->project_feedback_token_budget);
        self::assertSame(0, (int) $plan->project_followup_message_limit);
        self::assertFalse((bool) $plan->project_followup_attachments_enabled);

        $snapshot = app(\App\Services\CourseAccessPlanService::class)->snapshot($plan);
        CourseAccessPlanSnapshot::assertValidForPlan((int) $plan->id, $snapshot);
    }

    public function test_course_chat_capacity_is_scoped_to_the_purchased_plan(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $plan = $this->paidPlanTerms($course);
        DB::table('course_enrollments')->insert([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => json_encode($plan['snapshot'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        config()->set('openrouter.api_key', 'test-key');
        config()->set('openrouter.default_model', 'test/model');
        config()->set('openrouter.allowed_models', ['test/model']);
        Http::fake([
            '*' => Http::response(['choices' => [['message' => ['content' => 'رد']]]], 200),
        ]);

        $firstRequestId = (string) Str::uuid();
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', [
                'message' => 'السؤال الأول',
                'client_request_id' => $firstRequestId,
            ])
            ->assertOk()
            ->assertJsonPath('code', 'chat_answer_in_progress');
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', [
                'message' => 'السؤال الأول',
                'client_request_id' => $firstRequestId,
            ])
            ->assertOk()
            ->assertJsonPath('data.unavailable', false);
        $this->actingAs($user, 'api')
            ->postJson('/api/v1/courses/' . $course->id . '/chat', [
                'message' => 'السؤال الثاني',
                'client_request_id' => (string) Str::uuid(),
            ])
            ->assertOk()
            ->assertJsonPath('code', 'chat_answer_in_progress')
            ->assertJsonPath('data.poll_window_seconds', 95);
        Http::assertSentCount(2);
    }

    public function test_course_chat_context_is_server_owned_and_scoped(): void
    {
        $user = $this->user();
        $course = $this->course();
        $turns = app(CourseChatTurnService::class);
        $first = $turns->begin(
            $user->id,
            $course->id,
            null,
            null,
            '1f87903b-6035-4d5d-bb12-c6f796a71f47',
            'أريد كتابة عرض خدمة',
            'ar',
            'prompt-v1'
        );
        $turns->complete($first, 'ابدأ بالنتيجة التي تقدمها');
        $current = $turns->begin(
            $user->id,
            $course->id,
            null,
            null,
            '16e28c35-c2db-437d-aa3a-094111bec808',
            'وماذا أفعل بعدها؟',
            'ar',
            'prompt-v1'
        );

        self::assertSame([
            ['role' => 'user', 'content' => 'أريد كتابة عرض خدمة'],
            ['role' => 'assistant', 'content' => 'ابدأ بالنتيجة التي تقدمها'],
        ], $turns->context(
            $user->id,
            $course->id,
            null,
            'ar',
            'prompt-v1',
            $current->id
        ));
        self::assertSame([], $turns->context(
            $user->id,
            $course->id,
            null,
            'en',
            'prompt-v1',
            $current->id
        ));
    }

    public function test_course_chat_replay_presents_a_settled_answer_without_a_second_provider_call(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $plan = $this->paidPlanTerms($course);
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => json_encode($plan['snapshot'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestId = '4e52629a-8452-4636-8e8f-84d3ed611cf4';
        $question = 'أعد لي النتيجة المحفوظة';
        $promptVersion = app(\App\Services\CourseChatPromptContextService::class)
            ->version($course);
        $turn = app(CourseChatTurnService::class)->begin(
            $user->id,
            $course->id,
            $enrollmentId,
            null,
            $requestId,
            $question,
            'ar',
            $promptVersion
        );
        $usage = AiUsageEvent::query()->create([
            'request_id' => $requestId,
            'enrollment_id' => $enrollmentId,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'feature' => 'course_chat',
            'status' => 'completed',
            'metadata' => [
                'accepted_response' => 'هذه هي الإجابة المحفوظة',
                'request_context' => [
                    'question_hash' => hash('sha256', $question.'|'),
                    'lesson_id' => null,
                    'language' => 'ar',
                    'prompt_version' => $promptVersion,
                ],
            ],
            'completed_at' => now(),
        ]);
        Http::fake();

        $this->withHeader('Accept-Language', 'ar')
            ->actingAs($user, 'api')
            ->postJson('/api/v1/courses/'.$course->id.'/chat', [
                'message' => $question,
                'client_request_id' => $requestId,
            ])
            ->assertOk()
            ->assertJsonPath('data.message', 'هذه هي الإجابة المحفوظة')
            ->assertJsonPath('data.turn_status', CourseChatTurn::COMPLETED);

        self::assertSame(CourseChatTurn::COMPLETED, $turn->fresh()->status);
        self::assertNull(data_get($usage->fresh()->metadata, 'accepted_response'));
        self::assertNotNull(data_get($usage->fresh()->metadata, 'presentation_completed_at'));
        Http::assertNothingSent();
    }

    public function test_stalled_course_chat_turns_are_closed_without_touching_live_turns(): void
    {
        $user = $this->user();
        $course = $this->course();
        $turns = app(CourseChatTurnService::class);
        $stalled = $turns->begin(
            $user->id,
            $course->id,
            null,
            null,
            '4bb3c73d-2e28-4f0c-8ca6-c5dc244944f0',
            'طلب انقطع أثناء الإجابة',
            'ar',
            'prompt-v1'
        );
        $live = $turns->begin(
            $user->id,
            $course->id,
            null,
            null,
            '7fbde4e9-2252-49f1-a791-b00308163be9',
            'طلب جارٍ الآن',
            'ar',
            'prompt-v1'
        );
        $turns->markStreaming($stalled);
        $stalled->forceFill(['updated_at' => now()->subMinutes(10)])->save();
        $stalledAt = $stalled->fresh()->updated_at;

        // A recovery poll observes the lease; it is not worker progress and
        // must not refresh an abandoned turn forever.
        $turns->markStreaming($stalled);
        self::assertTrue($stalledAt->equalTo($stalled->fresh()->updated_at));

        self::assertSame(1, $turns->failStalled());
        self::assertSame('failed', $stalled->fresh()->status);
        self::assertSame('chat_request_abandoned', $stalled->fresh()->error_code);
        self::assertSame('queued', $live->fresh()->status);
    }

    public function test_course_chat_polling_does_not_claim_or_refresh_the_worker_lease(): void
    {
        $user = $this->user();
        $course = $this->course();
        $requestId = 'c367557e-8cc7-428e-8735-60ada7d5d54d';
        $turn = app(CourseChatTurnService::class)->begin(
            $user->id,
            $course->id,
            null,
            null,
            $requestId,
            'هل بدأ العامل؟',
            'ar',
            'prompt-v1'
        );
        $turn->forceFill(['updated_at' => now()->subSeconds(10)])->save();
        $observedAt = $turn->fresh()->updated_at;

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/course-chat/turns/'.$requestId)
            ->assertOk()
            ->assertJsonPath('code', 'chat_answer_in_progress')
            ->assertJsonPath('data.turn_status', CourseChatTurn::QUEUED);

        $turn->refresh();
        self::assertSame(CourseChatTurn::QUEUED, $turn->status);
        self::assertTrue($observedAt->equalTo($turn->updated_at));
    }

    public function test_stalled_course_chat_recovers_an_already_settled_answer(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestId = 'b1644f1f-21ff-4a52-bfc3-cf98fd87a388';
        $turns = app(CourseChatTurnService::class);
        $turn = $turns->begin(
            $user->id,
            $course->id,
            $enrollmentId,
            null,
            $requestId,
            'هل اكتملت الإجابة؟',
            'ar',
            'prompt-v1'
        );
        $turns->markStreaming($turn);
        $usageId = DB::table('ai_usage_events')->insertGetId([
            'request_id' => $requestId,
            'enrollment_id' => $enrollmentId,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'feature' => 'course_chat',
            'status' => 'completed',
            'metadata' => json_encode([
                'accepted_response' => 'الإجابة محفوظة بالفعل',
            ], JSON_THROW_ON_ERROR),
            'completed_at' => now()->subMinutes(5),
            'created_at' => now()->subMinutes(6),
            'updated_at' => now()->subMinutes(5),
        ]);
        $turn->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        self::assertSame(1, $turns->failStalled());
        $recovered = $turn->fresh();
        self::assertSame('completed', $recovered->status);
        self::assertSame('الإجابة محفوظة بالفعل', $recovered->answer);
        self::assertSame($usageId, (int) $recovered->usage_event_id);
        self::assertNull($recovered->error_code);
        self::assertSame(
            'completed',
            $turns->begin(
                $user->id,
                $course->id,
                $enrollmentId,
                null,
                $requestId,
                'هل اكتملت الإجابة؟',
                'ar',
                'prompt-v1'
            )->status
        );
        self::assertSame(
            'الإجابة محفوظة بالفعل',
            $turns->page($user->id, $course->id, null)->items()[0]->answer
        );
    }

    public function test_stalled_course_chat_does_not_close_a_live_entitlement_lease(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestId = '0354f9a0-f2b4-4670-9bf7-e30430374097';
        $turns = app(CourseChatTurnService::class);
        $turn = $turns->begin(
            $user->id,
            $course->id,
            $enrollmentId,
            null,
            $requestId,
            'طلب ما زال داخل مهلة المزود',
            'ar',
            'prompt-v1'
        );
        $turns->markStreaming($turn);
        DB::table('ai_usage_events')->insert([
            'request_id' => $requestId,
            'enrollment_id' => $enrollmentId,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'feature' => 'course_chat',
            'status' => 'reserved',
            'metadata' => json_encode([
                'provider_call_state' => 'started',
                'provider_call_started_at' => now()->toIso8601String(),
            ], JSON_THROW_ON_ERROR),
            'reservation_expires_at' => now()->addMinute(),
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);
        $turn->forceFill(['updated_at' => now()->subMinutes(10)])->save();

        self::assertSame(0, $turns->failStalled());
        self::assertSame('streaming', $turn->fresh()->status);
    }

    public function test_unknown_course_chat_provider_outcome_releases_the_reservation_without_using_a_message(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('ai_entitlement_usages')->insert([
            'enrollment_id' => $enrollmentId,
            'feature' => 'course_chat',
            'used_requests' => 0,
            'reserved_requests' => 1,
            'used_tokens' => 0,
            'reserved_tokens' => 320,
            'used_cost_usd' => 0,
            'reserved_cost_usd' => 0.02,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $event = AiUsageEvent::query()->create([
            'request_id' => 'f1a86104-3287-4497-af62-f02c773795e2',
            'enrollment_id' => $enrollmentId,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'feature' => 'course_chat',
            'status' => 'reserved',
            'reserved_tokens' => 320,
            'reserved_cost_usd' => 0.02,
            'reservation_expires_at' => now()->addMinute(),
            'metadata' => [
                'provider_call_state' => 'started',
                'provider_call_started_at' => now()->toIso8601String(),
            ],
        ]);

        app(PaidAiCallExecutionService::class)->settleUnknown(
            app(AiEntitlementBudgetService::class),
            $event,
            ['course_id' => $course->id],
            'stream_disconnected_after_provider_start'
        );

        $usage = DB::table('ai_entitlement_usages')
            ->where('enrollment_id', $enrollmentId)
            ->where('feature', 'course_chat')
            ->first();
        self::assertSame(0, (int) $usage->used_requests);
        self::assertSame(0, (int) $usage->reserved_requests);
        self::assertSame(0, (int) $usage->used_tokens);
        self::assertSame(0, (int) $usage->reserved_tokens);
        self::assertSame('completed', $event->fresh()->status);
        self::assertFalse((bool) data_get($event->fresh()->metadata, 'entitlement_delivered'));
    }

    public function test_failed_course_chat_keeps_its_durable_partial_in_status_and_history(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $plan = $this->paidPlanTerms($course);
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => json_encode($plan['snapshot'], JSON_THROW_ON_ERROR),
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestId = '3951a571-53db-43b6-84d6-4af2a77162d5';
        $turns = app(CourseChatTurnService::class);
        $turn = $turns->begin(
            $user->id,
            $course->id,
            $enrollmentId,
            null,
            $requestId,
            'اشرح الخطوة التالية',
            'ar',
            'prompt-v1'
        );
        $turns->markStreaming($turn);
        app(\App\Services\AiStreamCheckpointService::class)->courseChat(
            $turn->fresh(),
            'ابدأ بتجهيز الملف ثم راجع'
        );
        $turns->fail($turn->fresh(), 'chat_provider_outcome_unknown');
        $expected = "ابدأ بتجهيز الملف ثم راجع\n\nتوقف الرد قبل أن يكتمل";

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/course-chat/turns/'.$requestId)
            ->assertOk()
            ->assertJsonPath('data.turn_status', CourseChatTurn::FAILED)
            ->assertJsonPath('data.message', $expected)
            ->assertJsonPath('data.partial', true)
            ->assertJsonPath('data.can_retry', false);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/course-chat/messages?course_id='.$course->id)
            ->assertOk()
            ->assertJsonPath('data.messages.1.delivery_status', CourseChatTurn::FAILED)
            ->assertJsonPath('data.messages.1.text', $expected)
            ->assertJsonPath('data.messages.1.partial', true)
            ->assertJsonPath('data.messages.1.context_eligible', false);
    }

    public function test_cancelled_course_chat_status_remains_a_clean_terminal_result(): void
    {
        $user = $this->user();
        $course = $this->course();
        $requestId = '0d2210e7-13fa-4f03-893d-adca70606274';
        $turns = app(CourseChatTurnService::class);
        $turn = $turns->begin(
            $user->id,
            $course->id,
            null,
            null,
            $requestId,
            'أوقف هذا الرد',
            'ar',
            'prompt-v1'
        );

        $this->actingAs($user, 'api')
            ->deleteJson('/api/v1/course-chat/turns/'.$requestId)
            ->assertOk()
            ->assertJsonPath('code', 'chat_turn_cancelled');

        self::assertFalse($turns->markStreaming($turn->fresh()));
        self::assertFalse($turns->complete($turn->fresh(), 'رد وصل بعد الإلغاء'));
        $turns->fail($turn->fresh(), 'late_worker_failure');

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/course-chat/turns/'.$requestId)
            ->assertOk()
            ->assertJsonPath('data.turn_status', CourseChatTurn::CANCELLED)
            ->assertJsonPath('data.message', 'تم إيقاف الرد')
            ->assertJsonPath('data.can_retry', false);
    }

    public function test_failed_course_chat_turn_cannot_be_reopened_or_rewritten_as_cancelled(): void
    {
        $user = $this->user();
        $course = $this->course();
        $requestId = '54989560-b545-4eb2-a8ea-a2f2fdc6a095';
        $turns = app(CourseChatTurnService::class);
        $turn = $turns->begin(
            $user->id,
            $course->id,
            null,
            null,
            $requestId,
            'محاولة انتهت قبل وصول العامل',
            'ar',
            'prompt-v1'
        );
        $turns->failBeforeDispatch($turn, 'chat_dispatch_failed');

        self::assertFalse($turns->markStreaming($turn->fresh()));
        self::assertFalse($turns->complete($turn->fresh(), 'رد متأخر'));
        self::assertSame('terminal', $turns->cancelForUser($user->id, $requestId));
        self::assertSame(CourseChatTurn::FAILED, $turn->fresh()->status);
        self::assertSame('chat_dispatch_failed', $turn->fresh()->error_code);
    }

    public function test_course_chat_poll_repairs_a_terminal_provider_event_immediately(): void
    {
        $user = $this->user();
        $course = $this->course();
        $order = $this->order($user, $course, Order::PAYMENT_METHOD_WALLET_COINS, 4000, 4000);
        $enrollmentId = DB::table('course_enrollments')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'order_id' => $order->id,
            'is_active' => true,
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $requestId = 'de1480ea-4560-4eeb-9394-16d8526be593';
        $turns = app(CourseChatTurnService::class);
        $turn = $turns->begin(
            $user->id,
            $course->id,
            $enrollmentId,
            null,
            $requestId,
            'هل ستظل المحاولة معلقة؟',
            'ar',
            'prompt-v1'
        );
        $turns->markStreaming($turn);
        DB::table('ai_usage_events')->insert([
            'request_id' => $requestId,
            'enrollment_id' => $enrollmentId,
            'user_id' => $user->id,
            'course_id' => $course->id,
            'feature' => 'course_chat',
            'status' => 'failed',
            'metadata' => json_encode(['reason' => 'provider_unavailable'], JSON_THROW_ON_ERROR),
            'completed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $reconciled = $turns->reconcileTerminalUsage($turn->fresh());

        self::assertSame(CourseChatTurn::FAILED, $reconciled?->status);
        self::assertSame('ai_temporarily_unavailable', $reconciled?->error_code);
        self::assertNotNull($reconciled?->completed_at);
    }

    public function test_paid_ai_boundaries_reject_a_different_active_user(): void
    {
        $owner = $this->user();
        $other = $this->user();
        $course = $this->course();
        $event = AiUsageEvent::query()->create([
            'request_id' => (string) Str::uuid(),
            'enrollment_id' => 9001,
            'user_id' => $owner->id,
            'course_id' => $course->id,
            'feature' => 'course_chat',
            'status' => 'reserved',
            'reserved_tokens' => 100,
            'reserved_cost_usd' => 0.01,
            'reservation_expires_at' => now()->addMinute(),
        ]);
        $calls = app(PaidAiCallExecutionService::class);

        self::assertSame(
            PaidAiCallExecutionService::TERMINAL,
            $calls->beginForActiveUser($event, 'misrouted-worker', (int) $other->id)
        );
        self::assertNull(data_get($event->fresh()->metadata, 'provider_call_state'));
        self::assertSame(
            PaidAiCallExecutionService::TERMINAL,
            $calls->landSuccessfulResultForActiveUser(
                $event,
                'misrouted-worker',
                (int) $other->id,
                ['message' => 'must not land']
            )
        );
        self::assertSame('reserved', $event->fresh()->status);
        self::assertSame(
            AiEntitlementBudgetService::SETTLEMENT_TERMINAL_CONFLICT,
            app(AiEntitlementBudgetService::class)->settleForActiveUser(
                $event,
                ['message' => 'must not settle'],
                (int) $other->id
            )
        );
        self::assertSame('reserved', $event->fresh()->status);
    }

    public function test_social_completion_rejects_untrusted_provider_and_consumes_code_once(): void
    {
        $code = str_repeat('a', 64);
        $verifier = str_repeat('v', 64);
        $challenge = rtrim(strtr(
            base64_encode(hash('sha256', $verifier, true)),
            '+/',
            '-_'
        ), '=');
        DB::table('social_oauth_attempts')->insert([
            'state_hash' => hash('sha256', 'state-'.$code),
            'completion_hash' => hash('sha256', $code),
            'provider' => 'untrusted-provider',
            'encrypted_token' => Crypt::encryptString('provider-token'),
            'code_challenge' => $challenge,
            'return_to' => 'rokn://auth',
            'state_expires_at' => now()->addMinute(),
            'completion_expires_at' => now()->addMinute(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => $code,
            'code_verifier' => $verifier,
        ])
            ->assertStatus(410)
            ->assertJsonPath('code', 'social_login_expired');
        self::assertNotNull(DB::table('social_oauth_attempts')
            ->where('completion_hash', hash('sha256', $code))
            ->value('completion_consumed_at'));
    }

    public function test_same_level_can_be_awarded_once_per_course_without_silent_duplicates(): void
    {
        $user = $this->user();
        $levelId = DB::table('levels')->insertGetId([
            'name_ar' => 'Junior',
            'name_en' => 'Junior',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $first = $this->course(['level_id' => $levelId, 'awards_badge' => true, 'badge_track' => 'freelance']);
        $second = $this->course(['level_id' => $levelId, 'awards_badge' => true, 'badge_track' => 'professional']);
        $listener = new AwardLevelBadge();

        $listener->handle(new CourseCompleted($user, $first));
        $listener->handle(new CourseCompleted($user, $first));
        $listener->handle(new CourseCompleted($user, $second));

        self::assertSame(2, DB::table('user_level')->where('user_id', $user->id)->count());
        $this->assertDatabaseHas('user_level', ['user_id' => $user->id, 'course_id' => $first->id]);
        $this->assertDatabaseHas('user_level', ['user_id' => $user->id, 'course_id' => $second->id]);
    }

    public function test_badges_are_never_inferred_without_the_explicit_course_policy(): void
    {
        $user = $this->user();
        $levelId = DB::table('levels')->insertGetId([
            'name_ar' => 'متقدم',
            'name_en' => 'Advanced',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $disabled = $this->course([
            'name_ar' => 'كورس نظري بلا درع',
            'level_id' => $levelId,
            'awards_badge' => false,
            'badge_track' => 'professional',
        ]);
        $missingTrack = $this->course([
            'name_ar' => 'كورس بلا مسار مهني',
            'level_id' => $levelId,
            'awards_badge' => true,
            'badge_track' => null,
        ]);
        $listener = new AwardLevelBadge();

        $listener->handle(new CourseCompleted($user, $disabled));
        $listener->handle(new CourseCompleted($user, $missingTrack));

        self::assertSame(0, DB::table('user_level')->where('user_id', $user->id)->count());
    }

    public function test_guest_catalogue_is_real_and_bounded(): void
    {
        $classificationId = DB::table('classifications')->insertGetId([
            'name_ar' => 'الأكثر مشاهدة',
            'name_en' => 'Most watched',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        for ($index = 1; $index <= 16; $index++) {
            $course = $this->course(['name_ar' => 'كورس ' . $index, 'is_main_course' => $index === 1]);
            DB::table('classification_course')->insert([
                'classification_id' => $classificationId,
                'course_id' => $course->id,
            ]);
            DB::table('course_sections')->insert([
                'course_id' => $course->id,
                'sectionable_type' => null,
                'sectionable_id' => null,
                'section_type' => 'project',
                'title_ar' => 'مشروع',
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $response = $this->withHeader('Accept-Language', 'ar')
            ->getJson('/api/v1/courses/list?per_page=15')
            ->assertOk()
            ->assertJsonPath('success', true);

        self::assertCount(15, $response->json('data.courses'));
    }

    public function test_public_course_list_includes_published_and_announced_coming_soon_only(): void
    {
        $published = $this->course(['name_ar' => 'منشور']);
        DB::table('course_sections')->insert([
            'course_id' => $published->id,
            'section_type' => 'project',
            'title' => 'مشروع',
            'title_ar' => 'مشروع',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $announced = $this->course([
            'name_ar' => 'قريبًا ومُعلن',
            'is_coming_soon' => true,
            'is_catalog_visible' => true,
        ]);
        $hiddenDraft = $this->course([
            'name_ar' => 'مسودة داخلية',
            'is_coming_soon' => true,
            'is_catalog_visible' => false,
        ]);

        $response = $this->getJson('/api/v1/courses/list?per_page=50')
            ->assertOk()
            ->assertJsonPath('success', true);

        $ids = collect($response->json('data.courses'))->pluck('id')->map(fn ($id) => (int) $id);
        self::assertTrue($ids->contains($published->id));
        self::assertTrue($ids->contains($announced->id));
        self::assertFalse($ids->contains($hiddenDraft->id));
    }

    public function test_verified_institution_email_can_receive_only_one_course_grant(): void
    {
        $user = $this->user(['email' => 'student@college.edu', 'email_verified_at' => now()]);
        $first = CourseCode::query()->create([
            'code' => 'COLLEGE-ONE',
            'type' => 'course',
            'max_uses' => 100,
            'is_active' => true,
            'allowed_email_domains' => ['college.edu'],
        ]);
        $second = CourseCode::query()->create([
            'code' => 'COLLEGE-TWO',
            'type' => 'course',
            'max_uses' => 100,
            'is_active' => true,
            'allowed_email_domains' => ['college.edu'],
        ]);
        DB::table('course_code_usages')->insert([
            'course_code_id' => $first->id,
            'user_id' => $user->id,
            'used_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_grant_claims')->insert([
            'user_id' => $user->id,
            'normalized_email_hash' => \App\Models\CourseGrantClaim::emailHash($user->email),
            'email_hint' => \App\Models\CourseGrantClaim::emailHint($user->email),
            'course_code_id' => $first->id,
            'course_id' => 1,
            'status' => \App\Models\CourseGrantClaim::STATUS_ACTIVE,
            'claimed_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        self::assertTrue($second->hasReachedInstitutionalGrantLimit($user->id));
        self::assertFalse($second->canBeUsedByUser($user->id));
    }

    public function test_ai_upload_staging_is_bounded_before_more_bytes_are_written(): void
    {
        Storage::fake('local');
        config()->set('projects.submission_disk', 'local');
        config()->set('openrouter.attachment_staging_max_files_per_user', 1);
        config()->set('openrouter.attachment_staging_max_bytes_per_user', 1024 * 1024);
        $user = $this->user();
        $course = $this->course();
        $service = app(AiInputAttachmentService::class);
        $firstUploadId = (string) Str::uuid();

        $first = $service->store(
            $user,
            $course,
            UploadedFile::fake()->createWithContent('first.txt', 'first attachment'),
            AiInputAttachment::PURPOSE_COURSE_CHAT,
            $firstUploadId
        );

        self::assertSame(AiInputAttachment::READY, $first->status);
        self::assertCount(1, Storage::disk('local')->allFiles('ai_inputs'));
        $replay = $service->store(
            $user,
            $course,
            UploadedFile::fake()->createWithContent('first.txt', 'first attachment'),
            AiInputAttachment::PURPOSE_COURSE_CHAT,
            $firstUploadId
        );
        self::assertSame($first->id, $replay->id);
        self::assertCount(1, Storage::disk('local')->allFiles('ai_inputs'));

        try {
            $service->store(
                $user,
                $course,
                UploadedFile::fake()->createWithContent('second.txt', 'second attachment'),
                AiInputAttachment::PURPOSE_COURSE_CHAT,
                (string) Str::uuid()
            );
            self::fail('A second unclaimed upload must be rejected before storage is written.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('AI attachment staging limit reached.', $exception->getMessage());
        }

        self::assertSame(1, AiInputAttachment::query()->count());
        self::assertCount(1, Storage::disk('local')->allFiles('ai_inputs'));
    }

    public function test_an_inflight_ai_upload_replay_never_becomes_a_second_writer(): void
    {
        Storage::fake('local');
        config()->set('projects.submission_disk', 'local');
        $user = $this->user();
        $course = $this->course();
        $uploadId = (string) Str::uuid();
        $content = 'same inflight attachment';
        AiInputAttachment::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'client_upload_id' => $uploadId,
            'purpose' => AiInputAttachment::PURPOSE_COURSE_CHAT,
            'storage_disk' => 'local',
            'storage_path' => 'ai_inputs/reserved.txt',
            'original_file_name' => 'reserved.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => strlen($content),
            'sha256' => hash('sha256', $content),
            'status' => AiInputAttachment::ALLOCATING,
        ]);

        try {
            app(AiInputAttachmentService::class)->store(
                $user,
                $course,
                UploadedFile::fake()->createWithContent('reserved.txt', $content),
                AiInputAttachment::PURPOSE_COURSE_CHAT,
                $uploadId
            );
            self::fail('An inflight idempotency key must not create another storage writer.');
        } catch (\UnexpectedValueException $exception) {
            self::assertSame('AI upload id was reused for different content.', $exception->getMessage());
        }

        self::assertSame(0, count(Storage::disk('local')->allFiles('ai_inputs')));
        self::assertSame(AiInputAttachment::ALLOCATING, AiInputAttachment::query()->sole()->status);
    }

    public function test_abandoned_ai_upload_reservation_is_pruned_with_its_bytes(): void
    {
        Storage::fake('local');
        $user = $this->user();
        $course = $this->course();
        $path = "ai_inputs/{$user->id}/{$course->id}/abandoned.txt";
        Storage::disk('local')->put($path, 'abandoned');
        AiInputAttachment::query()->create([
            'public_id' => (string) Str::uuid(),
            'user_id' => $user->id,
            'course_id' => $course->id,
            'client_upload_id' => (string) Str::uuid(),
            'purpose' => AiInputAttachment::PURPOSE_COURSE_CHAT,
            'storage_disk' => 'local',
            'storage_path' => $path,
            'original_file_name' => 'abandoned.txt',
            'mime_type' => 'text/plain',
            'size_bytes' => 9,
            'sha256' => hash('sha256', 'abandoned'),
            'status' => AiInputAttachment::ALLOCATING,
            'created_at' => now()->subHour(),
            'updated_at' => now()->subHour(),
        ]);

        $this->artisan('data:prune-operational', ['--limit' => 100])->assertSuccessful();

        self::assertFalse(Storage::disk('local')->exists($path));
        self::assertSame(0, AiInputAttachment::query()->count());
    }

    private function user(array $overrides = []): User
    {
        $id = DB::table('users')->insertGetId(array_merge([
            'name' => 'Test User',
            'email' => uniqid('student-', true) . '@example.com',
            'phone' => null,
            'role' => 'client',
            'active' => true,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        $user = User::query()->findOrFail($id);
        $total = (int) $user->wallet_coins;
        $paid = (int) $user->wallet_purchased_coins;
        $reward = (int) $user->wallet_reward_coins;
        if ($total > 0) {
            DB::table('wallet_transactions')->insert([
                'public_id' => (string) Str::uuid(),
                'user_id' => $user->id,
                'direction' => WalletTransaction::DIRECTION_CREDIT,
                'category' => 'test_opening_balance',
                'bucket' => WalletTransaction::BUCKET_MIXED,
                'amount' => $total,
                'paid_amount' => $paid,
                'reward_amount' => $reward,
                'balance_after' => $total,
                'paid_balance_after' => $paid,
                'reward_balance_after' => $reward,
                'source_type' => null,
                'source_id' => null,
                'idempotency_key' => "test-opening:{$user->id}",
                'metadata' => json_encode(['fixture' => true], JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $user;
    }

    private function contactEditorVersion(Contact $contact): string
    {
        return AdminEditorVersion::for($contact, [
            'request_type', 'email', 'read', 'resolution_status', 'resolved_at',
            'resolved_by', 'resolved_user_id', 'resolution_metadata', 'updated_at',
        ]);
    }

    private function course(array $overrides = []): Course
    {
        $teacher = $this->user([
            'email' => uniqid('teacher-', true) . '@example.com',
            'role' => 'teacher',
        ]);
        $id = DB::table('courses')->insertGetId(array_merge([
            'name_ar' => 'كورس تجريبي',
            'name_en' => 'Test course',
            'description_ar' => 'وصف',
            'description_en' => 'Description',
            'price' => 4000,
            'teacher_id' => $teacher->id,
            'is_main_course' => false,
            'is_coming_soon' => false,
            'is_catalog_visible' => true,
            'image' => 'courses/test-cover.jpg',
            'ai_model_type' => null,
            'chat_ai_prompt' => 'اشرح مباشرة',
            'tokens_number' => 200,
            'temperature' => 0.3,
            'level_id' => null,
            'awards_badge' => false,
            'badge_track' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        DB::table('course_sections')->insert([
            'course_id' => $id,
            'section_type' => 'project',
            'title_ar' => 'محتوى الكورس',
            'order' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('photos')->insert([
            'photoable_type' => Course::class,
            'photoable_id' => $id,
            'path' => 'courses/test-cover.jpg',
            'type' => 'featured',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_teacher')->insert([
            'course_id' => $id,
            'teacher_id' => $teacher->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $classificationId = DB::table('classifications')->insertGetId([
            'name_ar' => 'التعلّم',
            'name_en' => 'Learning',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('classification_course')->insert([
            'classification_id' => $classificationId,
            'course_id' => $id,
        ]);
        DB::table('course_access_plans')->insert([
            'course_id' => $id,
            'code' => CourseAccessPlan::GUIDED,
            'name_ar' => 'التعلّم بإرشاد',
            'name_en' => 'Guided learning',
            'price_coins' => 4000,
            'minimum_paid_coins' => 1,
            'chat_enabled' => true,
            'chat_message_limit' => 25,
            'chat_token_budget' => 12000,
            'ai_budget_usd' => '0.450000',
            'request_reserve_usd' => '0.015000',
            'project_feedback_token_budget' => 0,
            'project_feedback_budget_usd' => '0.000000',
            'project_feedback_reserve_usd' => '0.000000',
            'project_followup_message_limit' => 0,
            'project_followup_token_budget' => 0,
            'project_followup_budget_usd' => '0.000000',
            'project_followup_reserve_usd' => '0.000000',
            'max_output_tokens' => 320,
            'project_feedback_level' => CourseAccessPlan::FEEDBACK_PASS_ONLY,
            'project_output_enabled' => false,
            'certificate_enabled' => true,
            'is_active' => true,
            'sort_order' => 20,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Course::query()->findOrFail($id);
    }

    /** @return array{id:int,snapshot:array<string,mixed>} */
    private function paidPlanTerms(Course $course): array
    {
        $plan = CourseAccessPlan::query()
            ->where('course_id', $course->id)
            ->where('code', CourseAccessPlan::GUIDED)
            ->firstOrFail();

        return [
            'id' => (int) $plan->id,
            'snapshot' => app(\App\Services\CourseAccessPlanService::class)->snapshot($plan),
        ];
    }

    private function grantProjectAccess(User $user, Project $project): Course
    {
        $course = $this->course();
        $plan = $this->paidPlanTerms($course);
        \App\Models\CourseEnrollment::query()->create([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'access_plan_id' => $plan['id'],
            'access_plan_snapshot' => $plan['snapshot'],
            'is_active' => true,
            'enrolled_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $course->id,
            'sectionable_type' => Project::class,
            'sectionable_id' => $project->id,
            'section_type' => 'project',
            'title_ar' => 'مشروع التطبيق',
            'order' => 2,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $course;
    }

    private function order(User $user, Course $course, string $method, int $amount, int $coins): Order
    {
        $id = DB::table('orders')->insertGetId([
            'user_id' => $user->id,
            'course_id' => $course->id,
            'payment_method' => $method,
            'amount' => $amount,
            'final_amount' => $amount,
            'total_coins' => $coins,
            'paid_coins' => $coins,
            'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED,
            'financial_status' => Order::FINANCIAL_SETTLED,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return Order::query()->findOrFail($id);
    }

    private function createSchema(): void
    {
        Schema::create('product_feature_flags', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->boolean('enabled')->default(false);
            $table->unsignedTinyInteger('rollout_percentage')->default(100);
            $table->string('owner')->nullable();
            $table->text('reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
        Schema::create('social_oauth_attempts', function (Blueprint $table): void {
            $table->id();
            $table->char('state_hash', 64)->unique();
            $table->char('completion_hash', 64)->nullable()->unique();
            $table->string('provider', 24);
            $table->string('return_to');
            $table->string('code_challenge', 128)->nullable();
            $table->text('encrypted_token')->nullable();
            $table->text('encrypted_session_response')->nullable();
            $table->timestamp('state_expires_at');
            $table->timestamp('state_consumed_at')->nullable();
            $table->timestamp('completion_expires_at')->nullable();
            $table->timestamp('completion_processing_at')->nullable();
            $table->uuid('completion_claim_id')->nullable();
            $table->timestamp('completion_consumed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->string('email')->nullable()->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('phone')->nullable()->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('client');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('wallet_coins')->default(0);
            $table->unsignedInteger('wallet_purchased_coins')->default(0);
            $table->unsignedInteger('wallet_reward_coins')->default(0);
            $table->string('api_token')->nullable();
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->boolean('enforce_course_section_order')->default(true);
            $table->json('ai_plan_policy')->nullable();
            $table->timestamps();
        });
        Schema::create('course_chat_turns', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->unsignedBigInteger('usage_event_id')->nullable();
            $table->uuid('client_request_id');
            $table->char('request_fingerprint', 64);
            $table->char('prompt_version', 40);
            $table->string('language', 12)->default('ar');
            $table->string('status', 16)->default('queued');
            $table->string('error_code', 64)->nullable();
            $table->unsignedTinyInteger('attachment_count')->default(0);
            $table->text('question');
            $table->text('answer')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->unique(['user_id', 'client_request_id']);
        });
        Schema::create('paths', function (Blueprint $table): void {
            $table->id();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->timestamps();
        });
        Schema::create('grades', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('courses', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar')->nullable();
            $table->string('name_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->string('image')->nullable();
            $table->unsignedBigInteger('teacher_id')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('price_before_discount', 10, 2)->nullable();
            $table->unsignedBigInteger('path_id')->nullable();
            $table->unsignedBigInteger('grade_id')->nullable();
            $table->string('type')->default('course');
            $table->string('course_type')->default('online');
            $table->boolean('is_main_course')->default(false);
            $table->unsignedInteger('home_sort_order')->default(0);
            $table->boolean('is_coming_soon')->default(false);
            $table->boolean('is_catalog_visible')->default(false);
            $table->string('ai_model_type')->nullable();
            $table->text('chat_ai_prompt')->nullable();
            $table->float('temperature')->nullable();
            $table->integer('tokens_number')->nullable();
            $table->boolean('ai_chat_enabled')->default(true);
            $table->unsignedBigInteger('level_id')->nullable();
            $table->boolean('awards_badge')->default(false);
            $table->string('badge_track')->nullable();
            $table->unsignedInteger('students_count')->default(0);
            $table->unsignedInteger('video_count')->default(0);
            $table->unsignedInteger('hours_count')->default(0);
            $table->unsignedInteger('home_work_count')->default(0);
            $table->unsignedInteger('files_count')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_authoring_revisions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('canonical_course_id');
            $table->unsignedBigInteger('revision_course_id')->unique();
            $table->unsignedBigInteger('base_authoring_version');
            $table->unsignedBigInteger('published_authoring_version')->nullable();
            $table->string('status', 16)->default('draft');
            $table->string('active_slot', 80)->nullable()->unique();
            $table->uuid('clone_key')->unique();
            $table->timestamp('published_at')->nullable();
            $table->timestamp('retain_until')->nullable();
            $table->timestamps();
        });
        Schema::create('course_authoring_revision_entities', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_authoring_revision_id');
            $table->string('entity_type', 120);
            $table->unsignedBigInteger('source_entity_id');
            $table->unsignedBigInteger('revision_entity_id');
            $table->boolean('survives_publish')->default(false);
            $table->boolean('carries_learner_state')->default(false);
            $table->unsignedBigInteger('learner_root_entity_id')->nullable();
            $table->unique(
                ['course_authoring_revision_id', 'entity_type', 'source_entity_id'],
                'course_revision_source_entity_unique'
            );
            $table->unique(
                ['course_authoring_revision_id', 'entity_type', 'revision_entity_id'],
                'course_revision_working_entity_unique'
            );
        });
        Schema::create('course_modules', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->text('description_ar')->nullable();
            $table->text('description_en')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
        Schema::create('course_sections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('module_id')->nullable();
            $table->string('sectionable_type')->nullable();
            $table->unsignedBigInteger('sectionable_id')->nullable();
            $table->string('section_type')->nullable();
            $table->string('title')->nullable();
            $table->string('title_ar')->nullable();
            $table->string('title_en')->nullable();
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('student_section_progress', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_section_id');
            $table->boolean('is_completed')->default(false);
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'course_section_id']);
        });
        Schema::create('course_ratings', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->unsignedTinyInteger('rating')->default(5);
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_codes', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name')->nullable();
            $table->string('type')->default('course');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->json('lesson_ids')->nullable();
            $table->unsignedBigInteger('lesson_id')->nullable();
            $table->timestamp('start_date')->nullable();
            $table->timestamp('expiry_date')->nullable();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_grant')->default(false);
            $table->text('description')->nullable();
            $table->json('allowed_email_domains')->nullable();
            $table->timestamps();
        });
        Schema::create('course_code_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('user_id');
            $table->timestamp('used_at');
            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
            $table->unique(['course_code_id', 'user_id']);
        });
        Schema::create('course_grant_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->char('normalized_email_hash', 64)->unique();
            $table->string('email_hint')->nullable();
            $table->unsignedBigInteger('course_code_id');
            $table->unsignedBigInteger('course_code_usage_id')->nullable();
            $table->unsignedBigInteger('course_id');
            $table->string('status')->default('active');
            $table->timestamp('claimed_at');
            $table->timestamp('reassigned_at')->nullable();
            $table->unsignedBigInteger('reassigned_by')->nullable();
            $table->text('support_note')->nullable();
            $table->timestamps();
        });
        Schema::create('lessons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('list_id')->nullable();
            $table->string('title')->nullable();
            $table->string('bunny_video_id')->nullable();
            $table->boolean('is_opened')->default(false);
            $table->timestamps();
        });
        Schema::create('lesson_media_states', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('lesson_id')->unique();
            $table->string('provider', 32)->default('bunny');
            $table->string('provider_media_id')->nullable();
            $table->string('status', 24)->default('unknown');
            $table->string('protocol', 16)->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->json('available_qualities')->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('last_probe_at')->nullable();
            $table->timestamp('last_reconciled_at')->nullable();
            $table->string('integrity_status', 24)->default('unknown');
            $table->string('last_error_code', 64)->nullable();
            $table->text('last_error_message')->nullable();
            $table->unsignedSmallInteger('retry_count')->default(0);
            $table->timestamps();
        });
        Schema::create('photos', function (Blueprint $table): void {
            $table->id();
            $table->string('photoable_type');
            $table->unsignedBigInteger('photoable_id');
            $table->string('url')->nullable();
            $table->string('path')->nullable();
            $table->string('type')->default('gallery');
            $table->timestamps();
        });
        Schema::create('course_teacher', function (Blueprint $table): void {
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('teacher_id');
            $table->timestamps();
        });
        Schema::create('classifications', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en')->nullable();
            $table->timestamps();
        });
        Schema::create('classification_course', function (Blueprint $table): void {
            $table->unsignedBigInteger('classification_id');
            $table->unsignedBigInteger('course_id');
        });
        Schema::create('orders', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id')->nullable();
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->json('access_plan_snapshot')->nullable();
            $table->unsignedBigInteger('parent_order_id')->nullable();
            $table->unsignedBigInteger('course_code_id')->nullable();
            $table->unsignedBigInteger('package_id')->nullable();
            $table->unsignedInteger('package_coins')->nullable();
            $table->string('payment_method');
            $table->string('order_ref')->nullable();
            $table->string('checkout_request_key')->nullable();
            $table->decimal('amount', 10, 2)->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->decimal('final_amount', 10, 2)->default(0);
            $table->unsignedInteger('total_coins')->nullable();
            $table->unsignedInteger('paid_coins')->nullable();
            $table->unsignedInteger('reward_coins')->nullable();
            $table->string('status');
            $table->string('financial_status')->default(Order::FINANCIAL_SETTLED);
            $table->timestamp('reversed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('course_enrollments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->json('access_plan_snapshot')->nullable();
            $table->unsignedBigInteger('access_plan_order_id')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('access_granted_at')->nullable();
            $table->timestamps();
        });
        Schema::create('course_access_plans', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('course_id');
            $table->string('code', 32);
            $table->string('name_ar', 120);
            $table->string('name_en', 120)->nullable();
            $table->unsignedInteger('price_coins');
            $table->unsignedInteger('minimum_paid_coins')->default(0);
            $table->boolean('chat_enabled')->default(false);
            $table->unsignedInteger('chat_message_limit')->default(0);
            $table->unsignedBigInteger('chat_token_budget')->default(0);
            $table->boolean('chat_attachments_enabled')->default(false);
            $table->unsignedTinyInteger('chat_attachment_max_files')->default(0);
            $table->decimal('ai_budget_usd', 12, 6)->default(0);
            $table->decimal('request_reserve_usd', 12, 6)->default(0);
            $table->unsignedBigInteger('project_feedback_token_budget')->default(0);
            $table->decimal('project_feedback_budget_usd', 12, 6)->default(0);
            $table->decimal('project_feedback_reserve_usd', 12, 6)->default(0);
            $table->unsignedInteger('project_followup_message_limit')->default(0);
            $table->unsignedBigInteger('project_followup_token_budget')->default(0);
            $table->decimal('project_followup_budget_usd', 12, 6)->default(0);
            $table->decimal('project_followup_reserve_usd', 12, 6)->default(0);
            $table->boolean('project_followup_attachments_enabled')->default(false);
            $table->unsignedTinyInteger('project_followup_attachment_max_files')->default(0);
            $table->unsignedInteger('max_output_tokens')->default(320);
            $table->string('model_override')->nullable();
            $table->string('project_feedback_level', 24)->default('pass_only');
            $table->boolean('project_output_enabled')->default(false);
            $table->boolean('certificate_enabled')->default(true);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(10);
            $table->timestamps();
            $table->unique(['course_id', 'code']);
        });
        Schema::create('ai_entitlement_usages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->string('feature', 40);
            $table->unsignedInteger('used_requests')->default(0);
            $table->unsignedInteger('reserved_requests')->default(0);
            $table->unsignedBigInteger('used_tokens')->default(0);
            $table->unsignedBigInteger('reserved_tokens')->default(0);
            $table->decimal('used_cost_usd', 12, 6)->default(0);
            $table->decimal('reserved_cost_usd', 12, 6)->default(0);
            $table->timestamps();
            $table->unique(['enrollment_id', 'feature']);
        });
        Schema::create('ai_usage_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->unsignedBigInteger('enrollment_id');
            $table->unsignedBigInteger('access_plan_id')->nullable();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->string('feature', 40);
            $table->string('model')->nullable();
            $table->string('status', 20)->default('reserved');
            $table->timestamp('reservation_expires_at')->nullable();
            $table->unsignedInteger('reserved_tokens')->default(0);
            $table->decimal('reserved_cost_usd', 12, 6)->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('total_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            $table->string('provider_request_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });
        Schema::create('wallet_transactions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('direction');
            $table->string('category');
            $table->string('bucket');
            $table->unsignedInteger('amount');
            $table->unsignedInteger('paid_amount');
            $table->unsignedInteger('reward_amount');
            $table->integer('balance_after');
            $table->unsignedInteger('paid_balance_after');
            $table->unsignedInteger('reward_balance_after');
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('idempotency_key');
            $table->json('metadata')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();
            $table->unique(['user_id', 'idempotency_key']);
        });
        Schema::create('projects', function (Blueprint $table): void {
            $table->id();
            $table->text('requirements_text')->nullable();
            $table->text('requirements_text_ar')->nullable();
            $table->text('requirements_text_en')->nullable();
            $table->text('ai_prompt')->nullable();
            $table->string('ai_model_type')->nullable();
            $table->float('temperature')->nullable();
            $table->unsignedInteger('tokens_number')->nullable();
            $table->unsignedInteger('passing_score')->default(50);
            $table->unsignedInteger('fallback_review_delay_seconds')->default(8);
            $table->boolean('is_graduation_project')->default(false);
            $table->timestamps();
        });
        Schema::create('project_submissions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->string('idempotency_key');
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->string('original_file_name')->nullable();
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('file_size')->nullable();
            $table->json('submission_metadata')->nullable();
            $table->string('effort_status');
            $table->string('review_status');
            $table->string('review_source')->nullable();
            $table->unsignedInteger('score')->nullable();
            $table->text('feedback')->nullable();
            $table->timestamp('submitted_at');
            $table->timestamp('auto_pass_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedBigInteger('reviewed_by')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'project_id', 'idempotency_key']);
        });
        Schema::create('user_project_evaluations', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('project_id');
            $table->integer('score')->default(0);
            $table->boolean('passed')->default(false);
            $table->json('evaluation_data')->nullable();
            $table->text('submission_text')->nullable();
            $table->string('submission_file')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
        Schema::create('levels', function (Blueprint $table): void {
            $table->id();
            $table->string('name_ar');
            $table->string('name_en');
            $table->unsignedInteger('order')->default(1);
            $table->timestamps();
        });
        Schema::create('user_level', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('level_id');
            $table->unsignedBigInteger('course_id');
            $table->timestamp('earned_at')->nullable();
            $table->timestamps();
            $table->unique(['user_id', 'level_id', 'course_id']);
        });
        Schema::create('contacts', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('message')->nullable();
            $table->boolean('read')->default(false);
            $table->string('request_type')->nullable();
            $table->string('resolution_status')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->unsignedBigInteger('resolved_user_id')->nullable();
            $table->json('resolution_metadata')->nullable();
            $table->timestamps();
        });

        (require database_path('migrations/2026_08_07_000022_create_account_file_deletions_table.php'))->up();
        (require database_path('migrations/2026_09_01_000066_create_ai_input_attachments.php'))->up();
        (require database_path('migrations/2026_09_01_000078_create_internal_signals_table.php'))->up();
        (require database_path('migrations/2026_09_02_000001_snapshot_project_reviews.php'))->up();
    }
}
