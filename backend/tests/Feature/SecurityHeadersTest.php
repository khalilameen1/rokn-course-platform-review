<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class SecurityHeadersTest extends TestCase
{
    public function test_html_responses_receive_compatible_browser_protections(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        Route::middleware('web')->get('/_security-headers-test', fn () => response('<html><body>ok</body></html>'));

        $response = $this->withServerVariables(['HTTPS' => 'on', 'SERVER_PORT' => 443])
            ->get('https://localhost/_security-headers-test')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
            ->assertHeader('Strict-Transport-Security', 'max-age=31536000')
            ->assertHeader('Content-Security-Policy');

        $policy = (string) $response->headers->get('Content-Security-Policy');
        self::assertStringNotContainsString("'unsafe-eval'", $policy);
        self::assertDoesNotMatchRegularExpression('/script-src[^;]*\\shttps:(?:\\s|;)/', $policy);
        self::assertStringContainsString('https://cdn.jsdelivr.net', $policy);
        self::assertStringContainsString('https://cdn.datatables.net', $policy);
        self::assertStringContainsString('https://maps.googleapis.com', $policy);
        self::assertStringNotContainsString('fonts.googleapis.com', $policy);
        self::assertStringNotContainsString('fonts.gstatic.com', $policy);
        self::assertStringNotContainsString('cdnjs.cloudflare.com', $policy);
        self::assertStringContainsString('https://checkout.kashier.io', $policy);
        self::assertMatchesRegularExpression("/frame-src\\s+'self'[^;]*https:\/\/iframe\\.mediadelivery\\.net/", $policy);
        self::assertDoesNotMatchRegularExpression('/frame-src[^;]*\\shttps:(?:\\s|;)/', $policy);
        self::assertStringContainsString('block-all-mixed-content', $policy);
    }

    public function test_json_health_response_is_protected_without_an_html_csp(): void
    {
        $response = $this->getJson('/api/health/live')
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        self::assertFalse($response->headers->has('Content-Security-Policy'));
    }
}
