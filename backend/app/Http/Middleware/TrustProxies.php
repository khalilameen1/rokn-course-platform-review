<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies as Middleware;
use Illuminate\Http\Request;

class TrustProxies extends Middleware
{
    /**
     * The trusted proxies for this application.
     *
     * @var array|string
     */
    protected $proxies;

    /**
     * The headers that should be used to detect proxies.
     *
     * @var int
     */
    protected $headers =
        Request::HEADER_X_FORWARDED_FOR |
        Request::HEADER_X_FORWARDED_HOST |
        Request::HEADER_X_FORWARDED_PORT |
        Request::HEADER_X_FORWARDED_PROTO |
        Request::HEADER_X_FORWARDED_AWS_ELB;

    public function handle(Request $request, Closure $next)
    {
        $configured = array_values(array_filter(array_map(
            static fn ($proxy): string => trim((string) $proxy),
            (array) config('trusted_proxies.proxies', [])
        )));

        // Laravel Cloud keeps the app origin private but its edge addresses
        // are not a customer-maintained fixed allow-list. In that topology,
        // trusting the cloud edge is required for real client IPs, per-user
        // throttles, secure redirects and audit evidence. The second explicit
        // flag prevents `*` becoming an accidental default elsewhere.
        $dynamicCloudEdge = $configured === ['*']
            && (bool) config('trusted_proxies.allow_dynamic_edge', false);
        $this->proxies = $dynamicCloudEdge
            ? '*'
            : array_values(array_filter(
                $configured,
                fn ($proxy) => $this->isSafeProxyDefinition($proxy)
            ));

        return parent::handle($request, $next);
    }

    private function isSafeProxyDefinition(string $proxy): bool
    {
        $proxy = trim($proxy);
        if ($proxy === '' || in_array($proxy, ['*', '0.0.0.0/0', '::/0'], true)) {
            return false;
        }
        if (! str_contains($proxy, '/')) {
            return filter_var($proxy, FILTER_VALIDATE_IP) !== false;
        }

        [$network, $prefix] = array_pad(explode('/', $proxy, 2), 2, null);
        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return ctype_digit((string) $prefix) && (int) $prefix >= 8 && (int) $prefix <= 32;
        }
        if (filter_var($network, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return ctype_digit((string) $prefix) && (int) $prefix >= 32 && (int) $prefix <= 128;
        }

        return false;
    }
}
