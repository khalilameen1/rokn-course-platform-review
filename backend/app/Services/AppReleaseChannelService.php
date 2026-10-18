<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppVersion;
use App\Models\Setting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AppReleaseChannelService
{
    private const CACHE_GENERATION_KEY = 'app-release-channels:generation';

    public function __construct(private readonly AppReleasePolicyService $policy) {}

    /** @return array{play: ?string, appstore: ?string, direct: ?string} */
    public function urls(?Setting $settings = null): array
    {
        $load = function (): Collection {
            if (! Schema::hasTable('app_versions')) {
                return collect();
            }

            return AppVersion::query()
                ->active()
                ->whereIn('distribution_channel', ['play', 'direct', 'appstore'])
                ->orderByDesc('version_code')
                ->orderByDesc('build_number')
                ->orderByDesc('id')
                ->get(['distribution_channel', 'download_url'])
                // Imported/legacy rows can predate dashboard validation. Skip
                // a malformed newest row and retain the previous valid release
                // instead of making a live store/APK link disappear.
                ->filter(fn (AppVersion $version): bool => $this->policy->isAllowedDownloadUrl(
                    (string) $version->distribution_channel,
                    $version->download_url
                ))
                ->unique('distribution_channel')
                ->mapWithKeys(fn (AppVersion $version): array => [
                    (string) $version->distribution_channel => $version->download_url,
                ]);
        };
        try {
            $generation = (int) Cache::get(self::CACHE_GENERATION_KEY, 1);
            $releases = Cache::remember(
                'app-release-channels:v2:'.$generation,
                60,
                $load
            );
        } catch (Throwable $cacheException) {
            // Redis is only the accelerator. Falling straight to an empty
            // collection here hid valid store/download links and then allowed
            // PublicAppSettingsService to cache that false absence for five
            // minutes.
            report($cacheException);
            try {
                $releases = $load();
            } catch (Throwable $databaseException) {
                // A genuinely unavailable/absent release table has no safe URL
                // to invent, so legacy store settings remain the fallback.
                report($databaseException);
                $releases = collect();
            }
        }

        return [
            'play' => $this->allowedUrl(
                $releases->get('play') ?: $settings?->android_app_url,
                AppReleasePolicyService::CHANNEL_PLAY,
            ),
            'appstore' => $this->allowedUrl(
                $releases->get('appstore') ?: $settings?->ios_app_url,
                AppReleasePolicyService::CHANNEL_APP_STORE,
            ),
            // Direct builds only exist as explicit release records. An old
            // generic Android URL must never silently become an APK channel.
            'direct' => $this->allowedUrl(
                $releases->get('direct'),
                AppReleasePolicyService::CHANNEL_DIRECT,
            ),
        ];
    }

    public static function invalidate(): void
    {
        try {
            Cache::add(self::CACHE_GENERATION_KEY, 1, now()->addYears(10));
            Cache::increment(self::CACHE_GENERATION_KEY);
            Cache::forget('app-release-channels:v2');
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function allowedUrl(mixed $value, string $channel): ?string
    {
        $url = trim((string) $value);

        return $this->policy->isAllowedDownloadUrl($channel, $url) ? $url : null;
    }
}
