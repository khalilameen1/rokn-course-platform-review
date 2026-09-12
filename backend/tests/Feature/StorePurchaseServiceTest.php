<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Contracts\StoreNotificationAuthenticityVerifier;
use App\Contracts\StorePurchaseProviderGateway;
use App\Data\VerifiedStorePurchase;
use App\Exceptions\StorePurchaseVerificationException;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\Order;
use App\Models\Package;
use App\Models\StoreNotificationEvent;
use App\Models\StorePurchase;
use App\Models\User;
use App\Services\PaymentChannelReportService;
use App\Services\StoreBillingAccountIdentity;
use App\Services\StorePurchaseService;
use App\Services\StudentNotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

final class StorePurchaseServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_verified_google_consumable_is_credited_exactly_once_and_reported(): void
    {
        Queue::fake();
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->andReturnNull()->byDefault();
        });
        $user = $this->user('store-buyer@rokn.test');
        $package = Package::query()->create([
            'name_ar' => 'باقة المتجر',
            'name_en' => 'Store package',
            'price' => 120,
            'coins' => 600,
            'google_product_id' => 'rokn.coins.600',
            'google_enabled' => true,
        ]);
        $binding = app(StoreBillingAccountIdentity::class)->google($user);
        $gateway = $this->mock(StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('consumeGoogle')->once()->with('rokn.coins.600', 'purchase-token-one', $binding)->andReturnNull();
        $gateway->shouldReceive('verify')
            ->once()
            ->with('google', 'rokn.coins.600', 'purchase-token-one', null, $binding)
            ->andReturn(new VerifiedStorePurchase(
                provider: StorePurchase::PROVIDER_GOOGLE,
                productId: 'rokn.coins.600',
                externalTransactionId: 'GPA.1111-2222',
                environment: 'production',
                currency: 'EGP',
                grossAmount: 120,
                auditPayload: ['order_id' => 'GPA.1111-2222']
            ));

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/store-billing/context')
            ->assertOk()
            ->assertJsonPath('data.google_obfuscated_account_id', $binding);
        $firstResponse = $this->actingAs($user, 'api')
            ->postJson('/api/v1/store-purchases/verify', [
                'provider' => StorePurchase::PROVIDER_GOOGLE,
                'product_id' => 'rokn.coins.600',
                'purchase_token' => 'purchase-token-one',
            ])
            ->assertOk()
            ->assertJsonPath('data.coins_added', 600)
            ->assertJsonPath('data.store_finalized', true)
            ->assertJsonPath('data.finalize_transaction', true);
        $secondResponse = $this->actingAs($user, 'api')
            ->postJson('/api/v1/store-purchases/verify', [
                'provider' => StorePurchase::PROVIDER_GOOGLE,
                'product_id' => 'rokn.coins.600',
                'purchase_token' => 'purchase-token-one',
            ])
            ->assertOk();
        $first = $firstResponse->json('data');
        $second = $secondResponse->json('data');

        self::assertFalse($first['already_processed']);
        self::assertTrue($second['already_processed']);
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(1, StorePurchase::query()->count());
        self::assertSame(1, Order::query()->where('payment_method', Order::PAYMENT_METHOD_GOOGLE_PLAY)->count());
        $this->assertDatabaseHas('orders', [
            'package_id' => $package->id,
            'payment_method' => Order::PAYMENT_METHOD_GOOGLE_PLAY,
            'status' => Order::STATUS_APPROVED,
            'gateway_settlement_status' => 'provider_verified',
        ]);

        $google = app(PaymentChannelReportService::class)
            ->summary()['rows']
            ->firstWhere('method', Order::PAYMENT_METHOD_GOOGLE_PLAY);
        self::assertSame(1, $google['live_count']);
        self::assertSame(600, $google['live_coins']);
        self::assertSame(120.0, $google['gross_amount']);

        $admin = $this->user('finance-admin@rokn.test', 'admin');
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($admin, 'web')
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee('Google Play')
            ->assertSee('تحصيل باقات العملات حسب القناة')
            ->assertSee('الصافي بانتظار التسوية');
        $order = Order::query()->where('payment_method', Order::PAYMENT_METHOD_GOOGLE_PLAY)->firstOrFail();
        $this->actingAs($admin, 'web')
            ->get(route('admin.orders.show', $order))
            ->assertOk()
            ->assertSee('باقة المتجر')
            ->assertSee('GPA.1111-2222')
            ->assertSee('بانتظار كشف التسوية');
        $this->actingAs($admin, 'web')
            ->post(route('admin.orders.record-settlement', $order), [
                'gross_amount' => 120,
                'fee_amount' => 18,
                'net_amount' => 102,
                'currency' => 'egp',
                'settled_at' => now()->subMinute()->format('Y-m-d H:i:s'),
                'provider_reference' => 'GOOGLE-STATEMENT-2026-08',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'gateway_fee_amount' => 18,
            'gateway_net_amount' => 102,
            'gateway_currency' => 'EGP',
            'gateway_settlement_status' => 'settled',
        ]);
        $settledGoogle = app(PaymentChannelReportService::class)
            ->summary()['rows']
            ->firstWhere('method', Order::PAYMENT_METHOD_GOOGLE_PLAY);
        self::assertSame(102.0, $settledGoogle['confirmed_net_amount']);
        self::assertSame(0, $settledGoogle['pending_settlement_count']);

        $this->actingAs($admin, 'web')
            ->post(route('admin.orders.record-settlement', $order), [
                'gross_amount' => 120,
                'fee_amount' => 1,
                'net_amount' => 119,
                'currency' => 'EGP',
                'settled_at' => now()->format('Y-m-d H:i:s'),
                'provider_reference' => 'SHOULD-NOT-OVERWRITE',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');
        self::assertSame('102.00', $order->fresh()->gateway_net_amount);
    }

    public function test_a_purchase_token_cannot_be_claimed_by_another_account(): void
    {
        Queue::fake();
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->andReturnNull()->byDefault();
        });
        $firstUser = $this->user('first-store-buyer@rokn.test');
        $secondUser = $this->user('second-store-buyer@rokn.test');
        Package::query()->create([
            'name_ar' => 'باقة آبل',
            'name_en' => 'Apple package',
            'price' => 90,
            'coins' => 400,
            'apple_product_id' => 'rokn.coins.400',
            'apple_enabled' => true,
        ]);
        $gateway = $this->mock(StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturn(new VerifiedStorePurchase(
                provider: StorePurchase::PROVIDER_APPLE,
                productId: 'rokn.coins.400',
                externalTransactionId: '200000000000001',
                environment: 'sandbox'
            ));
        $service = app(StorePurchaseService::class);
        $service->verifyAndCredit(
            $firstUser,
            StorePurchase::PROVIDER_APPLE,
            'rokn.coins.400',
            'signed-jws-one',
            '200000000000001'
        );

        try {
            $service->verifyAndCredit(
                $secondUser,
                StorePurchase::PROVIDER_APPLE,
                'rokn.coins.400',
                'signed-jws-one',
                '200000000000001'
            );
            self::fail('Cross-account replay must be rejected.');
        } catch (StorePurchaseVerificationException $exception) {
            self::assertSame('store_purchase_already_claimed', $exception->errorCode);
        }

        self::assertSame(400, (int) $firstUser->fresh()->wallet_purchased_coins);
        self::assertSame(0, (int) $secondUser->fresh()->wallet_purchased_coins);
        $apple = app(PaymentChannelReportService::class)
            ->summary()['rows']
            ->firstWhere('method', Order::PAYMENT_METHOD_APP_STORE);
        self::assertSame(0, $apple['live_count']);
        self::assertSame(1, $apple['test_count']);
        self::assertSame(0.0, $apple['gross_amount']);
    }

    public function test_paid_receipt_survives_package_and_channel_retirement(): void
    {
        Queue::fake();
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->andReturnNull()->byDefault();
        });
        $user = $this->user('retired-store-product@rokn.test');
        $package = Package::query()->create([
            'name_ar' => 'باقة منشورة',
            'name_en' => 'Published package',
            'price' => 120,
            'coins' => 600,
            'is_active' => true,
            'google_product_id' => 'rokn.coins.retired.600',
            'google_enabled' => true,
        ]);

        // The learner has already left the app for the provider sheet. These
        // flags may stop future sheets, but cannot revoke that paid contract.
        $package->forceFill([
            'is_active' => false,
            'google_enabled' => false,
        ])->save();
        $binding = app(StoreBillingAccountIdentity::class)->google($user);
        $gateway = $this->mock(StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('verify')
            ->once()
            ->with(
                'google',
                'rokn.coins.retired.600',
                'paid-before-retirement',
                null,
                $binding
            )
            ->andReturn(new VerifiedStorePurchase(
                provider: StorePurchase::PROVIDER_GOOGLE,
                productId: 'rokn.coins.retired.600',
                externalTransactionId: 'GPA.RETIRED.600',
                environment: 'production',
                currency: 'EGP',
                grossAmount: 120
            ));
        $gateway->shouldReceive('consumeGoogle')->once()
            ->with('rokn.coins.retired.600', 'paid-before-retirement', $binding)->andReturnNull();

        $result = app(StorePurchaseService::class)->verifyAndCredit(
            $user,
            StorePurchase::PROVIDER_GOOGLE,
            'rokn.coins.retired.600',
            'paid-before-retirement',
            null
        );

        self::assertSame(600, $result['coins_added']);
        self::assertTrue($result['finalize_transaction']);
        self::assertSame(600, (int) $user->fresh()->wallet_purchased_coins);
        $this->assertDatabaseHas('orders', [
            'package_id' => $package->id,
            'package_coins' => 600,
            'status' => Order::STATUS_APPROVED,
        ]);
    }

    public function test_store_catalog_coin_change_during_verification_is_rejected(): void
    {
        Queue::fake();
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->andReturnNull()->byDefault();
        });
        $user = $this->user('mutated-store-product@rokn.test');
        $package = Package::query()->create([
            'name_ar' => 'باقة ثابتة',
            'name_en' => 'Immutable package',
            'price' => 120,
            'coins' => 600,
            'google_product_id' => 'rokn.coins.immutable.600',
            'google_enabled' => true,
        ]);
        $gateway = $this->mock(StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('verify')
            ->once()
            ->andReturnUsing(function () use ($package): VerifiedStorePurchase {
                // Simulate an unsafe out-of-band catalogue write racing the
                // provider call. Normal model writes are rejected as well.
                DB::table('packages')->where('id', $package->id)->update(['coins' => 999]);

                return new VerifiedStorePurchase(
                    provider: StorePurchase::PROVIDER_GOOGLE,
                    productId: 'rokn.coins.immutable.600',
                    externalTransactionId: 'GPA.MUTATED.600',
                    environment: 'production'
                );
            });

        try {
            app(StorePurchaseService::class)->verifyAndCredit(
                $user,
                StorePurchase::PROVIDER_GOOGLE,
                'rokn.coins.immutable.600',
                'mutated-catalog-token',
                null
            );
            self::fail('A changed coin fulfilment contract must not be guessed.');
        } catch (StorePurchaseVerificationException $exception) {
            self::assertSame('store_product_catalog_changed', $exception->errorCode);
        }

        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(0, StorePurchase::query()->count());
    }

    public function test_unknown_store_product_never_creates_credit(): void
    {
        $user = $this->user('unknown-store-product@rokn.test');
        $gateway = $this->mock(StorePurchaseProviderGateway::class);
        $gateway->shouldNotReceive('verify');

        try {
            app(StorePurchaseService::class)->verifyAndCredit(
                $user,
                StorePurchase::PROVIDER_GOOGLE,
                'rokn.coins.unknown',
                'unknown-product-token',
                null
            );
            self::fail('An unknown store product must not be fulfilled.');
        } catch (StorePurchaseVerificationException $exception) {
            self::assertSame('store_product_not_configured', $exception->errorCode);
        }

        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(0, StorePurchase::query()->count());
    }

    public function test_google_voided_purchase_is_reversed_once_from_authenticated_rtdn(): void
    {
        Queue::fake();
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->andReturnNull()->byDefault();
        });
        $user = $this->user('google-refund@rokn.test');
        Package::query()->create([
            'name_ar' => 'باقة استرداد جوجل',
            'name_en' => 'Google refund package',
            'price' => 100,
            'coins' => 500,
            'google_product_id' => 'rokn.coins.refund.500',
            'google_enabled' => true,
        ]);
        $gateway = $this->mock(StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('verify')->once()->andReturn(new VerifiedStorePurchase(
            provider: StorePurchase::PROVIDER_GOOGLE,
            productId: 'rokn.coins.refund.500',
            externalTransactionId: 'GPA.REFUND-ONE',
            environment: 'production'
        ));
        $gateway->shouldReceive('consumeGoogle')->once()->andReturnNull();
        app(StorePurchaseService::class)->verifyAndCredit(
            $user,
            StorePurchase::PROVIDER_GOOGLE,
            'rokn.coins.refund.500',
            'google-refund-token',
            null
        );
        self::assertSame(500, (int) $user->fresh()->wallet_purchased_coins);

        $authenticity = $this->mock(StoreNotificationAuthenticityVerifier::class);
        $authenticity->shouldReceive('verifyGooglePushToken')
            ->twice()
            ->with('google-oidc-token')
            ->andReturn(['email' => 'play-rtdn@rokn.test']);
        $notification = [
            'version' => '1.0',
            'packageName' => 'com.rokn',
            'eventTimeMillis' => '1788117000000',
            'voidedPurchaseNotification' => [
                'purchaseToken' => 'google-refund-token',
                'orderId' => 'GPA.REFUND-ONE',
                'productType' => 2,
                'refundType' => 1,
            ],
        ];
        $envelope = [
            'message' => [
                'messageId' => 'google-event-1',
                'data' => base64_encode(json_encode($notification, JSON_THROW_ON_ERROR)),
            ],
        ];

        $this->withToken('google-oidc-token')
            ->postJson('/api/store-notifications/google', $envelope)
            ->assertNoContent();
        $this->withToken('google-oidc-token')
            ->postJson('/api/store-notifications/google', $envelope)
            ->assertNoContent();

        $purchase = StorePurchase::query()->firstOrFail();
        self::assertSame('refunded', $purchase->status);
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(Order::FINANCIAL_REVIEW_REQUIRED, $purchase->order->fresh()->financial_status);
        self::assertSame(500, (int) $purchase->order->fresh()->recovered_coins);
        self::assertSame(1, StoreNotificationEvent::query()->count());
        self::assertSame(
            StoreNotificationEvent::STATUS_PROCESSED,
            StoreNotificationEvent::query()->firstOrFail()->status
        );
        self::assertSame(
            1,
            $purchase->order->financialEvents()
                ->where('event_key', 'store-notification:google:google-event-1')
                ->count()
        );
    }

    public function test_apple_refund_and_refund_reversal_restore_the_same_purchase(): void
    {
        Queue::fake();
        $this->mock(StudentNotificationService::class, function ($mock): void {
            $mock->shouldReceive('notifyUser')->andReturnNull()->byDefault();
        });
        $user = $this->user('apple-refund@rokn.test');
        Package::query()->create([
            'name_ar' => 'باقة استرداد آبل',
            'name_en' => 'Apple refund package',
            'price' => 110,
            'coins' => 550,
            'apple_product_id' => 'rokn.coins.refund.550',
            'apple_enabled' => true,
        ]);
        $gateway = $this->mock(StorePurchaseProviderGateway::class);
        $gateway->shouldReceive('verify')->once()->andReturn(new VerifiedStorePurchase(
            provider: StorePurchase::PROVIDER_APPLE,
            productId: 'rokn.coins.refund.550',
            externalTransactionId: '200000000000550',
            environment: 'production'
        ));
        app(StorePurchaseService::class)->verifyAndCredit(
            $user,
            StorePurchase::PROVIDER_APPLE,
            'rokn.coins.refund.550',
            'apple-refund-token',
            '200000000000550'
        );

        $refund = [
            'notificationUUID' => 'apple-refund-event',
            'signedDate' => 1788117000000,
            'notificationType' => 'REFUND',
            'data' => [
                'bundleId' => 'com.rokn',
                'environment' => 'Production',
                'signedTransactionInfo' => 'apple-refund-transaction',
            ],
        ];
        $reversed = [
            'notificationUUID' => 'apple-reversed-event',
            'signedDate' => 1788117001000,
            'notificationType' => 'REFUND_REVERSED',
            'data' => [
                'bundleId' => 'com.rokn',
                'environment' => 'Production',
                'signedTransactionInfo' => 'apple-refund-transaction',
            ],
        ];
        $transaction = [
            'transactionId' => '200000000000550',
            'originalTransactionId' => '200000000000550',
            'productId' => 'rokn.coins.refund.550',
            'type' => 'Consumable',
            'revocationDate' => 1788117000000,
            'revocationReason' => 1,
        ];
        $authenticity = $this->mock(StoreNotificationAuthenticityVerifier::class);
        $authenticity->shouldReceive('verifyAppleSignedPayload')
            ->once()->with('apple-refund-outer')->andReturn($refund);
        $authenticity->shouldReceive('verifyAppleSignedPayload')
            ->once()->with('apple-reversed-outer')->andReturn($reversed);
        $authenticity->shouldReceive('verifyAppleSignedPayload')
            ->twice()->with('apple-refund-transaction')->andReturn($transaction);

        $this->postJson('/api/store-notifications/apple', [
            'signedPayload' => 'apple-refund-outer',
        ])->assertNoContent();
        self::assertSame(0, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame('refunded', StorePurchase::query()->firstOrFail()->status);

        $this->postJson('/api/store-notifications/apple', [
            'signedPayload' => 'apple-reversed-outer',
        ])->assertNoContent();

        $purchase = StorePurchase::query()->firstOrFail();
        self::assertSame('credited', $purchase->status);
        self::assertSame(550, (int) $user->fresh()->wallet_purchased_coins);
        self::assertSame(Order::FINANCIAL_SETTLED, $purchase->order->fresh()->financial_status);
        self::assertSame(2, StoreNotificationEvent::query()->where('status', 'processed')->count());
    }

    public function test_google_notification_without_push_identity_is_rejected(): void
    {
        $this->postJson('/api/store-notifications/google', [
            'message' => ['messageId' => 'missing-auth', 'data' => base64_encode('{}')],
        ])->assertUnauthorized()->assertJsonPath('error', 'google_rtdn_identity_missing');
    }

    private function user(string $email, string $role = 'client'): User
    {
        return User::query()->forceCreate([
            'name' => 'Store Buyer',
            'name_ar' => 'مستخدم متجر',
            'name_en' => 'Store Buyer',
            'email' => $email,
            'phone' => '01' . random_int(100000000, 999999999),
            'role' => $role,
            'active' => true,
            'wallet_coins' => 0,
            'wallet_purchased_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
    }
}
