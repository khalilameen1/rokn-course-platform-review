<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Package;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\GoogleService;
use App\Services\KashierCheckoutFlowService;
use App\Services\SocialAuthProviderRegistry;
use App\Services\SocialIdentityGuardService;
use App\Services\SocialOAuthAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WebWalletTest extends TestCase
{
    use RefreshDatabase;

    private User $student;
    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'app.url' => 'http://localhost',
            'social_auth.web_wallet_url' => null,
            'social_auth.public_api_url' => 'http://localhost/api/v1',
            'social_auth.providers' => ['google'],
            'services.google.client_id' => 'web-wallet-test',
            'services.google.client_secret' => 'web-wallet-test-secret',
            'operations.disaster_recovery_mode' => false,
        ]);
        Http::preventStrayRequests();
        $this->student = User::forceCreate([
            'name_ar' => 'طالب ركن', 'name_en' => 'Rokn Student',
            'email' => 'wallet@example.test', 'role' => 'client', 'active' => true,
            'wallet_coins' => 0, 'wallet_purchased_coins' => 0, 'wallet_reward_coins' => 0,
        ]);
        $this->package = Package::create([
            'name_ar' => 'باقة الرصيد', 'name_en' => 'Wallet package',
            'price' => 100, 'coins' => 500, 'sort_order' => 1,
            'is_active' => true, 'direct_enabled' => true,
        ]);
        DB::table('settings')->insert([
            'direct_checkout_discount_percent' => 10,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_guest_sees_social_sign_in_but_cannot_initiate_payment(): void
    {
        $this->get('/recharge')->assertOk()->assertSee('سجّل بحسابك في التطبيق')
            ->assertDontSee('name="idempotency_key"', false)->assertHeader('Referrer-Policy', 'no-referrer');
        $this->post('/recharge/checkout', $this->terms())->assertRedirect('/recharge');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_catalogue_reuses_direct_discount_and_excludes_unavailable_channels(): void
    {
        Package::create(['name_ar' => 'متجر فقط', 'price' => 50, 'coins' => 200,
            'is_active' => true, 'direct_enabled' => false]);
        Package::create(['name_ar' => 'باقة مخفية', 'price' => 50, 'coins' => 200,
            'is_active' => false, 'direct_enabled' => true]);
        $response = $this->actingAs($this->student, 'student')->get('/recharge');
        $response->assertOk()->assertSee('90.00')->assertSee('500')
            ->assertDontSee('متجر فقط')->assertDontSee('باقة مخفية');
        self::assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_dashboard_login_is_not_a_student_checkout_session(): void
    {
        $this->student->forceFill(['role' => 'admin'])->save();
        $this->actingAs($this->student, 'web')->get('/recharge')->assertSee('سجّل بحسابك في التطبيق');
        $this->post('/recharge/checkout', $this->terms())->assertRedirect('/recharge');
        $this->assertAuthenticatedAs($this->student, 'web');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_disabled_or_nonstudent_account_cannot_fund_wallet(): void
    {
        $this->student->forceFill(['active' => false])->save();
        $this->actingAs($this->student, 'student')->post('/recharge/checkout', $this->terms())
            ->assertRedirect('/recharge');
        $this->assertGuest('student');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_duplicate_click_reuses_the_same_order_without_crediting_wallet(): void
    {
        $this->actingAs($this->student, 'student');
        $terms = $this->terms();
        $first = $this->post('/recharge/checkout', $terms)->assertStatus(303);
        $second = $this->post('/recharge/checkout', $terms)->assertStatus(303);
        self::assertSame($first->headers->get('Location'), $second->headers->get('Location'));
        parse_str(parse_url($first->headers->get('Location'), PHP_URL_QUERY), $hpp);
        self::assertSame('90.00', $hpp['amount']);
        self::assertSame('http://localhost/recharge/callback', $hpp['merchantRedirect']);
        $this->assertDatabaseCount('orders', 1);
        $this->assertDatabaseHas('orders', ['user_id' => $this->student->id,
            'final_amount' => 90, 'package_coins' => 500, 'status' => Order::STATUS_PENDING]);
        self::assertSame(0, (int) $this->student->fresh()->wallet_coins);
    }

    public function test_mobile_checkout_retains_its_callback_and_price(): void
    {
        Auth::guard('api')->setUser($this->student);
        $result = app(KashierCheckoutFlowService::class)->initiate(
            Request::create('/api/v1/payment/initiate', 'POST', $this->terms())
        );
        self::assertSame(200, $result->status());
        parse_str(parse_url($result->getData(true)['data']['payment_url'], PHP_URL_QUERY), $hpp);
        self::assertSame('http://localhost/payment/callback', $hpp['merchantRedirect']);
        self::assertSame('90.00', $hpp['amount']);
    }

    public function test_stale_account_form_and_stale_price_do_not_create_an_order(): void
    {
        $this->actingAs($this->student, 'student');
        $this->post('/recharge/checkout', [...$this->terms(), 'expected_account' => $this->student->id + 1])
            ->assertRedirect('/recharge')->assertSessionHas('error');
        $this->post('/recharge/checkout', [...$this->terms(), 'expected_amount' => 1])
            ->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_disaster_recovery_blocks_website_checkout_too(): void
    {
        config(['operations.disaster_recovery_mode' => true]);
        $this->actingAs($this->student, 'student')->post('/recharge/checkout', $this->terms())
            ->assertRedirect('/recharge')->assertSessionHas('error');
        $this->assertDatabaseCount('orders', 0);
    }

    public function test_other_students_cannot_read_or_resume_this_order(): void
    {
        $order = $this->pendingOrder();
        $other = User::forceCreate(['email' => 'other@example.test', 'role' => 'client', 'active' => true]);
        $this->actingAs($other, 'student')->get('/recharge/orders/'.$order->order_ref)->assertNotFound();
        $this->getJson('/recharge/orders/'.$order->order_ref.'/status')->assertNotFound();
        $this->post('/recharge/orders/'.$order->order_ref.'/resume')->assertNotFound();
        $this->post('/recharge/orders/'.$order->order_ref.'/abandon')->assertNotFound();
    }

    public function test_status_polls_do_not_consume_payment_write_quota(): void
    {
        $order = $this->pendingOrder();
        $this->actingAs($this->student, 'student');
        for ($i = 0; $i < 12; $i++) {
            $this->getJson('/recharge/orders/'.$order->order_ref.'/status')->assertOk();
        }
        $this->post('/recharge/orders/'.$order->order_ref.'/resume')->assertStatus(303);
    }

    public function test_cancelled_and_review_receipts_still_refresh_on_return(): void
    {
        $order = $this->pendingOrder();
        $this->actingAs($this->student, 'student');
        foreach ([Order::STATUS_CANCELLED, Order::STATUS_APPROVED] as $status) {
            $order->update(['status' => $status, 'financial_status' => Order::FINANCIAL_REVIEW_REQUIRED]);
            $this->get('/recharge/orders/'.$order->order_ref)->assertOk()
                ->assertSee('data-payment-receipt', false)->assertSee('تحديث حالة الدفع')
                ->assertDontSee('data-unconfirmed-actions', false);
        }
    }

    public function test_untrusted_callback_claim_does_not_credit_wallet(): void
    {
        $order = $this->pendingOrder();
        $this->actingAs($this->student, 'student')->get('/recharge/callback?'.http_build_query([
            'merchantOrderId' => $order->order_ref, 'paymentStatus' => 'SUCCESS',
            'transactionId' => 'forged', 'signature' => str_repeat('0', 64),
        ]))->assertRedirect();
        self::assertSame(Order::STATUS_PENDING, $order->fresh()->status);
        self::assertSame(0, (int) $this->student->fresh()->wallet_coins);
    }

    public function test_noncanonical_origin_redirects_before_starting_oauth(): void
    {
        config(['social_auth.web_wallet_url' => 'https://rokn.app']);
        $this->get('/recharge/auth/google')->assertRedirect('https://rokn.app/recharge/auth/google');
        $this->assertDatabaseCount('social_oauth_attempts', 0);
        $this->assertNull(session('wallet.oauth_verifier'));
    }

    public function test_web_oauth_start_uses_existing_provider_callback_with_fixed_return(): void
    {
        $start = $this->get('/recharge/auth/google')->assertRedirect();
        parse_str(parse_url($start->headers->get('Location'), PHP_URL_QUERY), $params);
        self::assertSame('http://localhost/recharge/auth/complete', $params['return_to']);
        $provider = $this->get($start->headers->get('Location'))->assertRedirect();
        parse_str(parse_url($provider->headers->get('Location'), PHP_URL_QUERY), $oauth);
        self::assertSame('http://localhost/api/v1/social-auth/google/callback', $oauth['redirect_uri']);
        self::assertNotEmpty($oauth['nonce']);
    }

    public function test_web_signin_and_logout_preserve_native_token_device_and_dashboard_identity(): void
    {
        $this->student->forceFill(['locked_device_id' => (string) Str::uuid()])->save();
        $this->student->generateApiToken('google', 'google-wallet-student');
        $before = DB::table('api_tokens')->where('user_id', $this->student->id)->get()->toJson();
        $device = $this->student->locked_device_id;
        [$code, $verifier] = $this->oauthCompletion();
        $this->mock(GoogleService::class)->shouldReceive('verify')->once()->andReturn([
            'id' => 'google-wallet-student', 'email_verified' => true,
        ]);
        $this->withSession(['wallet.oauth_verifier' => $verifier])->get('/recharge/auth/complete?code='.$code)
            ->assertRedirect('/recharge')->assertSessionMissing('error');
        $this->assertAuthenticatedAs($this->student, 'student');
        $this->assertGuest('web');
        self::assertSame($before, DB::table('api_tokens')->where('user_id', $this->student->id)->get()->toJson());
        self::assertSame($device, $this->student->fresh()->locked_device_id);
        $this->get('/dashboard')->assertRedirect('/login');
        $this->post('/recharge/logout')->assertRedirect('/recharge');
        $this->assertGuest('student');
        self::assertSame($before, DB::table('api_tokens')->where('user_id', $this->student->id)->get()->toJson());
    }

    public function test_web_completion_cannot_be_used_by_native_completion_endpoint(): void
    {
        [$code, $verifier] = $this->oauthCompletion();
        $this->postJson('/api/v1/social-auth/complete', ['code' => $code, 'code_verifier' => $verifier])
            ->assertStatus(422);
    }

    public function test_wrong_session_cannot_consume_web_completion(): void
    {
        [$code] = $this->oauthCompletion();
        $this->withSession(['wallet.oauth_verifier' => Str::random(64)])
            ->get('/recharge/auth/complete?code='.$code)->assertSessionHas('error');
        self::assertNull(app(SocialOAuthAttemptService::class)->inspectCompletion($code)->completion_consumed_at);
        $this->assertGuest('student');
    }

    public function test_login_started_before_account_deletion_cannot_open_recreated_wallet(): void
    {
        [$code, $verifier] = $this->oauthCompletion();
        app(SocialIdentityGuardService::class)->markDeletionStarted($this->student->id);
        $this->mock(GoogleService::class)->shouldReceive('verify')->once()->andReturn(['id' => 'google-wallet-student']);
        $this->withSession(['wallet.oauth_verifier' => $verifier])->get('/recharge/auth/complete?code='.$code)
            ->assertSessionHas('error');
        $this->assertGuest('student');
    }

    public function test_same_email_without_linked_provider_does_not_create_or_fund_another_account(): void
    {
        [$code, $verifier] = $this->oauthCompletion();
        $this->mock(GoogleService::class)->shouldReceive('verify')->once()->andReturn([
            'id' => 'unlinked-identity', 'email' => $this->student->email, 'email_verified' => true,
        ]);
        $this->withSession(['wallet.oauth_verifier' => $verifier])->get('/recharge/auth/complete?code='.$code)
            ->assertSessionHas('error', 'استخدم نفس حساب الدخول المرتبط بتطبيق ركن');
        $this->assertGuest('student');
        $this->assertDatabaseCount('users', 1);
    }

    private function terms(): array
    {
        return ['package_id' => $this->package->id, 'expected_amount' => 90,
            'expected_coins' => 500, 'expected_account' => $this->student->id,
            'idempotency_key' => 'web-'.Str::uuid()];
    }

    private function pendingOrder(): Order
    {
        $this->actingAs($this->student, 'student')->post('/recharge/checkout', $this->terms())->assertStatus(303);
        return Order::query()->latest('id')->firstOrFail();
    }

    private function oauthCompletion(): array
    {
        SocialAccount::create(['user_id' => $this->student->id, 'provider' => 'google',
            'provider_user_id' => 'google-wallet-student']);
        $verifier = Str::random(64);
        $service = app(SocialOAuthAttemptService::class);
        $attempt = $service->begin(Str::random(64), 'google',
            app(SocialAuthProviderRegistry::class)->webWalletReturnUrl(),
            rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '='), 'test-nonce');
        $code = Str::random(72);
        $service->issueCompletion($attempt, $code, Crypt::encryptString('verified-provider-token'));
        return [$code, $verifier];
    }
}
