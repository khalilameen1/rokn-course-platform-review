<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DesignSetting;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use App\Support\RoknLocale;
use Throwable;

final class PublicAppSettingsService
{
    private const CACHE_KEY_PREFIX = 'public-app-settings:v3:';
    private const CACHE_GENERATION_KEY = 'public-app-settings:generation';

    private const SOCIAL_HOSTS = [
        'facebook' => ['facebook.com', 'fb.com'],
        'youtube' => ['youtube.com', 'youtu.be'],
        'instagram' => ['instagram.com'],
        'tiktok' => ['tiktok.com'],
        'telegram' => ['t.me', 'telegram.me'],
    ];

    public function __construct(private readonly AppReleaseChannelService $releases)
    {
    }

    /** @return array<string, mixed> */
    public function snapshot(?string $locale = null): array
    {
        $locale = RoknLocale::normalize($locale)
            ?? RoknLocale::normalize((string) config('app.locale'))
            ?? RoknLocale::ARABIC;

        $load = function () use ($locale): array {
            $general = Setting::query()->first() ?? new Setting();
            $design = DesignSetting::getDefaultSettings();
            $socialWhatsApp = $this->whatsAppUrl($design->whatsapp_url);
            $supportWhatsApp = $this->whatsAppUrl(
                $general->support_whatsapp_url ?: $design->whatsapp_url
            );
            $releaseUrls = $this->releases->urls($general);

            $payload = [
                'name' => trim((string) (
                    $locale === 'en'
                        ? ($design->name_en ?: $design->name_ar ?: 'Rokn')
                        : ($design->name_ar ?: $design->name_en ?: 'ركن')
                )),
                'branding' => [
                    'logo_url' => $this->publicMediaUrl($design->logo_url),
                    'icon_url' => $this->publicMediaUrl($design->icon_url),
                    'home_background_url' => $this->publicMediaUrl($design->home_background_url),
                ],
                'social_media' => [
                    'facebook' => $this->socialUrl('facebook', $design->facebook_url),
                    'youtube' => $this->socialUrl('youtube', $design->youtube_url),
                    'instagram' => $this->socialUrl('instagram', $design->instagram_url),
                    'tiktok' => $this->socialUrl('tiktok', $design->tiktok_url),
                    'whatsapp' => $socialWhatsApp,
                    'telegram' => $this->socialUrl('telegram', $design->telegram_url),
                ],
                'support_contacts' => [
                    'email' => filter_var($general->email, FILTER_VALIDATE_EMAIL)
                        ? strtolower(trim((string) $general->email))
                        : null,
                    'phone' => $this->publicPhone($general->phone),
                    'whatsapp' => $supportWhatsApp,
                ],
                'support_whatsapp_url' => $supportWhatsApp,
                'about_url' => route('about'),
                'contact_url' => route('contact'),
                'privacy_url' => route('privacy'),
                'terms_url' => route('terms'),
                // Compatibility for installed clients. This is intentionally
                // not presented as a separate settings item in the current app.
                'returns_policy_url' => route('returns-policy'),
                'account_deletion_url' => route('account-deletion.show'),
                'android_app_url' => $releaseUrls['play'],
                'ios_app_url' => $releaseUrls['appstore'],
                'direct_android_app_url' => $releaseUrls['direct'],
                'coin_rules' => $general->how_to_use_coins,
            ];

            // The ETag is a content identity, not a timestamp hint. MySQL
            // timestamps commonly have one-second precision, so two valid
            // saves in the same second must still produce different revisions.
            $revision = hash('sha256', json_encode(
                [2, $payload],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ));

            return ['contract_version' => 2, 'revision' => $revision] + $payload;
        };

        try {
            $generation = (int) Cache::get(self::CACHE_GENERATION_KEY, 1);
            $key = self::CACHE_KEY_PREFIX.$generation.':'.$locale;
            $cached = Cache::get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            return $load();
        }

        $loadStarted = false;
        try {
            return Cache::lock("lock:{$key}", 10)->block(2, function () use (
                $key,
                $load,
                &$loadStarted
            ): array {
                $cached = Cache::get($key);
                if (is_array($cached)) {
                    return $cached;
                }

                $loadStarted = true;
                $settings = $load();
                try {
                    Cache::put($key, $settings, now()->addMinutes(5));
                } catch (Throwable) {
                    // The settings snapshot is complete; keep serving it if
                    // only the cache write failed.
                }

                return $settings;
            });
        } catch (Throwable $exception) {
            if ($loadStarted) {
                throw $exception;
            }

            return $load();
        }
    }

    public static function invalidate(): void
    {
        $forget = static function (): bool {
            Cache::add(self::CACHE_GENERATION_KEY, 1, now()->addYears(10));
            Cache::increment(self::CACHE_GENERATION_KEY);
            // Retire the pre-generation keys left by older deployments.
            $arabic = Cache::forget(self::CACHE_KEY_PREFIX.'ar');
            $english = Cache::forget(self::CACHE_KEY_PREFIX.'en');
            return $arabic || $english;
        };
        try {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($forget);
                return;
            }
            $forget();
        } catch (Throwable $exception) {
            // Cache invalidation must never turn a successful settings write
            // into a failed dashboard action. The entry also has a short TTL.
            report($exception);
        }
    }

    public function socialUrl(string $channel, mixed $value): ?string
    {
        return $this->allowedHttpsUrl($value, self::SOCIAL_HOSTS[$channel] ?? []);
    }

    public function whatsAppUrl(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        $parts = filter_var($raw, FILTER_VALIDATE_URL) ? parse_url($raw) : false;
        if (is_array($parts)) {
            if (
                strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
                || !in_array(strtolower((string) ($parts['host'] ?? '')), ['wa.me', 'www.wa.me'], true)
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return null;
            }
            $digits = trim((string) ($parts['path'] ?? ''), '/');
        } else {
            $digits = preg_replace('/[\s()+.\-]+/', '', $raw) ?? '';
        }

        return preg_match('/^[1-9][0-9]{7,14}$/', $digits) === 1
            ? 'https://wa.me/'.$digits
            : null;
    }

    public function embedVideoUrl(mixed $value): ?string
    {
        $url = trim((string) $value);
        $parts = $url !== '' ? parse_url($url) : false;
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return null;
        }
        $host = preg_replace('/^www\./', '', strtolower((string) ($parts['host'] ?? ''))) ?? '';
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $videoId = null;

        if ($host === 'youtu.be') {
            $videoId = explode('/', $path)[0] ?? null;
        } elseif (in_array($host, ['youtube.com', 'm.youtube.com'], true)) {
            if ($path === 'watch') {
                parse_str((string) ($parts['query'] ?? ''), $query);
                $videoId = $query['v'] ?? null;
            } elseif (str_starts_with($path, 'embed/')) {
                $videoId = explode('/', substr($path, 6))[0] ?? null;
            }
        } elseif ($host === 'youtube-nocookie.com' && str_starts_with($path, 'embed/')) {
            $videoId = explode('/', substr($path, 6))[0] ?? null;
        }

        if (is_string($videoId) && preg_match('/^[A-Za-z0-9_-]{6,20}$/', $videoId) === 1) {
            return 'https://www.youtube-nocookie.com/embed/'.$videoId;
        }

        $vimeoId = null;
        if ($host === 'vimeo.com') {
            $vimeoId = explode('/', $path)[0] ?? null;
        } elseif ($host === 'player.vimeo.com' && str_starts_with($path, 'video/')) {
            $vimeoId = explode('/', substr($path, 6))[0] ?? null;
        }

        return is_string($vimeoId) && preg_match('/^[0-9]{5,15}$/', $vimeoId) === 1
            ? 'https://player.vimeo.com/video/'.$vimeoId
            : null;
    }

    /** @param list<string> $allowedRoots */
    private function allowedHttpsUrl(mixed $value, array $allowedRoots = []): ?string
    {
        $url = trim((string) $value);
        $parts = $url !== '' ? parse_url($url) : false;
        if (
            !is_array($parts)
            || strtolower((string) ($parts['scheme'] ?? '')) !== 'https'
            || isset($parts['user'])
            || isset($parts['pass'])
        ) {
            return null;
        }
        $host = strtolower((string) ($parts['host'] ?? ''));
        if ($host === '') {
            return null;
        }
        if ($allowedRoots !== []) {
            $trusted = false;
            foreach ($allowedRoots as $root) {
                if ($host === $root || str_ends_with($host, '.'.$root)) {
                    $trusted = true;
                    break;
                }
            }
            if (!$trusted) {
                return null;
            }
        }

        return $url;
    }

    private function publicMediaUrl(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if (str_starts_with($raw, '/') && !str_starts_with($raw, '//')) {
            $raw = rtrim((string) config('app.url'), '/').'/'.ltrim($raw, '/');
        }
        $url = $this->allowedHttpsUrl($raw);
        if ($url === null) {
            return null;
        }
        $appHost = strtolower((string) parse_url((string) config('app.url'), PHP_URL_HOST));
        $mediaHost = strtolower((string) parse_url($url, PHP_URL_HOST));

        $normaliseHost = static fn (string $host): string => preg_replace('/^www\./', '', $host) ?? $host;

        return $appHost !== '' && $normaliseHost($mediaHost) === $normaliseHost($appHost)
            ? $url
            : null;
    }

    private function publicPhone(mixed $value): ?string
    {
        $phone = trim((string) $value);
        return preg_match('/^\+?[0-9][0-9\s().-]{6,24}$/', $phone) === 1 ? $phone : null;
    }
}
