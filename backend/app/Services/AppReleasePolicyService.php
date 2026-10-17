<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppVersion;
use App\Support\DatabaseCapabilities;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class AppReleasePolicyService
{
    public const CHANNEL_PLAY = 'play';
    public const CHANNEL_DIRECT = 'direct';
    public const CHANNEL_APP_STORE = 'appstore';

    /** @return list<string> */
    public function channels(): array
    {
        return [self::CHANNEL_PLAY, self::CHANNEL_DIRECT, self::CHANNEL_APP_STORE];
    }

    public function platformForChannel(string $channel): ?string
    {
        return match ($channel) {
            self::CHANNEL_PLAY, self::CHANNEL_DIRECT => 'android',
            self::CHANNEL_APP_STORE => 'ios',
            default => null,
        };
    }

    public function publicContractIdentity(): string
    {
        $fingerprints = array_values(array_unique(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            (array) config('app_links.android_sha256_fingerprints', []),
        )));
        sort($fingerprints);
        $appleIds = array_values(array_unique(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('app_links.apple_app_ids', []),
        )));
        sort($appleIds);

        return hash('sha256', json_encode([
            'android_package' => trim((string) config('app_links.android_package')),
            'android_fingerprints' => $fingerprints,
            'apple_app_ids' => $appleIds,
            'api_contract' => max(1, (int) config('mobile_contract.current_version', 1)),
            'api_minimum' => max(1, (int) config('mobile_contract.minimum_supported_version', 1)),
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    public function isAllowedDownloadUrl(string $channel, mixed $value): bool
    {
        $parts = is_string($value) ? parse_url(trim($value)) : false;
        if (!is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['fragment'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        $path = (string) ($parts['path'] ?? '');
        parse_str((string) ($parts['query'] ?? ''), $query);

        return match ($channel) {
            self::CHANNEL_PLAY => $host === 'play.google.com'
                && rtrim($path, '/') === '/store/apps/details'
                && hash_equals(
                    trim((string) config('app_links.android_package', 'com.rokn')),
                    trim((string) ($query['id'] ?? '')),
                ),
            self::CHANNEL_APP_STORE => $host === 'apps.apple.com'
                && preg_match('#/(?:[a-z]{2}/)?app/(?:[^/]+/)?id[0-9]+/?$#i', $path) === 1,
            self::CHANNEL_DIRECT => in_array($host, ['rokn.app', 'www.rokn.app'], true)
                && str_ends_with(strtolower($path), '.apk'),
            default => false,
        };
    }

    /** @return Collection<int, AppVersion> */
    public function activeVersions(string $platform, ?string $channel): Collection
    {
        try {
            if (!DatabaseCapabilities::hasTable('app_versions')) {
                return collect();
            }

            return $this->activeVersionsFromDatabase($platform, $channel);
        } catch (Throwable $exception) {
            report($exception);
            return collect();
        }
    }

    /** @return Collection<int, AppVersion> */
    private function activeVersionsFromDatabase(string $platform, ?string $channel): Collection
    {
        $base = AppVersion::query()
            ->where('platform', $platform)
            ->where('is_active', true);

        if ($channel !== null) {
            $exact = $this->usableVersions(
                (clone $base)->where('distribution_channel', $channel)->get(),
                $platform,
                $channel,
            );
            if ($exact->isNotEmpty()) {
                return $exact;
            }

            return $this->usableVersions(
                (clone $base)->whereNull('distribution_channel')->get(),
                $platform,
                $channel,
            );
        }

        if ($platform === 'ios') {
            $appStore = $this->usableVersions(
                (clone $base)
                    ->where('distribution_channel', self::CHANNEL_APP_STORE)
                    ->get(),
                $platform,
                self::CHANNEL_APP_STORE,
            );
            if ($appStore->isNotEmpty()) {
                return $appStore;
            }

            return $this->usableVersions(
                (clone $base)->whereNull('distribution_channel')->get(),
                $platform,
                self::CHANNEL_APP_STORE,
            );
        }

        // Old Android APKs did not declare a channel. A legacy row remains the
        // operator's explicit cross-channel policy. If it is absent, Play is
        // the only safe modern fallback: a store build must never be directed
        // to a sideloaded APK, while a direct install can move to Play when the
        // application identity and signer match.
        $legacy = $this->usableVersions(
            (clone $base)->whereNull('distribution_channel')->get(),
            $platform,
            null,
        );
        if ($legacy->isNotEmpty()) {
            return $legacy;
        }

        return $this->usableVersions(
            (clone $base)
                ->where('distribution_channel', self::CHANNEL_PLAY)
                ->get(),
            $platform,
            self::CHANNEL_PLAY,
        );
    }

    /**
     * Never turn a malformed or stale release row into a forced-update wall.
     * Dashboard validation protects new writes, while this read-side guard
     * also covers imported rows and direct database changes.
     *
     * @param Collection<int, AppVersion> $versions
     * @return Collection<int, AppVersion>
     */
    private function usableVersions(Collection $versions, string $platform, ?string $requestedChannel): Collection
    {
        return $versions->filter(function (AppVersion $version) use ($platform, $requestedChannel): bool {
            $declared = trim((string) $version->distribution_channel);
            $channels = $declared !== ''
                ? [$declared]
                : ($requestedChannel !== null
                    ? [$requestedChannel]
                    : ($platform === 'ios'
                        ? [self::CHANNEL_APP_STORE]
                        : [self::CHANNEL_PLAY, self::CHANNEL_DIRECT]));

            foreach ($channels as $channel) {
                if ($this->isAllowedDownloadUrl($channel, $version->download_url)) {
                    return true;
                }
            }

            return false;
        })->values();
    }

    /** @return array{ready: bool, required_channels: list<string>, channels: array<string, array{ready: bool, reason: ?string, release_id: ?int}>} */
    public function launchReadiness(): array
    {
        $required = array_values(array_intersect(
            $this->channels(),
            array_values((array) config('mobile_contract.launch_channels', [])),
        ));
        $statuses = [];

        foreach ($this->channels() as $channel) {
            $status = ['ready' => false, 'reason' => null, 'release_id' => null];
            try {
                if (!Schema::hasTable('app_versions')
                    || !Schema::hasColumn('app_versions', 'distribution_channel')) {
                    $status['reason'] = 'schema_missing';
                } else {
                    $identifier = $channel === self::CHANNEL_APP_STORE
                        ? 'build_number'
                        : 'version_code';
                    /** @var AppVersion|null $release */
                    $release = AppVersion::query()
                        ->where('platform', $this->platformForChannel($channel))
                        ->where('distribution_channel', $channel)
                        ->where('is_active', true)
                        ->whereNotNull($identifier)
                        ->orderByDesc($identifier)
                        ->orderByDesc('id')
                        ->first();

                    if (!$release) {
                        $status['reason'] = 'no_active_release';
                    } elseif (!$this->isAllowedDownloadUrl($channel, $release->download_url)) {
                        $status['reason'] = 'invalid_download_url';
                        $status['release_id'] = (int) $release->id;
                    } else {
                        $status = [
                            'ready' => true,
                            'reason' => null,
                            'release_id' => (int) $release->id,
                        ];
                    }
                }
            } catch (Throwable) {
                $status['reason'] = 'check_failed';
            }
            $statuses[$channel] = $status;
        }

        return [
            'ready' => $required !== [] && collect($required)->every(
                fn (string $channel): bool => (bool) ($statuses[$channel]['ready'] ?? false),
            ),
            'required_channels' => $required,
            'channels' => $statuses,
        ];
    }
}
