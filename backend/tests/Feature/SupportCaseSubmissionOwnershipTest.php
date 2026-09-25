<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\API\FeedbackController;
use App\Models\Course;
use App\Models\FeedbackReport;
use App\Models\Lesson;
use App\Models\Order;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Services\SupportCaseAccessService;
use App\Services\SupportCaseService;
use App\Services\SupportCaseSubmissionService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SupportCaseSubmissionOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('testing', app()->environment());
        self::assertSame(':memory:', DB::connection()->getDatabaseName());
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('feedback');
        $this->app->bind(FeedbackController::class, static function (): never {
            throw new \LogicException('Case submission must not resolve its HTTP adapter.');
        });
    }

    public function test_guest_submission_and_retry_return_one_case_message_and_stable_credential(): void
    {
        $input = $this->input();
        $service = app(SupportCaseSubmissionService::class);
        $first = $service->submit($input, null, null, $this->telemetry());
        $again = $service->submit($input, null, null, $this->telemetry());
        self::assertFalse($first->replayed);
        self::assertTrue($again->replayed);
        self::assertSame($first->report->id, $again->report->id);
        self::assertSame($first->accessToken, $again->accessToken);
        self::assertSame(hash('sha256', $first->accessToken), $first->report->guest_access_hash);
        self::assertSame(1, FeedbackReport::query()->count());
        self::assertSame(1, SupportCaseMessage::query()->count());
        self::assertSame(1, $first->report->events()->where('event_type', 'created')->count());
        self::assertSame('learner@example.test', $first->report->requester_email);
        self::assertSame('android', $first->report->platform);
        self::assertSame(77, (int) $first->report->build_number);
        self::assertSame(['request_id' => str_repeat('a', 64)], $first->report->context);
        Http::assertNothingSent();
    }

    public function test_retry_keeps_the_existing_fingerprint_contract_and_rejects_changed_content(): void
    {
        $input = $this->input();
        $service = app(SupportCaseSubmissionService::class);
        $result = $service->submit($input, null, null, $this->telemetry());
        $expected = hash('sha256', json_encode([
            'category' => $input['category'], 'message' => trim($input['message']),
            'screen_key' => null, 'course_id' => null, 'lesson_id' => null, 'order_id' => null,
            'requester_email' => 'learner@example.test', 'screenshot' => null,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        self::assertSame($expected, $result->report->request_fingerprint);
        try {
            $service->submit([...$input, 'message' => 'Different report content'], null, null, $this->telemetry());
            self::fail('A reused request identity cannot accept another message.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame(1, SupportCaseMessage::query()->count());
    }

    public function test_pending_case_resumes_after_first_message_admission_failed(): void
    {
        $input = $this->input();
        $service = app(SupportCaseSubmissionService::class);
        DB::statement("CREATE TRIGGER reject_first_support_message BEFORE INSERT ON support_case_messages
            BEGIN SELECT RAISE(ABORT, 'message admission unavailable'); END");
        try {
            $service->submit($input, null, null, $this->telemetry());
            self::fail('The failing message admission must be reported.');
        } catch (QueryException $error) {
            self::assertStringContainsString('message admission unavailable', $error->getMessage());
        } finally {
            DB::statement('DROP TRIGGER reject_first_support_message');
        }
        self::assertSame(1, FeedbackReport::query()->count());
        self::assertSame(0, SupportCaseMessage::query()->count());
        $result = $service->submit($input, null, null, $this->telemetry());
        self::assertTrue($result->replayed);
        self::assertSame(1, FeedbackReport::query()->count());
        self::assertSame(1, SupportCaseMessage::query()->count());
        self::assertSame(1, $result->report->events()->where('event_type', 'created')->count());
    }

    public function test_signed_in_retry_claims_guest_case_once_and_revokes_guest_access(): void
    {
        $input = $this->input();
        $service = app(SupportCaseSubmissionService::class);
        $guest = $service->submit($input, null, null, $this->telemetry());
        $owner = $this->user();
        $claimed = $service->submit($input, $owner, null, $this->telemetry());
        self::assertNull($claimed->accessToken);
        self::assertNull($claimed->report->guest_access_hash);
        self::assertSame($owner->id, (int) $claimed->report->user_id);
        $version = $claimed->report->version;
        app(SupportCaseService::class)->claim($guest->report, $owner, null);
        self::assertSame($version, $claimed->report->fresh()->version);
        self::assertSame(1, $claimed->report->events()->where('event_type', 'claimed')->count());
        try {
            app(SupportCaseAccessService::class)->authorizeViewer($claimed->report, null, $guest->accessToken);
            self::fail('Claimed cases must no longer accept the guest credential.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
    }

    public function test_another_account_cannot_replay_or_claim_an_owned_case(): void
    {
        $input = $this->input();
        $owner = $this->user();
        $other = $this->user();
        $service = app(SupportCaseSubmissionService::class);
        $result = $service->submit($input, $owner, null, $this->telemetry());
        try {
            $service->submit($input, $other, null, $this->telemetry());
            self::fail('Another account cannot take over a request identity.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        try {
            app(SupportCaseService::class)->claim($result->report, $other, null);
            self::fail('Claims must reauthorize the locked case.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        self::assertSame($owner->id, (int) $result->report->fresh()->user_id);
        self::assertSame(1, SupportCaseMessage::query()->count());
    }

    public function test_context_must_match_lesson_course_and_order_owner_before_case_creation(): void
    {
        $owner = $this->user();
        $course = Course::factory()->create(['tenant_id' => 1, 'is_coming_soon' => true]);
        $otherCourse = Course::factory()->create(['tenant_id' => 1, 'is_coming_soon' => true]);
        $lesson = Lesson::query()->create(['list_id' => $course->id, 'title_ar' => 'مقطع']);
        $order = Order::query()->create([
            'user_id' => $this->user()->id, 'course_id' => $course->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
        ]);
        foreach ([['course_id' => $otherCourse->id, 'lesson_id' => $lesson->id], ['order_id' => $order->id]] as $context) {
            try {
                app(SupportCaseSubmissionService::class)->submit($this->input() + $context, $owner, null, $this->telemetry());
                self::fail('Unowned or mismatched context must be rejected before admission.');
            } catch (HttpException $error) {
                self::assertSame(422, $error->getStatusCode());
            }
        }
        self::assertSame(0, FeedbackReport::query()->count());
    }

    private function input(): array
    {
        return [
            'client_request_id' => (string) Str::uuid(), 'category' => 'bug',
            'message' => '  تفاصيل المشكلة عند فتح الكورس  ', 'requester_email' => ' Learner@Example.Test ',
        ];
    }

    private function telemetry(): array
    {
        return [
            'platform' => 'android', 'app_version' => '1.0.76', 'build_number' => 77,
            'request_id' => str_repeat('a', 64), 'ip_hash' => str_repeat('b', 64), 'user_agent' => str_repeat('c', 64),
        ];
    }

    private function user(): User
    {
        return User::query()->forceCreate([
            'name' => 'Student', 'email' => Str::uuid().'@rokn.test', 'role' => 'client', 'active' => true,
        ]);
    }
}
