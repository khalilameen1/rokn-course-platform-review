<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Routing\Redirector;

/** Exercise Laravel's actual FormRequest lifecycle in adapter-level tests. */
trait ValidatesFormRequests
{
    /** @template T of FormRequest @param class-string<T> $class @return T */
    protected function validatedFormRequest(string $class, Request $request, ?string $routeName = null, array $models = []): FormRequest
    {
        if ($routeName !== null) {
            $route = clone $this->app->make('router')->getRoutes()->getByName($routeName);
            $route->bind($request);
            foreach ($models as $key => $model) {
                $route->setParameter($key, $model);
            }
            $request->setRouteResolver(fn () => $route);
        }
        $form = $class::createFrom($request);
        $form->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));
        $form->validateResolved();

        return $form;
    }
}
