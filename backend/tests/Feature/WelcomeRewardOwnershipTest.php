<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\SendUserPushNotification;
use App\Models\AdminNotification;
use App\Models\CoinEarningMethod;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\StudentNotification;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\EngagementMessageService;
use App\Services\StudentNotificationService;
use App\Services\WalletService;
use App\Services\WelcomeRewardOfferService;
use App\Services\WelcomeRewardService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class WelcomeRewardOwnershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Real commits: an outer test transaction would hide push dispatch.
        $this->artisan('migrate:fresh')->assertExitCode(0);
        Bus::fake();
        Http::preventStrayRequests();
        Setting::query()->firstOrCreate([])->update([
            'reward_balance_cap' => 1200,
            'recommended_social_provider' => 'google',
            'recommended_provider_bonus_coins' => 9,
        ]);
        $this->rule()->update(['coins_amount' => 20, 'is_active' => true]);
    }

    public function test_provider_offer_and_grant_use_one_amount_and_one_receipt(): void
    {
        $user = $this->student();
        $offer = app(WelcomeRewardOfferService::class);
        self::assertSame(29, $offer->amountForProvider(' GOOGLE '));
        self::assertSame(20, $offer->amountForProvider('apple'));
        self::assertSame(29, app(WelcomeRewardService::class)->grant($user, 'google'));
        self::assertSame(0, app(WelcomeRewardService::class)->grant($user, 'apple'));
        self::assertSame(29, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(29, (int) WalletTransaction::query()->sole()->amount);
        self::assertSame('welcome_bonus', WalletTransaction::query()->sole()->category);
        self::assertSame(29, (int) $user->coinEarnings()->sole()->amount);
        $receipt = StudentNotification::query()->sole();
        self::assertSame('registration-bonus:' . $user->id, $receipt->delivery_key);
        self::assertSame('coins_claimed', $receipt->notification_type);
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
        Bus::assertDispatched(SendUserPushNotification::class,
            fn ($job) => $job->uniqueId() === 'notification:' . $receipt->id);
    }

    public function test_welcome_receipt_uses_its_own_template_not_the_generic_task_template(): void
    {
        $this->template('coins_claimed')->update(['is_active' => false]);
        $this->template('welcome_bonus_received')->update([
            'title_ar' => 'هديتك وصلت', 'title_en' => 'Welcome reward',
            'description_ar' => '{coins} عملة ترحيب',
            'description_en' => '{coins} welcome coins',
        ]);
        self::assertSame(29, app(WelcomeRewardService::class)->grant($this->student()));
        $receipt = StudentNotification::query()->sole();
        self::assertSame('coins_claimed', $receipt->notification_type);
        self::assertSame('هديتك وصلت', $receipt->title_ar);
        self::assertSame('Welcome reward', $receipt->title_en);
        self::assertSame('٢٩ عملة ترحيب', $receipt->message_ar);
        self::assertSame('29 welcome coins', $receipt->message_en);
    }

    public function test_disabled_receipt_does_not_disable_the_reward_and_replay_can_restore_receipt(): void
    {
        $template = $this->template('welcome_bonus_received');
        $template->update(['is_active' => false]);
        $user = $this->student();
        $service = app(WelcomeRewardService::class);
        self::assertSame(29, $service->grant($user));
        self::assertSame(0, StudentNotification::query()->count());
        Bus::assertNothingDispatched();

        $template->update(['is_active' => true]);
        self::assertSame(0, $service->grant($user));
        self::assertSame(29, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(1, WalletTransaction::query()->count());
        self::assertSame(1, StudentNotification::query()->count());
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_repair_uses_committed_amount_even_after_the_dashboard_offer_changes(): void
    {
        $user = $this->student();
        $service = app(WelcomeRewardService::class);
        self::assertSame(29, $service->grant($user));
        StudentNotification::query()->delete();
        $user->coinEarnings()->delete();
        $this->rule()->update(['coins_amount' => 40]);
        self::assertSame(49, app(WelcomeRewardOfferService::class)->amountForProvider('google'));

        self::assertSame(0, $service->grant($user));
        self::assertSame(29, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(29, (int) $user->coinEarnings()->sole()->amount);
        self::assertStringContainsString('٢٩', StudentNotification::query()->sole()->message_ar);
        self::assertSame(1, WalletTransaction::query()->count());
    }

    public function test_legacy_audit_without_a_ledger_entry_is_not_paid_again(): void
    {
        $user = $this->student();
        $method = CoinEarningMethod::query()->where('action_key', 'register')->firstOrFail();
        $user->coinEarnings()->create(['coin_earning_method_id' => $method->id, 'amount' => 29]);
        self::assertSame(0, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, StudentNotification::query()->count());
        self::assertSame(1, $user->coinEarnings()->count());
        Bus::assertNothingDispatched();
    }

    public function test_inactive_audit_method_does_not_replace_the_canonical_offer(): void
    {
        CoinEarningMethod::query()->where('action_key', 'register')->update(['is_active' => false]);
        $user = $this->student();
        self::assertSame(29, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(0, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(0, $user->coinEarnings()->count());
        self::assertSame(1, WalletTransaction::query()->count());
        self::assertNull(StudentNotification::query()->sole()->notifiable_id);
    }

    public function test_offer_larger_than_the_cap_cannot_be_promised_or_consumed(): void
    {
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 25]);
        $user = $this->student();
        self::assertSame(0, app(WelcomeRewardOfferService::class)->amountForProvider('google'));
        self::assertSame(0, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, $user->coinEarnings()->count());
        self::assertSame(0, StudentNotification::query()->count());
    }

    public function test_remaining_wallet_room_does_not_turn_a_one_time_offer_into_a_partial_grant(): void
    {
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 30]);
        $user = $this->student();
        app(WalletService::class)->creditRewardWithinConfiguredCap(
            $user->id, 10, 'task_reward', 'existing-reward'
        );
        self::assertSame(29, app(WelcomeRewardOfferService::class)->amountForProvider('google'));
        self::assertSame(0, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(10, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, $user->coinEarnings()->count());
        self::assertSame(0, StudentNotification::query()->count());
        self::assertSame(0, WalletTransaction::query()->where('category', 'welcome_bonus')->count());
    }

    public function test_receipt_failure_rolls_back_credit_and_audit_and_retry_can_complete(): void
    {
        $user = $this->student();
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('welcomeRewardReceipt')->once()->andThrow(new \RuntimeException('receipt unavailable'));
        });
        try {
            app(WelcomeRewardService::class)->grant($user);
            self::fail('Receipt failure must roll back the grant.');
        } catch (\RuntimeException $exception) {
            self::assertSame('receipt unavailable', $exception->getMessage());
        }
        self::assertSame(0, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, $user->coinEarnings()->count());
        Bus::assertNothingDispatched();

        $this->app->forgetInstance(StudentNotificationService::class);
        self::assertSame(29, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(1, StudentNotification::query()->count());
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_push_is_not_enqueued_until_the_enclosing_login_transaction_commits(): void
    {
        $user = $this->student();
        DB::transaction(function () use ($user): void {
            self::assertSame(29, app(WelcomeRewardService::class)->grant($user));
            self::assertSame(1, StudentNotification::query()->count());
            Bus::assertNothingDispatched();
        });
        Bus::assertDispatchedTimes(SendUserPushNotification::class, 1);
    }

    public function test_enclosing_login_rollback_does_not_leave_a_reward_or_a_push(): void
    {
        $user = $this->student();
        DB::beginTransaction();
        try {
            self::assertSame(29, app(WelcomeRewardService::class)->grant($user));
        } finally {
            DB::rollBack();
        }
        self::assertSame(0, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(0, WalletTransaction::query()->count());
        self::assertSame(0, StudentNotification::query()->count());
        self::assertSame(0, $user->coinEarnings()->count());
        Bus::assertNothingDispatched();
    }

    public function test_broker_failure_does_not_undo_the_committed_grant_or_duplicate_it(): void
    {
        $dispatcher = \Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->once()->andThrow(new \RuntimeException('broker unavailable'));
        $this->app->instance(Dispatcher::class, $dispatcher);
        $user = $this->student();
        self::assertSame(29, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(0, app(WelcomeRewardService::class)->grant($user));
        self::assertSame(29, (int) $user->fresh()->wallet_reward_coins);
        self::assertSame(1, WalletTransaction::query()->count());
        self::assertNull(StudentNotification::query()->sole()->push_attempted_at);
    }

    public function test_guest_message_uses_the_same_capped_offer_without_constructing_a_grant_service(): void
    {
        $this->app->bind(WelcomeRewardService::class, static fn () => throw new \LogicException('No grant owner on a read path.'));
        Setting::query()->firstOrFail()->update(['reward_balance_cap' => 10]);
        $offer = app(WelcomeRewardOfferService::class)->amountForProvider();
        self::assertSame(0, $offer);
        $message = app(EngagementMessageService::class)->publicMessage('guest_registration_prompt');
        self::assertNotNull($message);
        self::assertSame($offer, $message['coins']);
        $this->getJson('/api/v1/engagement/messages/guest_registration_prompt')
            ->assertOk()->assertJsonPath('data.coins', $offer);
        self::assertSame(0, WalletTransaction::query()->count());
        Bus::assertNothingDispatched();
    }

    private function student(): User
    {
        return User::query()->forceCreate([
            'name' => 'Welcome Student', 'name_ar' => 'طالب ركن', 'name_en' => 'Welcome Student',
            'email' => 'welcome-owner@rokn.test', 'role' => 'client', 'active' => true,
            'social_provider' => 'google', 'social_id' => 'welcome-owner-google',
            'wallet_coins' => 0, 'wallet_reward_coins' => 0, 'wallet_purchased_coins' => 0,
        ]);
    }

    private function rule(): RewardRule
    {
        return RewardRule::query()->where('event_key', 'welcome_bonus')->firstOrFail();
    }

    private function template(string $key): AdminNotification
    {
        return AdminNotification::query()->where('system_key', $key)->firstOrFail();
    }
}
