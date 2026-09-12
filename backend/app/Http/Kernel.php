<?php

namespace App\Http;

use App\Http\Middleware\ApplyAPILocale;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\GrantAdminAccess;
use App\Http\Middleware\WebsiteVisitorCount;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

class Kernel extends HttpKernel
{
    /**
     * The application's global HTTP middleware stack.
     *
     * These middleware are run during every request to your application.
     *
     * @var array
     */
    protected $middleware = [
        // Install the host allow-list before any middleware can ask Symfony to
        // resolve the host. Otherwise a long-lived worker may validate the new
        // request against the previous request's static trusted-host state.
        \App\Http\Middleware\TrustHosts::class,
        \App\Http\Middleware\TrustProxies::class,
        \App\Http\Middleware\AssignRequestIdentity::class,
        \App\Http\Middleware\SecurityHeaders::class,
        \Illuminate\Http\Middleware\HandleCors::class,
        \App\Http\Middleware\PreventRequestsDuringMaintenance::class,
        \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
        \App\Http\Middleware\TrimStrings::class,
        \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            \App\Http\Middleware\EncryptCookies::class,
            \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
            \Illuminate\Session\Middleware\StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            \Illuminate\View\Middleware\ShareErrorsFromSession::class,
            \App\Http\Middleware\VerifyCsrfToken::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ApplyLocale::class,
        ],

        'api' => [
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            ApplyAPILocale::class,
        ],
    ];

    /**
     * The application's route middleware.
     *
     * These middleware may be assigned to groups or used individually.
     *
     * @var array
     */
    protected $middlewareAliases = [
        'auth' => \App\Http\Middleware\Authenticate::class,
        'auth.optional' => \App\Http\Middleware\AuthenticateOptionalApiToken::class,
        'auth.basic' => \Illuminate\Auth\Middleware\AuthenticateWithBasicAuth::class,
        'bindings' => \Illuminate\Routing\Middleware\SubstituteBindings::class,
        'cache.headers' => \Illuminate\Http\Middleware\SetCacheHeaders::class,
        'can' => \Illuminate\Auth\Middleware\Authorize::class,
        'guest' => \App\Http\Middleware\RedirectIfAuthenticated::class,
        'password.confirm' => \Illuminate\Auth\Middleware\RequirePassword::class,
        'signed' => \Illuminate\Routing\Middleware\ValidateSignature::class,
        'throttle' => \App\Http\Middleware\ResilientThrottleRequests::class,
        'verified' => \Illuminate\Auth\Middleware\EnsureEmailIsVerified::class,
        'admin' => GrantAdminAccess::class,
        'admin.only' => \App\Http\Middleware\RequireAdministrator::class,
        'admin.mfa' => \App\Http\Middleware\RequireAdminMfa::class,
        'admin.audit' => \App\Http\Middleware\AuditAdminMutation::class,
        'course.draft' => \App\Http\Middleware\ResolveCourseAuthoringDraft::class,
        'product.feature' => \App\Http\Middleware\RequireProductFeature::class,
        'ai.consent' => \App\Http\Middleware\RequireAiConsent::class,
        'recovery.write' => \App\Http\Middleware\PauseDuringRecovery::class,
        'WebsiteVisitorCount' => WebsiteVisitorCount::class,
    ];
}
