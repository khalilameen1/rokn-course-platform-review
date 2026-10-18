<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Tests\TestCase;

final class TrustedProxyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Request::setTrustedHosts(['^api\\.rokn\\.test$']);
    }

    protected function tearDown(): void
    {
        Request::setTrustedHosts([]);
        parent::tearDown();
    }

    public function test_forwarded_ip_and_https_are_honoured_only_from_configured_edge(): void
    {
        config(['trusted_proxies.proxies' => ['10.20.30.0/24']]);
        $request = Request::create('http://api.rokn.test/api/v1/main', 'GET', [], [], [], [
            'REMOTE_ADDR' => '10.20.30.7',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.44',
            'HTTP_X_FORWARDED_PROTO' => 'https',
            'HTTP_X_FORWARDED_HOST' => 'api.rokn.test',
            'HTTP_X_FORWARDED_PORT' => '443',
        ]);

        app(TrustProxies::class)->handle($request, function (Request $trusted) {
            self::assertSame('198.51.100.44', $trusted->ip());
            self::assertTrue($trusted->isSecure());

            return response('ok');
        });

        $untrusted = Request::create('http://api.rokn.test/api/v1/main', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.44',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        app(TrustProxies::class)->handle($untrusted, function (Request $request) {
            self::assertSame('203.0.113.9', $request->ip());
            self::assertFalse($request->isSecure());

            return response('ok');
        });
    }

    public function test_trust_all_proxy_definitions_are_rejected(): void
    {
        config(['trusted_proxies.proxies' => ['*', '0.0.0.0/0', '::/0']]);
        $request = Request::create('http://api.rokn.test/', 'GET', [], [], [], [
            'REMOTE_ADDR' => '203.0.113.9',
            'HTTP_X_FORWARDED_FOR' => '198.51.100.44',
            'HTTP_X_FORWARDED_PROTO' => 'https',
        ]);
        app(TrustProxies::class)->handle($request, function (Request $request) {
            self::assertSame('203.0.113.9', $request->ip());
            self::assertFalse($request->isSecure());

            return response('ok');
        });
    }
}
