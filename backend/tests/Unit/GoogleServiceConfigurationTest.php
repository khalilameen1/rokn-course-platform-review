<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\GoogleService;
use Google\Client;
use ReflectionProperty;
use Tests\TestCase;

final class GoogleServiceConfigurationTest extends TestCase
{
    public function test_google_certificate_fetches_have_bounded_timeouts(): void
    {
        config()->set('services.google.connect_timeout_seconds', 2);
        config()->set('services.google.timeout_seconds', 7);

        $property = new ReflectionProperty(GoogleService::class, 'client');
        $property->setAccessible(true);
        $client = $property->getValue(new GoogleService);

        self::assertInstanceOf(Client::class, $client);
        self::assertSame(2.0, $client->getHttpClient()->getConfig('connect_timeout'));
        self::assertSame(7.0, $client->getHttpClient()->getConfig('timeout'));
    }
}
