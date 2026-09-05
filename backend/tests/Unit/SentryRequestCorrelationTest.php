<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Support\SentryEventScrubber;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\SentrySdk;
use Sentry\UserDataBag;
use Tests\TestCase;

final class SentryRequestCorrelationTest extends TestCase
{
    public function test_scrubber_keeps_only_the_current_internal_user_id(): void
    {
        $previousRequest = $this->app->make('request');
        $user = new User();
        $user->forceFill(['id' => 73]);
        $request = Request::create('/api/v1/courses/3', 'GET');
        $this->app->instance('request', $request);
        $request->setUserResolver(static fn (?string $guard = null) => $user);

        try {
            $event = Event::createEvent();
            $event->setUser(UserDataBag::createFromArray([
                'id' => 'external-provider-id',
                'email' => 'learner@example.test',
                'username' => 'learner',
                'ip_address' => '203.0.113.10',
                'private_note' => 'must disappear',
            ]));

            $userData = SentryEventScrubber::scrub($event)->getUser();

            self::assertSame(73, $userData?->getId());
            self::assertNull($userData?->getEmail());
            self::assertNull($userData?->getUsername());
            self::assertNull($userData?->getIpAddress());
            self::assertSame([], $userData?->getMetadata());

            $request->setUserResolver(static fn (?string $guard = null) => null);
            self::assertNull(SentryEventScrubber::scrub($event)->getUser());
        } finally {
            $this->app->instance('request', $previousRequest);
        }
    }

    public function test_real_http_exceptions_are_correlated_without_leaking_user_to_next_guest(): void
    {
        Route::middleware(['api', 'auth:api'])
            ->get('/api/_sentry-correlation/auth/{item}', static function (): never {
                throw new \RuntimeException('authenticated correlation probe');
            });
        Route::middleware('api')
            ->get('/api/_sentry-correlation/guest/{item}', static function (): never {
                throw new \RuntimeException('guest correlation probe');
            });

        $events = [];
        $client = SentrySdk::getCurrentHub()->getClient();
        self::assertNotNull($client);
        $options = $client->getOptions();
        $beforeSend = $options->getBeforeSendCallback();
        $options->setBeforeSendCallback(
            static function (Event $event, ?EventHint $hint = null) use (&$events, $beforeSend): ?Event {
                $event = $beforeSend($event, $hint);
                if ($event !== null) {
                    $events[] = $event;
                }

                return null;
            }
        );

        try {
            $user = new User();
            $user->forceFill(['id' => 42]);
            $authenticated = $this->actingAs($user, 'api')->withHeaders([
                'X-Request-ID' => '3b99c882-5989-4e31-b84b-a208b7e04c11',
                'X-Rokn-App-Version' => '1.0.40',
                'X-Rokn-App-Build' => '41',
                'X-Rokn-Platform' => 'ANDROID',
            ])->getJson('/api/_sentry-correlation/auth/91');

            $authenticated->assertStatus(500);
            self::assertCount(1, $events);
            self::assertSame([
                'request_id' => '3b99c882-5989-4e31-b84b-a208b7e04c11',
                'endpoint' => '/api/_sentry-correlation/auth/{item}',
                'app_version' => '1.0.40',
                'app_build' => '41',
                'platform' => 'android',
            ], array_intersect_key($events[0]->getTags(), array_flip([
                'request_id', 'endpoint', 'app_version', 'app_build', 'platform',
            ])));
            self::assertSame(42, $events[0]->getUser()?->getId());
            self::assertSame(
                '3b99c882-5989-4e31-b84b-a208b7e04c11',
                $authenticated->headers->get('X-Request-ID')
            );

            $this->app['auth']->forgetGuards();
            $guest = $this->withHeaders([
                'X-Request-ID' => '12d46e21-5241-4dfd-ac42-f03f7cdb206f',
                'X-Rokn-App-Version' => '../../private',
                'X-Rokn-App-Build' => '99999999999',
                'X-Rokn-Platform' => 'browser',
            ])->getJson('/api/_sentry-correlation/guest/92');

            $guest->assertStatus(500);
            self::assertCount(2, $events);
            self::assertSame(
                '12d46e21-5241-4dfd-ac42-f03f7cdb206f',
                $events[1]->getTags()['request_id'] ?? null
            );
            self::assertSame(
                '/api/_sentry-correlation/guest/{item}',
                $events[1]->getTags()['endpoint'] ?? null
            );
            self::assertArrayNotHasKey('app_version', $events[1]->getTags());
            self::assertArrayNotHasKey('app_build', $events[1]->getTags());
            self::assertArrayNotHasKey('platform', $events[1]->getTags());
            self::assertNull($events[1]->getUser());
        } finally {
            $options->setBeforeSendCallback($beforeSend);
        }
    }
}
