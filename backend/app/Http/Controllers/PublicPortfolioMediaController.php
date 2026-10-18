<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\BunnyDeliveryService;
use App\Services\PortfolioMediaReadinessService;
use App\Services\PublicPortfolioService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PublicPortfolioMediaController extends Controller
{
    /** Signed URLs are identical within one bucket so browsers and the CDN can reuse them. */
    public const CACHE_BUCKET_SECONDS = 3600;

    /** A cached redirect always points at a URL that is still valid this long after it expires. */
    public const SIGNATURE_GRACE_SECONDS = 600;

    public function portfolio(
        string $slug,
        string $mediaId,
        PublicPortfolioService $portfolios,
        BunnyDeliveryService $delivery,
        PortfolioMediaReadinessService $readiness,
        Request $request
    ): RedirectResponse {
        $media = $portfolios->mediaForPortfolio($slug, $mediaId,
            is_string($request->query('revision')) ? $request->query('revision') : '',
            is_string($request->query('snapshot')) ? $request->query('snapshot') : ''
        );
        abort_if(! $media || ! $media->file_path, 404);
        $variant = $request->query('variant');
        abort_unless($variant === null || $variant === 'poster', 404);

        $bucket = self::CACHE_BUCKET_SECONDS;
        $grace = self::SIGNATURE_GRACE_SECONDS;
        $url = null;
        if ($media->file_type === 'image') {
            $url = $delivery->storageUrl((string) $media->file_path, $grace, $bucket);
        } elseif ($media->file_type === 'video' && $variant === 'poster') {
            $url = $delivery->videoThumbnail((string) $media->file_path, 'thumbnail.jpg', $bucket)['url'] ?? null;
        } elseif ($media->file_type === 'video') {
            abort_unless(($readiness->presentation($media)['status'] ?? null) === 'ready', 404);
            $url = $delivery->videoEmbed((string) $media->file_path, $grace, $bucket)['url'] ?? null;
        }
        abort_unless(is_string($url) && str_starts_with($url, 'https://'), 404);

        return $this->cacheableRedirect($url, BunnyDeliveryService::bucketEnd($bucket) - time());
    }

    /**
     * Only approved public media reach this point. The redirect may be cached
     * publicly until the signed URL changes at the end of the bucket.
     */
    private function cacheableRedirect(string $url, int $maxAge): RedirectResponse
    {
        return redirect()
            ->away($url, 302)
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Cache-Control', 'public, max-age='.max(0, min(self::CACHE_BUCKET_SECONDS, $maxAge)))
            ->header('Referrer-Policy', 'no-referrer');
    }
}
