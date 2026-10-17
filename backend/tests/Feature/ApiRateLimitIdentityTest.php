<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Many learners share one carrier-grade NAT address. Their personal quota
 * must follow the account (or its bearer token before auth resolves), while
 * the address only carries the generous anti-rotation ceiling.
 */
final class ApiRateLimitIdentityTest extends TestCase
{
    public function test_authenticated_users_behind_one_address_get_separate_buckets(): void
    {
        $first = $this->keys($this->request('GET', userId: 41));
        $second = $this->keys($this->request('GET', userId: 42));

        self::assertSame(['read:identity:user:41', 'read:ip:100.64.0.10'], $first);
        self::assertSame(['read:identity:user:42', 'read:ip:100.64.0.10'], $second);
    }

    public function test_bearer_tokens_are_keyed_per_token_before_auth_resolves(): void
    {
        $first = $this->keys($this->request('POST', token: 'token-a'));
        $second = $this->keys($this->request('POST', token: 'token-b'));

        self::assertSame('write:identity:token:'.hash('sha256', 'token-a'), $first[0]);
        self::assertSame('write:identity:token:'.hash('sha256', 'token-b'), $second[0]);
        self::assertSame('write:ip:100.64.0.10', $first[1]);
    }

    public function test_anonymous_requests_are_keyed_by_client_address(): void
    {
        self::assertSame(
            ['read:identity:ip:100.64.0.10', 'read:ip:100.64.0.10'],
            $this->keys($this->request('GET'))
        );
    }

    private function request(string $method, ?int $userId = null, ?string $token = null): Request
    {
        $server = ['REMOTE_ADDR' => '100.64.0.10'];
        if ($token !== null) {
            $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
        }
        $request = Request::create('/api/v1/courses', $method, server: $server);
        if ($userId !== null) {
            $user = new User();
            $user->id = $userId;
            $request->setUserResolver(static fn () => $user);
        }

        return $request;
    }

    /** @return list<string> */
    private function keys(Request $request): array
    {
        $limits = RateLimiter::limiter('api')($request);

        return array_map(static fn (Limit $limit): string => (string) $limit->key, (array) $limits);
    }
}
