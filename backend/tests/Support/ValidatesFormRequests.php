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
    protected function validatedFormRequest(string $class, Request $request): FormRequest
    {
        $form = $class::createFrom($request);
        $form->setContainer($this->app)->setRedirector($this->app->make(Redirector::class));
        $form->validateResolved();

        return $form;
    }
}
