<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\StoreNotificationAuthenticityVerifier;
use App\Contracts\StorePurchaseProviderGateway;
use App\Data\VerifiedStorePurchase;
use App\Exceptions\StorePurchaseVerificationException;
use App\Models\Order;
use App\Models\Package;
use App\Models\StoreNotificationEvent;
use App\Models\StorePurchase;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\OrderLifecycleService;
use App\Services\PaymentChannelReportService;
use App\Services\StoreBillingAccountIdentity;
use App\Services\StorePurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class StorePurchaseRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        Http::preventStrayRequests();
    }

    public static function receiptEnvironments(): array
    {
        return [['google', 'test'], ['apple', 'sandbox']];
    }

    #[DataProvider('receiptEnvironments')]
    public function test_verified_store_test_receipts_credit_in_production_with_zero_cash(string $provider, string $environment): void
    {
        [$user, $package, $gateway, $binding] = $this->fixture($provider);
        $this->app->instance('env', 'production');
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt($provider, $environment, $binding));
        if ($provider === 'google') {
            $gateway->shouldReceive('consumeGoogle')->once()->with($package->google_product_id, 'receipt-token', $binding)->andReturnNull();
        } else {
            $gateway->shouldNotReceive('consumeGoogle');
        }

        $first = $this->credit($user, $provider);
        $again = $this->credit($user, $provider);
        self::assertTrue($first['credited']);
        self::assertTrue($again['already_processed']);
        self::assertSame($environment, $first['environment']);
        self::assertSame($provider === 'google', $first['store_finalized']);
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(1, Order::query()->count());
        self::assertSame(1, WalletTransaction::query()->where('category', 'package_purchase')->count());
        $order = Order::query()->firstOrFail();
        foreach (['amount', 'final_amount', 'gateway_gross_amount', 'gateway_fee_amount', 'gateway_net_amount'] as $field) {
            self::assertSame('0.00', $order->{$field}, $field);
        }
        self::assertSame('test_purchase', $order->gateway_settlement_status);
        self::assertSame(600, (int) $order->paidCreditLot->original_amount);
        self::assertTrue($order->paidCreditLot->metadata['store_test_purchase']);
        $this->assertDatabaseHas('package_user', ['order_id' => $order->id, 'price' => 0, 'coins' => 600]);
        $channel = app(PaymentChannelReportService::class)->summary()['rows']->firstWhere('method', $order->payment_method);
        self::assertSame(1, $channel['test_count']);
        self::assertSame(0, $channel['live_count']);
        self::assertSame(0.0, $channel['gross_amount']);
        self::assertSame(0.0, $channel['confirmed_net_amount']);
    }

    public function test_client_sandbox_flags_cannot_relabel_a_real_receipt(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('google', 'production', $binding));
        $gateway->shouldReceive('consumeGoogle')->once()->andReturnNull();
        $this->actingAs($user, 'api')->postJson('/api/v1/store-purchases/verify', [
            'provider' => 'google', 'product_id' => 'rokn.coins.recovery.600',
            'purchase_token' => 'receipt-token', 'environment' => 'sandbox', 'test_purchase' => true,
        ])->assertOk()->assertJsonPath('data.environment', 'production')->assertJsonPath('data.coins_added', 600);
        $order = Order::query()->firstOrFail();
        self::assertSame('120.00', $order->final_amount);
        self::assertSame('149.00', $order->gateway_gross_amount);
        self::assertNull($order->gateway_net_amount);
        self::assertSame('provider_verified', $order->gateway_settlement_status);
    }

    public function test_failed_consumption_is_durable_and_retry_does_not_credit_again(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('google', 'production', $binding));
        $calls = 0;
        $gateway->shouldReceive('consumeGoogle')->twice()->andReturnUsing(function () use (&$calls, $user): void {
            // The order, lot and wallet must all exist before any provider write.
            self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
            self::assertSame(Order::STATUS_APPROVED, Order::query()->firstOrFail()->status);
            self::assertSame(1, DB::table('wallet_credit_lots')->count());
            if (++$calls === 1) throw new StorePurchaseVerificationException('google_consumption_unavailable', 'Retry', 503);
        });
        $first = $this->credit($user);
        self::assertTrue($first['credited']);
        self::assertFalse($first['store_finalized']);
        self::assertNotNull(StorePurchase::query()->firstOrFail()->finalization_retry_at);
        self::assertNotSame('receipt-token', DB::table('store_purchases')->value('purchase_token'));
        self::assertFalse($this->credit($user)['store_finalized']);
        self::assertSame(1, $calls, 'Active lease must suppress immediate duplicate calls.');
        $this->travel(3)->minutes();
        $this->artisan('payments:finalize-store-purchases', ['--limit' => 10])->assertExitCode(0);
        self::assertTrue($this->credit($user)['store_finalized']);
        $this->artisan('payments:finalize-store-purchases')->assertExitCode(0);
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(1, Order::query()->count());
        self::assertSame(1, WalletTransaction::query()->where('category', 'package_purchase')->count());
    }

    public function test_rollback_never_consumes_a_receipt(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('google', 'production', $binding));
        $gateway->shouldNotReceive('consumeGoogle');
        try {
            DB::transaction(function () use ($user): void {
                $this->credit($user);
                throw new \RuntimeException('Abort outer transaction');
            });
            self::fail('Expected rollback.');
        } catch (\RuntimeException $exception) {
            self::assertSame('Abort outer transaction', $exception->getMessage());
        }
        self::assertSame(0, StorePurchase::query()->count());
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        $this->artisan('payments:finalize-store-purchases')->assertExitCode(0);
    }

    public function test_provider_write_waits_for_the_outer_credit_commit(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('google', 'production', $binding));
        $calls = 0;
        $gateway->shouldReceive('consumeGoogle')->once()->andReturnUsing(function () use (&$calls): void {
            $calls++;
        });
        DB::transaction(function () use ($user, &$calls): void {
            self::assertFalse($this->credit($user)['store_finalized']);
            self::assertSame(0, $calls);
        });
        self::assertSame(1, $calls);
        self::assertTrue($this->credit($user)['store_finalized']);
    }

    public function test_recovery_command_reports_failure_without_losing_the_next_retry(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('google', 'test', $binding));
        $gateway->shouldReceive('consumeGoogle')->twice()
            ->andThrow(new StorePurchaseVerificationException('google_consumption_unavailable', 'Retry', 503));
        $this->credit($user);
        $this->travel(3)->minutes();
        $this->artisan('payments:finalize-store-purchases')->assertExitCode(1);
        $purchase = StorePurchase::query()->firstOrFail();
        self::assertNull($purchase->finalized_at);
        self::assertTrue($purchase->finalization_retry_at->isFuture());
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        $this->artisan('payments:finalize-store-purchases')->assertExitCode(0);
    }

    public static function invalidProviderEvidence(): array
    {
        return [
            ['google', 'xcode', 1, 'store_purchase_environment_invalid'],
            ['apple', 'unknown', 1, 'store_purchase_environment_invalid'],
            ['google', 'test', 2, 'store_purchase_quantity_unsupported'],
            ['apple', 'sandbox', 2, 'store_purchase_quantity_unsupported'],
        ];
    }

    #[DataProvider('invalidProviderEvidence')]
    public function test_unsupported_environment_or_quantity_fails_closed(string $provider, string $environment, int $quantity, string $code): void
    {
        [$user, , $gateway, $binding] = $this->fixture($provider);
        $gateway->shouldReceive('verify')->once()->andReturn(new VerifiedStorePurchase(
            provider: $provider, productId: 'rokn.coins.recovery.600', externalTransactionId: 'verified-transaction',
            environment: $environment, quantity: $quantity, accountBinding: $binding
        ));
        $gateway->shouldNotReceive('consumeGoogle');
        try {
            $this->credit($user, $provider);
            self::fail('Unsupported provider evidence must fail closed.');
        } catch (StorePurchaseVerificationException $exception) {
            self::assertSame($code, $exception->errorCode);
        }
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
    }

    public static function rejectedReceipts(): array
    {
        return [['store_purchase_pending'], ['store_purchase_cancelled'], ['store_account_mismatch']];
    }

    #[DataProvider('rejectedReceipts')]
    public function test_uncompleted_or_unbound_receipt_cannot_credit_or_consume(string $code): void
    {
        [$user, , $gateway] = $this->fixture();
        $gateway->shouldReceive('verify')->once()->andThrow(new StorePurchaseVerificationException($code));
        $gateway->shouldNotReceive('consumeGoogle');
        $this->actingAs($user, 'api')->postJson('/api/v1/store-purchases/verify', [
            'provider' => 'google', 'product_id' => 'rokn.coins.recovery.600', 'purchase_token' => 'receipt-token',
        ])->assertStatus(422)->assertJsonPath('code', $code);
        self::assertSame(0, StorePurchase::query()->count());
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
    }

    public function test_completed_rtdn_recovers_absent_device_using_persisted_account_binding(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        app(StoreBillingAccountIdentity::class)->rememberGoogle($user);
        config(['store_billing.account_binding_key' => 'rotated-after-checkout']);
        $gateway->shouldNotReceive('verify');
        $gateway->shouldReceive('verifyGoogleNotification')->once()
            ->with('rokn.coins.recovery.600', 'receipt-token')->andReturn($this->receipt('google', 'test', $binding));
        $gateway->shouldReceive('consumeGoogle')->once()->with('rokn.coins.recovery.600', 'receipt-token', $binding)->andReturnNull();
        $this->notification()->assertNoContent();
        $this->notification()->assertNoContent();
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(1, StorePurchase::query()->count());
        self::assertNotNull(StorePurchase::query()->firstOrFail()->finalized_at);
        self::assertSame('processed', StoreNotificationEvent::query()->firstOrFail()->status);
        self::assertTrue($this->credit($user)['already_processed']);
    }

    public function test_rtdn_provider_outage_then_pending_then_purchase_is_retried_safely(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        app(StoreBillingAccountIdentity::class)->rememberGoogle($user);
        $responses = 0;
        $gateway->shouldReceive('verifyGoogleNotification')->times(3)->andReturnUsing(function () use (&$responses, $binding): VerifiedStorePurchase {
            if (++$responses === 1) throw new StorePurchaseVerificationException('google_verification_unavailable', 'Unavailable', 503);
            if ($responses === 2) throw new StorePurchaseVerificationException('store_purchase_pending');
            return $this->receipt('google', 'production', $binding);
        });
        $gateway->shouldReceive('consumeGoogle')->once()->andReturnNull();
        $this->notification()->assertStatus(503);
        $this->notification()->assertStatus(503);
        self::assertSame(0, Order::query()->count());
        $this->notification()->assertNoContent();
        self::assertSame(1, Order::query()->count());
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
    }

    public function test_rtdn_unknown_account_binding_never_guesses_a_user(): void
    {
        [$user, , $gateway] = $this->fixture();
        $gateway->shouldReceive('verifyGoogleNotification')->once()->andReturn($this->receipt('google', 'test', 'unknown-binding'));
        $gateway->shouldNotReceive('consumeGoogle');
        $this->notification()->assertNoContent();
        self::assertSame('store_account_not_recoverable', StoreNotificationEvent::query()->firstOrFail()->error_code);
        self::assertSame(0, Order::query()->count());
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
    }

    public function test_rtdn_cancelled_payment_never_credits_and_reverses_an_already_credited_purchase(): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        $gateway->shouldReceive('verifyGoogleNotification')->twice()->andThrow(new StorePurchaseVerificationException('store_purchase_cancelled'));
        $this->notification('unpaid-cancel', 2, 'unpaid-receipt-token')->assertNoContent();
        self::assertSame(0, Order::query()->count());
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('google', 'production', $binding));
        $gateway->shouldReceive('consumeGoogle')->once()->andThrow(new StorePurchaseVerificationException('google_consumption_unavailable', 'Retry', 503));
        $this->credit($user);
        $this->notification('paid-cancel', 2)->assertNoContent();
        $this->notification('paid-cancel', 2)->assertNoContent();
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame('refunded', StorePurchase::query()->firstOrFail()->status);
        self::assertFalse($this->credit($user)['credited']);
        $this->travel(3)->minutes();
        $this->artisan('payments:finalize-store-purchases')->assertExitCode(0);
    }

    public static function googleCancellationNotificationTypes(): array
    {
        return [[1], [2]];
    }

    #[DataProvider('googleCancellationNotificationTypes')]
    public function test_verified_cancellation_before_receipt_cannot_leave_stale_purchase_credit(int $notificationType): void
    {
        [$user, , $gateway, $binding] = $this->fixture();
        $gateway->shouldReceive('verifyGoogleNotification')->once()
            ->andThrow(new StorePurchaseVerificationException('store_purchase_cancelled'));
        $gateway->shouldReceive('verify')->once()->andReturnUsing(function () use ($binding, $notificationType): VerifiedStorePurchase {
            // The device already fetched PURCHASED, but RTDN observes the
            // subsequent cancellation before that device stores its receipt.
            $this->notification('cancel-before-credit', $notificationType)->assertNoContent();
            self::assertSame(0, StorePurchase::query()->count());

            return $this->receipt('google', 'production', $binding);
        });
        $gateway->shouldNotReceive('consumeGoogle');

        $result = $this->credit($user);
        self::assertFalse($result['credited']);
        self::assertSame(0, $result['coins_added']);
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame('refunded', StorePurchase::query()->firstOrFail()->status);
        self::assertSame('processed', StoreNotificationEvent::query()->where('event_id', 'cancel-before-credit')->value('status'));
        $this->notification('cancel-before-credit', $notificationType)->assertNoContent();
        self::assertSame(1, WalletTransaction::query()->where('category', 'package_reversal')->count());
        $this->travel(3)->minutes();
        $this->artisan('payments:finalize-store-purchases')->assertExitCode(0);
    }

    public function test_zero_price_without_verified_test_evidence_is_not_a_free_credit_backdoor(): void
    {
        [$user, $package] = $this->fixture();
        $order = Order::query()->create([
            'user_id' => $user->id, 'package_id' => $package->id, 'package_coins' => 600,
            'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY, 'order_ref' => 'fake-zero-test',
            'amount' => 0, 'final_amount' => 0, 'gateway_gross_amount' => 0,
            'gateway_settlement_status' => 'test_purchase', 'total_coins' => 600,
            'status' => Order::STATUS_PENDING, 'financial_status' => Order::FINANCIAL_PENDING,
        ]);
        try {
            app(OrderLifecycleService::class)->approve($order, null, null, true);
            self::fail('An unverified zero-price order must not be fulfilled.');
        } catch (\DomainException) {
            self::assertSame(Order::STATUS_PENDING, $order->fresh()->status);
            self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        }
    }

    public function test_apple_refund_cycles_use_notification_identity_not_purchase_identity(): void
    {
        [$user, , $gateway, $binding] = $this->fixture('apple');
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('apple', 'production', $binding));
        $gateway->shouldNotReceive('consumeGoogle');
        $this->credit($user, 'apple');
        $this->appleNotification('refund-one', 'REFUND', 1788117001000)->assertNoContent();
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        $this->appleNotification('reverse-one', 'REFUND_REVERSED', 1788117002000)->assertNoContent();
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        $this->appleNotification('refund-two', 'REFUND', 1788117003000)->assertNoContent();
        $this->appleNotification('refund-two', 'REFUND', 1788117003000)->assertNoContent();
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(2, WalletTransaction::query()->where('category', 'package_reversal')->count());
        self::assertSame(['refund-one', 'refund-two'], DB::table('order_financial_events')
            ->where('provider', Order::PAYMENT_METHOD_APP_STORE)->orderBy('id')->pluck('external_event_id')->all());
    }

    public function test_apple_snapshot_order_survives_early_reversal_and_late_old_refunds(): void
    {
        [$user, , $gateway, $binding] = $this->fixture('apple');
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('apple', 'production', $binding));
        $this->credit($user, 'apple');
        $this->appleNotification('early-reverse', 'REFUND_REVERSED', 1788117002000)->assertNoContent();
        $this->appleNotification('late-refund', 'REFUND', 1788117001000)->assertNoContent();
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame('ignored', StoreNotificationEvent::query()->where('event_id', 'late-refund')->value('status'));
        $this->appleNotification('new-refund', 'REFUND', 1788117003000)->assertNoContent();
        $this->appleNotification('stale-reverse-new-id', 'REFUND_REVERSED', 1788117002000)->assertNoContent();
        $this->appleNotification('early-reverse', 'REFUND_REVERSED', 1788117002000)->assertNoContent();
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        $this->appleNotification('new-reverse', 'REFUND_REVERSED', 1788117004000)->assertNoContent();
        $this->appleNotification('late-refund', 'REFUND', 1788117001000)->assertNoContent();
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(Order::FINANCIAL_SETTLED, Order::query()->firstOrFail()->financial_status);
    }

    public function test_apple_notifications_before_receipt_use_the_latest_signed_snapshot(): void
    {
        [$user, , $gateway, $binding] = $this->fixture('apple');
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('apple', 'production', $binding));
        $this->appleNotification('before-refund', 'REFUND', 1788117001000)->assertNoContent();
        $this->appleNotification('before-reverse', 'REFUND_REVERSED', 1788117002000)->assertNoContent();
        self::assertSame(0, Order::query()->count());
        $result = $this->credit($user, 'apple');
        self::assertTrue($result['credited']);
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(0, WalletTransaction::query()->where('category', 'package_reversal')->count());
        self::assertSame('processed', StoreNotificationEvent::query()->where('event_id', 'before-reverse')->value('status'));
        self::assertSame('ignored', StoreNotificationEvent::query()->where('event_id', 'before-refund')->value('status'));
    }

    public function test_apple_ambiguous_chronology_and_cross_environment_notifications_do_not_restore_coins(): void
    {
        [$user, , $gateway, $binding] = $this->fixture('apple');
        $gateway->shouldReceive('verify')->once()->andReturn($this->receipt('apple', 'production', $binding));
        $this->credit($user, 'apple');
        $this->appleNotification('valid-refund', 'REFUND', 1788117001000)->assertNoContent();
        $this->appleNotification('undated-reverse', 'REFUND_REVERSED', null)->assertNoContent();
        $this->appleNotification('foreign-environment', 'REFUND_REVERSED', 1788117002000, 'Sandbox')->assertNoContent();
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame('apple_notification_chronology_ambiguous', StoreNotificationEvent::query()->where('event_id', 'undated-reverse')->value('error_code'));
        self::assertSame('store_purchase_environment_invalid', StoreNotificationEvent::query()->where('event_id', 'foreign-environment')->value('error_code'));
    }

    private function fixture(string $provider = 'google'): array
    {
        $user = User::query()->forceCreate([
            'name' => 'Recovery Buyer', 'name_ar' => 'مشتري اختبار', 'name_en' => 'Recovery Buyer',
            'email' => 'store-recovery@rokn.test', 'role' => 'client', 'active' => true,
            'wallet_coins' => 0, 'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
        ]);
        $package = Package::query()->create([
            'name_ar' => 'باقة اختبار', 'name_en' => 'Recovery package', 'price' => 120, 'coins' => 600,
            $provider . '_product_id' => 'rokn.coins.recovery.600', $provider . '_enabled' => true,
        ]);
        $identities = app(StoreBillingAccountIdentity::class);
        return [$user, $package, $this->mock(StorePurchaseProviderGateway::class), $identities->{$provider}($user)];
    }

    private function receipt(string $provider, string $environment, string $binding): VerifiedStorePurchase
    {
        return new VerifiedStorePurchase(
            provider: $provider, productId: 'rokn.coins.recovery.600', externalTransactionId: 'verified-transaction',
            environment: $environment, currency: 'EGP', grossAmount: 149, accountBinding: $binding
        );
    }

    private function credit(User $user, string $provider = 'google'): array
    {
        return app(StorePurchaseService::class)->verifyAndCredit(
            $user, $provider, 'rokn.coins.recovery.600', 'receipt-token', $provider === 'apple' ? 'verified-transaction' : null
        );
    }

    private function notification(string $id = 'completed-event', int $type = 1, string $purchaseToken = 'receipt-token'): \Illuminate\Testing\TestResponse
    {
        $this->mock(StoreNotificationAuthenticityVerifier::class)->shouldReceive('verifyGooglePushToken')
            ->once()->with('test-oidc-token')->andReturn(['email' => 'rtdn@rokn.test']);
        return $this->withToken('test-oidc-token')->postJson('/api/store-notifications/google', [
            'message' => ['messageId' => $id, 'data' => base64_encode(json_encode([
                'version' => '1.0', 'packageName' => 'com.rokn',
                'oneTimeProductNotification' => ['notificationType' => $type, 'sku' => 'rokn.coins.recovery.600', 'purchaseToken' => $purchaseToken],
            ], JSON_THROW_ON_ERROR))],
        ]);
    }

    private function appleNotification(string $id, string $type, ?int $signedDate, string $environment = 'Production'): \Illuminate\Testing\TestResponse
    {
        $authenticity = $this->mock(StoreNotificationAuthenticityVerifier::class);
        $authenticity->shouldReceive('verifyAppleSignedPayload')->once()->with('outer-' . $id)->andReturn([
            'notificationUUID' => $id, 'notificationType' => $type, 'signedDate' => $signedDate,
            'data' => ['bundleId' => 'com.rokn', 'environment' => $environment, 'signedTransactionInfo' => 'transaction-' . $id],
        ]);
        $authenticity->shouldReceive('verifyAppleSignedPayload')->once()->with('transaction-' . $id)->andReturn([
            'transactionId' => 'verified-transaction', 'originalTransactionId' => 'verified-transaction',
            'productId' => 'rokn.coins.recovery.600', 'bundleId' => 'com.rokn', 'type' => 'Consumable',
        ]);
        return $this->postJson('/api/store-notifications/apple', ['signedPayload' => 'outer-' . $id]);
    }
}
