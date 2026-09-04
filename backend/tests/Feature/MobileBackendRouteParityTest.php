<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class MobileBackendRouteParityTest extends TestCase
{
    #[DataProvider('mobileRouteProvider')]
    public function test_every_mobile_route_resolves_in_the_versioned_contract(
        string $method,
        string $path,
    ): void {
        $route = $this->app['router']->getRoutes()->match(
            Request::create('/api/v1/'.$path, $method),
        );

        self::assertInstanceOf(Route::class, $route);
        self::assertSame($method, $route->methods()[0]);
        self::assertStringStartsWith('api/v1/', $route->uri());
    }

    public function test_unversioned_mobile_api_is_absent(): void
    {
        $this->getJson('/api/courses/list')->assertNotFound();
    }

    /** @return iterable<string, array{string, string}> */
    public static function mobileRouteProvider(): iterable
    {
        yield 'feature flags' => ['GET', 'product-features'];
        yield 'product events' => ['POST', 'product-events'];
        yield 'app version policy' => ['POST', 'app/check-version'];
        yield 'settings' => ['GET', 'settings'];
        yield 'about content' => ['GET', 'content/pages/about'];
        yield 'privacy content' => ['GET', 'content/pages/privacy'];
        yield 'terms content' => ['GET', 'content/pages/terms'];
        yield 'contact content' => ['GET', 'content/pages/contact'];
        yield 'contact form' => ['POST', 'contact'];
        yield 'authentication methods' => ['GET', 'auth-methods'];
        yield 'social login' => ['POST', 'social-login'];
        yield 'social auth completion' => ['POST', 'social-auth/complete'];
        yield 'logout' => ['POST', 'logout'];
        yield 'account deletion' => ['POST', 'delete-account'];
        yield 'device token registration' => ['POST', 'user/device-token'];
        yield 'device token removal' => ['DELETE', 'user/device-token'];
        yield 'device sessions' => ['GET', 'user/sessions'];
        yield 'other device sessions revocation' => ['DELETE', 'user/sessions'];
        yield 'device session revocation' => ['DELETE', 'user/sessions/11111111-1111-4111-8111-111111111111'];
        yield 'course list' => ['GET', 'courses/list'];
        yield 'course search' => ['GET', 'search/courses'];
        yield 'course details' => ['GET', 'courses/1/details'];
        yield 'course rating create or update' => ['POST', 'courses/1/rate'];
        yield 'course rating delete' => ['DELETE', 'courses/1/rate'];
        yield 'course authorization' => ['POST', 'courses/authorize'];
        yield 'course purchase quote' => ['POST', 'courses/purchase-quote'];
        yield 'course redemption' => ['POST', 'course-codes/redeem'];
        yield 'course chat' => ['POST', 'courses/1/chat'];
        yield 'full track quote' => ['GET', 'courses/1/full-track-upgrade'];
        yield 'full track purchase' => ['POST', 'courses/1/full-track-upgrade'];
        yield 'learning dashboard' => ['GET', 'learning/courses'];
        yield 'profile read' => ['GET', 'user/profile'];
        yield 'profile update' => ['PUT', 'user/profile'];
        yield 'user paths' => ['GET', 'user/paths'];
        yield 'watch history read' => ['GET', 'user/watch-history'];
        yield 'watch history write' => ['POST', 'user/watch-history'];
        yield 'watch history clear' => ['DELETE', 'user/watch-history'];
        yield 'playback manifest' => ['POST', 'lessons/1/playback-manifest'];
        yield 'section completion' => ['POST', 'courses/1/sections/1/complete'];
        yield 'streaks' => ['GET', 'streaks'];
        yield 'certificates' => ['GET', 'certificates'];
        yield 'certificate recovery' => ['POST', 'certificates/1/issue'];
        yield 'notifications' => ['GET', 'notifications'];
        yield 'notification details' => ['GET', 'notifications/1'];
        yield 'notification read' => ['POST', 'notifications/1/mark-read'];
        yield 'notifications read all' => ['POST', 'notifications/mark-all-read'];
        yield 'saved folders read' => ['GET', 'saved-folders'];
        yield 'saved lessons read' => ['GET', 'saved-lessons'];
        yield 'saved lesson state' => ['GET', 'saved-lessons/state'];
        yield 'saved folder create' => ['POST', 'saved-folders'];
        yield 'saved folder delete' => ['DELETE', 'saved-folders/1'];
        yield 'saved lesson folders' => ['GET', 'saved-lessons/1/folders'];
        yield 'saved lesson create' => ['POST', 'saved-folders/1/lessons'];
        yield 'saved lesson delete' => ['DELETE', 'saved-lessons/1'];
        yield 'saved folder lesson delete' => ['DELETE', 'saved-folders/1/lessons/1'];
        yield 'project submission' => ['POST', 'projects/1/submissions'];
        yield 'project submission status' => ['GET', 'project-submissions/submission-id'];
        yield 'project report retry' => ['POST', 'project-submissions/submission-id/report/retry'];
        yield 'project details' => ['GET', 'projects/1'];
        yield 'project feedback thread' => ['GET', 'project-feedback-threads/thread-id'];
        yield 'project feedback reply' => ['POST', 'project-feedback-threads/thread-id/messages'];
        yield 'project feedback attachment' => ['POST', 'project-feedback-threads/thread-id/attachments'];
        yield 'wallet' => ['GET', 'wallet'];
        yield 'daily reward' => ['POST', 'rewards/daily'];
        yield 'coin packages' => ['GET', 'packages'];
        yield 'coin earning methods' => ['GET', 'coin-earning-methods'];
        yield 'coin task start' => ['POST', 'coin-earning-methods/1/start'];
        yield 'coin task claim' => ['POST', 'claim-coins'];
        yield 'engagement message' => ['GET', 'engagement/messages/guest_registration_prompt'];
        yield 'next engagement message' => ['GET', 'engagement/next'];
        yield 'payment initiate' => ['POST', 'payment/initiate'];
        yield 'payment status' => ['GET', 'payment/status/order-reference'];
        yield 'payment reconciliation' => ['POST', 'payment/reconcile/order-reference'];
        yield 'payment abandonment' => ['POST', 'payment/abandon/order-reference'];
        yield 'store billing context' => ['GET', 'store-billing/context'];
        yield 'native store verification' => ['POST', 'store-purchases/verify'];
        yield 'portfolio profile read' => ['GET', 'portfolio-profile'];
        yield 'portfolio profile update' => ['PUT', 'portfolio-profile'];
        yield 'portfolio list' => ['GET', 'portfolio'];
        yield 'portfolio create' => ['POST', 'portfolio'];
        yield 'portfolio details' => ['GET', 'portfolio/1'];
        yield 'portfolio update' => ['POST', 'portfolio/1'];
        yield 'portfolio publish' => ['POST', 'portfolio/1/finalize'];
        yield 'portfolio media append' => ['POST', 'portfolio/1/media'];
        yield 'portfolio media delete' => ['DELETE', 'portfolio/1/media/1'];
        yield 'portfolio delete' => ['DELETE', 'portfolio/1'];
        yield 'portfolio eligible projects' => ['GET', 'portfolio/eligible-projects'];
        yield 'feedback' => ['POST', 'feedback'];
    }
}
