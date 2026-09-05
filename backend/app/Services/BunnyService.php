<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\Lesson;
use App\Models\BunnyVideoCleanupCandidate;
use App\Models\BunnyStorageCleanupCandidate;
use App\Models\BunnyVideoAllocationIntent;
use App\Models\LessonMediaState;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Exception;
use RuntimeException;
use Throwable;

class BunnyService
{
    public const PROBE_CIRCUIT_KEY = 'bunny:probe-circuit-open';
    private const PROBE_FAILURE_KEY = 'bunny:probe-circuit-failures';

    private ?Setting $settings = null;

    /**
     * Get the current settings
     */
    private function getSettings(): ?Setting
    {
        if ($this->settings === null) {
            $this->settings = Setting::first();
        }
        return $this->settings;
    }

    /**
     * Check if Bunny.net integration is enabled
     */
    public function isEnabled(): bool
    {
        $settings = $this->getSettings();
        return $settings && $settings->isBunnyConfigured();
    }

    /**
     * Get the API key
     */
    private function getApiKey(): ?string
    {
        return config('bunny.stream_api_key')
            ?: $this->getSettings()?->bunny_api_key_secret
            ?: $this->getSettings()?->bunny_api_key;
    }

    /**
     * Get the library ID
     */
    private function getLibraryId(): ?string
    {
        return config('bunny.library_id') ?: $this->getSettings()?->bunny_library_id;
    }

    /**
     * Get the CDN hostname
     */
    private function getCdnHostname(): ?string
    {
        return $this->validHostname(
            config('bunny.cdn_hostname') ?: $this->getSettings()?->bunny_cdn_hostname
        );
    }

    /**
     * Get the storage zone name
     */
    private function getStorageZoneName(): ?string
    {
        return config('bunny.storage_zone') ?: $this->getSettings()?->bunny_storage_zone_name;
    }

    /**
     * Get the storage password (API Key for storage)
     */
    private function getStoragePassword(): ?string
    {
        return config('bunny.storage_password')
            ?: $this->getSettings()?->bunny_storage_password_secret
            ?: $this->getSettings()?->bunny_storage_password;
    }

    private function getStorageCdnHostname(): ?string
    {
        return $this->validHostname(config('bunny.storage_cdn_hostname'));
    }

    private function validHostname(mixed $value): ?string
    {
        $hostname = strtolower(trim((string) $value));

        // Refuse schemes, ports, paths and user info before a configured value
        // can become the authority of a signed URL.
        return preg_match('/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $hostname) === 1
            ? $hostname
            : null;
    }

    private function getStorageSecurityKey(): ?string
    {
        $key = trim((string) config('bunny.storage_token_auth_key'));

        return $key !== '' ? $key : null;
    }

    private function getSecurityKey(): ?string
    {
        return config('bunny.token_auth_key')
            ?: $this->getSettings()?->bunny_security_key_secret;
    }

    /**
     * Create a new video in Bunny Stream and get upload URL
     *
     * @param string $title Video title
     * @return array|null Returns video data with guid and upload URL, or null on failure
     */
    public function createVideo(string $title): ?array
    {
        if (!$this->isEnabled()) {
            return null;
        }

        try {
            $response = $this->client()->withHeaders([
                'AccessKey' => $this->getApiKey(),
                'Content-Type' => 'application/json',
            ])->post("https://video.bunnycdn.com/library/{$this->getLibraryId()}/videos", [
                'title' => $title,
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'guid' => $data['guid'],
                    'title' => $data['title'],
                ];
            }

            Log::error('Bunny.net create video failed', [
                'status' => $response->status(),
                'response_fingerprint' => hash('sha256', $response->body()),
            ]);
            return null;
        } catch (Throwable $e) {
            Log::error('Bunny.net create video exception', [
                'exception' => $e::class,
            ]);
            return null;
        }
    }

    /**
     * Upload a video file to Bunny Stream
     *
     * @param string $videoGuid The video GUID from createVideo
     * @param UploadedFile $file The video file to upload
     * @return bool
     */
    public function uploadVideo(string $videoGuid, UploadedFile $file): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $stream = null;
        try {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the uploaded video stream.');
            }

            $response = Http::withHeaders([
                'AccessKey' => $this->getApiKey(),
            ])
                ->connectTimeout(max(1, (int) config('bunny.connect_timeout_seconds', 15)))
                ->timeout(max(1, (int) config('bunny.upload_timeout_seconds', 3600)))
                ->withBody(
                    $stream,
                    $file->getMimeType() ?: 'application/octet-stream'
                )->put("https://video.bunnycdn.com/library/{$this->getLibraryId()}/videos/{$videoGuid}");

            if ($response->successful()) {
                return true;
            }

            Log::error('Bunny.net upload video failed', [
                'status' => $response->status(),
                'response_fingerprint' => hash('sha256', $response->body()),
            ]);
            return false;
        } catch (Throwable $e) {
            Log::error('Bunny.net upload video exception', [
                'exception' => $e::class,
            ]);
            return false;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /**
     * Confirm that Bunny accepted the upload and the new remote video exists.
     */
    public function verifyVideoUpload(string $videoGuid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $response = $this->client()->withHeaders([
                'AccessKey' => $this->getApiKey(),
            ])->get("https://video.bunnycdn.com/library/{$this->getLibraryId()}/videos/{$videoGuid}");

            if (!$response->successful()) {
                Log::error('Bunny.net video verification failed', [
                    'video_guid' => $videoGuid,
                    'status' => $response->status(),
                ]);
                return false;
            }

            $details = (array) $response->json();

            return $this->remoteVideoIntegrityError($details, $videoGuid) === null;
        } catch (Throwable $exception) {
            Log::error('Bunny.net video verification exception', [
                'video_guid' => $videoGuid,
                'exception' => $exception::class,
            ]);
            return false;
        }
    }

    /** @return array{headers: array<string, string>, authorization_expires_at: string, authorization_expires_in_seconds: int} */
    public function directUploadAuthorization(string $videoGuid): array
    {
        $libraryId = trim((string) $this->getLibraryId());
        $apiKey = trim((string) $this->getApiKey());
        if (!$this->isEnabled() || $libraryId === '' || $apiKey === '') {
            throw new RuntimeException('Bunny Stream is not configured.');
        }
        // Bunny fixes the resumable upload resource lifetime from the
        // AuthorizationExpire used by its first POST. Renewing the signature
        // later authorizes requests but does not extend that resource lifetime.
        $expiresAt = time() + max(3600, min(
            86400,
            (int) config('bunny.direct_upload_signature_ttl_seconds', 86400)
        ));

        return [
            'headers' => [
                'AuthorizationSignature' => self::directUploadSignature(
                    $libraryId,
                    $apiKey,
                    $expiresAt,
                    $videoGuid
                ),
                'AuthorizationExpire' => (string) $expiresAt,
                'LibraryId' => $libraryId,
                'VideoId' => $videoGuid,
            ],
            'authorization_expires_at' => date('c', $expiresAt),
            'authorization_expires_in_seconds' => max(0, $expiresAt - time()),
        ];
    }

    public static function directUploadSignature(
        string $libraryId,
        string $streamApiKey,
        int $expiresAt,
        string $videoGuid
    ): string {
        return hash('sha256', $libraryId . $streamApiKey . $expiresAt . $videoGuid);
    }

    /** Confirm a direct TUS upload contains bytes, not merely a created GUID. */
    public function verifyDirectUpload(string $videoGuid, int $expectedBytes): bool
    {
        for ($attempt = 0; $attempt < 4; $attempt++) {
            $details = $this->getRemoteVideoDetails($videoGuid);
            $remoteGuid = strtolower(trim((string) ($details['guid'] ?? '')));
            $remoteBytes = (int) (
                $details['storageSize']
                ?? $details['storageSizeBytes']
                ?? $details['fileSize']
                ?? 0
            );
            $status = (int) ($details['status'] ?? -1);
            $tolerance = max(1024 * 1024, (int) ceil($expectedBytes * 0.01));
            $uploadComplete = ($remoteBytes > 0 && abs($remoteBytes - $expectedBytes) <= $tolerance)
                || self::providerVideoStatusConfirmsUpload($status);
            if ($remoteGuid !== ''
                && hash_equals(strtolower($videoGuid), $remoteGuid)
                && $uploadComplete
                && $this->remoteVideoIntegrityError($details, $videoGuid) === null
                && !self::providerVideoStatusIsFailure($status)) {
                return true;
            }
            if ($attempt < 3) {
                usleep(350000);
            }
        }

        return false;
    }

    /** Read-only provider probe used by Media Health; never publishes content. */
    public function getRemoteVideoDetails(string $videoGuid): ?array
    {
        $inspection = $this->inspectRemoteVideo($videoGuid);

        return $inspection['state'] === 'ok' ? $inspection['details'] : null;
    }

    /**
     * Preserve the difference between a transient control-plane outage and a
     * provider-confirmed missing object. Playback health must not keep a
     * deleted GUID marked ready merely because both cases used to return null.
     *
     * @return array{state:string,details:?array,http_status:?int}
     */
    public function inspectRemoteVideo(string $videoGuid): array
    {
        if (!$this->isEnabled()) {
            return ['state' => 'unconfigured', 'details' => null, 'http_status' => null];
        }
        if ($this->probeCircuitIsOpen()) {
            return ['state' => 'circuit_open', 'details' => null, 'http_status' => null];
        }
        try {
            $response = $this->client(10)
                ->withHeaders(['AccessKey' => $this->getApiKey()])
                ->get("https://video.bunnycdn.com/library/{$this->getLibraryId()}/videos/{$videoGuid}");
            if ($response->successful()) {
                $this->recordProbeSuccess();
                $details = (array) $response->json();
                $integrityError = $this->remoteVideoIntegrityError($details, $videoGuid);

                return [
                    'state' => $integrityError ?: 'ok',
                    'details' => $details,
                    'http_status' => $response->status(),
                ];
            }
            if (in_array($response->status(), [404, 410], true)) {
                return ['state' => 'not_found', 'details' => null, 'http_status' => $response->status()];
            }
            if (in_array($response->status(), [401, 403, 429], true) || $response->serverError()) {
                $this->recordProbeFailure('http_' . $response->status());
            }
            return [
                'state' => match (true) {
                    in_array($response->status(), [401, 403], true) => 'unauthorized',
                    $response->status() === 429 => 'rate_limited',
                    default => 'unavailable',
                },
                'details' => null,
                'http_status' => $response->status(),
            ];
        } catch (Throwable $exception) {
            $this->recordProbeFailure('connection');
            Log::warning('Bunny media probe failed', [
                'video_guid' => $videoGuid,
                'exception' => $exception::class,
            ]);
            return ['state' => 'unavailable', 'details' => null, 'http_status' => null];
        }
    }

    /** @return array<int, string> */
    public function findVideoGuidsByTitleMarker(string $marker): array
    {
        $marker = trim($marker);
        if (!$this->isEnabled() || $marker === '' || strlen($marker) > 100) return [];
        try {
            $response = $this->client(15)
                ->withHeaders(['AccessKey' => $this->getApiKey()])
                ->get("https://video.bunnycdn.com/library/{$this->getLibraryId()}/videos", [
                    'page' => 1,
                    'itemsPerPage' => 100,
                    'search' => $marker,
                ]);
            if (!$response->successful()) return [];
            return collect((array) data_get($response->json(), 'items', []))
                ->filter(fn ($item): bool => is_array($item)
                    && str_contains((string) ($item['title'] ?? ''), $marker))
                ->pluck('guid')
                ->map(fn ($guid): string => strtolower(trim((string) $guid)))
                ->filter(fn (string $guid): bool => preg_match('/^[a-f0-9-]{36}$/i', $guid) === 1)
                ->unique()
                ->values()
                ->all();
        } catch (Throwable $exception) {
            Log::warning('Bunny allocation marker lookup failed.', [
                'marker_hash' => hash('sha256', $marker),
                'exception' => $exception::class,
            ]);
            return [];
        }
    }

    /** Return a stable operational code without exposing provider payloads. */
    public function remoteVideoIntegrityError(array $details, string $expectedGuid): ?string
    {
        $remoteGuid = strtolower(trim((string) ($details['guid'] ?? '')));
        if ($remoteGuid === '' || !hash_equals(strtolower(trim($expectedGuid)), $remoteGuid)) {
            return 'provider_guid_mismatch';
        }

        $remoteLibraryId = trim((string) ($details['videoLibraryId'] ?? ''));
        $configuredLibraryId = trim((string) $this->getLibraryId());
        if ($remoteLibraryId === '' || $configuredLibraryId === ''
            || !hash_equals($configuredLibraryId, $remoteLibraryId)) {
            return 'provider_library_mismatch';
        }

        return null;
    }

    /** Bunny Stream status contract shared by upload and reconciliation. */
    public static function providerVideoStatusIsPlayable(int $status): bool
    {
        // Finished, ResolutionFinished, CaptionsGenerated and
        // TitleOrDescriptionGenerated all describe an already playable video.
        return in_array($status, [3, 4, 9, 10], true);
    }

    public static function providerVideoStatusIsFailure(int $status): bool
    {
        return in_array($status, [5, 8], true);
    }

    public static function providerVideoStatusConfirmsUpload(int $status): bool
    {
        // Processing/encoding and later successful events can happen only
        // after Bunny owns the uploaded bytes. PresignedUploadStarted (6)
        // deliberately does not prove completion.
        return in_array($status, [1, 2, 3, 4, 7, 9, 10], true);
    }

    private function probeCircuitIsOpen(): bool
    {
        try {
            return Cache::has(self::PROBE_CIRCUIT_KEY);
        } catch (Throwable) {
            return false;
        }
    }

    private function recordProbeFailure(string $reason): void
    {
        try {
            Cache::add(self::PROBE_FAILURE_KEY, 0, now()->addMinute());
            $failures = (int) Cache::increment(self::PROBE_FAILURE_KEY);
            if ($failures < max(2, (int) config('bunny.probe_circuit_failure_threshold', 3))) {
                return;
            }
            Cache::put(
                self::PROBE_CIRCUIT_KEY,
                ['reason' => $reason, 'opened_at' => now()->toIso8601String()],
                now()->addSeconds(max(15, (int) config('bunny.probe_circuit_open_seconds', 60)))
            );
        } catch (Throwable $exception) {
            Log::warning('Bunny probe circuit state could not be recorded.', [
                'reason' => $reason,
                'exception' => $exception::class,
            ]);
        }
    }

    private function recordProbeSuccess(): void
    {
        try {
            Cache::forget(self::PROBE_FAILURE_KEY);
            Cache::forget(self::PROBE_CIRCUIT_KEY);
        } catch (Throwable) {
            // Provider success must never be converted into application failure.
        }
    }

    /**
     * Upload and verify a remote video without publishing a database pointer.
     *
     * Controllers use this two-phase primitive so the expensive remote upload
     * happens before the short database transaction that atomically publishes
     * the lesson and its course-section pointer.
     */
    public function uploadVerifiedVideo(
        string $title,
        UploadedFile $file,
        ?Lesson $lesson = null,
        ?string $trackingMarker = null
    ): ?string {
        if (!$this->isEnabled()) {
            return null;
        }

        $marker = $trackingMarker && Str::isUuid($trackingMarker)
            ? strtolower($trackingMarker)
            : (string) Str::uuid();
        try {
            return Cache::lock('bunny-verified-upload:' . $marker, 3900)
                ->block(30, function () use ($marker, $title, $file, $lesson): ?string {
        $intent = BunnyVideoAllocationIntent::query()->firstOrCreate(
            ['marker' => $marker],
            ['video_guid' => null, 'status' => 'allocating', 'context' => 'verified_upload']
        );
        $remoteTitle = trim($title) !== '' ? trim($title) : 'Rokn lesson';
        $remoteTitle = mb_substr($remoteTitle, 0, 190) . " [rokn-upload:{$marker}]";
        $videoGuid = strtolower(trim((string) $intent->video_guid));
        if ($videoGuid === '') {
            $videoGuid = $this->findVideoGuidsByTitleMarker("[rokn-upload:{$marker}]")[0] ?? '';
        }
        if ($videoGuid === '') {
            $videoData = $this->createVideo($remoteTitle);
            $videoGuid = strtolower(trim((string) ($videoData['guid'] ?? '')));
        }
        if ($videoGuid === '') {
            return null;
        }

        BunnyVideoAllocationIntent::query()
            ->where('marker', $marker)
            ->update([
                'video_guid' => $videoGuid,
                'status' => 'allocated',
                'updated_at' => now(),
            ]);
        $cleanup = $this->queueVideoCleanup(
            $videoGuid,
            $lesson,
            'unpublished_upload',
            24,
            false
        );
        if (!$cleanup || $cleanup->last_attempt_at !== null || $cleanup->remote_deleted_at !== null) {
            // Do not upload bytes into a GUID whose deletion was already sent
            // to Bunny. The remote outcome may still be unknown here.
            $this->deleteVideo($videoGuid);
            return null;
        }

        if (!$this->uploadVideo($videoGuid, $file) || !$this->verifyVideoUpload($videoGuid)) {
            Log::warning('Unpublished Bunny upload retained for safe cleanup', [
                'lesson_id' => $lesson?->getKey(),
                'video_guid' => $videoGuid,
            ]);

            return null;
        }

        BunnyVideoAllocationIntent::query()
            ->where('marker', $marker)
            ->update(['status' => 'uploaded', 'updated_at' => now()]);

                    return $videoGuid;
                });
        } catch (LockTimeoutException) {
            Log::warning('Bunny verified upload is already active for this marker.', [
                'marker' => $marker,
            ]);
            return null;
        }
    }

    /**
     * Delete a video from Bunny Stream
     *
     * @param string $videoGuid The video GUID
     * @return bool
     */
    public function deleteVideo(string $videoGuid): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        try {
            $response = $this->client()->withHeaders([
                'AccessKey' => $this->getApiKey(),
            ])->delete("https://video.bunnycdn.com/library/{$this->getLibraryId()}/videos/{$videoGuid}");

            // A retry after a successful remote delete receives 404. Treat it
            // as success so cleanup remains idempotent across worker crashes.
            return $response->successful() || $response->status() === 404;
        } catch (Exception $e) {
            Log::error('Bunny.net delete video exception', [
                'exception' => $e::class,
            ]);
            return false;
        }
    }

    /**
     * Get video details from Bunny Stream
     *
     * @param string $videoGuid The video GUID
     * @return array|null
     */
    public function getVideo(string $videoGuid): ?array
    {
        if (!$this->playbackIsSecurelyConfigured()) {
            return null;
        }

        $cdnHostname = $this->getCdnHostname();
        if (!$cdnHostname) {
            return null;
        }

        $expiresAt = time() + $this->playbackUrlTtlSeconds();
        $path = "/{$videoGuid}/playlist.m3u8";
        $directoryPath = "/{$videoGuid}/";
        $url = "https://{$cdnHostname}{$path}";

        // HLS playlists load many relative segment files. A query-string token
        // only signs the manifest and leaves every segment unauthorised, so use
        // Bunny's directory/path token format whenever CDN token auth is enabled.
        if ($this->getSecurityKey()) {
            $url = $this->generateSignedDirectoryUrl(
                $cdnHostname,
                $path,
                $directoryPath,
                $expiresAt
            );
        }

        return [
            'url' => $url,
            'type' => 'hls',
            'expires_at' => date('c', $expiresAt),
        ];
    }

    /** Issue a thumbnail URL in the same protected Stream directory. */
    public function getVideoThumbnail(string $videoGuid, string $fileName): ?array
    {
        if (!$this->playbackIsSecurelyConfigured()) {
            return null;
        }
        $fileName = basename(trim($fileName));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $fileName) !== 1) {
            return null;
        }

        $expiresAt = time() + $this->playbackUrlTtlSeconds();
        $directoryPath = "/{$videoGuid}/";
        $filePath = $directoryPath . $fileName;

        return [
            'url' => $this->generateSignedDirectoryUrl(
                (string) $this->getCdnHostname(),
                $filePath,
                $directoryPath,
                $expiresAt
            ),
            'expires_at' => date('c', $expiresAt),
        ];
    }

    /**
     * Generate a signed URL for video playback (iframe embed)
     *
     * @param string $videoGuid The video GUID
     * @param int $expiresInSeconds URL expiration time in seconds (default 2 hours)
     * @return array|null Returns array with url and expires_at, or null on failure
     */
    public function getSignedEmbedUrl(string $videoGuid, int $expiresInSeconds = 7200): ?array
    {
        if (!$this->playbackIsSecurelyConfigured()) {
            return null;
        }

        $libraryId = $this->getLibraryId();
        // Calculate expiration timestamp
        $expiresAt = time() + $expiresInSeconds;

        // Embed-view authentication is intentionally different from Bunny
        // CDN authentication: SHA256_HEX(token key + video id + expiry).
        $securityKey = $this->getSecurityKey();
        $token = $securityKey
            ? hash('sha256', $securityKey . $videoGuid . $expiresAt)
            : '';

        // Build the embed URL
        $embedUrl = "https://iframe.mediadelivery.net/embed/{$libraryId}/{$videoGuid}";
        $signedUrl = $embedUrl . ($token ? "?token={$token}&expires={$expiresAt}" : '');

        return [
            'url' => $signedUrl,
            'expires_at' => date('c', $expiresAt), // ISO 8601 format
        ];
    }

    /**
     * Generate a signed URL for direct HLS playback
     *
     * @param string $videoGuid The video GUID
     * @param int $expiresInSeconds URL expiration time in seconds (default 2 hours)
     * @return array|null Returns array with url and expires_at, or null on failure
     */
    public function getSignedPlayUrl(string $videoGuid, int $expiresInSeconds = 7200): ?array
    {
        if (!$this->playbackIsSecurelyConfigured()) {
            return null;
        }

        $cdnHostname = $this->getCdnHostname();
        if (!$cdnHostname) {
            return null;
        }

        $expiresAt = time() + max(600, min(7200, $expiresInSeconds));
        $path = "/{$videoGuid}/playlist.m3u8";
        $directoryPath = "/{$videoGuid}/";
        $signedUrl = $this->getSecurityKey()
            ? $this->generateSignedDirectoryUrl($cdnHostname, $path, $directoryPath, $expiresAt)
            : "https://{$cdnHostname}{$path}";

        return [
            'url' => $signedUrl,
            'expires_at' => date('c', $expiresAt),
        ];
    }

    /**
     * Generate signed token for Bunny CDN URLs
     *
     * @param string $videoGuid
     * @param int $expiresAt
     * @return string
     */
    private function generateSignedToken(string $path, int $expiresAt, string $signingData = ''): string
    {
        $securityKey = $this->getSecurityKey();
        if (!$securityKey) {
            return '';
        }

        return self::advancedToken($securityKey, $path, $expiresAt, $signingData);
    }

    private function playbackUrlTtlSeconds(): int
    {
        return max(600, min(7200, (int) config('playback.signed_url_ttl_seconds', 3600)));
    }

    /**
     * Bunny Advanced Token Authentication reference implementation.
     * Keeping the pure token primitive public makes the production signer
     * testable against a fixed official-format vector without network calls.
     */
    public static function advancedToken(
        string $securityKey,
        string $signaturePath,
        int $expiresAt,
        string $signingData = ''
    ): string {
        // Bunny's current signer uses an HMAC over the exact signature path,
        // expiry and sorted signing data. The HS256 prefix is part of the
        // wire token; omitting it produces a valid-looking but rejected URL.
        $digest = hash_hmac(
            'sha256',
            $signaturePath . $expiresAt . $signingData,
            $securityKey,
            true
        );

        return 'HS256-' . rtrim(strtr(base64_encode($digest), '+/', '-_'), '=');
    }

    private function generateSignedDirectoryUrl(
        string $hostname,
        string $filePath,
        string $directoryPath,
        int $expiresAt
    ): string {
        $signingData = 'token_path=' . $directoryPath;
        $token = $this->generateSignedToken($directoryPath, $expiresAt, $signingData);
        if ($token === '') {
            throw new RuntimeException('Bunny playback signing is not configured.');
        }

        return sprintf(
            'https://%s/bcdn_token=%s&token_path=%s&expires=%d%s',
            $hostname,
            $token,
            rawurlencode($directoryPath),
            $expiresAt,
            $filePath
        );
    }

    private function playbackIsSecurelyConfigured(): bool
    {
        $ready = $this->isEnabled()
            && (bool) $this->getCdnHostname()
            && (bool) $this->getSecurityKey();
        if (!$ready) {
            Log::critical('Bunny playback refused because signed delivery is incomplete.');
        }

        return $ready;
    }

    public function queueVideoCleanup(
        string $videoGuid,
        ?Lesson $lesson,
        string $reason,
        int $delayHours,
        bool $requiresReview = true
    ): ?BunnyVideoCleanupCandidate {
        $videoGuid = trim($videoGuid);
        if ($videoGuid === '') {
            return null;
        }

        try {
            return BunnyVideoCleanupCandidate::query()->updateOrCreate(
                ['video_guid' => $videoGuid],
                [
                    'lesson_id' => $lesson && $lesson->exists ? $lesson->getKey() : null,
                    'reason' => $reason,
                    'requires_review' => $requiresReview,
                    'eligible_after' => now()->addHours(max(1, $delayHours)),
                    'reviewed_at' => $requiresReview ? null : now(),
                    'reviewed_by' => null,
                    'remote_deleted_at' => null,
                    'last_error' => null,
                ]
            );
        } catch (Throwable $exception) {
            Log::error('Unable to record Bunny cleanup candidate', [
                'video_guid' => $videoGuid,
                'lesson_id' => $lesson?->getKey(),
                'exception' => $exception::class,
            ]);

            return null;
        }
    }

    /**
     * Consume the unpublished-object guard in the same transaction that adds
     * the live reference. A cleanup worker that claimed the GUID first wins;
     * publishing an object with an uncertain/deleting provider state is denied.
     */
    public function consumeVideoCleanupCandidate(string $videoGuid): void
    {
        $videoGuid = strtolower(trim($videoGuid));
        $candidate = BunnyVideoCleanupCandidate::query()
            ->whereRaw('LOWER(video_guid) = ?', [$videoGuid])
            ->whereNull('remote_deleted_at')
            ->whereNull('last_attempt_at')
            ->lockForUpdate()
            ->first();
        if (!$candidate) {
            throw new RuntimeException('The staged Bunny video is no longer safe to publish.');
        }

        $candidate->delete();
    }

    /**
     * Get video data for a lesson, including signed URLs if using Bunny
     *
     * @param Lesson $lesson
     * @return array
     */
    public function getVideoDataForLesson(Lesson $lesson): array
    {
        $data = [
            'video_source_type' => 'bunny',
            'video_link' => null,
            'bunny_video_url' => null,
            'bunny_video_expires_at' => null,
        ];

        if ($lesson->video_source_type === 'bunny' && !empty($lesson->bunny_video_id)) {
            $state = $lesson->relationLoaded('mediaState')
                ? $lesson->mediaState
                : $lesson->mediaState()->first();
            // Public previews used to bypass the playback control plane and
            // receive a signed URL while Bunny was still processing, missing,
            // or quarantined. The player then surfaced Bunny's raw domain
            // error. Only a coherently reconciled generation is playable.
            if (
                !$state
                || (string) $state->provider_media_id !== (string) $lesson->bunny_video_id
                || $state->status !== 'ready'
                || $state->last_reconciled_at === null
                || $state->integrity_status === 'quarantined'
            ) {
                return $data;
            }
            // Get signed embed URL for Bunny video
            $signedUrl = $this->getVideo($lesson->bunny_video_id);
            if ($signedUrl) {
                $data['bunny_video_url'] = $signedUrl['url'];
                $data['bunny_video_expires_at'] = $signedUrl['expires_at'];
            }
        }

        return $data;
    }

    /**
     * Upload a file to Bunny Storage
     *
     * @param UploadedFile $file
     * @param string $folder
     * @return string|null Returns the file path/URL on success, or null on failure
     */
    public function uploadFileToStorage(
        \Illuminate\Http\UploadedFile $file,
        string $folder = 'general',
        ?string $objectKey = null,
        ?string $cleanupReason = 'unpublished_storage_upload'
    ): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $storageZone = $this->getStorageZoneName();
        $password = $this->getStoragePassword();
        if (!$storageZone || !$password) {
            Log::error('Bunny Storage not configured');
            return null;
        }

        $folder = trim(str_replace('\\', '/', $folder), '/');
        if ($folder === '' || preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9_-]+$#', $folder) !== 1) {
            Log::warning('Rejected unsafe Bunny Storage folder.');
            return null;
        }

        $mimeType = strtolower(trim((string) ($file->getMimeType() ?: 'application/octet-stream')));
        $extension = $this->extensionForMimeType($mimeType);
        // A cryptographically random, server-owned object key prevents
        // same-second collisions and never exposes or trusts the client name.
        $fileName = ($objectKey && Str::isUuid($objectKey)
            ? strtolower($objectKey)
            : Str::uuid()->toString()) . '.' . $extension;
        $path = "{$folder}/{$fileName}";
        if ($cleanupReason !== null) {
            // The deterministic path and cleanup row are durable before the
            // external PUT, closing the process-death gap after Bunny accepts
            // bytes but before the application publishes a reference.
            if (!$this->queueStorageCleanup($path, $cleanupReason, 24 * 60)) {
                // A cleanup worker has already claimed this deterministic key.
                // Never overwrite an object whose delete outcome is uncertain.
                return null;
            }
        }
        $stream = null;
        try {
            $stream = fopen($file->getRealPath(), 'rb');
            if ($stream === false) {
                throw new RuntimeException('Unable to open the uploaded file stream.');
            }

            $response = Http::withHeaders([
                'AccessKey' => $password,
                'Content-Type' => $mimeType,
            ])
                ->connectTimeout(max(1, (int) config('bunny.connect_timeout_seconds', 15)))
                ->timeout(max(1, (int) config('bunny.upload_timeout_seconds', 3600)))
                ->withBody(
                    $stream,
                    $mimeType
                )->put("https://storage.bunnycdn.com/{$storageZone}/{$path}");
            if ($response->successful()) {
                return $path;
            }

            Log::error('Bunny Storage upload failed', [
                'status' => $response->status(),
                'response_fingerprint' => hash('sha256', $response->body()),
            ]);
            return null;
        } catch (Throwable $e) {
            Log::error('Bunny Storage upload exception', [
                'exception' => $e::class,
            ]);
            return null;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    private function extensionForMimeType(string $mimeType): string
    {
        return match ($mimeType) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'application/pdf' => 'pdf',
            'application/zip', 'application/x-zip-compressed' => 'zip',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
            'text/plain' => 'txt',
            default => 'bin',
        };
    }

    /**
     * Delete a file from Bunny Storage
     *
     * @param string $fileUrl Full URL of the file
     * @return bool
     */
    public function deleteFileFromStorage(string $fileUrl): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        $storageZone = $this->getStorageZoneName();
        $password = $this->getStoragePassword();
        $cdnHostname = $this->getStorageCdnHostname();

        if (!$storageZone || !$password) {
            return false;
        }

        // Extract path from URL
        $path = $this->normalizeStorageObjectPath($fileUrl);
        if ($path === null) {
            Log::warning('Rejected an invalid Bunny Storage deletion path.');
            return false;
        }

        try {
            $response = $this->client()->withHeaders([
                'AccessKey' => $password,
            ])->delete("https://storage.bunnycdn.com/{$storageZone}/{$path}");

            // Missing already means the requested end state and makes retries
            // safe if a worker died after Bunny deleted the object.
            return $response->successful() || $response->status() === 404;
        } catch (Exception $e) {
            Log::error('Bunny Storage delete exception', [
                'exception' => $e::class,
            ]);
            return false;
        }
    }

    /**
     * Generate a signed URL for a BunnyCDN private storage file
     *
     * @param string $filePath    Path to the file in storage (must start with /)
     * @param string $accessKey   Storage Zone Access Key
     * @param int    $ttl         Time-to-live in seconds (default 3600)
     * @return string             Signed URL
     */
    public function generateBunnySignedUrl($filePath, $ttl = 3600): ?string
    {
        $expires = time() + max(60, (int) $ttl);
        $securityKey = $this->getStorageSecurityKey();
        $hostname = $this->getStorageCdnHostname();
        if (!$hostname || !$securityKey) {
            Log::critical('Bunny private asset signing refused because delivery configuration is incomplete.');
            return null;
        }

        $objectPath = $this->normalizeStorageObjectPath((string) $filePath);
        if ($objectPath === null) {
            return null;
        }
        $path = '/' . $objectPath;
        $token = self::advancedToken($securityKey, $path, $expires);

        return rtrim("https://{$hostname}", '/') . $path
            . '?token=' . rawurlencode($token)
            . '&expires=' . $expires;
    }

    public function queueStorageCleanup(string $path, string $reason, int $delayMinutes = 0): bool
    {
        $normalized = $this->normalizeStorageObjectPath($path);
        if ($normalized === null) return false;

        $attributes = [
            'path' => $normalized,
            'reason' => mb_substr($reason, 0, 100),
            'eligible_after' => now()->addMinutes(max(0, $delayMinutes)),
            'completed_at' => null,
            'attempts' => 0,
            'last_attempt_at' => null,
            'last_error' => null,
        ];
        if (Schema::hasColumn('bunny_storage_cleanup_candidates', 'quarantined_at')) {
            $attributes['quarantined_at'] = null;
        }

        return DB::transaction(function () use ($normalized, $attributes): bool {
            $pathHash = hash('sha256', $normalized);
            BunnyStorageCleanupCandidate::query()->firstOrCreate(
                ['path_hash' => $pathHash],
                $attributes
            );
            $candidate = BunnyStorageCleanupCandidate::query()
                ->where('path_hash', $pathHash)
                ->lockForUpdate()
                ->firstOrFail();

            // Once a delete request has left our process its result can be
            // unknown. Reusing the same deterministic object key would let a
            // late DELETE erase newly uploaded bytes.
            if ($candidate->completed_at === null && $candidate->last_attempt_at !== null) {
                return false;
            }

            $candidate->forceFill($attributes)->save();
            return true;
        }, 3);
    }

    /** Consume a staged Storage cleanup row atomically with its live reference. */
    public function consumeStorageCleanupCandidate(string $path): void
    {
        $normalized = $this->normalizeStorageObjectPath($path);
        if ($normalized === null) {
            throw new RuntimeException('The staged Bunny Storage path is invalid.');
        }
        $candidate = BunnyStorageCleanupCandidate::query()
            ->where('path_hash', hash('sha256', $normalized))
            ->whereNull('completed_at')
            ->whereNull('last_attempt_at')
            ->lockForUpdate()
            ->first();
        if (!$candidate) {
            throw new RuntimeException('The staged Bunny Storage object is no longer safe to publish.');
        }

        $candidate->delete();
    }

    private function normalizeStorageObjectPath(string $value): ?string
    {
        $value = trim($value);
        if ($value === '' || str_contains($value, "\0") || str_contains($value, '\\')) {
            return null;
        }

        if (filter_var($value, FILTER_VALIDATE_URL)) {
            $parts = parse_url($value);
            $expectedHost = $this->getStorageCdnHostname();
            if (
                ($parts['scheme'] ?? null) !== 'https'
                || !$expectedHost
                || strtolower((string) ($parts['host'] ?? '')) !== $expectedHost
                || isset($parts['user'])
                || isset($parts['pass'])
                || isset($parts['query'])
                || isset($parts['fragment'])
            ) {
                return null;
            }
            $value = (string) ($parts['path'] ?? '');
        }

        $path = ltrim(rawurldecode($value), '/');

        return preg_match('#^(?:[A-Za-z0-9_-]+/)*[A-Za-z0-9][A-Za-z0-9._-]*$#', $path) === 1
            ? $path
            : null;
    }

    private function client(int $timeoutSeconds = 30): PendingRequest
    {
        return Http::connectTimeout(max(1, (int) config('bunny.connect_timeout_seconds', 15)))
            ->timeout(max(1, $timeoutSeconds));
    }

    /**
     * Test connection to Bunny.net API
     *
     * @param string $apiKey
     * @param string $libraryId
     * @return array
     */
    public static function testConnection(string $apiKey, string $libraryId): array
    {
        try {
            $response = Http::connectTimeout(max(1, (int) config('bunny.connect_timeout_seconds', 15)))
                ->timeout(max(1, (int) config('bunny.request_timeout_seconds', 30)))
                ->withHeaders(['AccessKey' => $apiKey])
                ->get("https://video.bunnycdn.com/library/{$libraryId}");

            if ($response->successful()) {
                $data = $response->json();
                return [
                    'success' => true,
                    'message' => 'تم الاتصال بنجاح',
                    'library_name' => $data['Name'] ?? 'Unknown',
                ];
            }

            return [
                'success' => false,
                'message' => 'فشل الاتصال: ' . $response->status(),
            ];
        } catch (Exception) {
            return [
                'success' => false,
                'message' => 'تعذر الاتصال بخدمة الفيديو.',
            ];
        }
    }
}

