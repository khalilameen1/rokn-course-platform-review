<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Models\AiUsageEvent;
use App\Models\Course;
use App\Models\CourseChatTurn;
use App\Models\CourseEnrollment;
use App\Models\FeedbackReport;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\ProjectFeedbackThread;
use App\Models\ProjectFeedbackMessage;
use App\Models\User;
use App\Services\AiConsentService;
use App\Services\PaidAiCallExecutionService;
use App\Support\ProjectReportRetryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AiConsentAndReportingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }

    public function test_consent_requires_current_version_is_account_bound_and_can_be_withdrawn(): void
    {
        $learner = $this->learner();
        $other = $this->learner();
        $token = $learner->generateApiToken();
        $this->withToken($token)->getJson('/api/v1/ai-consent')->assertOk()->assertJsonPath('data.accepted', false);
        $this->withToken($token)->putJson('/api/v1/ai-consent', ['version' => 'old', 'accepted' => true])->assertUnprocessable();
        $this->withToken($token)->putJson('/api/v1/ai-consent', ['version' => AiConsentService::VERSION, 'accepted' => true])
            ->assertOk()->assertJsonPath('data.accepted', true);
        self::assertFalse(app(AiConsentService::class)->accepted($other->id));
        $this->withToken($token)->putJson('/api/v1/ai-consent', ['version' => AiConsentService::VERSION, 'accepted' => false])
            ->assertOk()->assertJsonPath('data.accepted', false);
        self::assertNull($learner->fresh()->ai_consent_accepted_at);
        Http::assertNothingSent();
    }

    public function test_queued_provider_attempt_requires_consent_but_a_landed_answer_is_recoverable(): void
    {
        $learner = $this->learner();
        $course = Course::factory()->create(['tenant_id' => 1]);
        $enrollment = CourseEnrollment::query()->forceCreate([
            'tenant_id' => 1, 'user_id' => $learner->id, 'course_id' => $course->id,
            'is_active' => true, 'enrolled_at' => now(),
        ]);
        $event = AiUsageEvent::query()->create([
            'request_id' => (string) Str::uuid(), 'user_id' => $learner->id,
            'course_id' => $course->id, 'enrollment_id' => $enrollment->id,
            'feature' => 'course_chat', 'status' => 'reserved', 'metadata' => [],
        ]);
        $calls = app(PaidAiCallExecutionService::class);
        self::assertSame($calls::CONSENT_REQUIRED, $calls->beginForActiveUser($event, 'execution', $learner->id));
        self::assertNull(data_get($event->fresh()->metadata, 'provider_call_started_at'));
        app(AiConsentService::class)->record($learner, true);
        self::assertSame($calls::START, $calls->beginForActiveUser($event, 'execution', $learner->id));
        $event->forceFill(['metadata' => ['provider_call_state' => 'landed',
            'provider_success_landing' => ['message' => 'رد محفوظ']]])->save();
        app(AiConsentService::class)->record($learner, false);
        self::assertSame($calls::LANDED, $calls->beginForActiveUser($event, 'execution', $learner->id));
        self::assertTrue(ProjectReportRetryPolicy::allows('ai_consent_required', 4, 'failed'));
        Http::assertNothingSent();
    }

    public function test_every_new_learner_ai_route_requires_consent_and_read_routes_do_not(): void
    {
        $actions = [
            'ProjectController@submit', 'ProjectController@retryEvaluation',
            'ProjectController@retryInitialReport', 'ProjectController@sendFeedbackMessage',
            'ProjectController@uploadFeedbackAttachment', 'CourseChatController@sendForCourse',
            'CourseChatController@uploadAttachment',
        ];
        foreach ($actions as $action) {
            $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_ends_with($route->getActionName(), $action));
            self::assertNotEmpty($routes, $action);
            foreach ($routes as $route) self::assertContains('ai.consent', $route->gatherMiddleware(), $action);
        }
        foreach (collect(app('router')->getRoutes())->filter(fn ($route) =>
            str_ends_with($route->getActionName(), 'CourseChatController@history') ||
            str_ends_with($route->getActionName(), 'ProjectController@feedbackThread')) as $route) {
            self::assertNotContains('ai.consent', $route->gatherMiddleware());
        }
        $user = $this->learner();
        $course = Course::factory()->create(['tenant_id' => 1]);
        $this->withToken($user->generateApiToken())->postJson('/api/v1/courses/'.$course->id.'/chat', ['message' => 'سؤال'])
            ->assertForbidden()->assertJsonPath('code', 'ai_consent_required');
        Http::assertNothingSent();
    }

    public function test_chat_report_uses_only_owned_server_output_and_is_idempotent_in_support(): void
    {
        $user = $this->learner();
        $course = Course::factory()->create(['tenant_id' => 1]);
        $turn = CourseChatTurn::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'course_id' => $course->id,
            'client_request_id' => (string) Str::uuid(), 'request_fingerprint' => str_repeat('a', 64),
            'prompt_version' => str_repeat('b', 40), 'question' => 'private question not copied',
            'answer' => 'الرد المراد مراجعته', 'status' => 'completed', 'expires_at' => now()->addDay(),
        ]);
        $input = ['scope' => 'course_chat', 'course_id' => $course->id, 'client_request_id' => $turn->client_request_id,
            'answer' => 'spoofed answer', 'reason' => 'غير دقيق'];
        $this->withToken($this->learner()->generateApiToken())->postJson('/api/v1/ai-content-reports', $input)->assertNotFound();
        app('auth')->forgetGuards();
        $token = $user->generateApiToken();
        $receipt = $this->withToken($token)->postJson('/api/v1/ai-content-reports', $input)->assertOk();
        $this->withToken($token)->postJson('/api/v1/ai-content-reports', $input)->assertOk()
            ->assertJsonPath('data.public_id', $receipt->json('data.public_id'));
        $report = FeedbackReport::query()->sole();
        self::assertStringContainsString($turn->answer, $report->message);
        self::assertStringNotContainsString('spoofed', $report->message);
        self::assertStringNotContainsString('private question', $report->message);
        self::assertSame(1, $report->messages()->count());
        $this->withToken($token)->getJson('/api/v1/feedback')->assertOk()->assertJsonCount(1, 'data.items');
    }

    public function test_project_report_rejects_foreign_threads_and_user_messages(): void
    {
        $user = $this->learner();
        $course = Course::factory()->create(['tenant_id' => 1]);
        $project = Project::factory()->create();
        $submission = ProjectSubmission::query()->create([
            'public_id' => (string) Str::uuid(), 'user_id' => $user->id, 'project_id' => $project->id,
            'idempotency_key' => (string) Str::uuid(), 'submitted_at' => now(),
        ]);
        $thread = ProjectFeedbackThread::query()->create([
            'public_id' => (string) Str::uuid(), 'submission_id' => $submission->id,
            'user_id' => $user->id, 'course_id' => $course->id, 'project_id' => $project->id,
            'feedback_level' => 'report',
        ]);
        $message = ProjectFeedbackMessage::query()->create([
            'public_id' => (string) Str::uuid(), 'thread_id' => $thread->id,
            'role' => 'assistant', 'body' => 'تقرير المشروع', 'status' => 'completed',
        ]);
        $input = ['scope' => 'project_feedback', 'thread_id' => $thread->public_id, 'message_id' => $message->public_id];
        $this->withToken($this->learner()->generateApiToken())->postJson('/api/v1/ai-content-reports', $input)->assertNotFound();
        app('auth')->forgetGuards();
        $token = $user->generateApiToken();
        $this->withToken($token)->postJson('/api/v1/ai-content-reports', $input)->assertOk();
        $message->forceFill(['role' => 'user'])->save();
        $this->withToken($token)->postJson('/api/v1/ai-content-reports', $input)->assertNotFound();
        self::assertSame(1, FeedbackReport::query()->count());
    }

    private function learner(): User
    {
        return User::query()->forceCreate(['name' => 'AI policy learner', 'email' => Str::uuid().'@rokn.test',
            'active' => true, 'role' => 'client']);
    }
}
