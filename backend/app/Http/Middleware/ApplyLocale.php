<?php

namespace App\Http\Middleware;

use App\Support\RoknLocale;
use Closure;
use Illuminate\Http\Request;

class ApplyLocale
{
    /**
     * Handle an incoming request.
     *
     * @param  Request  $request
     * @return mixed
     */
    public function handle($request, Closure $next)
    {
        $locale = RoknLocale::normalize($request->segment(1))
            ?? RoknLocale::normalize($request->session()->get('locale'))
            ?? RoknLocale::normalize((string) config('app.locale', 'ar'))
            ?? RoknLocale::ARABIC;
        app()->setLocale($locale);

        $response = $next($request);
        $response->headers->set('Content-Language', RoknLocale::normalize(app()->getLocale()) ?? $locale);

        return $response;
    }
}
