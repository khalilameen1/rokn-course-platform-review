<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Permitted-Cross-Domain-Policies', 'none');
        // Controllers serving unlisted capability pages may deliberately set
        // no-referrer. Never weaken an explicit, stricter response policy.
        if (!$response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        }
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');

        // Account-scoped JSON often contains progress, wallet state or a
        // short-lived media capability. Do not let a proxy/browser cache reuse
        // that response for a different bearer token on a shared device.
        if ($request->headers->has('Authorization') || $request->user('api')) {
            $response->headers->set('Cache-Control', 'private, no-store, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->setVary('Authorization', false);
        }

        if ($request->isSecure() && app()->environment('production')) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $contentType = strtolower((string) $response->headers->get('Content-Type'));
        if (str_contains($contentType, 'text/html')) {
            // Existing dashboard templates still use inline handlers, scripts
            // and styles, so removing unsafe-inline requires a separate view
            // migration. External script/style/connect origins are nevertheless
            // explicit, and unsafe-eval is not needed by the shipped bundles.
            $policy = implode('; ', [
                "default-src 'self'",
                "base-uri 'self'",
                "object-src 'none'",
                "frame-ancestors 'self'",
                "form-action 'self' https://checkout.kashier.io https://*.kashier.io",
                "img-src 'self' data: blob: https:",
                "font-src 'self' data:",
                "style-src 'self' 'unsafe-inline' https://cdn.datatables.net",
                "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdn.datatables.net https://maps.googleapis.com https://maps.gstatic.com https://checkout.kashier.io https://*.kashier.io",
                "connect-src 'self' https://maps.googleapis.com https://maps.gstatic.com https://*.bunny.net https://*.bunnycdn.com https://*.b-cdn.net https://*.kashier.io",
                "media-src 'self' blob: https:",
                // Portfolio video frames first enter a same-origin access-checked
                // media route, which redirects to a short-lived Bunny embed.
                "frame-src 'self' https://checkout.kashier.io https://*.kashier.io https://iframe.mediadelivery.net https://www.youtube.com https://www.youtube-nocookie.com https://player.vimeo.com https://drive.google.com",
                "worker-src 'self' blob:",
                "manifest-src 'self'",
                'block-all-mixed-content',
            ]);
            if ($request->isSecure() && app()->environment('production')) {
                $policy .= '; upgrade-insecure-requests';
            }
            $response->headers->set('Content-Security-Policy', $policy);
        }

        return $response;
    }
}
