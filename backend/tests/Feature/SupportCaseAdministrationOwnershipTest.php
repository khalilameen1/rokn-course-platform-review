<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\FeedbackController;
use App\Models\Course;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\Order;
use App\Models\SupportCaseEvent;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SupportCaseAttachmentDeliveryService;
use App\Services\SupportCaseCompensationService;
use App\Services\SupportCaseService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

final class SupportCaseAdministrationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        Storage::fake('feedback');
        $this->freezeTime();
        $this->app->bind(FeedbackController::class, static function (): never {
            throw new \LogicException('Support commands must not resolve their dashboard adapter.');
        });
    }

    public function test_state_change_records_actor_and_replay_does_not_duplicate_event(): void
    {
        $report = $this->report();
        $actor = $this->user('admin');
        $input = ['version' => 1, 'status' => 'reviewing', 'priority' => 'high', 'assigned_to' => $actor->id];
        $service = app(SupportCaseService::class);
        $service->updateState($report, $input, $actor->id);
        $service->updateState($report, $input, $actor->id);
        $fresh = $report->fresh();
        self::assertSame(2, $fresh->version);
        self::assertSame('reviewing', $fresh->status);
        self::assertSame(now()->addHours(8)->startOfSecond()->toISOString(), $fresh->first_response_due_at->toISOString());
        $event = $report->events()->sole();
        self::assertSame($actor->id, (int) $event->actor_id);
        self::assertSame('updated', $event->event_type);
        self::assertSame('high', $event->metadata['priority']);
        Http::assertNothingSent();
    }

    public function test_genuinely_stale_state_edit_is_rejected_without_an_event(): void
    {
        $report = $this->report(['version' => 2, 'status' => 'reviewing']);
        try {
            app(SupportCaseService::class)->updateState($report, [
                'version' => 1, 'status' => 'resolved', 'priority' => 'normal', 'resolution_kind' => 'fixed',
            ], null);
            self::fail('A different stale desired state must be rejected.');
        } catch (HttpException $error) {
            self::assertSame(409, $error->getStatusCode());
        }
        self::assertSame('reviewing', $report->fresh()->status);
        self::assertSame(0, $report->events()->count());
    }

    public function test_compensation_credits_the_verified_order_and_links_case_event(): void
    {
        [$report, $order, $owner] = $this->settledCase();
        $actor = $this->user('admin');
        app(SupportCaseCompensationService::class)->compensate($report, [
            'version' => 1, 'amount' => 100, 'note' => '  Confirmed course delivery issue  ',
        ], $actor->id);
        self::assertSame('compensated', $report->fresh()->resolution_kind);
        self::assertSame(2, $report->fresh()->version);
        $credit = WalletTransaction::query()->where('category', 'course_service_compensation')->sole();
        self::assertSame($owner->id, (int) $credit->user_id);
        self::assertSame(100, $credit->amount);
        self::assertSame($order->id, (int) $credit->source_id);
        self::assertSame(Order::STATUS_APPROVED, $order->fresh()->status);
        self::assertSame(Order::FINANCIAL_SETTLED, $order->fresh()->financial_status);
        $event = $report->events()->sole();
        self::assertSame('compensated', $event->event_type);
        self::assertSame($actor->id, (int) $event->actor_id);
        self::assertSame($order->id, (int) $event->metadata['order_id']);
        self::assertStringStartsWith('support-case-compensation:'.$report->id.':', $event->metadata['compensation_event_key']);
    }

    public function test_compensation_and_case_record_roll_back_together(): void
    {
        [$report] = $this->settledCase();
        $before = WalletTransaction::query()->count();
        try {
            DB::transaction(function () use ($report): void {
                app(SupportCaseCompensationService::class)->compensate($report, [
                    'version' => 1, 'amount' => 100, 'note' => 'Confirmed delivery issue',
                ], null);
                throw new \RuntimeException('outer command failed');
            });
            self::fail('The enclosing operation must roll back.');
        } catch (\RuntimeException $error) {
            self::assertSame('outer command failed', $error->getMessage());
        }
        self::assertSame($before, WalletTransaction::query()->count());
        self::assertSame(1, $report->fresh()->version);
        self::assertNull($report->fresh()->resolution_kind);
        self::assertSame(0, $report->events()->count());
    }

    public function test_compensation_rejects_stale_version_and_wrong_order_owner_without_credit(): void
    {
        [$report] = $this->settledCase();
        $service = app(SupportCaseCompensationService::class);
        foreach ([0, 1] as $version) {
            if ($version === 1) $report->update(['user_id' => $this->user()->id]);
            try {
                $service->compensate($report, [
                    'version' => $version, 'amount' => 100, 'note' => 'Investigated order issue',
                ], null);
                self::fail('Invalid case/order context must not create compensation.');
            } catch (HttpException $error) {
                self::assertSame($version === 0 ? 409 : 422, $error->getStatusCode());
            }
        }
        self::assertSame(0, WalletTransaction::query()->where('category', 'course_service_compensation')->count());
        self::assertSame(0, SupportCaseEvent::query()->count());
    }

    public function test_attachment_delivery_verifies_bytes_and_marks_a_digest_mismatch(): void
    {
        $attachment = $this->attachment();
        $service = app(SupportCaseAttachmentDeliveryService::class);
        self::assertSame('sanitized-test-bytes', $service->bytes($attachment));
        self::assertSame('sanitized', $attachment->fresh()->scan_status);
        Storage::disk('feedback')->put($attachment->path, 'changed-bytes');
        try {
            $service->bytes($attachment);
            self::fail('Corrupted bytes must never be delivered.');
        } catch (HttpException $error) {
            self::assertSame(410, $error->getStatusCode());
        }
        self::assertSame('corrupt', $attachment->fresh()->scan_status);
    }

    public function test_attachment_delivery_rejects_unsanitized_and_missing_files(): void
    {
        $attachment = $this->attachment();
        $service = app(SupportCaseAttachmentDeliveryService::class);
        $attachment->update(['scan_status' => 'corrupt']);
        try {
            $service->bytes($attachment);
            self::fail('Unsanitized files must not be delivered.');
        } catch (HttpException $error) {
            self::assertSame(404, $error->getStatusCode());
        }
        $attachment->update(['scan_status' => 'sanitized']);
        Storage::disk('feedback')->delete($attachment->path);
        try {
            $service->bytes($attachment);
            self::fail('Missing files must be reported as gone.');
        } catch (HttpException $error) {
            self::assertSame(410, $error->getStatusCode());
        }
    }

    private function attachment(): FeedbackAttachment
    {
        $path = 'support/'.Str::uuid().'.jpg';
        Storage::disk('feedback')->put($path, 'sanitized-test-bytes');
        return FeedbackAttachment::query()->create([
            'feedback_report_id' => $this->report()->id, 'disk' => 'feedback', 'path' => $path,
            'mime_type' => 'image/jpeg', 'size_bytes' => strlen('sanitized-test-bytes'),
            'sha256' => hash('sha256', 'sanitized-test-bytes'), 'scan_status' => 'sanitized',
        ]);
    }

    private function settledCase(): array
    {
        $owner = $this->user();
        $course = Course::factory()->create(['tenant_id' => 1, 'is_coming_soon' => true]);
        $wallet = app(WalletService::class);
        $wallet->credit($owner->id, 500, 'test-credit', (string) Str::uuid());
        $debit = $wallet->debit($owner->id, 500, 'course_purchase', (string) Str::uuid(), $course);
        $order = Order::query()->create([
            'user_id' => $owner->id, 'course_id' => $course->id,
            'wallet_transaction_id' => $debit->id, 'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS,
            'amount' => 500, 'final_amount' => 500, 'total_coins' => 500,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED,
        ]);

        return [$this->report(['user_id' => $owner->id, 'order_id' => $order->id]), $order, $owner];
    }

    private function report(array $extra = []): FeedbackReport
    {
        return FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new',
            'priority' => 'normal', 'message' => 'تفاصيل المشكلة', 'version' => 1, ...$extra,
        ]);
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Support user', 'email' => Str::uuid().'@rokn.test', 'role' => $role, 'active' => true,
        ]);
    }
}
