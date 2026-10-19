<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Filament\Resources\FeedbackResource\Pages\ViewFeedback;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackReport;
use App\Models\Order;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\WalletService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\TestCase;

final class FilamentSupportCaseWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
        Storage::fake('feedback');
        $this->actingAs($this->user('admin'), 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_native_case_sends_customer_reply_and_internal_note_through_the_same_owner(): void
    {
        $report = $this->report();
        $page = Livewire::test(ViewFeedback::class, ['record' => $report->id]);
        self::assertSame(0, $report->messages()->count());
        $page->callAction('message', data: ['message' => 'ملاحظة داخلية للفريق', 'visibility' => 'internal'])->assertHasNoActionErrors();
        $page->callAction('message', data: ['message' => 'رد واضح للطالب', 'visibility' => 'customer'])->assertHasNoActionErrors();
        self::assertSame(2, $report->messages()->count());
        self::assertSame(1, $report->messages()->where('visibility', 'internal')->count());
        self::assertSame('reviewing', $report->fresh()->status);
        self::assertSame(3, $report->fresh()->version);
        $page->assertSee('ملاحظة داخلية للفريق')->assertSee('رد واضح للطالب')->assertSee('مشكلة تحتاج مراجعة')->assertSee('ملاحظة داخلية — حساب admin');
        self::assertCount(3, $page->instance()->conversation());
    }

    public function test_native_state_action_and_stale_page_do_not_overwrite_newer_decisions(): void
    {
        $report = $this->report();
        $stale = Livewire::test(ViewFeedback::class, ['record' => $report->id]);
        Livewire::test(ViewFeedback::class, ['record' => $report->id])->callAction('state', data: [
            'status' => 'reviewing', 'priority' => 'high', 'assigned_to' => auth('web')->id(), 'resolution_kind' => null,
        ])->assertHasNoActionErrors();
        self::assertSame('high', $report->fresh()->priority);
        $stale->callAction('state', data: ['status' => 'closed', 'priority' => 'normal', 'assigned_to' => null, 'resolution_kind' => 'fixed']);
        self::assertSame('reviewing', $report->fresh()->status);
        self::assertSame(1, $report->events()->count());
    }

    public function test_current_initial_message_is_not_duplicated_in_the_conversation(): void
    {
        $requestId = (string) Str::uuid();
        $report = $this->report(['client_request_id' => $requestId]);
        SupportCaseMessage::query()->create(['public_id' => (string) Str::ulid(), 'feedback_report_id' => $report->id,
            'author_type' => 'learner', 'visibility' => 'customer', 'body' => $report->message,
            'client_request_id' => $requestId, 'request_fingerprint' => hash('sha256', 'initial')]);
        $page = Livewire::test(ViewFeedback::class, ['record' => $report->id])->assertSee($report->message);
        self::assertCount(1, $page->instance()->conversation());
    }

    public function test_compensation_needs_confirmation_obeys_ceiling_and_credits_once_through_the_order_ledger(): void
    {
        [$report, $owner] = $this->settledCase();
        $data = ['amount' => 100, 'note' => 'عطل تشغيل موثق بعد المراجعة'];
        Livewire::test(ViewFeedback::class, ['record' => $report->id])->callAction('compensate', data: $data)
            ->assertHasActionErrors(['confirm_credit']);
        self::assertSame(0, WalletTransaction::query()->where('category', 'course_service_compensation')->count());
        Livewire::test(ViewFeedback::class, ['record' => $report->id])->callAction('compensate', data: [...$data, 'amount' => 501, 'confirm_credit' => true])
            ->assertHasActionErrors(['amount' => 'max']);
        $page = Livewire::test(ViewFeedback::class, ['record' => $report->id]);
        $page->callAction('compensate', data: [...$data, 'confirm_credit' => true])->assertHasNoActionErrors();
        self::assertSame(100, (int) $owner->fresh()->wallet_coins);
        self::assertSame(100, (int) WalletTransaction::query()->where('category', 'course_service_compensation')->sum('amount'));
        self::assertSame(400, $page->instance()->compensableCoins());
        self::assertSame('compensated', $report->fresh()->resolution_kind);
    }

    public function test_private_attachments_are_linked_through_the_authorized_delivery_route_and_case_get_does_not_write(): void
    {
        $report = $this->report(['guest_access_hash' => 'must-not-render-guest-secret']);
        Storage::disk('feedback')->put('private/native.jpg', 'test-image-bytes');
        $attachment = FeedbackAttachment::query()->create(['feedback_report_id' => $report->id, 'disk' => 'feedback', 'path' => 'private/native.jpg',
            'mime_type' => 'image/jpeg', 'sha256' => hash('sha256', 'test-image-bytes'), 'size_bytes' => 16, 'scan_status' => 'sanitized']);
        $before = $report->refresh()->getAttributes();
        Livewire::test(ViewFeedback::class, ['record' => $report->id])->assertSee(route('admin.feedback.attachment', [$report, $attachment]))
            ->assertDontSee('private/native.jpg')->assertDontSee('must-not-render-guest-secret');
        $this->get(route('admin.feedback.attachment', [$report, $attachment]))->assertOk()->assertContent('test-image-bytes');
        self::assertSame($before, $report->fresh()->getAttributes());
        self::assertSame(0, $report->events()->count());
    }

    public function test_portfolio_report_keeps_the_existing_suspend_restore_workflow_and_permissions(): void
    {
        $owner = $this->user();
        $report = $this->report(['screen_key' => 'public_portfolio_report', 'context' => ['portfolio_owner_id' => $owner->id]]);
        $page = Livewire::test(ViewFeedback::class, ['record' => $report->id]);
        $page->callAction('portfolioSharing')->assertHasNoActionErrors();
        self::assertNotNull($owner->fresh()->portfolio_sharing_suspended_at);
        $page->callAction('portfolioSharing')->assertHasNoActionErrors();
        self::assertNull($owner->fresh()->portfolio_sharing_suspended_at);
        $this->actingAs($this->user('moderator'), 'web');
        Livewire::test(ViewFeedback::class, ['record' => $report->id])->assertForbidden();
    }

    private function report(array $data = []): FeedbackReport
    {
        return FeedbackReport::query()->create(['public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new', 'priority' => 'normal',
            'message' => 'مشكلة تحتاج مراجعة', 'version' => 1, ...$data]);
    }

    private function settledCase(): array
    {
        $owner = $this->user();
        $course = Course::factory()->create(['tenant_id' => 1, 'is_coming_soon' => true]);
        $wallet = app(WalletService::class);
        $wallet->credit($owner->id, 500, 'test-credit', (string) Str::uuid());
        $debit = $wallet->debit($owner->id, 500, 'course_purchase', (string) Str::uuid(), $course);
        $order = Order::query()->create(['user_id' => $owner->id, 'course_id' => $course->id, 'wallet_transaction_id' => $debit->id,
            'payment_method' => Order::PAYMENT_METHOD_WALLET_COINS, 'amount' => 500, 'final_amount' => 500, 'total_coins' => 500,
            'status' => Order::STATUS_APPROVED, 'financial_status' => Order::FINANCIAL_SETTLED]);

        return [$this->report(['user_id' => $owner->id, 'order_id' => $order->id]), $owner];
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate(['name' => 'حساب '.$role, 'email' => Str::uuid().'@native-support.test', 'role' => $role, 'active' => true]);
    }
}
