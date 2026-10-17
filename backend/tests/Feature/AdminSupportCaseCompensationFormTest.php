<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\Course;
use App\Models\FeedbackReport;
use App\Models\Order;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\SupportCaseCompensationService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminSupportCaseCompensationFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
        $this->withoutMiddleware(RequireAdminMfa::class);
    }

    public function test_case_page_states_the_immediate_wallet_credit_and_the_remaining_ceiling(): void
    {
        [$report] = $this->settledCase();

        $this->actingAs($this->user('admin'))
            ->get(route('admin.feedback.show', $report))
            ->assertOk()
            ->assertSee('تُضاف العملات إلى محفظة الطالب فور التأكيد', false)
            ->assertSee('الحد الأقصى المتاح للتعويض', false)
            ->assertSee('max="500"', false)
            ->assertSee('return confirm(', false)
            ->assertDontSee('ولا يُنفذ تلقائيًا', false);
    }

    public function test_ceiling_shrinks_after_a_credit_and_overdraw_is_refused_without_credit(): void
    {
        [$report, $order] = $this->settledCase();
        $admin = $this->user('admin');
        $service = app(SupportCaseCompensationService::class);
        self::assertSame(500, $service->remainingCompensableCoins($order));

        $this->actingAs($admin)->post(route('admin.feedback.compensate', $report), [
            'version' => 1, 'amount' => 200, 'note' => 'Verified playback outage',
        ])->assertSessionHas('success');
        self::assertSame(300, $service->remainingCompensableCoins($order->fresh()));

        $this->actingAs($admin)->post(route('admin.feedback.compensate', $report), [
            'version' => 2, 'amount' => 301, 'note' => 'Second verified outage',
        ])->assertSessionHas('error');
        self::assertSame(200, (int) WalletTransaction::query()->where('category', 'course_service_compensation')->sum('amount'));

        $this->actingAs($admin)->get(route('admin.feedback.show', $report))
            ->assertOk()->assertSee('max="300"', false);
    }

    public function test_ineligible_order_shows_no_compensation_form(): void
    {
        [$report, $order] = $this->settledCase();
        $order->update(['financial_status' => Order::FINANCIAL_REVIEW_REQUIRED]);

        self::assertSame(0, app(SupportCaseCompensationService::class)->remainingCompensableCoins($order->fresh()));
        $this->actingAs($this->user('admin'))
            ->get(route('admin.feedback.show', $report))
            ->assertOk()
            ->assertDontSee('data-course-compensation', false)
            ->assertSee('لا يتوفر تعويض لهذا الطلب', false);
    }

    public function test_moderator_cannot_reach_case_compensation(): void
    {
        [$report] = $this->settledCase();
        $this->actingAs($this->user('moderator'))->post(route('admin.feedback.compensate', $report), [
            'version' => 1, 'amount' => 10, 'note' => 'Moderator attempt',
        ])->assertForbidden();
        self::assertSame(0, WalletTransaction::query()->where('category', 'course_service_compensation')->count());
    }

    /** @return array{FeedbackReport, Order, User} */
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
        $report = FeedbackReport::query()->create([
            'public_id' => (string) Str::ulid(), 'category' => 'bug', 'status' => 'new',
            'priority' => 'normal', 'message' => 'تفاصيل المشكلة', 'version' => 1,
            'user_id' => $owner->id, 'order_id' => $order->id,
        ]);

        return [$report, $order, $owner];
    }

    private function user(string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Support '.$role, 'email' => Str::uuid().'@rokn.test', 'role' => $role, 'active' => true,
        ]);
    }
}
