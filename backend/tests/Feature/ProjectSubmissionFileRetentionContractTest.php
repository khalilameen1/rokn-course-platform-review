<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DeleteAccountFile;
use App\Models\AccountFileDeletion;
use App\Models\AiInputAttachment;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseEnrollment;
use App\Models\CourseModule;
use App\Models\CourseSection;
use App\Models\Order;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\CourseAccessPlanService;
use App\Services\ProjectSubmissionFileRetentionService;
use App\Services\StoredFileReferenceService;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectSubmissionFileRetentionContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Storage::fake('local');
        Storage::fake('public');
    }

    public function test_retention_detaches_every_database_reference_before_deleting_bytes(): void
    {
        $submission = $this->submission(ProjectSubmission::STATUS_NEEDS_RESUBMISSION, null);
        $files = [
            ['disk' => 'local', 'path' => 'projects/work.pdf'],
            ['disk' => 'public', 'path' => 'projects/extra.jpg'],
            ['disk' => 'local', 'path' => 'ai-inputs/work.png'],
        ];
        foreach ($files as $file) {
            Storage::disk($file['disk'])->put($file['path'], 'learner bytes');
        }
        $submission->forceFill([
            'submission_file' => 'projects/work.pdf',
            'original_file_name' => 'work.pdf',
            'mime_type' => 'application/pdf',
            'file_size' => 13,
            'submission_metadata' => [
                'storage_disk' => 'local',
                'files' => [
                    ['path' => 'projects/work.pdf', 'storage_disk' => 'local'],
                    ['path' => 'projects/extra.jpg', 'storage_disk' => 'public'],
                ],
                'request_fingerprint' => 'fingerprint',
                'assessment_type' => 'relevance',
                'progression_credit' => false,
                'evaluation' => ['status' => 'ready'],
                'learner_note' => 'private text copied into metadata',
            ],
        ])->save();
        $attachment = $this->attachment($submission, 'ai-inputs/work.png');

        self::assertTrue(app(ProjectSubmissionFileRetentionService::class)->purgeIfEligible($submission));

        $retired = $submission->fresh();
        foreach (['submission_text', 'submission_file', 'original_file_name', 'mime_type', 'file_size'] as $field) {
            self::assertNull($retired->{$field}, $field);
        }
        self::assertSame(
            ['request_fingerprint', 'assessment_type', 'progression_credit', 'evaluation', 'files_purged_at'],
            array_keys($retired->submission_metadata)
        );
        self::assertSame(ProjectSubmission::STATUS_NEEDS_RESUBMISSION, $retired->review_status);
        self::assertNull(AiInputAttachment::query()->find($attachment->id));

        // Cleanup is admitted only for paths no row references any longer;
        // the reference check would have skipped a still-referenced file.
        $ledger = AccountFileDeletion::query()->get();
        self::assertEqualsCanonicalizing($files, $ledger->map(
            static fn (AccountFileDeletion $row): array => ['disk' => $row->disk, 'path' => $row->path]
        )->all());
        Queue::assertPushed(DeleteAccountFile::class, count($files));
        foreach ($ledger as $row) {
            Storage::disk($row->disk)->assertExists($row->path);
            (new DeleteAccountFile($row->id))->handle(app(StoredFileReferenceService::class));
            Storage::disk($row->disk)->assertMissing($row->path);
        }
    }

    /** @return array<string,array{string,?string,int,bool}> */
    public static function retentionStates(): array
    {
        return [
            'needs resubmission' => [ProjectSubmission::STATUS_NEEDS_RESUBMISSION, null, 0, true],
            'ready report' => [ProjectSubmission::STATUS_PASSED, 'ready', 0, true],
            'report not applicable' => [ProjectSubmission::STATUS_PASSED, 'not_applicable', 0, true],
            'queued report' => [ProjectSubmission::STATUS_PASSED, 'queued', 40, false],
            'retryable failed report' => [ProjectSubmission::STATUS_PASSED, 'unavailable', 2, false],
            'failed report past retention' => [ProjectSubmission::STATUS_PASSED, 'unavailable', 8, true],
            'pending review' => [ProjectSubmission::STATUS_PENDING, null, 40, false],
        ];
    }

    #[DataProvider('retentionStates')]
    public function test_retryable_reports_are_retained_but_terminal_files_are_bounded(
        string $review,
        ?string $report,
        int $ageDays,
        bool $purged
    ): void {
        config(['retention.project_submission_failed_files_days' => 7]);
        $submission = $this->submission($review, $report);
        $attachment = $this->attachment($submission, 'ai-inputs/'.$submission->public_id.'.png');
        $submission->forceFill(['updated_at' => now()->subDays($ageDays)])->save();

        self::assertSame(
            $purged ? 1 : 0,
            app(ProjectSubmissionFileRetentionService::class)->purgeExpiredTerminalFailures(10)
        );

        $after = $submission->fresh();
        if ($purged) {
            self::assertNull($after->submission_text);
            self::assertNotEmpty(data_get($after->submission_metadata, 'files_purged_at'));
            self::assertNull(AiInputAttachment::query()->find($attachment->id));
        } else {
            self::assertSame('محاولة مرتبطة بالشعار', $after->submission_text);
            self::assertNull(data_get($after->submission_metadata, 'files_purged_at'));
            self::assertNotNull(AiInputAttachment::query()->find($attachment->id));
            self::assertSame(0, AccountFileDeletion::query()->count());
        }
        self::assertSame($review, $after->review_status);
    }

    // Account deletion collects secondary submission files (metadata `files`
    // with their own storage_disk) and queues them in the deletion transaction:
    // AccountUploadedContentErasureTest::test_deletion_releases_all_owned_uploads_without_erasing_another_learners_content.

    private function attachment(ProjectSubmission $submission, string $path): AiInputAttachment
    {
        Storage::disk('local')->put($path, 'learner bytes');

        return AiInputAttachment::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $submission->user_id, 'course_id' => $submission->evaluation_snapshot['course']['id'] ?? null,
            'client_upload_id' => (string) Str::uuid(), 'purpose' => 'project_submission',
            'owner_type' => AiInputAttachment::OWNER_PROJECT_SUBMISSION, 'owner_id' => $submission->id,
            'storage_disk' => 'local', 'storage_path' => $path,
            'original_file_name' => 'work.png', 'mime_type' => 'image/png', 'size_bytes' => 13,
            'sha256' => str_repeat('a', 64), 'status' => 'ready']);
    }

    private function submission(string $review, ?string $report): ProjectSubmission
    {
        $user = new User;
        $user->forceFill(['name' => 'Retention learner', 'email' => Str::uuid().'@test.rokn',
            'password' => bcrypt('test'), 'active' => true, 'role' => 'client'])->save();
        $course = Course::factory()->make();
        $course->forceFill(['tenant_id' => 1])->save();
        $project = Project::factory()->create(['requirements_text_ar' => 'صمم شعار شجرة', 'requirements_text_en' => 'Draw a tree']);
        $module = CourseModule::query()->create(['course_id' => $course->id, 'title_ar' => 'الوحدة', 'order' => 1]);
        $section = CourseSection::factory()->project()->create(['course_id' => $course->id, 'module_id' => $module->id,
            'sectionable_type' => Project::class, 'sectionable_id' => $project->id]);
        // A report-enabled plan: a passed attempt keeps its input until the
        // report lifecycle is terminal.
        $plan = CourseAccessPlan::query()->create(['course_id' => $course->id, 'code' => 'guided',
            'name_ar' => 'تعلم', 'price_coins' => 100, 'minimum_paid_coins' => 100,
            'project_feedback_level' => 'report', 'project_feedback_token_budget' => 4000,
            'project_feedback_budget_usd' => '.200000', 'project_feedback_reserve_usd' => '.020000']);
        $terms = app(CourseAccessPlanService::class)->snapshot($plan->fresh());
        $order = Order::query()->create(['user_id' => $user->id, 'course_id' => $course->id,
            'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 100,
            'final_amount' => 100, 'total_coins' => 100, 'paid_coins' => 100, 'reward_coins' => 0,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED, 'approved_at' => now()]);
        $transaction = WalletTransaction::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'direction' => 'debit', 'category' => 'course_purchase', 'bucket' => 'paid',
            'amount' => 100, 'paid_amount' => 100, 'reward_amount' => 0, 'balance_after' => 0,
            'paid_balance_after' => 0, 'reward_balance_after' => 0, 'source_type' => Course::class,
            'source_id' => $course->id, 'idempotency_key' => 'retention-test:'.$order->id,
            'metadata' => ['order_id' => $order->id], 'occurred_at' => now()]);
        $order->forceFill(['wallet_transaction_id' => $transaction->id])->save();
        $enrollment = new CourseEnrollment;
        $enrollment->forceFill(['tenant_id' => 1, 'user_id' => $user->id, 'course_id' => $course->id,
            'order_id' => $order->id, 'access_plan_id' => $plan->id, 'access_plan_snapshot' => $terms,
            'is_active' => true, 'enrolled_at' => now()])->save();

        return ProjectSubmission::query()->create(['public_id' => (string) Str::uuid(),
            'user_id' => $user->id, 'project_id' => $project->id, 'idempotency_key' => (string) Str::uuid(),
            'submission_text' => 'محاولة مرتبطة بالشعار',
            'submission_metadata' => $report === null ? [] : ['ai_feedback' => ['status' => $report]],
            'evaluation_snapshot' => ProjectSubmissionEvaluationSnapshot::capture($project, $section, $enrollment, $terms),
            'effort_status' => 'valid', 'review_status' => $review, 'review_source' => 'ai',
            'submitted_at' => now()->subMinutes(10),
            'reviewed_at' => $review === ProjectSubmission::STATUS_PENDING ? null : now()->subMinutes(5)])->fresh();
    }
}
