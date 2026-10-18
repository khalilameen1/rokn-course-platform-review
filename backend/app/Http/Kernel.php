<?php

namespace App\Http;

use App\Http\Middleware\ApplyAPILocale;
use App\Http\Middleware\ApplyLocale;
use App\Http\Middleware\AssignRequestIdentity;
use App\Http\Middleware\AuditAdminMutation;
use App\Http\Middleware\Authenticate;
use App\Http\Middleware\AuthenticateOptionalApiToken;
use App\Http\Middleware\EncryptCookies;
use App\Http\Middleware\GrantAdminAccess;
use App\Http\Middleware\PauseDuringRecovery;
use App\Http\Middleware\PreventRequestsDuringMaintenance;
use App\Http\Middleware\RedirectIfAuthenticated;
use App\Http\Middleware\RequireAdministrator;
use App\Http\Middleware\RequireAdminMfa;
use App\Http\Middleware\RequireAiConsent;
use App\Http\Middleware\RequireProductFeature;
use App\Http\Middleware\ResilientThrottleRequests;
use App\Http\Middleware\ResolveCourseAuthoringDraft;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Middleware\TrimStrings;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Http\Middleware\VerifyCsrfToken;
use App\Http\Middleware\WebsiteVisitorCount;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Auth\Middleware\Authorize;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Foundation\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\ValidatePostSize;
use Illuminate\Http\Middleware\HandleCors;
use Illuminate\Http\Middleware\SetCacheHeaders;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ValidateSignature;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;

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
        TrustHosts::class,
        TrustProxies::class,
        AssignRequestIdentity::class,
        SecurityHeaders::class,
        HandleCors::class,
        PreventRequestsDuringMaintenance::class,
        ValidatePostSize::class,
        TrimStrings::class,
        ConvertEmptyStringsToNull::class,
    ];

    /**
     * The application's route middleware groups.
     *
     * @var array
     */
    protected $middlewareGroups = [
        'web' => [
            EncryptCookies::class,
            AddQueuedCookiesToResponse::class,
            StartSession::class,
            // \Illuminate\Session\Middleware\AuthenticateSession::class,
            ShareErrorsFromSession::class,
            VerifyCsrfToken::class,
            SubstituteBindings::class,
            ApplyLocale::class,
        ],

        'api' => [
            'throttle:api',
            SubstituteBindings::class,
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
        'auth' => Authenticate::class,
        'auth.optional' => AuthenticateOptionalApiToken::class,
        'auth.basic' => AuthenticateWithBasicAuth::class,
        'bindings' => SubstituteBindings::class,
        'cache.headers' => SetCacheHeaders::class,
        'can' => Authorize::class,
        'guest' => RedirectIfAuthenticated::class,
        'password.confirm' => RequirePassword::class,
        'signed' => ValidateSignature::class,
        'throttle' => ResilientThrottleRequests::class,
        'verified' => EnsureEmailIsVerified::class,
        'admin' => GrantAdminAccess::class,
        'admin.only' => RequireAdministrator::class,
        'admin.mfa' => RequireAdminMfa::class,
        'admin.audit' => AuditAdminMutation::class,
        'course.draft' => ResolveCourseAuthoringDraft::class,
        'product.feature' => RequireProductFeature::class,
        'ai.consent' => RequireAiConsent::class,
        'recovery.write' => PauseDuringRecovery::class,
        'WebsiteVisitorCount' => WebsiteVisitorCount::class,
    ];
}
