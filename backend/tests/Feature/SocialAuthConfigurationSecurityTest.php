<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class SocialAuthConfigurationSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_return_url_configuration_fails_closed(): void
    {
        config([
            'social_auth.return_urls' => [],
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);

        $this->get('/api/v1/social-auth/google/start?' . http_build_query([
            'code_challenge' => str_repeat('A', 43),
            'code_challenge_method' => 'S256',
            'return_to' => 'rokn://auth',
        ]))->assertStatus(422);
    }

    public function test_misconfigured_external_return_url_is_never_accepted_at_runtime(): void
    {
        config([
            'social_auth.return_urls' => [
                'rokn://auth',
                'https://attacker.invalid/callback',
            ],
        ]);
        $challenge = str_repeat('A', 43);

        $this->get('/api/v1/social-auth/google/start?' . http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'return_to' => 'https://attacker.invalid/callback',
        ]))->assertStatus(422);
    }

    public function test_explicit_rokn_callback_remains_usable_with_pkce(): void
    {
        config([
            'social_auth.return_urls' => ['rokn://auth'],
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
        $challenge = str_repeat('B', 43);

        $response = $this->get('/api/v1/social-auth/google/start?' . http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'return_to' => 'rokn://auth',
        ]));

        $response->assertRedirect();
        self::assertStringStartsWith(
            'https://accounts.google.com/o/oauth2/v2/auth?',
            (string) $response->headers->get('Location')
        );
    }

    public function test_callback_is_bound_to_the_pkce_attempt_that_started_it(): void
    {
        config([
            'social_auth.return_urls' => ['rokn://auth'],
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
        $challenge = str_repeat('C', 43);
        $start = $this->get('/api/v1/social-auth/google/start?' . http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'return_to' => 'rokn://auth',
        ]));
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);

        $callback = $this->get('/api/v1/social-auth/google/callback?' . http_build_query([
            'state' => $query['state'],
            'error' => 'access_denied',
        ]));

        $callback->assertRedirect('rokn://auth?' . http_build_query([
            'error' => 'login_cancelled',
            'attempt' => $challenge,
        ]));
    }

    public function test_transient_token_exchange_failure_does_not_replay_provider_code(): void
    {
        config([
            'social_auth.providers' => ['google'],
            'social_auth.return_urls' => ['rokn://auth'],
            'services.google.client_id' => 'test-client-id',
            'services.google.client_secret' => 'test-client-secret',
        ]);
        Http::fakeSequence()->push(['error' => 'temporarily_unavailable'], 503);
        $challenge = str_repeat('D', 43);
        $start = $this->get('/api/v1/social-auth/google/start?' . http_build_query([
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
            'return_to' => 'rokn://auth',
        ]));
        parse_str((string) parse_url((string) $start->headers->get('Location'), PHP_URL_QUERY), $query);
        $state = (string) $query['state'];
        $callbackUrl = '/api/v1/social-auth/google/callback?' . http_build_query([
            'state' => $state,
            'code' => 'one-provider-authorization-code',
        ]);

        $this->get($callbackUrl)
            ->assertRedirect('rokn://auth?' . http_build_query([
                'error' => 'provider_unavailable',
                'attempt' => $challenge,
            ]));
        $this->assertDatabaseHas('social_oauth_attempts', [
            'state_hash' => hash('sha256', $state),
            'completion_hash' => null,
        ]);
        self::assertNotNull(\App\Models\SocialOAuthAttempt::query()
            ->where('state_hash', hash('sha256', $state))
            ->value('state_consumed_at'));

        $this->get($callbackUrl)->assertRedirect('rokn://auth?' . http_build_query([
            'error' => 'login_cancelled',
            'attempt' => $challenge,
        ]));
        Http::assertSentCount(1);
    }
}
