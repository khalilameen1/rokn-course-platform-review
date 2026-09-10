<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * This namespace is applied to your controller routes.
     *
     * In addition, it is set as the URL generator's root namespace.
     *
     * @var string
     */
    protected $namespace = 'App\Http\Controllers';

    /**
     * The path to the "home" route for your application.
     *
     * @var string
     */
    public const HOME = '/home';

    /**
     * Define your route model bindings, pattern filters, etc.
     *
     * @return void
     */
    public function boot()
    {
        $this->configureRateLimiting();

        parent::boot();
    }

    /**
     * Keep ordinary catalogue and learning reads responsive while placing
     * tighter boundaries around authentication and money-moving endpoints.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            if ($request->isMethod('GET') || $request->isMethod('HEAD')) {
                return [
                    Limit::perMinute(max(1, (int) config('rate_limits.api_read_identity_per_minute', 240)))
                        ->by('read:identity:'.$identity),
                    Limit::perMinute(max(1, (int) config('rate_limits.api_read_ip_per_minute', 1200)))
                        ->by('read:ip:'.$ip),
                ];
            }

            return [
                Limit::perMinute(max(1, (int) config('rate_limits.api_write_identity_per_minute', 90)))
                    ->by('write:identity:'.$identity),
                Limit::perMinute(max(1, (int) config('rate_limits.api_write_ip_per_minute', 450)))
                    ->by('write:ip:'.$ip),
            ];
        });

        RateLimiter::for('auth-api', function (Request $request) {
            $identity = $this->authAttemptIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(12)->by('auth:identity:'.$identity),
                Limit::perHour(90)->by('auth:identity-hour:'.$identity),
                // A shared school connection remains usable while a rotating
                // identity flood still has a finite origin-wide ceiling.
                Limit::perMinute(300)->by('auth:ip-minute:'.$ip),
                Limit::perHour(3000)->by('auth:ip-hour:'.$ip),
            ];
        });

        RateLimiter::for('admin-login-route', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $emailIdentity = hash('sha256', $email !== '' ? $email : '__missing__');
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(12)->by('admin-login:email-minute:'.$emailIdentity),
                Limit::perHour(60)->by('admin-login:email-hour:'.$emailIdentity),
                Limit::perMinute(120)->by('admin-login:ip-minute:'.$ip),
            ];
        });

        RateLimiter::for('admin-password-reset', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));
            $emailIdentity = hash('sha256', $email !== '' ? $email : '__missing__');
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perHour(5)->by('admin-password-reset:email:'.$emailIdentity),
                Limit::perHour(120)->by('admin-password-reset:ip:'.$ip),
            ];
        });

        RateLimiter::for('admin-mfa', function (Request $request) {
            $userId = optional($request->user())->getAuthIdentifier() ?: 'guest';
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(12)->by('admin-mfa:minute:'.$userId.':'.$ip),
                Limit::perHour(60)->by('admin-mfa:hour:'.$userId),
            ];
        });

        RateLimiter::for('kashier-callback', function (Request $request) {
            $order = $this->kashierOrderIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_callback_order_per_minute', 30)))
                    // Couple an untrusted order identifier to its origin so a
                    // third party cannot exhaust a real customer's bucket.
                    ->by('kashier-callback:order:'.$order.':ip:'.$ip),
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_callback_ip_per_minute', 120)))
                    ->by('kashier-callback:ip:'.$ip),
            ];
        });

        RateLimiter::for('kashier-webhook', function (Request $request) {
            $order = $this->kashierOrderIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_webhook_order_per_minute', 30)))
                    ->by('kashier-webhook:order:'.$order.':ip:'.$ip),
                Limit::perMinute(max(1, (int) config('rate_limits.kashier_webhook_ip_per_minute', 300)))
                    ->by('kashier-webhook:ip-minute:'.$ip),
                Limit::perHour(max(1, (int) config('rate_limits.kashier_webhook_ip_per_hour', 2000)))
                    ->by('kashier-webhook:ip-hour:'.$ip),
            ];
        });

        RateLimiter::for('store-notification', function (Request $request) {
            $identity = $this->providerEventIdentity($request, [
                'message.messageId',
                'message.message_id',
                'notificationUUID',
                'signedPayload',
            ]);
            $ip = $request->ip() ?: 'unknown';
            return [
                Limit::perMinute(30)->by('store-notification:event:'.$identity),
                Limit::perMinute(1200)->by('store-notification:ip-minute:'.$ip),
                Limit::perHour(10000)->by('store-notification:ip-hour:'.$ip),
            ];
        });

        RateLimiter::for('whatsapp-webhook', function (Request $request) {
            $identity = $this->providerEventIdentity($request, [
                // Whatspie v1 and v2 documented message identifiers.
                'message_id',
                'data.message_id',
                'entry.0.changes.0.value.messages.0.id',
                'messages.0.id',
            ]);
            $ip = $request->ip() ?: 'unknown';
            return [
                Limit::perMinute(30)->by('whatsapp-webhook:event:'.$identity),
                Limit::perMinute(600)->by('whatsapp-webhook:ip-minute:'.$ip),
            ];
        });

        foreach (['auth-start' => 10, 'auth-complete' => 20, 'read' => 60, 'write' => 6, 'reconcile' => 10] as $action => $limit) {
            RateLimiter::for('web-wallet-'.$action, function (Request $request) use ($action, $limit) {
                $studentId = $request->user('student')?->id;
                $identity = $studentId ? 'user:'.$studentId : 'session:'.$request->session()->getId();
                $limits = [Limit::perMinute($limit)->by('web-wallet:'.$action.':'.$identity)];
                if (str_starts_with($action, 'auth-')) {
                    $limits[] = Limit::perMinute(120)->by('web-wallet:'.$action.':ip:'.$request->ip());
                }
                return $limits;
            });
        }

        RateLimiter::for('payment-write', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);

            return Limit::perMinute(6)->by('payment-write:'.$identity);
        });

        RateLimiter::for('payment-read', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);

            return Limit::perMinute(60)->by('payment-read:'.$identity);
        });

        RateLimiter::for('payment-reconcile', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);

            return Limit::perMinute(max(
                4,
                (int) config('rate_limits.payment_reconcile_identity_per_minute', 20)
            ))->by('payment-reconcile:'.$identity);
        });

        RateLimiter::for('client-events', function (Request $request) {
            $identity = 'client-events:'.$this->rateLimitIdentity($request);
            $ip = $request->ip() ?: 'unknown';

            return [
                // Distinct buckets are required: sharing one key makes each
                // request increment both limits and halves the minute quota.
                Limit::perMinute(30)->by($identity.':minute'),
                Limit::perDay(500)->by($identity.':day'),
                Limit::perDay(10000)->by('client-events:ip-day:'.$ip),
            ];
        });

        RateLimiter::for('product-events', function (Request $request) {
            $session = (string) ($request->input('session_key') ?: $request->input('events.0.session_key'));
            $presentedToken = trim((string) $request->bearerToken());
            $identity = $presentedToken !== ''
                ? 'token:'.hash('sha256', $presentedToken)
                : ($session !== ''
                    ? 'session:'.hash('sha256', $session)
                    : 'ip:'.($request->ip() ?: 'unknown'));
            $ip = $request->ip() ?: 'unknown';

            return [
                Limit::perMinute(60)->by('product-events:minute:'.$identity),
                Limit::perDay(1500)->by('product-events:day:'.$identity),
                Limit::perDay(5000)->by('product-events:ip-day:'.$ip),
            ];
        });

        RateLimiter::for('catalog-search', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);
            $ip = $request->ip() ?: 'unknown';
            return [
                Limit::perMinute(60)->by('catalog-search:minute:'.$identity),
                Limit::perDay(1500)->by('catalog-search:day:'.$identity),
                Limit::perMinute(1200)->by('catalog-search:ip-minute:'.$ip),
                Limit::perDay(30000)->by('catalog-search:ip-day:'.$ip),
            ];
        });

        RateLimiter::for('feedback', function (Request $request) {
            $identity = $this->rateLimitIdentity($request);
            $ip = $request->ip() ?: 'unknown';
            return [
                Limit::perMinute(5)->by('feedback:minute:'.$identity),
                Limit::perDay(30)->by('feedback:day:'.$identity),
                Limit::perDay(3000)->by('feedback:ip-day:'.$ip),
            ];
        });

        RateLimiter::for('admin-bulk', function (Request $request) {
            $userId = optional($request->user())->getAuthIdentifier() ?: 'guest';
            return [
                Limit::perMinute(6)->by('admin-bulk:minute:'.$userId),
                Limit::perHour(60)->by('admin-bulk:hour:'.$userId),
            ];
        });
    }

    private function rateLimitIdentity(Request $request): string
    {
        $userId = optional($request->user())->getAuthIdentifier();
        if ($userId) {
            return 'user:'.$userId;
        }

        // The API group's limiter can execute before auth:api resolves the
        // current user. Hashing the presented bearer token avoids making a
        // whole university/workplace Wi-Fi share one IP bucket.
        $bearerToken = trim((string) $request->bearerToken());
        if ($bearerToken !== '') {
            return 'token:'.hash('sha256', $bearerToken);
        }

        $installation = strtolower(trim((string) $request->header('X-Rokn-Installation')));
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $installation)) {
            return 'installation:'.hash('sha256', $installation);
        }

        return 'ip:'.($request->ip() ?: 'unknown');
    }

    private function authAttemptIdentity(Request $request): string
    {
        foreach (['state', 'code_challenge', 'code', 'token', 'email', 'device_id'] as $field) {
            $value = $request->input($field, $request->query($field));
            if (!is_scalar($value)) continue;
            $value = strtolower(trim((string) $value));
            if ($value !== '') {
                return $field.':'.hash('sha256', mb_substr($value, 0, 2048));
            }
        }

        return $this->rateLimitIdentity($request);
    }

    private function kashierOrderIdentity(Request $request): string
    {
        foreach (['orderId', 'order_id', 'order_ref', 'merchantOrderId'] as $field) {
            $value = $request->input($field);
            if (is_scalar($value)) {
                $value = trim((string) $value);
                if ($value !== '') {
                    return hash('sha256', substr($value, 0, 128));
                }
            }
        }

        return 'missing';
    }

    /** @param list<string> $fields */
    private function providerEventIdentity(Request $request, array $fields): string
    {
        foreach ($fields as $field) {
            $value = data_get($request->all(), $field);
            if (!is_scalar($value)) continue;
            $value = trim((string) $value);
            if ($value !== '') {
                return hash('sha256', mb_substr($value, 0, 4096));
            }
        }

        return 'body:'.hash('sha256', (string) $request->getContent());
    }

    /**
     * Define the routes for the application.
     *
     * @return void
     */
    public function map()
    {
        $this->mapApiRoutes();

        $this->mapWebRoutes();

        //
    }

    /**
     * Define the "web" routes for the application.
     *
     * These routes all receive session state, CSRF protection, etc.
     *
     * @return void
     */
    protected function mapWebRoutes()
    {
        $locale = request()->segment(1);
        if (! in_array($locale, config('app.locales'))) {
            $locale = '';
        }
        Route::middleware('web')
             ->namespace($this->namespace)
             ->prefix($locale)
             ->group(base_path('routes/web.php'));
    }

    /**
     * Define the "api" routes for the application.
     *
     * These routes are typically stateless.
     *
     * @return void
     */
    protected function mapApiRoutes()
    {
        Route::prefix('api')
             ->middleware('api')
             ->namespace($this->namespace.  '\API')
             ->group(base_path('routes/api.php'));
    }
}
