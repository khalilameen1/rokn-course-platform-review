<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Http\Middleware\AppFrontNameSpace;
use App\Http\Middleware\WebsiteVisitorCount;
use App\Models\AiUsageEvent;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class ProjectSubmissionLookupTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware([AppFrontNameSpace::class, WebsiteVisitorCount::class]);
        Bus::fake();
        Http::preventStrayRequests();
    }

    public static function acceptedStates(): array
    {
        return [
            'pending before acknowledgement' => ['pending', 'evaluating'],
            'review finished and files removed' => ['needs_resubmission', 'needs_changes'],
            'already passed' => ['passed', 'passed'],
        ];
    }

    #[DataProvider('acceptedStates')]
    public function test_lost_upload_acknowledgement_is_recovered_by_exact_key_without_upload_or_dispatch(
        string $status, string $expected
    ): void {
        [$user, $project, $submission] = $this->fixture($status);
        $before = $submission->fresh()->getRawOriginal();
        foreach ([1, 2] as $read) {
            $this->actingAs($user, 'api')->getJson($this->url($project->id, $submission->idempotency_key))
                ->assertOk()->assertJsonPath('success', true)
                ->assertJsonPath('data.id', $submission->public_id)
                ->assertJsonPath('data.project_id', $project->id)
                ->assertJsonPath('data.client_submission_id', $submission->idempotency_key)
                ->assertJsonPath('data.submission_status', $expected)
                ->assertJsonPath('data.attachments', []);
        }
        self::assertSame($before, $submission->fresh()->getRawOriginal());
        self::assertSame(1, ProjectSubmission::query()->count());
        self::assertSame(0, AiUsageEvent::query()->count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_lookup_never_substitutes_the_latest_submission_or_another_owner_or_project(): void
    {
        [$owner, $project, $submission] = $this->fixture('pending');
        [$otherOwner, $otherProject] = $this->fixture('pending');
        foreach ([
            [$owner, $project->id, 'missing-attempt'],
            [$owner, $project->id, strtolower($submission->idempotency_key)],
            [$owner, $otherProject->id, $submission->idempotency_key],
            [$otherOwner, $project->id, $submission->idempotency_key],
        ] as [$reader, $projectId, $key]) {
            $this->actingAs($reader, 'api')->getJson($this->url($projectId, $key))
                ->assertNotFound()->assertJsonPath('success', false)->assertJsonPath('data', null);
        }
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    public function test_lookup_requires_a_nonempty_bounded_key(): void
    {
        [$user, $project] = $this->fixture('pending');
        foreach ([null, '', str_repeat('a', 101), ['not-a-string']] as $key) {
            $url = $key === null ? "/api/v1/projects/{$project->id}/submissions/lookup"
                : "/api/v1/projects/{$project->id}/submissions/lookup?".http_build_query(['client_submission_id' => $key]);
            $this->actingAs($user, 'api')->getJson($url)->assertUnprocessable()
                ->assertJsonValidationErrors('client_submission_id');
        }
        Bus::assertNothingDispatched();
    }

    public function test_guest_cannot_lookup_an_upload(): void
    {
        [, $project, $submission] = $this->fixture('pending');
        $this->getJson($this->url($project->id, $submission->idempotency_key))->assertUnauthorized();
    }

    public function test_polling_does_not_block_submission_and_exhausted_upload_limit_does_not_block_lookup(): void
    {
        [$user, $project, $submission] = $this->fixture('pending');
        $this->actingAs($user, 'api');
        for ($read = 0; $read < 8; $read++) {
            $this->getJson('/api/v1/course-chat/turns/'.Str::uuid())->assertNotFound();
        }
        for ($write = 0; $write < 8; $write++) {
            // Validation rejects this intentionally incomplete upload, not the
            // preceding chat polling. Rejected attempts still count normally.
            $this->postJson('/api/v1/projects/'.$project->id.'/submissions', [])
                ->assertUnprocessable()->assertJsonPath('code', 'validation_failed');
        }
        $this->postJson('/api/v1/projects/'.$project->id.'/submissions', [])
            ->assertStatus(429)->assertJsonPath('code', 'rate_limited')->assertHeader('Retry-After');
        $this->getJson($this->url($project->id, $submission->idempotency_key))
            ->assertOk()->assertJsonPath('data.id', $submission->public_id);
        self::assertSame(1, ProjectSubmission::query()->count());
        Bus::assertNothingDispatched();
        Http::assertNothingSent();
    }

    private function url(int $projectId, string $key): string
    {
        return "/api/v1/projects/{$projectId}/submissions/lookup?".http_build_query(['client_submission_id' => $key]);
    }

    private function fixture(string $status): array
    {
        $user = new User();
        $user->forceFill(['name' => 'Learner', 'email' => Str::uuid().'@test.rokn',
            'password' => bcrypt('test'), 'active' => true, 'role' => 'client'])->save();
        $project = Project::factory()->create();
        $submission = ProjectSubmission::query()->create([
            'user_id' => $user->id, 'project_id' => $project->id, 'public_id' => (string) Str::uuid(),
            'idempotency_key' => 'Exact-'.Str::uuid(), 'review_status' => $status,
            'submission_text' => null, 'submission_file' => null,
            'submission_metadata' => $status === 'pending'
                ? ['evaluation' => ['status' => 'queued', 'request_id' => (string) Str::uuid()]]
                : ['files_purged_at' => now()->toIso8601String()],
            'auto_pass_at' => $status === 'pending' ? now()->subMinute() : null,
            'submitted_at' => now()->subMinute(), 'reviewed_at' => $status === 'pending' ? null : now(),
        ]);
        return [$user, $project, $submission];
    }
}
