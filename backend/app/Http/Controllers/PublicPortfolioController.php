<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PublicPortfolioService;
use Illuminate\Http\Response;

class PublicPortfolioController extends Controller
{
    public function show(string $slug, PublicPortfolioService $service): Response
    {
        $portfolio = $service->find($slug);
        abort_if(!$portfolio, 404);

        return $this->page(view('portfolio.public', compact('portfolio')));
    }

    /** Shareable page for one approved project (/@{slug}/p/{project}). */
    public function project(string $slug, string $projectId, PublicPortfolioService $service): Response
    {
        $page = $service->project($slug, $projectId);
        abort_if(!$page, 404);

        return $this->page(view('portfolio.project', $page));
    }

    private function page($view): Response
    {
        // The HTML stays uncached so a removal or suspension applies on the
        // next request. noindex stays until search indexing is decided;
        // link-preview crawlers still read the Open Graph tags.
        return response($view)
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
