<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\ResilientThrottleRequests;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class ApiRateLimitSecurityTest extends TestCase
{
    public function test_chat_reads_and_playback_do_not_consume_project_or_purchase_attempts(): void
    {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->numericRequest('GET', '/api/v1/course-chat/turns/00000000-0000-4000-8000-000000000001');
            $this->numericRequest('POST', '/api/v1/lessons/'.$attempt.'/playback-manifest');
        }

        $this->assertSame(202, $this->numericRequest('POST', '/api/v1/projects/8/submissions')->getStatusCode());
        $this->assertSame(202, $this->numericRequest('POST', '/api/v1/courses/authorize')->getStatusCode());
        $this->assertSame(202, $this->numericRequest('POST', '/api/v1/project-submissions/00000000-0000-4000-8000-000000000001/review/retry')->getStatusCode());
    }

    public function test_project_limit_still_applies_across_project_ids_and_returns_retry_after(): void
    {
        for ($attempt = 1; $attempt <= 8; $attempt++) {
            $this->numericRequest('POST', '/api/v1/projects/'.$attempt.'/submissions');
        }

        try {
            $this->numericRequest('POST', '/api/v1/projects/99/submissions');
            $this->fail('The ninth project submission must remain limited.');
        } catch (ThrottleRequestsException $error) {
            $this->assertSame(429, $error->getStatusCode());
            $this->assertGreaterThan(0, (int) $error->getHeaders()['Retry-After']);
        }
    }

    public function test_same_purchase_action_aliases_cannot_double_the_limit(): void
    {
        for ($attempt = 1; $attempt <= 6; $attempt++) {
            $this->numericRequest('POST', '/api/v1/courses/'.$attempt.'/chat-upgrade');
        }
        $this->expectException(ThrottleRequestsException::class);
        $this->numericRequest('POST', '/api/v1/courses/99/full-track-upgrade');
    }

    private function numericRequest(string $method, string $url): \Symfony\Component\HttpFoundation\Response
    {
        $request = Request::create($url, $method);
        $user = new User();
        $user->id = 701;
        $request->setUserResolver(static fn () => $user);
        $route = app('router')->getRoutes()->match($request);
        $request->setRouteResolver(static fn () => $route);
        $middleware = collect($route->gatherMiddleware())->first(
            static fn ($name) => preg_match('/^throttle:\d/', $name) === 1
        );
        $this->assertNotNull($middleware, 'Exercise the numeric limiter from the real API route.');

        return app(ResilientThrottleRequests::class)->handle(
            $request,
            static fn () => response()->json(['accepted' => true], 202),
            ...explode(',', substr($middleware, strlen('throttle:')))
        );
    }

    public function test_rotating_bogus_bearer_tokens_cannot_bypass_the_ip_ceiling(): void
    {
        $ip = '198.51.100.77';
        config([
            'rate_limits.api_read_identity_per_minute' => 100,
            'rate_limits.api_read_ip_per_minute' => 3,
        ]);
        Route::middleware('api')->get('/_api-rate-limit-test', static fn () => response()->json(['ok' => true]));
        RateLimiter::clear('read:ip:'.$ip);

        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $this->withHeaders(['Authorization' => 'Bearer forged-token-'.$attempt])
                ->withServerVariables(['REMOTE_ADDR' => $ip])
                ->get('/_api-rate-limit-test')
                ->assertOk();
        }

        $this->withHeaders(['Authorization' => 'Bearer forged-token-4'])
            ->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('/_api-rate-limit-test')
            ->assertStatus(429);
    }

    public function test_read_and_write_ip_ceilings_use_separate_buckets(): void
    {
        $ip = '198.51.100.78';
        config([
            'rate_limits.api_read_identity_per_minute' => 100,
            'rate_limits.api_read_ip_per_minute' => 1,
            'rate_limits.api_write_identity_per_minute' => 100,
            'rate_limits.api_write_ip_per_minute' => 1,
        ]);
        Route::middleware('api')->match(['GET', 'POST'], '/_api-split-rate-limit-test', static fn () => response('ok'));
        Route::middleware('api')->get('/_api-shared-read-rate-limit-test', static fn () => response('ok'));
        RateLimiter::clear('read:ip:'.$ip);
        RateLimiter::clear('write:ip:'.$ip);

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('/_api-split-rate-limit-test')
            ->assertOk();
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/_api-split-rate-limit-test')
            ->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('/_api-split-rate-limit-test')
            ->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->get('/_api-shared-read-rate-limit-test')
            ->assertStatus(429);
        $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post('/_api-split-rate-limit-test')
            ->assertStatus(429);
    }
}
