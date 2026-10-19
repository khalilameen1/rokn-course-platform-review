<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\InternalSignal;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\ProjectSubmissionReviewService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PDOException;
use Tests\TestCase;

final class StaffProjectReviewRetryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Like the existing ownership fixtures, this test needs real outer
        // commits rather than RefreshDatabase's enclosing transaction.
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
    }

    public function test_transient_concurrency_failure_retries_the_existing_review_transaction_without_duplicate_signals(): void
    {
        Bus::fake();
        Http::preventStrayRequests();
        $learner = User::query()->forceCreate(['role' => 'client', 'active' => true]);
        $reviewer = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $project = Project::factory()->create();
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $learner->id, 'project_id' => $project->id,
            'idempotency_key' => (string) Str::uuid(), 'submission_text' => 'تنفيذ واضح', 'review_status' => 'pending', 'submitted_at' => now(),
        ]);
        self::assertSame(0, DB::transactionLevel());
        $attempts = 0;
        $armed = true;
        DB::listen(function (QueryExecuted $query) use (&$attempts, &$armed): void {
            if ($query->connection->transactionLevel() !== 1 || ! str_starts_with($query->sql, 'select * from "project_submissions"')) {
                return;
            }
            $attempts++;
            if ($armed) {
                $armed = false;
                throw new QueryException($query->connectionName, $query->sql, $query->bindings, new PDOException('database is locked'));
            }
        });

        $reviewed = app(ProjectSubmissionReviewService::class)->reviewByStaff($submission, $reviewer, false, 'أعد التطبيق العملي');
        self::assertGreaterThanOrEqual(2, $attempts);
        self::assertSame('needs_resubmission', $reviewed->review_status);
        self::assertSame('أعد التطبيق العملي', $reviewed->feedback);
        self::assertSame(1, InternalSignal::query()->where('type', 'project.review.notification')->count());
        self::assertSame(0, DB::transactionLevel());
    }
}
