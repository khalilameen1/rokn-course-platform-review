<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\RateLimitObservabilityService;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

final class ResilientThrottleRequests extends ThrottleRequests
{
    /** @var array<string,int> */
    private static array $lastDegradedLogAt = [];

    public function __construct(
        RateLimiter $limiter,
        private readonly RateLimitObservabilityService $observability
    ) {
        parent::__construct($limiter);
    }

    protected function resolveRequestSignature($request)
    {
        $identity = parent::resolveRequestSignature($request);
        $route = $request->route();
        if (!$request->is('api/*') || !$route) {
            return $identity;
        }

        // Laravel's numeric limiter otherwise shares one counter across all
        // routes for this user/IP. Polling must not exhaust submission limits.
        // Controller aliases share a quota; resource IDs never create new ones.
        // Named limiters do not call this method and retain their shared keys.
        $action = $route->getActionName();
        if ($action === 'Closure') {
            $action = $route->uri();
        }
        $method = $request->isMethod('HEAD') ? 'GET' : $request->method();

        return hash('sha256', $identity.'|'.$method.'|'.$action);
    }

    protected function handleRequest($request, Closure $next, array $limits)
    {
        try {
            foreach ($limits as $limit) {
                if ($this->limiter->tooManyAttempts($limit->key, $limit->maxAttempts)) {
                    throw $this->buildException(
                        $request,
                        $limit->key,
                        $limit->maxAttempts,
                        $limit->responseCallback
                    );
                }

                if (!$limit->afterCallback) {
                    $this->limiter->hit($limit->key, $limit->decaySeconds);
                }
            }
        } catch (ThrottleRequestsException|HttpResponseException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->logDegradedLimiter($request, $exception, 'preflight');
            if ($this->mayFailOpen($request)) {
                return $next($request);
            }

            // Money movement, authentication and every mutation stop before
            // their controller when the shared limiter cannot arbitrate.
            throw new ServiceUnavailableHttpException(5, 'Request protection is temporarily unavailable.');
        }

        $response = $next($request);

        // A cache outage after a successful controller must never replace its
        // response with a 503 and provoke a duplicate mutation retry.
        try {
            foreach ($limits as $limit) {
                if ($limit->afterCallback && ($limit->afterCallback)($response)) {
                    $this->limiter->hit($limit->key, $limit->decaySeconds);
                }

                $response = $this->addHeaders(
                    $response,
                    $limit->maxAttempts,
                    $this->calculateRemainingAttempts($limit->key, $limit->maxAttempts)
                );
            }
        } catch (Throwable $exception) {
            $this->logDegradedLimiter($request, $exception, 'response');
        }

        return $response;
    }

    protected function buildException($request, $key, $maxAttempts, $responseCallback = null)
    {
        $exception = parent::buildException($request, $key, $maxAttempts, $responseCallback);
        try {
            $retryAfter = method_exists($exception, 'getHeaders')
                ? (int) ($exception->getHeaders()['Retry-After'] ?? 0)
                : (int) ($exception->getResponse()?->headers->get('Retry-After') ?? 0);
            $this->observability->record(
                $request,
                (string) $key,
                $retryAfter
            );
        } catch (Throwable $recordingFailure) {
            report($recordingFailure);
        }

        return $exception;
    }

    private function mayFailOpen($request): bool
    {
        if ($request->isMethodSafe()) {
            return !$request->is(
                'api/*/social-auth/*',
                'api/social-auth/*',
                'payment/*',
                'api/*/store-notifications/*',
                'api/store-notifications/*',
                'api/*/whatsapp/webhook',
                'api/whatsapp/webhook',
                'api/*/courses/*/download',
                'api/courses/*/download'
            );
        }

        // These authenticated writes are cheap and naturally idempotent or
        // last-write-wins. A Redis incident must not erase learning progress
        // or make the app appear broken. Expensive, financial, authentication,
        // upload, AI and admin mutations remain fail-closed.
        if (trim((string) $request->bearerToken()) === '') {
            return false;
        }

        return $request->is(
            'api/*/logout',
            'api/logout',
            'api/*/client-events',
            'api/client-events',
            'api/*/product-events',
            'api/product-events',
            'api/*/user/watch-history',
            'api/user/watch-history',
            'api/*/notifications/*/mark-read',
            'api/notifications/*/mark-read',
            'api/*/notifications/mark-all-read',
            'api/notifications/mark-all-read',
            'api/*/courses/*/sections/*/complete',
            'api/courses/*/sections/*/complete',
            'api/*/saved-folders*',
            'api/saved-folders*',
            'api/*/saved-lessons*',
            'api/saved-lessons*'
        );
    }

    private function logDegradedLimiter($request, Throwable $exception, string $phase): void
    {
        $key = $phase . ':' . (string) ($request->route()?->getName() ?: $request->path());
        $now = time();
        if (($now - (self::$lastDegradedLogAt[$key] ?? 0)) < 60) {
            return;
        }
        self::$lastDegradedLogAt[$key] = $now;

        Log::error('Rate limiter storage is unavailable.', [
            'phase' => $phase,
            'route' => $request->route()?->getName(),
            'method' => $request->method(),
            'exception' => $exception::class,
            'failed_open' => $this->mayFailOpen($request),
        ]);
    }
}
