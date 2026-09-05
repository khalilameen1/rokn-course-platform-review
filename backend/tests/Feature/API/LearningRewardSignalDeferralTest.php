<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use App\Jobs\ProcessInternalSignal;
use App\Models\Course;
use App\Models\InternalSignal;
use App\Models\RewardRule;
use App\Services\InternalSignalHandler;
use App\Services\LearningRewardService;
use App\Services\WalletService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

final class LearningRewardSignalDeferralTest extends ApiTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        DB::table('settings')->update(['reward_balance_cap' => 1000]);
        DB::table('wallet_transactions')->where('user_id', $this->user->id)->delete();
        $this->user->forceFill([
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ])->save();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_reward_effect_waits_for_balance_room_without_delaying_its_badge_sibling(): void
    {
        Carbon::setTestNow('2026-09-05 09:00:00');
        DB::table('settings')->update(['reward_balance_cap' => 100]);
        $wallet = app(WalletService::class);
        $wallet->credit(
            (int) $this->user->id,
            95,
            'test_reward_balance',
            'test-reward-balance-anchor'
        );

        $parent = $this->signal('course.completed', 'completion-parent', [
            'user_id' => (int) $this->user->id,
            'course_id' => $this->courseId,
            'curriculum_revision' => 1,
            'reward_contract' => $this->contract(10, 100),
        ]);
        $this->process($parent);
        self::assertSame(InternalSignal::STATUS_HANDLED, $parent->fresh()->status);

        $reward = InternalSignal::query()->where('type', 'course.completed.reward')->sole();
        $badge = InternalSignal::query()->where('type', 'course.completed.badge')->sole();
        $this->process($reward);
        $reward->refresh();
        self::assertSame(InternalSignal::STATUS_PENDING, $reward->status);
        self::assertSame(
            now()->addHours(12)->timestamp,
            $reward->available_at?->timestamp
        );
        self::assertNull($reward->last_error_fingerprint);
        $this->assertDatabaseMissing('wallet_transactions', [
            'idempotency_key' => "course-completion-reward:{$this->user->id}:{$this->courseId}",
        ]);

        $this->process($badge);
        self::assertSame(InternalSignal::STATUS_HANDLED, $badge->fresh()->status);

        $wallet->debit(
            (int) $this->user->id,
            95,
            'test_spend',
            'test-reward-balance-spend'
        );
        Carbon::setTestNow($reward->available_at->copy()->addSecond());
        $this->process($reward);
        self::assertSame(InternalSignal::STATUS_HANDLED, $reward->fresh()->status);
        $this->assertDatabaseHas('wallet_transactions', [
            'idempotency_key' => "course-completion-reward:{$this->user->id}:{$this->courseId}",
            'amount' => 10,
        ]);

        $this->process($reward);
        self::assertSame(1, DB::table('wallet_transactions')
            ->where('idempotency_key', "course-completion-reward:{$this->user->id}:{$this->courseId}")
            ->count());
    }

    public function test_rolling_cap_waits_until_enough_credit_has_really_expired(): void
    {
        $businessTimezone = (string) config('app.business_timezone', 'Africa/Cairo');
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-08 09:00:00', $businessTimezone));
        $anchorAt = CarbonImmutable::parse('2026-10-10 09:00:00', $businessTimezone)->utc();
        $wallet = app(WalletService::class);
        $anchor = $wallet->credit(
            (int) $this->user->id,
            95,
            'course_completion_reward',
            'old-course-completion-reward'
        );
        $anchor->forceFill(['occurred_at' => $anchorAt])->save();

        $signal = $this->signal('course.completed.reward', 'rolling-cap-effect', [
            'user_id' => (int) $this->user->id,
            'course_id' => $this->courseId,
            'reward_contract' => $this->contract(10, 100),
        ]);
        $this->process($signal);
        $signal->refresh();

        $rollingExpiry = $anchorAt
            ->setTimezone($businessTimezone)
            ->addDays(30)
            ->addSecond();
        self::assertNotSame(
            $anchorAt->setTimezone($businessTimezone)->utcOffset(),
            $rollingExpiry->utcOffset()
        );
        self::assertSame(InternalSignal::STATUS_PENDING, $signal->status);
        self::assertSame(
            $rollingExpiry->utc()->timestamp,
            $signal->available_at?->timestamp
        );

        Carbon::setTestNow($signal->available_at->copy()->addSecond());
        $this->process($signal);
        self::assertSame(InternalSignal::STATUS_HANDLED, $signal->fresh()->status);
        self::assertSame(1, DB::table('wallet_transactions')
            ->where('idempotency_key', "course-completion-reward:{$this->user->id}:{$this->courseId}")
            ->count());
    }

    public function test_first_project_effect_uses_the_same_deferred_settlement(): void
    {
        Carbon::setTestNow('2026-09-05 09:00:00');
        DB::table('settings')->update(['reward_balance_cap' => 100]);
        $wallet = app(WalletService::class);
        $wallet->credit(
            (int) $this->user->id,
            95,
            'test_reward_balance',
            'first-project-balance-anchor'
        );
        $projectId = (int) DB::table('projects')->insertGetId([
            'requirements_text' => 'نفذ المشروع',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_sections')->insert([
            'course_id' => $this->courseId,
            'module_id' => $this->moduleId,
            'title_ar' => 'مشروع العبور',
            'section_type' => 'project',
            'sectionable_type' => \App\Models\Project::class,
            'sectionable_id' => $projectId,
            'order' => 2,
            'sort_order' => 2,
            'is_free' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signal = $this->signal('project.passed.first_reward', 'first-project-effect', [
            'user_id' => (int) $this->user->id,
            'project_id' => $projectId,
            'course_id' => $this->courseId,
            'reward_contract' => $this->contract(10, 100, 'first_project_passed'),
        ]);
        $this->process($signal);
        $signal->refresh();
        self::assertSame(InternalSignal::STATUS_PENDING, $signal->status);

        $wallet->debit(
            (int) $this->user->id,
            95,
            'test_spend',
            'first-project-balance-spend'
        );
        Carbon::setTestNow($signal->available_at->copy()->addSecond());
        $this->process($signal);

        self::assertSame(InternalSignal::STATUS_HANDLED, $signal->fresh()->status);
        self::assertSame(1, DB::table('wallet_transactions')
            ->where('idempotency_key', "first-project-reward:{$this->user->id}")
            ->count());
    }

    public function test_terminal_contracts_settle_and_an_existing_ledger_credit_is_a_replay(): void
    {
        $impossible = $this->signal('course.completed.reward', 'impossible-contract', [
            'user_id' => (int) $this->user->id,
            'course_id' => $this->courseId,
            'reward_contract' => $this->contract(10, 5),
        ]);
        $this->process($impossible);
        self::assertSame(InternalSignal::STATUS_HANDLED, $impossible->fresh()->status);

        $zero = $this->signal('course.completed.reward', 'zero-contract', [
            'user_id' => (int) $this->user->id,
            'course_id' => $this->courseId,
            'reward_contract' => $this->contract(0, 0),
        ]);
        $this->process($zero);
        self::assertSame(InternalSignal::STATUS_HANDLED, $zero->fresh()->status);

        app(LearningRewardService::class)->awardCourseCompletion(
            $this->user,
            Course::query()->findOrFail($this->courseId),
            $this->contract(10, 100)
        );
        $replay = $this->signal('course.completed.reward', 'settled-replay', [
            'user_id' => (int) $this->user->id,
            'course_id' => $this->courseId,
            'reward_contract' => $this->contract(10, 100),
        ]);
        $this->process($replay);
        self::assertSame(InternalSignal::STATUS_HANDLED, $replay->fresh()->status);
        self::assertSame(1, DB::table('wallet_transactions')
            ->where('idempotency_key', "course-completion-reward:{$this->user->id}:{$this->courseId}")
            ->count());
    }

    public function test_scholarship_exclusion_is_terminal_not_deferred(): void
    {
        $grantId = (int) DB::table('course_codes')->insertGetId([
            'code' => 'REWARD-GRANT',
            'type' => 'course',
            'course_id' => $this->courseId,
            'is_active' => true,
            'is_grant' => true,
            'is_used' => false,
            'used_count' => 0,
            'max_uses' => 10,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $orderId = (int) DB::table('orders')->insertGetId([
            'order_ref' => 'reward-grant-order',
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'course_code_id' => $grantId,
            'payment_method' => 'course_code',
            'amount' => 0,
            'final_amount' => 0,
            'status' => 'approved',
            'financial_status' => 'settled',
            'total_coins' => 0,
            'paid_coins' => 0,
            'reward_coins' => 0,
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('course_enrollments')->insert([
            'user_id' => $this->user->id,
            'course_id' => $this->courseId,
            'order_id' => $orderId,
            'is_active' => true,
            'enrolled_at' => now(),
            'access_granted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $signal = $this->signal('course.completed.reward', 'scholarship-effect', [
            'user_id' => (int) $this->user->id,
            'course_id' => $this->courseId,
            'reward_contract' => $this->contract(10, 100),
        ]);
        $this->process($signal);

        self::assertSame(InternalSignal::STATUS_HANDLED, $signal->fresh()->status);
        $this->assertDatabaseMissing('wallet_transactions', [
            'idempotency_key' => "course-completion-reward:{$this->user->id}:{$this->courseId}",
        ]);
    }

    /** @return array<string,int> */
    private function contract(
        int $coins,
        int $rollingCap,
        string $event = 'course_completed'
    ): array
    {
        return [
            'rule_id' => (int) RewardRule::query()
                ->where('event_key', $event)
                ->value('id'),
            'coins_amount' => $coins,
            'rolling_30_day_cap' => $rollingCap,
        ];
    }

    /** @param array<string,mixed> $payload */
    private function signal(string $type, string $identity, array $payload): InternalSignal
    {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);

        return InternalSignal::query()->create([
            'signal_key' => hash('sha256', $type.'|'.$identity),
            'type' => $type,
            'payload_fingerprint' => hash('sha256', $encoded),
            'payload' => $payload,
            'status' => InternalSignal::STATUS_PENDING,
            'available_at' => now(),
        ]);
    }

    private function process(InternalSignal $signal): void
    {
        (new ProcessInternalSignal((int) $signal->id, (string) $signal->type))
            ->handle(app(InternalSignalHandler::class));
    }
}
