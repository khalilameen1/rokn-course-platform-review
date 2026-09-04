<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\RecordQueueHeartbeat;
use App\Models\Setting;
use App\Models\Package;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Throwable;

final class ProductionCapabilityService
{
    public function __construct(
        private readonly SocialAuthProviderRegistry $socialProviders,
        private readonly RecoveryEvidenceService $recoveryEvidence
    ) {}

    /**
     * Configuration readiness is deliberately separate from live provider
     * connectivity. A public readiness probe must not call third parties or
     * restart healthy app instances merely because a provider is unavailable.
     * Queue readiness is stronger: its heartbeat proves that both scheduler
     * dispatch and a queue worker completed a real asynchronous job recently.
     *
     * @return array{
     *   ready: bool,
     *   checked_at: string,
     *   capabilities: array{
     *     bunny: array{ready: bool, stream: array, upload: array, playback: array, signing: array, assets: array},
     *     payment: array{ready: bool, reason: string},
     *     ai: array{ready: bool, reason: string},
     *     mail: array{ready: bool, reason: string},
     *     push: array{ready: bool, reason: string},
     *     social: array{ready: bool, reason: string, google: array, facebook: array, tiktok: array, apple: array, callbacks: array, handoff: array},
     *     app_links: array{ready: bool, reason: string, android: array, apple: array},
     *     queue: array{ready: bool, reason: string, required_queues: list<string>, queues: array<string, array>},
     *     recovery: array{ready: bool, reason: string, checks: array, recovery_mode: bool, rpo_seconds: ?int, rto_seconds: ?int}
     *   }
     * }
     */
    public function report(): array
    {
        $settings = $this->settings();
        $bunnyEnabled = (bool) ($settings?->bunny_enabled ?? false);
        $streamKey = $this->configuredValue(
            config('bunny.stream_api_key'),
            $this->settingValue($settings, 'bunny_api_key_secret'),
            $this->settingValue($settings, 'bunny_api_key')
        );
        $libraryId = $this->configuredValue(
            config('bunny.library_id'),
            $this->settingValue($settings, 'bunny_library_id')
        );
        $cdnHostname = $this->configuredValue(
            config('bunny.cdn_hostname'),
            $this->settingValue($settings, 'bunny_cdn_hostname')
        );
        $signingKey = $this->configuredValue(
            config('bunny.token_auth_key'),
            $this->settingValue($settings, 'bunny_security_key_secret')
        );
        $storageZone = trim((string) config('bunny.storage_zone'));
        $storagePassword = trim((string) config('bunny.storage_password'));
        $storageHostname = trim((string) config('bunny.storage_cdn_hostname'));
        $storageSigningKey = trim((string) config('bunny.storage_token_auth_key'));

        $streamReady = $bunnyEnabled && $streamKey !== '' && $libraryId !== '';
        $uploadReady = $streamReady
            && (int) config('bunny.connect_timeout_seconds', 0) > 0
            && (int) config('bunny.upload_timeout_seconds', 0) >= 60;
        $playbackReady = $bunnyEnabled && $this->validBareHostname($cdnHostname);
        $signingReady = $bunnyEnabled && $signingKey !== '';
        $assetsReady = $bunnyEnabled
            && $storageZone !== ''
            && $storagePassword !== ''
            && $this->validBareHostname($storageHostname)
            && $storageSigningKey !== '';

        $bunny = [
            'stream' => $this->item(
                $streamReady,
                !$bunnyEnabled
                    ? 'Bunny متوقف من الإعدادات'
                    : ($streamReady ? 'مفتاح Stream ومعرّف المكتبة موجودان' : 'مفتاح Stream أو معرّف المكتبة ناقص')
            ),
            'upload' => $this->item(
                $uploadReady,
                $uploadReady ? 'رفع Stream مضبوط بمهلة مناسبة' : 'إعداد رفع الفيديو أو المهلة ناقص'
            ),
            'playback' => $this->item(
                $playbackReady,
                $playbackReady ? 'اسم CDN صالح للتشغيل' : 'اسم CDN ناقص أو غير صالح'
            ),
            'signing' => $this->item(
                $signingReady,
                $signingReady ? 'مفتاح توقيع التشغيل موجود' : 'مفتاح توقيع التشغيل ناقص'
            ),
            'assets' => $this->item(
                $assetsReady,
                $assetsReady
                    ? 'رفع وتوقيع صور وملفات Bunny مضبوط'
                    : 'Storage Zone أو كلمة الرفع أو CDN أو مفتاح توقيع الملفات ناقص'
            ),
        ];
        $bunny['ready'] = $streamReady
            && $uploadReady
            && $playbackReady
            && $signingReady
            && $assetsReady;

        $launchChannels = $this->requiredLaunchChannels();
        $payment = $this->paymentCapability($launchChannels);
        $ai = $this->aiCapability();
        $mail = $this->mailCapability();
        $push = $this->pushCapability();
        $social = $this->socialCapability();
        $appLinks = $this->appLinksCapability($launchChannels);
        $queue = $this->queueCapability();
        $recoveryState = $this->recoveryEvidence->readiness();
        $recovery = [
            'ready' => (bool) ($recoveryState['ready'] ?? false)
                && !(bool) ($recoveryState['recovery_mode'] ?? false),
            'reason' => (bool) ($recoveryState['recovery_mode'] ?? false)
                ? 'Recovery mode is active; payment and background mutations remain paused'
                : ((bool) ($recoveryState['ready'] ?? false)
                    ? 'Signed backup and restore evidence satisfies the configured RPO and RTO'
                    : 'Signed backup, restore, encryption, schema, ledger, or media evidence is incomplete'),
            'checks' => (array) ($recoveryState['checks'] ?? []),
            'recovery_mode' => (bool) ($recoveryState['recovery_mode'] ?? false),
            'rpo_seconds' => $recoveryState['rpo_seconds'] ?? null,
            'rto_seconds' => $recoveryState['rto_seconds'] ?? null,
        ];

        return [
            'ready' => $bunny['ready']
                && $payment['ready']
                && $ai['ready']
                && $mail['ready']
                && $push['ready']
                && $social['ready']
                && $appLinks['ready']
                && $queue['ready']
                && $recovery['ready'],
            'checked_at' => now()->toIso8601String(),
            'launch_channels' => $launchChannels,
            'capabilities' => [
                'bunny' => $bunny,
                'payment' => $payment,
                'ai' => $ai,
                'mail' => $mail,
                'push' => $push,
                'social' => $social,
                'app_links' => $appLinks,
                'queue' => $queue,
                'recovery' => $recovery,
            ],
        ];
    }

    /** @param list<string> $launchChannels */
    private function paymentCapability(array $launchChannels): array
    {
        $mode = strtolower(trim((string) config('kashier.mode')));
        $selected = is_array(config("kashier.{$mode}")) ? config("kashier.{$mode}") : [];
        $configured = in_array($mode, ['live', 'test'], true)
            && trim((string) ($selected['api_key'] ?? '')) !== ''
            && trim((string) ($selected['secret_key'] ?? '')) !== ''
            && trim((string) ($selected['mid'] ?? '')) !== ''
            && filter_var($selected['base_url'] ?? null, FILTER_VALIDATE_URL) !== false;
        $productionModeReady = config('app.env') !== 'production' || $mode === 'live';
        $kashierReady = $configured && $productionModeReady;
        $kashier = $this->item(
            $kashierReady,
            !$configured
                ? 'بيانات بوابة Kashier المختارة ناقصة'
                : ($productionModeReady ? "Kashier مضبوط على {$mode}" : 'الإنتاج ما زال يستخدم وضع Kashier التجريبي')
        );

        $packageColumnsReady = Schema::hasTable('packages')
            && Schema::hasColumn('packages', 'google_product_id')
            && Schema::hasColumn('packages', 'apple_product_id');
        $googleProductsReady = $packageColumnsReady
            && Package::query()
                ->where('google_enabled', true)
                ->whereNotNull('google_product_id')
                ->where('google_product_id', '<>', '')
                ->exists();
        $googleCredentials = $this->decodedJsonCredential(
            config('store_billing.google.credentials_base64'),
            config('store_billing.google.credentials_file')
        );
        $googleReady = $googleProductsReady
            && $this->validPackageName(trim((string) config('store_billing.google.package_name')))
            && is_array($googleCredentials)
            && filter_var($googleCredentials['client_email'] ?? null, FILTER_VALIDATE_EMAIL) !== false
            && $this->filled($googleCredentials['private_key'] ?? null)
            && filter_var(config('store_billing.google.rtdn_audience'), FILTER_VALIDATE_URL) !== false
            && filter_var(
                config('store_billing.google.rtdn_service_account_email'),
                FILTER_VALIDATE_EMAIL
            ) !== false;
        $google = $this->item(
            $googleReady,
            $googleReady
                ? 'منتجات Play والتحقق الخادمي وإشعارات الاسترداد مضبوطة'
                : 'منتجات Google Play أو service account أو package name أو RTDN ناقصة'
        );

        $appleProductsReady = $packageColumnsReady
            && Package::query()
                ->where('apple_enabled', true)
                ->whereNotNull('apple_product_id')
                ->where('apple_product_id', '<>', '')
                ->exists();
        $applePrivateKey = $this->configuredFileOrBase64(
            config('store_billing.apple.private_key_base64'),
            config('store_billing.apple.private_key_file')
        );
        $appleRoots = (array) config('store_billing.apple.root_certificate_sha256', []);
        $appleReady = $appleProductsReady
            && $this->validPackageName(trim((string) config('store_billing.apple.bundle_id')))
            && $this->filled(config('store_billing.apple.issuer_id'))
            && $this->filled(config('store_billing.apple.key_id'))
            && is_string($applePrivateKey)
            && str_contains($applePrivateKey, 'PRIVATE KEY')
            && $appleRoots !== []
            && collect($appleRoots)->every(
                static fn ($fingerprint): bool => preg_match('/\A[0-9a-f]{64}\z/', (string) $fingerprint) === 1
            );
        $apple = $this->item(
            $appleReady,
            $appleReady
                ? 'منتجات App Store ومفتاح التحقق وجذور Apple مضبوطة'
                : 'منتجات App Store أو issuer/key أو private key أو جذور Apple ناقصة'
        );

        $required = [
            'kashier' => in_array(AppReleasePolicyService::CHANNEL_DIRECT, $launchChannels, true),
            'google_play' => in_array(AppReleasePolicyService::CHANNEL_PLAY, $launchChannels, true),
            'app_store' => in_array(AppReleasePolicyService::CHANNEL_APP_STORE, $launchChannels, true),
        ];
        $ready = $launchChannels !== []
            && (!$required['kashier'] || $kashierReady)
            && (!$required['google_play'] || $googleReady)
            && (!$required['app_store'] || $appleReady);

        return [
            'ready' => $ready,
            'reason' => $ready
                ? 'قنوات الدفع المعلنة جاهزة'
                : 'قناة دفع معلنة واحدة أو أكثر غير مكتملة',
            'kashier' => $kashier + ['required' => $required['kashier']],
            'google_play' => $google + ['required' => $required['google_play']],
            'app_store' => $apple + ['required' => $required['app_store']],
        ];
    }

    /** @return array<string, mixed>|null */
    private function decodedJsonCredential(mixed $encoded, mixed $file): ?array
    {
        $encoded = trim((string) $encoded);
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);
            $json = is_string($decoded) ? json_decode($decoded, true) : null;
            if (is_array($json)) return $json;
        }

        $file = trim((string) $file);
        if ($file !== '' && is_file($file) && is_readable($file)) {
            $json = json_decode((string) file_get_contents($file), true);
            if (is_array($json)) return $json;
        }

        return null;
    }

    private function configuredFileOrBase64(mixed $encoded, mixed $file): ?string
    {
        $encoded = trim((string) $encoded);
        if ($encoded !== '') {
            $decoded = base64_decode($encoded, true);
            if (is_string($decoded) && $decoded !== '') return $decoded;
        }

        $file = trim((string) $file);
        if ($file !== '' && is_file($file) && is_readable($file)) {
            return (string) file_get_contents($file);
        }

        return null;
    }

    private function aiCapability(): array
    {
        $apiKey = trim((string) config('openrouter.api_key'));
        $model = trim((string) config('openrouter.default_model'));
        $allowed = array_values(array_filter(config('openrouter.allowed_models', [])));
        $budgetsReady = (int) config('openrouter.global_daily_request_limit') > 0
            && (int) config('openrouter.global_daily_token_budget') > 0
            && (int) config('openrouter.global_monthly_token_budget') > 0;
        $ready = $apiKey !== '' && $model !== '' && in_array($model, $allowed, true) && $budgetsReady;

        return $this->item(
            $ready,
            $ready
                ? 'المفتاح والنموذج وقائمة السماح وحدود التكلفة مضبوطة'
                : 'مفتاح OpenRouter أو النموذج المسموح أو حدود التكلفة ناقصة'
        );
    }

    private function mailCapability(): array
    {
        $mailer = strtolower(trim((string) config('mail.default')));
        $host = trim((string) config("mail.mailers.{$mailer}.host"));
        $port = (int) config("mail.mailers.{$mailer}.port");
        $username = trim((string) config("mail.mailers.{$mailer}.username"));
        $password = trim((string) config("mail.mailers.{$mailer}.password"));
        $from = trim((string) config('mail.from.address'));
        $ready = $mailer === 'smtp'
            && $this->validHostname($host)
            && $port > 0
            && $port <= 65535
            && $username !== ''
            && $password !== ''
            && filter_var($from, FILTER_VALIDATE_EMAIL) !== false;

        return $this->item(
            $ready,
            $ready
                ? 'SMTP transactional mail and sender are configured'
                : 'Transactional mail host, credentials, port, or sender is incomplete'
        );
    }

    private function pushCapability(): array
    {
        $credentials = $this->firebaseCredentials();
        $ready = is_array($credentials)
            && $this->filled($credentials['project_id'] ?? null)
            && filter_var($credentials['client_email'] ?? null, FILTER_VALIDATE_EMAIL) !== false
            && $this->filled($credentials['private_key'] ?? null);

        return $this->item(
            $ready,
            $ready
                ? 'Firebase service account صالح لإرسال الإشعارات'
                : 'بيانات Firebase غير موجودة أو لا تحتوي project_id وclient_email وprivate_key'
        );
    }

    private function socialCapability(): array
    {
        $declaredProviders = $this->socialProviders->declared();
        $publicApiUrl = trim((string) config('social_auth.public_api_url'));
        $returnUrls = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('social_auth.return_urls', [])
        ))));
        $callbacksReady = $this->validSocialApiUrl($publicApiUrl)
            && $returnUrls === ['rokn://auth'];
        $handoffReady = $this->socialHandoffIsReady();

        $social = [
            'callbacks' => $this->item(
                $callbacksReady,
                $callbacksReady ? 'روابط العودة وPKCE مضبوطة للإنتاج' : 'Public API URL أو return URL أو سياسة PKCE غير صالحة'
            ),
            'handoff' => $this->item(
                $handoffReady,
                $handoffReady
                    ? 'مسار OAuth والجلسة المشفرة مكتمل داخليًا'
                    : 'مسار OAuth أو تشفير الجلسة غير مكتمل'
            ),
        ];
        foreach (['google', 'tiktok', 'apple', 'facebook'] as $provider) {
            $social[$provider] = $this->item(
                $this->socialProviders->isReady($provider),
                $this->socialProviders->reason($provider)
            ) + ['required' => $declaredProviders->contains($provider)];
        }
        $social['declared_providers'] = $declaredProviders->all();
        $social['ready'] = $declaredProviders->isNotEmpty()
            && $callbacksReady
            && $handoffReady
            && $declaredProviders->every(
                fn (string $provider): bool => (bool) data_get($social, "{$provider}.ready")
            );
        $social['reason'] = $social['ready']
            ? 'كل طرق تسجيل الدخول المعلنة وروابط العودة مكتملة'
            : 'إحدى طرق الدخول المعلنة أو عقد العودة ما زال ناقصًا';

        return $social;
    }

    public function socialHandoffIsReady(): bool
    {
        foreach ([
            'api.auth-methods',
            'api.social-login',
            'api.social.start',
            'api.social.callback',
            'api.social.complete',
        ] as $routeName) {
            if (!Route::has($routeName)) {
                return false;
            }
        }

        try {
            $probe = bin2hex(random_bytes(16));

            return hash_equals($probe, Crypt::decryptString(Crypt::encryptString($probe)));
        } catch (Throwable $exception) {
            return false;
        }
    }

    /** @param list<string> $launchChannels */
    private function appLinksCapability(array $launchChannels): array
    {
        $androidPackage = trim((string) config('app_links.android_package'));
        $androidFingerprints = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            (array) config('app_links.android_sha256_fingerprints', [])
        ))));
        $appleAppIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('app_links.apple_app_ids', [])
        ))));
        $androidReady = $this->validPackageName($androidPackage)
            && $androidFingerprints !== []
            && collect($androidFingerprints)->every(
                static fn (string $fingerprint): bool => preg_match('/\A(?:[0-9A-F]{2}:){31}[0-9A-F]{2}\z/', $fingerprint) === 1
            );
        $appleReady = $appleAppIds !== []
            && collect($appleAppIds)->every(
                static fn (string $appId): bool => preg_match('/\A[A-Z0-9]{10}\.(?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]+\z/', $appId) === 1
            );
        $appLinks = [
            'android' => $this->item(
                $androidReady,
                $androidReady ? 'Android App Links وهوية توقيع التطبيق مضبوطان' : 'Android package أو SHA-256 signing fingerprints ناقصة'
            ),
            'apple' => $this->item(
                $appleReady,
                $appleReady ? 'Apple Universal Links app IDs مضبوطة' : 'Apple Team ID أو bundle ID ناقص'
            ),
        ];
        $requiresAndroid = in_array(AppReleasePolicyService::CHANNEL_DIRECT, $launchChannels, true)
            || in_array(AppReleasePolicyService::CHANNEL_PLAY, $launchChannels, true);
        $requiresApple = in_array(AppReleasePolicyService::CHANNEL_APP_STORE, $launchChannels, true);
        $appLinks['android']['required'] = $requiresAndroid;
        $appLinks['apple']['required'] = $requiresApple;
        $appLinks['ready'] = $launchChannels !== []
            && (!$requiresAndroid || $androidReady)
            && (!$requiresApple || $appleReady);
        $requiredPlatforms = array_values(array_filter([
            $requiresAndroid ? 'Android' : null,
            $requiresApple ? 'Apple' : null,
        ]));
        $appLinks['reason'] = $appLinks['ready']
            ? 'روابط فتح التطبيق مضبوطة للقنوات المعلنة: '.implode(' و', $requiredPlatforms)
            : ($requiredPlatforms === []
                ? 'لا توجد قناة إصدار معلنة'
                : 'ربط التطبيق بالنطاق ناقص للقنوات المعلنة: '.implode(' و', $requiredPlatforms));

        return $appLinks;
    }

    /** @return list<string> */
    private function requiredLaunchChannels(): array
    {
        $supported = [
            AppReleasePolicyService::CHANNEL_PLAY,
            AppReleasePolicyService::CHANNEL_DIRECT,
            AppReleasePolicyService::CHANNEL_APP_STORE,
        ];

        return array_values(array_intersect(
            $supported,
            array_values(array_unique(array_map(
                static fn ($channel): string => strtolower(trim((string) $channel)),
                (array) config('mobile_contract.launch_channels', [])
            )))
        ));
    }

    private function queueCapability(): array
    {
        $driver = strtolower(trim((string) config('queue.default')));
        $asynchronous = !in_array($driver, ['', 'sync', 'null'], true);
        if (!$asynchronous) {
            return $this->item(false, 'QUEUE_CONNECTION متزامن ولا يناسب التشغيل الفعلي');
        }
        if (config('app.env') === 'production' && $driver !== 'redis') {
            return $this->item(false, 'الإنتاج يحتاج Redis queue لهذا الحجم');
        }

        $requiredQueues = RecordQueueHeartbeat::requiredQueues();
        if ($requiredQueues === []) {
            return [
                ...$this->item(false, 'No required queue heartbeats are configured'),
                'required_queues' => [],
                'queues' => [],
            ];
        }

        $maxAge = max(60, (int) config('operations.queue_heartbeat_max_age_seconds', 180));
        $oldestAllowed = now()->subSeconds($maxAge);
        $queueChecks = [];

        foreach ($requiredQueues as $queue) {
            try {
                $value = Cache::get(RecordQueueHeartbeat::cacheKey($queue));

                // During a rolling deployment, accept the historical key only
                // for the configured default queue. It can never satisfy a
                // heartbeat for notifications, AI feedback, or webhooks.
                if (($value === null || $value === '') && $queue === RecordQueueHeartbeat::defaultQueueName()) {
                    $value = Cache::get(RecordQueueHeartbeat::legacyCacheKey());
                }

                $heartbeat = is_string($value) && $value !== ''
                    ? CarbonImmutable::parse($value)
                    : null;
                $fresh = $heartbeat !== null && $heartbeat->greaterThanOrEqualTo($oldestAllowed);
            } catch (Throwable) {
                $heartbeat = null;
                $fresh = false;
            }

            $queueChecks[$queue] = [
                'ready' => $fresh,
                'last_heartbeat_at' => $heartbeat?->toIso8601String(),
            ];
        }

        $missing = array_keys(array_filter(
            $queueChecks,
            static fn (array $check): bool => !$check['ready']
        ));
        $fresh = $missing === [];

        return [
            ...$this->item(
                $fresh,
                $fresh
                    ? "Every required {$driver} queue executed a recent heartbeat"
                    : 'Missing or stale queue heartbeats: '.implode(', ', $missing)
            ),
            'required_queues' => $requiredQueues,
            'queues' => $queueChecks,
        ];
    }

    private function settings(): ?Setting
    {
        try {
            return Setting::query()->first();
        } catch (Throwable) {
            return null;
        }
    }

    private function settingValue(?Setting $settings, string $attribute): mixed
    {
        try {
            return $settings?->{$attribute};
        } catch (Throwable) {
            return null;
        }
    }

    private function configuredValue(mixed ...$values): string
    {
        foreach ($values as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function firebaseCredentials(): ?array
    {
        $base64 = trim((string) config('firebase.credentials.base64'));
        if ($base64 !== '') {
            $decoded = base64_decode($base64, true);

            return is_string($decoded) ? $this->decodeJsonObject($decoded) : null;
        }

        $file = trim((string) config('firebase.credentials.file'));
        if ($file === '' || !is_readable($file)) {
            return null;
        }

        try {
            $contents = file_get_contents($file);
        } catch (Throwable) {
            return null;
        }

        return is_string($contents) ? $this->decodeJsonObject($contents) : null;
    }

    private function decodeJsonObject(string $json): ?array
    {
        try {
            $decoded = json_decode($json, true, 32, JSON_THROW_ON_ERROR);

            return is_array($decoded) ? $decoded : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function configured(string $key): bool
    {
        return $this->filled(config($key));
    }

    private function filled(mixed $value): bool
    {
        return trim((string) $value) !== '';
    }

    private function validPackageName(string $value): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_-]*)+\z/', $value) === 1;
    }

    private function validSocialApiUrl(string $url): bool
    {
        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }

        $parts = parse_url($url);

        return is_array($parts)
            && strtolower((string) ($parts['scheme'] ?? '')) === 'https'
            && $this->validHostname((string) ($parts['host'] ?? ''))
            && !isset($parts['user'], $parts['pass'], $parts['query'], $parts['fragment'])
            && rtrim((string) ($parts['path'] ?? ''), '/') === '/api/v1';
    }

    private function validHostname(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));
        if (str_starts_with($hostname, 'http://') || str_starts_with($hostname, 'https://')) {
            $hostname = (string) parse_url($hostname, PHP_URL_HOST);
        }

        return $hostname !== ''
            && filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    private function validBareHostname(string $hostname): bool
    {
        $hostname = strtolower(trim($hostname));

        return $hostname !== ''
            && !str_contains($hostname, ':')
            && !str_contains($hostname, '/')
            && !str_contains($hostname, '@')
            && filter_var($hostname, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }

    /** @return array{ready: bool, reason: string} */
    private function item(bool $ready, string $reason): array
    {
        return ['ready' => $ready, 'reason' => $reason];
    }
}
