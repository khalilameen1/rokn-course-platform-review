<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\BunnyStoragePath;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Exception;
use RuntimeException;
use Throwable;

/** Remote Stream/Storage operations. Delivery URLs and local lifecycle records have separate owners. */
class BunnyService
{
    public function __construct(
        private readonly BunnyConfiguration $configuration,
        private readonly BunnyMediaRegistry $mediaRegistry
    ) {}

    public const PROBE_CIRCUIT_KEY = 'bunny:probe-circuit-open';

    private const PROBE_FAILURE_KEY = 'bunny:probe-circuit-failures';

    public function isEnabled(): bool
    {
        return $this->configuration->isEnabled();
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
                'AccessKey' => $this->configuration->getApiKey(),
                'Content-Type' => 'application/json',
            ])->post("https://video.bunnycdn.com/library/{$this->configuration->getLibraryId()}/videos", [
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

    /** @return array{headers: array<string, string>, authorization_expires_at: string, authorization_expires_in_seconds: int} */
    public function directUploadAuthorization(string $videoGuid): array
    {
        $libraryId = trim((string) $this->configuration->getLibraryId());
        $apiKey = trim((string) $this->configuration->getApiKey());
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
                ->withHeaders(['AccessKey' => $this->configuration->getApiKey()])
                ->get("https://video.bunnycdn.com/library/{$this->configuration->getLibraryId()}/videos/{$videoGuid}");
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
                ->withHeaders(['AccessKey' => $this->configuration->getApiKey()])
                ->get("https://video.bunnycdn.com/library/{$this->configuration->getLibraryId()}/videos", [
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
        $configuredLibraryId = trim((string) $this->configuration->getLibraryId());
        if ($remoteLibraryId === '' || $configuredLibraryId === ''
            || !hash_equals($configuredLibraryId, $remoteLibraryId)) {
            return 'provider_library_mismatch';
        }

        return null;
    }

    /**
     * GET /videos/{id} VideoModelStatus, not webhook notification Status.
     * https://github.com/BunnyWay/bunny-stream-android/blob/main/bunny-stream-api/src/main/java/net/bunny/api/model/VideoModelStatus.kt
     * Webhooks use a separate event enum (for example, event 3 is Finished,
     * whereas video status 3 is Transcoding) and only trigger a fresh GET.
     */
    public static function providerVideoStatusIsPlayable(int $status): bool
    {
        // Finished. JitPlaylistsCreated (8) is not a failure, but does not
        // establish playback compatibility with our custom HLS player.
        return $status === 4;
    }

    public static function providerVideoStatusIsFailure(int $status): bool
    {
        // Error and UploadFailed in the GET video model.
        return in_array($status, [5, 6], true);
    }

    public static function providerVideoStatusConfirmsUpload(int $status): bool
    {
        // Uploaded, Processing, Transcoding, Finished, JitSegmenting and
        // JitPlaylistsCreated prove receipt, not necessarily playability.
        return in_array($status, [1, 2, 3, 4, 7, 8], true);
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
                'AccessKey' => $this->configuration->getApiKey(),
            ])->delete("https://video.bunnycdn.com/library/{$this->configuration->getLibraryId()}/videos/{$videoGuid}");

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
     * Upload a file to Bunny Storage
     *
     * @param UploadedFile $file
     * @param string $folder
     * @return string|null Returns the file path/URL on success, or null on failure
     */
    public function uploadFileToStorage(
        UploadedFile $file,
        string $folder = 'general',
        ?string $objectKey = null,
        ?string $cleanupReason = 'unpublished_storage_upload'
    ): ?string
    {
        if (!$this->isEnabled()) {
            return null;
        }

        $storageZone = $this->configuration->getStorageZoneName();
        $password = $this->configuration->getStoragePassword();
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
            if (!$this->mediaRegistry->queueStorageCleanup($path, $cleanupReason, 24 * 60)) {
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

        $storageZone = $this->configuration->getStorageZoneName();
        $password = $this->configuration->getStoragePassword();

        if (!$storageZone || !$password) {
            return false;
        }

        // Extract path from URL
        $path = BunnyStoragePath::normalize($fileUrl, $this->configuration->getStorageCdnHostname());
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

