<?php

namespace Rokn\FormCompat;

use Collective\Html\FormBuilder;
use Illuminate\Support\ServiceProvider;

final class FormCompatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The builder keeps the model currently bound by Form::model(). A
        // process-wide singleton leaks that mutable state and its Request into
        // the next Laravel Cloud/Octane request. Scoped lifetime gives every
        // HTTP request its own builder while preserving binding inside a view.
        $this->app->scoped('form', static fn ($app): FormBuilder => new FormBuilder(
            $app['url']
        ));
    }
}
