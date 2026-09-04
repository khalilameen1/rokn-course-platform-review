<?php

declare(strict_types=1);

namespace Tests\Feature\API;

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Authentication contract tests for Rokn's social-only mobile sign-in.
 *
 * Phone/password and OTP are not part of the first mobile API contract.
 */
class AuthEndpointTest extends ApiTestCase
{
    protected function tearDown(): void
    {
        RateLimiter::clear('auth:127.0.0.1');

        parent::tearDown();
    }

    public function test_auth_methods_advertise_social_only_sign_in(): void
    {
        config()->set([
            'social_auth.providers' => ['google', 'facebook'],
            'services.google.client_id' => 'configured',
            'services.google.client_secret' => 'configured',
            'services.facebook.client_id' => null,
            'services.facebook.client_secret' => null,
            'services.tiktok.client_key' => null,
            'services.tiktok.client_secret' => null,
            'services.apple.client_id' => null,
        ]);
        $expectedAuthorizationApiUrl = rtrim(
            (string) (config('social_auth.public_api_url')
                ?: rtrim((string) config('app.url'), '/') . '/api/v1'),
            '/'
        );

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.otp_enabled', false)
            ->assertJsonPath('data.password_login_visible', false)
            ->assertJsonPath('data.welcome_bonus_coins', 20)
            ->assertJsonPath('data.providers', ['google'])
            ->assertJsonPath('data.authorization_api_url', $expectedAuthorizationApiUrl)
            ->assertJsonPath('data.recommended_provider', 'google')
            ->assertJsonStructure([
                'data' => ['providers', 'authorization_api_url', 'authorization_urls', 'recommendation_badge'],
            ]);
    }

    public function test_auth_methods_declare_the_independent_social_auth_api_base(): void
    {
        config()->set([
            'social_auth.providers' => ['google'],
            'social_auth.public_api_url' => 'https://identity.rokn.test/api/v1',
            'services.google.client_id' => 'configured',
            'services.google.client_secret' => 'configured',
        ]);

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.authorization_api_url', 'https://identity.rokn.test/api/v1')
            ->assertJsonPath(
                'data.authorization_urls.google',
                'https://identity.rokn.test/api/v1/social-auth/google/start'
            );
    }

    public function test_every_api_response_has_a_safe_support_request_id(): void
    {
        $requestId = (string) Str::uuid();
        $this->withHeader('X-Request-ID', $requestId)
            ->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertHeader('X-Request-ID', $requestId);

        $generated = $this->withHeader('X-Request-ID', 'not-a-safe-id')
            ->postJson('/api/v1/social-login', [])
            ->assertUnprocessable()
            ->headers->get('X-Request-ID');

        self::assertIsString($generated);
        self::assertTrue(Str::isUuid($generated));
    }

    public function test_auth_methods_hide_facebook_until_its_graph_contract_is_safe(): void
    {
        config()->set([
            'social_auth.providers' => ['facebook', 'google'],
            'services.google.client_id' => 'configured',
            'services.google.client_secret' => 'configured',
            'services.facebook.client_id' => 'configured',
            'services.facebook.client_secret' => 'configured',
            'services.facebook.graph_version' => 'v19.0',
            'services.tiktok.client_key' => null,
            'services.tiktok.client_secret' => null,
            'services.apple.client_id' => null,
        ]);

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.providers', ['google']);

        config()->set('services.facebook.graph_version', 'v26.0');

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.providers', ['facebook', 'google']);

        config()->set('services.facebook.graph_version', 'v999.0');

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.providers', ['google']);
    }

    public function test_phone_password_and_otp_routes_are_absent(): void
    {
        foreach ([
            '/api/v1/login',
            '/api/v1/register',
            '/api/v1/send-verification',
            '/api/v1/verify-phone',
            '/api/v1/forgot-password',
            '/api/v1/reset-password',
        ] as $endpoint) {
            $this->postJson($endpoint, [])->assertNotFound();
        }
    }

    public function test_expired_social_completion_code_is_rejected_without_creating_a_user(): void
    {
        $before = \App\Models\User::query()->count();

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => str_repeat('x', 64),
            'code_verifier' => str_repeat('v', 43),
            'device_os' => 'android',
        ])->assertStatus(410)
            ->assertJsonPath('code', 'social_login_expired');

        $this->assertSame($before, \App\Models\User::query()->count());
    }

    public function test_browser_social_providers_cannot_bypass_the_pkce_attempt(): void
    {
        config()->set([
            'social_auth.providers' => ['google', 'facebook', 'tiktok', 'apple'],
            'social_auth.tiktok.user_info_url' => 'https://open.tiktokapis.com/v2/user/info/',
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
            'services.facebook.client_id' => 'facebook-client',
            'services.facebook.client_secret' => 'facebook-secret',
            'services.facebook.graph_version' => 'v23.0',
            'services.tiktok.client_key' => 'tiktok-client',
            'services.tiktok.client_secret' => 'tiktok-secret',
        ]);

        foreach (['google', 'facebook', 'tiktok'] as $provider) {
            $this->postJson('/api/v1/social-login', [
                'provider' => $provider,
                'token' => 'provider-token-that-must-not-be-verified-directly',
                'device_os' => 'android',
            ])->assertUnprocessable()
                ->assertJsonPath('code', 'social_browser_attempt_required');
        }
    }

    public function test_transient_session_failure_does_not_burn_social_completion_code(): void
    {
        $verifier = str_repeat('v', 43);
        $challenge = rtrim(strtr(
            base64_encode(hash('sha256', $verifier, true)),
            '+/',
            '-_'
        ), '=');
        $completionCode = str_repeat('c', 64);
        $attempts = app(\App\Services\SocialOAuthAttemptService::class);
        $attempt = $attempts->begin(
            str_repeat('s', 64),
            'google',
            'rokn://auth',
            $challenge
        );
        $attempts->issueCompletion(
            $attempt,
            $completionCode,
            \Illuminate\Support\Facades\Crypt::encryptString('provider-token')
        );

        $failedSignIn = \Mockery::mock(\App\Http\Controllers\API\SignController::class);
        $failedSignIn->shouldReceive('socialLogin')->once()->andReturn(
            response()->json([
                'status' => 503,
                'success' => false,
                'code' => 'provider_unavailable',
                'data' => null,
            ], 503)
        );
        $this->app->instance(\App\Http\Controllers\API\SignController::class, $failedSignIn);

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => $completionCode,
            'code_verifier' => $verifier,
            'device_os' => 'android',
            'device_type' => 'android',
        ])->assertStatus(503);

        $this->assertDatabaseHas('social_oauth_attempts', [
            'id' => $attempt->id,
            'completion_processing_at' => null,
            'completion_consumed_at' => null,
        ]);

        $successfulSignIn = \Mockery::mock(\App\Http\Controllers\API\SignController::class);
        $successfulSignIn->shouldReceive('socialLogin')->once()->andReturn(
            response()->json([
                'status' => 200,
                'success' => true,
                'data' => ['api_token' => 'session-token'],
            ])
        );
        $this->app->instance(\App\Http\Controllers\API\SignController::class, $successfulSignIn);

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => $completionCode,
            'code_verifier' => $verifier,
            'device_os' => 'android',
            'device_type' => 'android',
        ])->assertOk();

        $this->assertDatabaseMissing('social_oauth_attempts', [
            'id' => $attempt->id,
            'completion_consumed_at' => null,
        ]);
        self::assertNull($attempt->fresh()->encrypted_token);
    }

    public function test_social_start_persists_a_hashed_cross_container_attempt(): void
    {
        config()->set([
            'social_auth.providers' => ['google'],
            'social_auth.public_api_url' => 'https://api.rokn.test/api/v1',
            'services.google.client_id' => 'google-client',
            'services.google.client_secret' => 'google-secret',
        ]);
        $challenge = str_repeat('a', 43);

        $response = $this->get(
            '/api/v1/social-auth/google/start?return_to=rokn%3A%2F%2Fauth'
            . '&code_challenge=' . $challenge
            . '&code_challenge_method=S256'
        )->assertRedirect();

        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = (string) ($query['state'] ?? '');

        self::assertSame(64, strlen($state));
        $this->assertDatabaseHas('social_oauth_attempts', [
            'state_hash' => hash('sha256', $state),
            'provider' => 'google',
            'return_to' => 'rokn://auth',
            'code_challenge' => $challenge,
        ]);
        $this->assertDatabaseMissing('social_oauth_attempts', ['state_hash' => $state]);
    }

    public function test_social_auth_completion_is_bounded_without_throttling_catalog_reads(): void
    {
        RateLimiter::clear('auth:127.0.0.1');

        for ($attempt = 1; $attempt <= 12; $attempt++) {
            $this->postJson('/api/v1/social-auth/complete', [
                'code' => str_repeat('x', 64),
                'code_verifier' => str_repeat('v', 43),
                'device_os' => 'android',
            ])->assertStatus(410);
        }

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => str_repeat('x', 64),
            'device_os' => 'android',
        ])->assertStatus(429);

        // Public discovery remains usable after the stricter auth bucket fills.
        $this->getJson('/api/v1/auth-methods')->assertOk();
    }

    public function test_public_notification_resource_does_not_advertise_an_unimplemented_show_route(): void
    {
        $showRoute = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/admin_notification/{admin_notification}');

        $this->assertNull($showRoute);
    }

    public function test_unknown_social_provider_is_not_routable(): void
    {
        $this->get('/api/v1/social-auth/unknown/start?return_to=rokn%3A%2F%2Fauth')
            ->assertNotFound();
    }

    public function test_authenticated_user_can_logout(): void
    {
        $this->actingAs($this->user, 'api')->postJson('/api/v1/logout')
            ->assertOk()
            ->assertJsonPath('success', true);
    }

    public function test_protected_api_routes_never_redirect_html_clients_to_web_login(): void
    {
        $this->withHeaders(['Accept' => 'text/html'])
            ->get('/api/v1/user/profile')
            ->assertUnauthorized()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJson([
                'status' => 401,
                'success' => false,
                'data' => null,
                'message' => 'سجّل الدخول أولًا',
                'code' => 'unauthenticated',
            ]);
    }

    public function test_logout_revokes_only_the_current_api_session(): void
    {
        $firstToken = $this->user->generateApiToken();
        $secondToken = $this->user->generateApiToken();

        $this->withToken($secondToken)->postJson('/api/v1/logout')
            ->assertOk();

        $this->assertDatabaseHas('api_tokens', ['token' => hash('sha256', $firstToken)]);
        $this->assertDatabaseHas('api_tokens', [
            'token' => hash('sha256', $secondToken),
        ]);
        self::assertNotNull(
            \App\Models\ApiToken::query()
                ->find(hash('sha256', $secondToken))
                ?->revoked_at
        );

        // Laravel's feature runner reuses the application between the two
        // synthetic requests. Re-resolve this request-bound guard just as a
        // real HTTP request lifecycle does.
        $this->app['auth']->forgetGuards();
        $this->withToken($firstToken)->getJson('/api/v1/user/profile')
            ->assertOk();
    }

    public function test_user_sessions_keep_the_standard_api_envelope(): void
    {
        $plainToken = $this->user->generateApiToken(
            session: ['device_class' => 'tablet']
        );

        $response = $this->withToken($plainToken)
            ->getJson('/api/v1/user/sessions')
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'تم تحميل الأجهزة المسجّل عليها الحساب')
            ->assertJsonStructure(['data' => [['id', 'platform', 'device_class', 'current']]])
            ->assertJsonPath('data.0.device_class', 'tablet');

        $sessionId = (string) $response->json('data.0.id');
        self::assertNotSame('', $sessionId);

        $this->app['auth']->forgetGuards();
        $this->withToken($plainToken)
            ->deleteJson('/api/v1/user/sessions/' . $sessionId)
            ->assertOk()
            ->assertJsonPath('status', 200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['signed_out'], 'signed_out']);
    }

    public function test_revoking_an_already_revoked_device_session_is_idempotent(): void
    {
        $currentToken = $this->user->generateApiToken();
        $otherToken = $this->user->generateApiToken();
        $otherSessionId = (string) \App\Models\ApiToken::query()
            ->where('token', hash('sha256', $otherToken))
            ->value('session_id');

        $this->withToken($currentToken)
            ->deleteJson('/api/v1/user/sessions/' . $otherSessionId)
            ->assertOk()
            ->assertJsonPath('data.already_revoked', false);

        $this->app['auth']->forgetGuards();
        $this->withToken($currentToken)
            ->deleteJson('/api/v1/user/sessions/' . $otherSessionId)
            ->assertOk()
            ->assertJsonPath('data.already_revoked', true)
            ->assertJsonPath('data.signed_out', false);
    }

    public function test_query_body_and_basic_token_transports_are_rejected_by_default(): void
    {
        $plainToken = $this->user->generateApiToken();

        $this->getJson('/api/v1/user/profile?api_token=' . urlencode($plainToken))
            ->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/user/profile', [
            'api_token' => $plainToken,
            'name' => 'Transport Probe',
        ])->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withHeaders([
            'Authorization' => 'Basic ' . base64_encode('mobile:' . $plainToken),
        ])->getJson('/api/v1/user/profile')->assertUnauthorized();
    }

    public function test_bearer_token_transport_remains_authoritative(): void
    {
        $plainToken = $this->user->generateApiToken();

        $this->assertDatabaseHas('api_tokens', ['token' => hash('sha256', $plainToken)]);
        $this->assertDatabaseMissing('api_tokens', ['token' => $plainToken]);

        $this->app['auth']->forgetGuards();
        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile')
            ->assertOk();
    }

    public function test_stale_completion_owner_cannot_run_session_writes_after_reclaim(): void
    {
        $attempts = app(\App\Services\SocialOAuthAttemptService::class);
        $completionCode = str_repeat('q', 64);
        $attempt = $attempts->begin(
            str_repeat('s', 64),
            'google',
            'rokn://auth',
            str_repeat('a', 43)
        );
        $attempts->issueCompletion(
            $attempt,
            $completionCode,
            \Illuminate\Support\Facades\Crypt::encryptString('provider-token')
        );

        $firstClaim = $attempts->claimCompletion($completionCode);
        self::assertNotNull($firstClaim);
        \App\Models\SocialOAuthAttempt::query()
            ->whereKey($attempt->id)
            ->update(['completion_processing_at' => now()->subMinutes(3)]);
        $secondClaim = $attempts->claimCompletion($completionCode);
        self::assertNotNull($secondClaim);
        self::assertNotSame($firstClaim->completion_claim_id, $secondClaim->completion_claim_id);

        $firstRan = false;
        $firstResult = $attempts->whileCompletionClaimIsOwned(
            (int) $firstClaim->id,
            (string) $firstClaim->completion_claim_id,
            function () use (&$firstRan): string {
                $firstRan = true;
                return 'stale';
            }
        );
        self::assertNull($firstResult);
        self::assertFalse($firstRan);

        $secondResult = $attempts->whileCompletionClaimIsOwned(
            (int) $secondClaim->id,
            (string) $secondClaim->completion_claim_id,
            static fn (): string => 'owned'
        );
        self::assertSame('owned', $secondResult);
    }

    public function test_revoking_one_session_keeps_push_for_another_session_on_the_same_device(): void
    {
        $deviceId = (string) Str::uuid();
        $currentToken = $this->user->generateApiToken();
        $otherToken = $this->user->generateApiToken();
        \App\Models\ApiToken::query()
            ->whereIn('token', [hash('sha256', $currentToken), hash('sha256', $otherToken)])
            ->update(['device_id' => $deviceId]);
        \App\Models\UserDeviceToken::query()->create([
            'user_id' => $this->user->id,
            'device_token' => 'shared-device-push-token',
            'device_type' => 'android',
            'device_os' => 'android',
            'device_id' => $deviceId,
        ]);
        $otherSessionId = (string) \App\Models\ApiToken::query()
            ->where('token', hash('sha256', $otherToken))
            ->value('session_id');

        $this->app['auth']->forgetGuards();
        $this->withToken($currentToken)
            ->deleteJson('/api/v1/user/sessions/'.$otherSessionId)
            ->assertOk();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_id' => $deviceId,
            'device_token' => 'shared-device-push-token',
        ]);
    }

    public function test_auth_methods_hide_half_configured_tiktok_and_blank_credentials(): void
    {
        config()->set([
            'social_auth.providers' => ['google', 'tiktok'],
            'services.google.client_id' => '   ',
            'services.google.client_secret' => 'configured',
            'services.tiktok.client_key' => 'configured',
            'services.tiktok.client_secret' => 'configured',
            'social_auth.tiktok.user_info_url' => 'http://open.tiktokapis.com/v2/user/info/',
        ]);

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.providers', [])
            ->assertJsonPath('data.authorization_urls', []);

        config()->set([
            'services.google.client_id' => 'configured',
            'social_auth.tiktok.user_info_url' => 'https://open.tiktokapis.com/v2/user/info/',
        ]);

        $this->getJson('/api/v1/auth-methods')
            ->assertOk()
            ->assertJsonPath('data.providers', ['google', 'tiktok'])
            ->assertJsonStructure([
                'data' => [
                    'authorization_urls' => ['google', 'tiktok'],
                ],
            ]);
    }

    public function test_social_oauth_requires_pkce_on_start_and_completion(): void
    {
        $this->get('/api/v1/social-auth/google/start?return_to=rokn%3A%2F%2Fauth')
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['code_challenge', 'code_challenge_method']);

        $this->postJson('/api/v1/social-auth/complete', [
            'code' => str_repeat('x', 64),
            'device_os' => 'android',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['code_verifier']);
    }

    public function test_query_token_cannot_override_the_bearer_token(): void
    {
        $plainToken = $this->user->generateApiToken();

        $this->app['auth']->forgetGuards();
        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile?api_token=attacker-controlled-value')
            ->assertOk();
    }

    public function test_inactive_account_token_is_rejected_deleted_and_never_renewed(): void
    {
        $plainToken = $this->user->generateApiToken();
        $storedToken = hash('sha256', $plainToken);
        \App\Models\ApiToken::query()
            ->where('token', $storedToken)
            ->update(['expired_at' => now()->addDays(2)]);

        $this->user->forceFill(['active' => false])->save();
        $this->app['auth']->forgetGuards();

        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile')
            ->assertUnauthorized();

        $this->assertDatabaseHas('api_tokens', ['token' => $storedToken]);
        self::assertNotNull(\App\Models\ApiToken::query()->find($storedToken)?->revoked_at);
    }

    public function test_soft_deleted_account_token_is_rejected_and_deleted(): void
    {
        $plainToken = $this->user->generateApiToken();
        $storedToken = hash('sha256', $plainToken);
        $this->user->delete();
        $this->app['auth']->forgetGuards();

        $this->withToken($plainToken)
            ->getJson('/api/v1/user/profile')
            ->assertUnauthorized();

        $this->assertDatabaseHas('api_tokens', ['token' => $storedToken]);
        self::assertNotNull(\App\Models\ApiToken::query()->find($storedToken)?->revoked_at);
    }

    public function test_logout_does_not_delete_other_installations_push_tokens(): void
    {
        $apiToken = $this->user->generateApiToken();
        foreach (['phone-a-fcm-token', 'phone-b-fcm-token'] as $deviceToken) {
            \App\Models\UserDeviceToken::query()->create([
                'user_id' => $this->user->id,
                'device_token' => $deviceToken,
                'device_type' => 'android',
                'device_os' => 'android',
            ]);
        }

        $this->withToken($apiToken)->postJson('/api/v1/logout')->assertOk();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-a-fcm-token',
        ]);
        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-b-fcm-token',
        ]);
    }

    public function test_logout_atomically_removes_only_this_installations_push_token(): void
    {
        $apiToken = $this->user->generateApiToken();
        foreach (['phone-a-fcm-token', 'phone-b-fcm-token'] as $deviceToken) {
            \App\Models\UserDeviceToken::query()->create([
                'user_id' => $this->user->id,
                'device_token' => $deviceToken,
                'device_type' => 'android',
                'device_os' => 'android',
            ]);
        }

        $this->withToken($apiToken)->postJson('/api/v1/logout', [
            'device_token' => 'phone-a-fcm-token',
        ])->assertOk();

        $this->assertDatabaseMissing('api_tokens', ['token' => $apiToken]);
        $this->assertDatabaseMissing('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-a-fcm-token',
        ]);
        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => 'phone-b-fcm-token',
        ]);
    }

    public function test_replaced_bearer_keeps_the_same_installations_push_registration(): void
    {
        $deviceId = (string) Str::uuid();
        $oldToken = $this->user->generateApiToken();
        $replacementToken = $this->user->generateApiToken();
        \App\Models\ApiToken::query()
            ->whereIn('token', [hash('sha256', $oldToken), hash('sha256', $replacementToken)])
            ->update(['device_id' => $deviceId]);
        \App\Models\UserDeviceToken::query()->create([
            'user_id' => $this->user->id,
            'device_token' => 'same-phone-current-push-token',
            'device_type' => 'android',
            'device_os' => 'android',
            'device_id' => $deviceId,
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($oldToken)->postJson('/api/v1/logout', [
            'device_token' => 'same-phone-current-push-token',
            'preserve_device_registration' => true,
        ])->assertOk();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_id' => $deviceId,
            'device_token' => 'same-phone-current-push-token',
        ]);
        $this->assertDatabaseHas('api_tokens', [
            'token' => hash('sha256', $replacementToken),
            'revoked_at' => null,
        ]);
        $this->assertDatabaseMissing('api_tokens', [
            'token' => hash('sha256', $oldToken),
            'revoked_at' => null,
        ]);
    }

    public function test_logout_is_not_exposed_as_a_state_changing_get_request(): void
    {
        $getLogoutRoute = collect(app('router')->getRoutes()->getRoutes())
            ->first(fn ($route) => $route->uri() === 'api/v1/logout'
                && in_array('GET', $route->methods(), true));

        $this->assertNull($getLogoutRoute);
    }

    public function test_authenticated_user_can_register_and_remove_this_device_push_token(): void
    {
        $token = 'fcm-test-token-for-one-installation';

        $this->actingAs($this->user, 'api')->postJson('/api/v1/user/device-token', [
            'device_token' => $token,
            'device_type' => 'android',
            'device_os' => 'android',
        ])->assertOk();

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => $token,
        ]);
        // Registering one installation proves that this device can receive a
        // token; it must not silently opt the account into notifications.
        $this->assertDatabaseHas('users', [
            'id' => $this->user->id,
            'notifications_status' => false,
        ]);

        $this->actingAs($this->user, 'api')->deleteJson('/api/v1/user/device-token', [
            'device_token' => $token,
        ])->assertOk();

        $this->assertDatabaseMissing('user_device_tokens', [
            'user_id' => $this->user->id,
            'device_token' => $token,
        ]);
    }

    public function test_authenticated_user_can_delete_own_account(): void
    {
        $this->user->forceFill([
            'social_provider' => 'facebook',
            'social_id' => 'facebook-owner-1',
        ])->save();
        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'facebook-owner-1',
            'last_verified_at' => now(),
        ]);
        $reauthToken = $this->user->generateApiToken('facebook', 'facebook-owner-1');

        $this->app['auth']->forgetGuards();
        $this->withToken($reauthToken)->postJson('/api/v1/delete-account')
            ->assertOk();

        $deleted = \App\Models\User::withTrashed()->findOrFail($this->user->id);
        $this->assertFalse((bool) $deleted->active);
        $this->assertNotNull($deleted->deleted_at);
    }

    public function test_account_deletion_rejects_an_ordinary_or_wrong_provider_session(): void
    {
        $this->user->forceFill([
            'social_provider' => 'facebook',
            'social_id' => 'facebook-owner-2',
        ])->save();
        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'google',
            'provider_user_id' => 'google-owner-2',
            'last_verified_at' => now(),
        ]);
        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'facebook',
            'provider_user_id' => 'facebook-owner-2',
            'last_verified_at' => now(),
        ]);
        $token = $this->user->generateApiToken();

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/delete-account')
            ->assertForbidden()
            ->assertJsonPath('code', 'social_reauthentication_required');

        \App\Models\SocialAccount::query()
            ->where('user_id', $this->user->id)
            ->where('provider', 'facebook')
            ->where('provider_user_id', 'facebook-owner-2')
            ->update(['last_verified_at' => now()->subMinutes(11)]);
        $staleReauthenticationToken = $this->user->generateApiToken(
            'facebook',
            'facebook-owner-2'
        );
        \App\Models\ApiToken::query()
            ->where('token', hash('sha256', $staleReauthenticationToken))
            ->update(['issued_at' => now()->subMinutes(11)]);

        $this->app['auth']->forgetGuards();
        $this->withToken($staleReauthenticationToken)->postJson('/api/v1/delete-account')
            ->assertForbidden()
            ->assertJsonPath('code', 'social_reauthentication_required');

        $this->assertTrue((bool) $this->user->fresh()->active);
    }

    public function test_linked_provider_reauthentication_is_bound_to_its_bearer(): void
    {
        $this->user->forceFill([
            'social_provider' => 'facebook',
            'social_id' => 'facebook-linked-owner',
        ])->save();
        foreach ([
            ['facebook', 'facebook-linked-owner'],
            ['google', 'google-linked-owner'],
        ] as [$provider, $providerUserId]) {
            \App\Models\SocialAccount::query()->create([
                'user_id' => $this->user->id,
                'provider' => $provider,
                'provider_user_id' => $providerUserId,
                'last_verified_at' => now(),
            ]);
        }

        $googleToken = $this->user->generateApiToken('google', 'google-linked-owner');

        $this->app['auth']->forgetGuards();
        $this->withToken($googleToken)
            ->getJson('/api/v1/user/profile')
            ->assertOk()
            ->assertJsonPath('data.social_provider', 'google');

        $this->app['auth']->forgetGuards();
        $this->withToken($googleToken)
            ->postJson('/api/v1/delete-account')
            ->assertOk();
    }

    public function test_deleted_identity_cannot_repeat_welcome_or_one_time_task_rewards(): void
    {
        config(['social_auth.reward_tombstone_hmac_key' => 'unit-test-tombstone-key']);
        $originalEmail = (string) $this->user->email;
        $providerId = 'raw-provider-id-must-not-be-stored';
        $this->user->forceFill([
            'social_provider' => 'facebook',
            'social_id' => $providerId,
            'email_verified_at' => now(),
        ])->save();
        \App\Models\SocialAccount::query()->create([
            'user_id' => $this->user->id,
            'provider' => 'facebook',
            'provider_user_id' => $providerId,
            'last_verified_at' => now(),
        ]);

        $registerId = (int) \App\Models\CoinEarningMethod::query()
            ->where('action_key', 'register')
            ->value('id');
        $instagramId = (int) \Illuminate\Support\Facades\DB::table('coin_earning_methods')->insertGetId([
            'action_key' => 'instagram',
            'coins_amount' => 75,
            'is_repeatable' => false,
            'is_active' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $anonymousMethodId = (int) \Illuminate\Support\Facades\DB::table('coin_earning_methods')->insertGetId([
            'action_key' => null,
            'coins_amount' => 40,
            'is_repeatable' => false,
            'is_active' => true,
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ([$registerId, $instagramId, $anonymousMethodId] as $methodId) {
            $this->user->coinEarnings()->create([
                'coin_earning_method_id' => $methodId,
                'amount' => 20,
            ]);
        }

        $reauthToken = $this->user->generateApiToken('facebook', $providerId);
        $this->app['auth']->forgetGuards();
        $this->withToken($reauthToken)->postJson('/api/v1/delete-account')->assertOk();

        $this->assertDatabaseCount('deleted_social_reward_tombstones', 2);
        $tombstone = \App\Models\DeletedSocialRewardTombstone::query()
            ->where('provider', 'facebook')
            ->sole();
        $this->assertSame([
            'method:' . $anonymousMethodId,
            'task:instagram',
            'welcome_bonus',
        ], $tombstone->consumed_reward_keys);
        $this->assertStringNotContainsString($providerId, (string) $tombstone->identity_hmac);
        $this->assertDatabaseMissing('deleted_social_reward_tombstones', [
            'identity_hmac' => $providerId,
        ]);

        $replacement = \App\Models\User::query()->forceCreate([
            'name' => 'Same learner, new account row',
            'email' => 'replacement@rokn.test',
            'password' => bcrypt('irrelevant'),
            'role' => 'client',
            'active' => true,
            'social_provider' => 'facebook',
            'social_id' => $providerId,
            'wallet_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
        \App\Models\SocialAccount::query()->create([
            'user_id' => $replacement->id,
            'provider' => 'facebook',
            'provider_user_id' => $providerId,
            'last_verified_at' => now(),
        ]);

        $this->assertSame(0, \App\Services\StudentNotificationService::sendRegistrationBonus($replacement));
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $replacement->id,
            'category' => 'welcome_bonus',
        ]);

        $replacementToken = $replacement->generateApiToken();
        $this->app['auth']->forgetGuards();
        $this->withToken($replacementToken)
            ->postJson('/api/v1/claim-coins', ['method_id' => $instagramId])
            ->assertOk()
            ->assertJsonPath('data.already_claimed', true)
            ->assertJsonPath('data.earned_amount', 0);
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $replacement->id,
            'category' => 'task_reward',
        ]);

        $this->app['auth']->forgetGuards();
        $this->withToken($replacementToken)
            ->postJson('/api/v1/claim-coins', ['method_id' => $anonymousMethodId])
            ->assertNotFound();

        $crossProviderReplacement = \App\Models\User::query()->forceCreate([
            'name' => 'Same email via another provider',
            'email' => $originalEmail,
            'password' => bcrypt('irrelevant'),
            'role' => 'client',
            'active' => true,
            'email_verified_at' => now(),
            'social_provider' => 'google',
            'social_id' => 'new-google-provider-id',
            'wallet_coins' => 0,
            'wallet_reward_coins' => 0,
        ]);
        \App\Models\SocialAccount::query()->create([
            'user_id' => $crossProviderReplacement->id,
            'provider' => 'google',
            'provider_user_id' => 'new-google-provider-id',
            'last_verified_at' => now(),
        ]);

        $this->assertSame(
            0,
            \App\Services\StudentNotificationService::sendRegistrationBonus($crossProviderReplacement)
        );
        $this->assertDatabaseMissing('wallet_transactions', [
            'user_id' => $crossProviderReplacement->id,
            'category' => 'welcome_bonus',
        ]);
    }
}
