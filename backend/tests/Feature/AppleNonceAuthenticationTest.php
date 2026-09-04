<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class AppleNonceAuthenticationTest extends TestCase
{
    public function test_apple_social_login_requires_the_raw_nonce(): void
    {
        $this->postJson('/api/v1/social-login', [
            'provider' => 'apple',
            'token' => 'signed-identity-token',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['nonce']);
    }

    public function test_google_cannot_use_the_native_apple_token_endpoint(): void
    {
        config([
            'social_auth.providers' => ['google'],
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        $this->postJson('/api/v1/social-login', [
            'provider' => 'google',
            'token' => 'google-id-token',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'social_browser_attempt_required')
            ->assertJsonMissingValidationErrors(['nonce']);
    }

}
