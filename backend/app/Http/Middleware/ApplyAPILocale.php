<?php

namespace App\Http\Middleware;

use App\Support\ApiErrorContract;
use App\Support\BusinessClock;
use App\Support\RoknLocale;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApplyAPILocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $locale = RoknLocale::fromRequest($request);
        app()->setLocale($locale);

        $response = $next($request);
        if ($response instanceof JsonResponse && ! $response->headers->has('ETag')) {
            $this->normalizeEnvelope($response);
        }
        $response->headers->set('Content-Language', $locale);
        $vary = array_filter(array_map('trim', explode(',', (string) $response->headers->get('Vary'))));
        $normalizedVary = array_map('strtolower', $vary);
        if (! in_array('*', $vary, true) && ! in_array('accept-language', $normalizedVary, true)) {
            $vary[] = 'Accept-Language';
        }
        $response->headers->set('Vary', implode(', ', $vary));

        return $response;
    }

    private function normalizeEnvelope(JsonResponse $response): void
    {
        $status = $response->getStatusCode();
        // ApiResponseService and PaymentApiResponseService already emit the
        // full success envelope. Their original payload is the array that was
        // encoded, so the top-level clock is visible without decoding (and
        // re-encoding) what can be a large catalogue or lesson body.
        $original = $response->getOriginalContent();
        if ($status < 400 && is_array($original) && array_key_exists('server_time', $original)) {
            return;
        }

        $body = $response->getData(true);
        if (! is_array($body)) {
            return;
        }

        $changed = false;
        if ($status >= 400) {
            $body['status'] = is_int($body['status'] ?? null) ? $body['status'] : $status;
            $body['http_status'] = $status;
            $body['success'] = false;
            $body['data'] = $body['data'] ?? null;
            $body['message'] = trim((string) ($body['message'] ?? '')) !== ''
                ? $body['message']
                : 'تعذّر إكمال الطلب';
            $body['code'] = trim((string) ($body['code'] ?? '')) !== ''
                ? $body['code']
                : ApiErrorContract::codeForStatus($status);
            $body['retryable'] = array_key_exists('retryable', $body)
                ? (bool) $body['retryable']
                : ApiErrorContract::retryable($status);
            $changed = true;
        }
        if (! array_key_exists('server_time', $body)) {
            // Non-versioned manual and legacy controllers share the same
            // clock contract as ApiResponseService. ETagged bodies stay
            // byte-stable and clients use their HTTP Date header instead.
            $body['server_time'] = BusinessClock::utcNow()->toIso8601String();
            $changed = true;
        }
        if ($changed) {
            // One encode for both additions instead of one per addition.
            $response->setData($body);
        }
    }
}
