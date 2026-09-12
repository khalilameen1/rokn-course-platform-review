<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\Resources\PortfolioMediaResource;
use App\Models\PortfolioMedia;
use App\Services\PublicPortfolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PublicPortfolioMediaController extends Controller
{
    public function portfolio(
        string $slug,
        string $mediaId,
        PublicPortfolioService $portfolios,
        Request $request
    ): RedirectResponse {
        return $this->freshRedirect($portfolios->mediaForPortfolio($slug, $mediaId,
            is_string($request->query('revision')) ? $request->query('revision') : '',
            is_string($request->query('snapshot')) ? $request->query('snapshot') : ''
        ));
    }

    private function freshRedirect(?PortfolioMedia $media): RedirectResponse
    {
        abort_if(!$media, 404);
        $payload = (new PortfolioMediaResource($media))->resolve();
        abort_unless(($payload['status'] ?? null) === 'ready', 404);
        $url = ($payload['file_type'] ?? null) === 'video'
            ? ($payload['video_url'] ?? null)
            : ($payload['image_url'] ?? null);
        abort_unless(is_string($url) && str_starts_with($url, 'https://'), 404);

        return redirect()
            ->away($url, 302)
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Referrer-Policy', 'no-referrer');
    }
}
