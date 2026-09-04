<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustHosts;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

final class TrustedHostTest extends TestCase
{
    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    public function test_global_middleware_accepts_only_an_explicit_configured_host(): void
    {
        config([
            'app.url' => 'https://api.rokn.academy',
            'trusted_hosts.hosts' => ['api.rokn.academy'],
        ]);
        Request::setTrustedHosts(app(TrustHosts::class)->hosts());
        Route::get('/_trusted-host-test', static fn () => response('ok'));

        self::assertSame(200, $this->statusForHost('api.rokn.academy', '/_trusted-host-test'));
        self::assertSame(400, $this->statusForHost('attacker.invalid', '/_trusted-host-test'));
        self::assertSame(400, $this->statusForHost('preview.api.rokn.academy', '/_trusted-host-test'));
    }

    public function test_app_url_host_is_an_exact_fallback_for_local_and_test_environments(): void
    {
        config([
            'app.url' => 'http://localhost',
            'trusted_hosts.hosts' => [],
        ]);
        Request::setTrustedHosts(app(TrustHosts::class)->hosts());
        Route::get('/_trusted-host-fallback-test', static fn () => response('ok'));

        self::assertSame(200, $this->statusForHost('localhost', '/_trusted-host-fallback-test'));
        self::assertSame(400, $this->statusForHost('preview.localhost', '/_trusted-host-fallback-test'));
    }

    public function test_app_url_and_configured_mobile_origin_are_both_exactly_trusted(): void
    {
        config([
            'app.url' => 'https://rokn.app',
            'trusted_hosts.hosts' => [
                'rokn.app',
                'rokn-course-platform-review-production-b7gpy1.laravel.cloud',
            ],
        ]);
        Request::setTrustedHosts(app(TrustHosts::class)->hosts());
        Route::get('/_trusted-mobile-origin-test', static fn () => response('ok'));

        self::assertSame(200, $this->statusForHost('rokn.app', '/_trusted-mobile-origin-test'));
        self::assertSame(200, $this->statusForHost(
            'rokn-course-platform-review-production-b7gpy1.laravel.cloud',
            '/_trusted-mobile-origin-test'
        ));
        self::assertSame(400, $this->statusForHost(
            'preview.rokn-course-platform-review-production-b7gpy1.laravel.cloud',
            '/_trusted-mobile-origin-test'
        ));
    }

    private function statusForHost(string $host, string $path): int
    {
        $kernel = app(HttpKernel::class);
        $request = Request::create('https://' . $host . $path, 'GET');
        $response = $kernel->handle($request);
        $kernel->terminate($request, $response);

        return $response->getStatusCode();
    }
}
