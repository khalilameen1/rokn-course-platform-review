<?php

namespace App\Providers;

use App\Contracts\StoreNotificationAuthenticityVerifier;
use App\Contracts\StorePurchaseProviderGateway;
use App\Http\Middleware\TrustHosts;
use App\Services\LiveStorePurchaseProviderGateway;
use App\Services\LiveStoreNotificationAuthenticityVerifier;
use App\Services\StudentNotificationPresentationService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Http\Request;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Event;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Factory as FirebaseFactory;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind(
            StorePurchaseProviderGateway::class,
            LiveStorePurchaseProviderGateway::class
        );
        $this->app->bind(
            StoreNotificationAuthenticityVerifier::class,
            LiveStoreNotificationAuthenticityVerifier::class
        );
        // Notification resources resolve this service once per request so a
        // paginated inbox loads the dashboard template family in one query.
        $this->app->scoped(StudentNotificationPresentationService::class);
        $this->app->scoped(\App\Services\AppArtworkService::class);

        // We use only FCM from the Firebase Admin SDK. Binding the contract
        // directly keeps the integration small and avoids coupling the whole
        // application to a Laravel wrapper that does not support Laravel 12.
        // Resolution is lazy, so CLI commands that do not send a
        // notification never require production credentials.
        $this->app->singleton(Messaging::class, static function (): Messaging {
            $encodedCredentials = trim((string) config('firebase.credentials.base64'));
            if ($encodedCredentials !== '') {
                $decodedCredentials = base64_decode($encodedCredentials, true);
                $credentials = is_string($decodedCredentials)
                    ? json_decode($decodedCredentials, true)
                    : null;

                if (!is_array($credentials)) {
                    throw new \RuntimeException('Firebase credentials are malformed.');
                }
            } else {
                $credentials = trim((string) config('firebase.credentials.file'));
                if ($credentials === '' || !is_file($credentials) || !is_readable($credentials)) {
                    throw new \RuntimeException('Firebase credentials are missing or unreadable.');
                }
            }

            return (new FirebaseFactory())
                ->withServiceAccount($credentials)
                ->createMessaging();
        });
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        $this->hydratePrivateObjectStorageDisks();

        // Historical migrations may load a current Eloquent model before a
        // later additive migration creates one of its scoped columns. Reboot
        // models once the migration command ends so the running process sees
        // the final schema without coupling migrations to application code.
        Event::listen(MigrationsEnded::class, static function (): void {
            Model::clearBootedModels();
        });

        // Cloud terminates TLS before the request reaches PHP. Generate
        // redirects and signed URLs from the configured public origin without
        // broadening the trusted-proxy allow-list.
        if (parse_url((string) config('app.url'), PHP_URL_SCHEME) === 'https') {
            URL::forceScheme('https');
        }

        // Symfony can resolve the host while constructing a request, before
        // the HTTP middleware stack runs. Install the allow-list during app
        // boot as well as in the middleware so long-lived workers never parse
        // a new request using the previous request's host policy.
        $hosts = $this->app->make(TrustHosts::class)->hosts();
        if ($hosts !== []) {
            Request::setTrustedHosts(array_filter($hosts));
        }

        Paginator::useBootstrap();

        // A restored database may be older than external side effects that
        // already happened. Recovery deployments serve read-only learning but
        // do not execute restored/new jobs until evidence is verified and the
        // operator clears DISASTER_RECOVERY_MODE then restarts workers.
        Queue::looping(static fn (): bool => !(bool) config(
            'operations.disaster_recovery_mode',
            false
        ));
    }

    /**
     * Laravel Cloud injects its managed bucket into the standard s3 disk at
     * runtime, after a cached config file may already have been built. Private
     * scoped disks must inherit those final credentials instead of retaining
     * the empty build-time values.
     */
    private function hydratePrivateObjectStorageDisks(): void
    {
        $base = config('filesystems.disks.s3');
        if (!is_array($base) || empty($base['bucket'])) {
            return;
        }

        $connectionKeys = [
            'key',
            'secret',
            'region',
            'bucket',
            'url',
            'endpoint',
            'use_path_style_endpoint',
        ];

        foreach (['feedback', 'security-quarantine'] as $diskName) {
            $disk = config("filesystems.disks.{$diskName}");
            if (!is_array($disk) || strtolower((string) ($disk['driver'] ?? '')) !== 's3') {
                continue;
            }

            foreach ($connectionKeys as $key) {
                $disk[$key] = $base[$key] ?? null;
            }
            config(["filesystems.disks.{$diskName}" => $disk]);
        }
    }
}
