<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Illuminate\Http\Middleware\TrustHosts as Middleware;
use Symfony\Component\HttpFoundation\Exception\SuspiciousOperationException;

class TrustHosts extends Middleware
{
    public function handle(Request $request, $next)
    {
        if ($this->shouldSpecifyTrustedHosts()) {
            Request::setTrustedHosts(array_filter($this->hosts()));

            try {
                $request->getHost();
            } catch (SuspiciousOperationException) {
                return response('Bad Request', 400);
            }
        }

        return $next($request);
    }

    /**
     * Get the host patterns that should be trusted.
     *
     * @return array<int, string>
     */
    public function hosts(): array
    {
        $configured = array_values(array_filter(array_map(
            static fn ($value): string => strtolower(trim((string) $value)),
            (array) config('trusted_hosts.hosts', [])
        )));

        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        if ($appHost !== '') {
            // APP_URL is also an authoritative first-party origin. Keeping it
            // in the exact allow-list avoids rejecting health/API traffic when
            // operations switch the canonical URL during a domain cut-over.
            $configured[] = $appHost;
        }

        return array_map(
            static fn (string $host): string => '^' . preg_quote($host) . '$',
            array_values(array_unique($configured))
        );
    }

    /**
     * Host validation must also run in local and automated environments so a
     * release cannot silently depend on behaviour disabled by the framework.
     */
    protected function shouldSpecifyTrustedHosts(): bool
    {
        return $this->hosts() !== [];
    }
}
