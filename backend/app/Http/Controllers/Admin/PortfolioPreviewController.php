<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioMediaResource;
use App\Models\User;
use App\Services\PublicPortfolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

/** Routes require both admin-only authorization and the dashboard MFA guard. */
final class PortfolioPreviewController extends Controller
{
    public function show(User $user, PublicPortfolioService $portfolios): Response
    {
        return response()->view('portfolio.public', [
            'portfolio' => $portfolios->adminPreview($user),
            'isAdminPreview' => true,
        ])->withHeaders($this->headers());
    }

    public function media(User $user, string $mediaId, PublicPortfolioService $portfolios): RedirectResponse
    {
        $media = $portfolios->adminPreviewMedia($user, $mediaId);
        abort_unless($media, 404);
        $payload = (new PortfolioMediaResource($media))->resolve();
        abort_unless(($payload['status'] ?? null) === 'ready', 404);
        $url = ($payload['file_type'] ?? null) === 'video'
            ? ($payload['video_url'] ?? null) : ($payload['image_url'] ?? null);
        abort_unless(is_string($url) && str_starts_with($url, 'https://'), 404);

        return redirect()->away($url)->withHeaders($this->headers());
    }

    private function headers(): array
    {
        return [
            'Cache-Control' => 'private, no-store, max-age=0',
            'X-Robots-Tag' => 'noindex, nofollow, noarchive',
            'Referrer-Policy' => 'no-referrer',
        ];
    }
}
