<?php

namespace App\Providers;

use App\Models\Setting;
use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;
use Throwable;

class SettingServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        $this->app->bind('settings', function ($app) {
            return new Setting;
        });
        $loader = AliasLoader::getInstance();
        $loader->alias('Setting', Setting::class);
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        if (\App::runningInConsole()) {
            return;
        }

        // Liveness must prove that the PHP process can answer without first
        // depending on MySQL. Otherwise a database restart or a release
        // migration prevents the platform from distinguishing a live process
        // from one that failed to boot and can amplify a short dependency
        // interruption into repeated instance replacement.
        if (request()->is('api/health/live')) {
            return;
        }

        try {
            // Cached projection invalidated by Setting saved/deleted events;
            // a cache hit costs no information_schema or settings query.
            foreach (Setting::runtimeConfig() as $key => $value) {
                Config::set('settings.'.$key, $value);
            }
        } catch (Throwable $exception) {
            // Readiness performs its own database check and will return a
            // structured 503. Do not turn service-provider bootstrap into an
            // earlier unobservable 500, especially while a candidate release
            // is being probed before it receives traffic.
            report($exception);
        }
    }
}
