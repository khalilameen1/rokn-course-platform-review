<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPortfolioService;

class PublicPortfolioController extends Controller
{
    public function show(string $slug, PublicPortfolioService $service)
    {
        $portfolio = $service->find($slug);
        abort_if(!$portfolio, 404);

        return response(view('portfolio.public', compact('portfolio')))
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
