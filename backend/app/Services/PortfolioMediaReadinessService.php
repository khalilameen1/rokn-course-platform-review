<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Throwable;

final class PortfolioMediaReadinessService
{
    public function __construct(private BunnyService $bunny)
    {
    }

    /**
     * @return array{
     *     status:string,
     *     video_url:?string,
     *     playback_url:?string,
     *     image_url:?string,
     *     url_expires_at:?string
     * }
     */
    public function presentation(object $media, bool $refresh = false): array
    {
        $result = [
            'status' => $media->file_path
                && in_array($media->file_type, ['image', 'video'], true)
                ? 'processing'
                : 'failed',
            'video_url' => null,
            'playback_url' => null,
            'image_url' => null,
            'url_expires_at' => null,
        ];

        try {
            if ($media->file_type === 'image' && $media->file_path) {
                $signedUrl = $this->bunny->generateBunnySignedUrl(
                    (string) $media->file_path,
                    300
                );
                $result['image_url'] = $signedUrl ?: null;
                $result['url_expires_at'] = $signedUrl
                    ? now()->addSeconds(300)->toIso8601String()
                    : null;
                $result['status'] = $signedUrl ? 'ready' : 'failed';

                return $result;
            }

            if ($media->file_type !== 'video' || !$media->file_path) {
                return $result;
            }

            $cacheKey = 'portfolio:video-state:' . hash('sha256', (string) $media->file_path);
            if ($refresh) {
                $inspection = $this->bunny->inspectRemoteVideo((string) $media->file_path);
                Cache::put($cacheKey, $inspection, now()->addSeconds(45));
            } else {
                $inspection = Cache::remember(
                    $cacheKey,
                    now()->addSeconds(45),
                    fn () => $this->bunny->inspectRemoteVideo((string) $media->file_path)
                );
            }
            if (in_array((string) ($inspection['state'] ?? ''), [
                'not_found',
                'provider_guid_mismatch',
                'provider_library_mismatch',
            ], true)) {
                $result['status'] = 'failed';

                return $result;
            }

            $details = $inspection['details'] ?? null;
            if (($inspection['state'] ?? null) !== 'ok' || !is_array($details)) {
                return $result;
            }

            $providerStatus = (int) ($details['status'] ?? -1);
            if (BunnyService::providerVideoStatusIsFailure($providerStatus)) {
                $result['status'] = 'failed';

                return $result;
            }
            $resolutions = array_filter(array_map(
                'trim',
                explode(',', (string) ($details['availableResolutions'] ?? ''))
            ));
            $ready = BunnyService::providerVideoStatusIsPlayable($providerStatus)
                || (float) ($details['encodeProgress'] ?? 0) >= 100
                || $resolutions !== [];
            if (!$ready) {
                return $result;
            }

            $embed = $this->bunny->getSignedEmbedUrl((string) $media->file_path, 300);
            $playback = $this->bunny->getSignedPlayUrl((string) $media->file_path, 300);
            $result['video_url'] = $embed['url'] ?? null;
            $result['playback_url'] = $playback['url'] ?? null;
            $result['url_expires_at'] = $playback['expires_at']
                ?? $embed['expires_at']
                ?? null;
            $result['status'] = $result['video_url'] || $result['playback_url']
                ? 'ready'
                : 'failed';
        } catch (Throwable $exception) {
            report($exception);
            // A provider transport failure remains retryable. Only an
            // explicit provider failure above is terminal.
            $result['status'] = 'processing';
        }

        return $result;
    }
}
