<?php

namespace App\Providers;

use App\Support\DatabaseCapabilities;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Listeners\SendEmailVerificationNotification;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Illuminate\Support\Facades\Event;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event listener mappings for the application.
     *
     * @var array
     */
    protected $listen = [
        Registered::class => [
            SendEmailVerificationNotification::class,
        ],
    ];

    /**
     * Register any events for your application.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        // Schema capabilities and the settings projection are cached across
        // requests; a finished migrate/rollback moves them to a new namespace.
        Event::listen(MigrationsEnded::class, static function (): void {
            DatabaseCapabilities::invalidateSharedCache();
        });
    }
}
