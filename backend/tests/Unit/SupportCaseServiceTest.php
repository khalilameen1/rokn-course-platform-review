<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\FeedbackReport;
use App\Models\SupportCaseMessage;
use App\Services\SupportCaseService;
use App\Services\SupportCaseAccessService;
use App\Services\SupportCaseReadService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SupportCaseServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(str_repeat('a', 32))]);
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('role')->default('client');
            $table->timestamps();
        });
        Schema::create('feedback_reports', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('course_id')->nullable();
            $table->char('guest_access_hash', 64)->nullable();
            $table->string('category');
            $table->string('status')->default('new');
            $table->string('priority')->default('normal');
            $table->text('message');
            $table->unsignedInteger('version')->default(1);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('reopened_at')->nullable();
            $table->timestamp('last_user_message_at')->nullable();
            $table->timestamps();
        });
        Schema::create('support_case_messages', function (Blueprint $table): void {
            $table->id();
            $table->char('public_id', 26)->unique();
            $table->foreignId('feedback_report_id');
            $table->foreignId('author_id')->nullable();
            $table->string('author_type');
            $table->string('visibility');
            $table->text('body');
            $table->uuid('client_request_id')->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->timestamps();
            $table->unique(['feedback_report_id', 'client_request_id']);
        });
        Schema::create('support_case_events', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_report_id');
            $table->foreignId('actor_id')->nullable();
            $table->string('event_type');
            $table->string('from_status')->nullable();
            $table->string('to_status')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('feedback_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('feedback_report_id');
            $table->foreignId('support_case_message_id')->nullable();
            $table->string('disk');
            $table->string('path');
            $table->string('mime_type');
            $table->unsignedBigInteger('size_bytes');
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('feedback_attachments');
        Schema::dropIfExists('support_case_events');
        Schema::dropIfExists('support_case_messages');
        Schema::dropIfExists('feedback_reports');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_guest_token_is_digest_only_and_wrong_token_is_non_enumerable(): void
    {
        $service = app(SupportCaseAccessService::class);
        $credential = $service->createGuestCredential((string) Str::uuid());
        $report = $this->report($credential['hash']);
        $this->assertNotSame($credential['token'], $report->guest_access_hash);
        $service->authorizeViewer($report, null, $credential['token']);

        try {
            $service->authorizeViewer($report, null, 'wrong-token-value-that-is-long-enough');
            self::fail('Wrong token was accepted.');
        } catch (HttpException $exception) {
            self::assertSame(404, $exception->getStatusCode());
        }
    }

    public function test_customer_timeline_hides_internal_notes_and_retries_are_idempotent(): void
    {
        $service = app(SupportCaseService::class);
        $report = $this->report(null);
        $requestId = (string) Str::uuid();
        $first = $service->appendLearnerMessage($report, null, 'تفاصيل المشكلة كاملة', $requestId);
        $replayed = $service->appendLearnerMessage($report->fresh(), null, 'تفاصيل المشكلة كاملة', $requestId);
        self::assertSame($first->id, $replayed->id);

        SupportCaseMessage::query()->create([
            'public_id' => (string) Str::ulid(),
            'feedback_report_id' => $report->id,
            'author_type' => SupportCaseMessage::AUTHOR_STAFF,
            'visibility' => SupportCaseMessage::VISIBILITY_INTERNAL,
            'body' => 'معلومة داخلية لا يراها الطالب',
        ]);
        $payload = app(SupportCaseReadService::class)->customerPayload($report->fresh());
        self::assertCount(1, $payload['messages']);
        self::assertSame('تفاصيل المشكلة كاملة', $payload['messages'][0]['text']);
    }

    private function report(?string $guestHash): FeedbackReport
    {
        return FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(),
            'guest_access_hash' => $guestHash,
            'category' => 'bug',
            'status' => 'new',
            'priority' => 'normal',
            'message' => 'تفاصيل المشكلة كاملة',
            'version' => 1,
        ]);
    }
}
