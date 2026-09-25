<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\BunnyStoragePath;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/** Protected delivery URLs only; no provider requests or media lifecycle writes. */
class BunnyDeliveryService
{
    public function __construct(private readonly BunnyConfiguration $configuration)
    {
    }

    /**
     * Build the protected HLS URL using the configured or requested lifetime
     *
     * @param string $videoGuid The video GUID
     * @return array|null
     */
    public function videoPlayback(string $videoGuid, ?int $expiresInSeconds = null): ?array
    {
        if (!$this->playbackIsSecurelyConfigured()) {
            return null;
        }

        $cdnHostname = $this->configuration->getCdnHostname();
        if (!$cdnHostname) {
            return null;
        }

        $expiresAt = time() + $this->playbackLifetime($expiresInSeconds);
        $path = "/{$videoGuid}/playlist.m3u8";
        $directoryPath = "/{$videoGuid}/";
        // HLS playlists load many relative segment files. A query-string token
        // only signs the manifest and leaves every segment unauthorised.
        $url = $this->generateSignedDirectoryUrl(
            $cdnHostname,
            $path,
            $directoryPath,
            $expiresAt
        );

        return [
            'url' => $url,
            'type' => 'hls',
            'expires_at' => date('c', $expiresAt),
        ];
    }

    /** Issue a thumbnail URL in the same protected Stream directory. */
    public function videoThumbnail(string $videoGuid, string $fileName): ?array
    {
        if (!$this->playbackIsSecurelyConfigured()) {
            return null;
        }
        $fileName = basename(trim($fileName));
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,190}$/', $fileName) !== 1) {
            return null;
        }

        $expiresAt = time() + $this->playbackLifetime();
        $directoryPath = "/{$videoGuid}/";
        $filePath = $directoryPath . $fileName;

        return [
            'url' => $this->generateSignedDirectoryUrl(
                (string) $this->configuration->getCdnHostname(),
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
    public function videoEmbed(string $videoGuid, int $expiresInSeconds = 7200): ?array
    {
        if (!$this->playbackIsSecurelyConfigured()) {
            return null;
        }

        $libraryId = $this->configuration->getLibraryId();
        $expiresAt = time() + $expiresInSeconds;

        // Embed-view authentication is intentionally different from Bunny
        // CDN authentication: SHA256_HEX(token key + video id + expiry).
        $securityKey = $this->configuration->getSecurityKey();
        $token = hash('sha256', $securityKey . $videoGuid . $expiresAt);

        // Build the embed URL
        $embedUrl = "https://iframe.mediadelivery.net/embed/{$libraryId}/{$videoGuid}";
        $signedUrl = $embedUrl . "?token={$token}&expires={$expiresAt}";

        return [
            'url' => $signedUrl,
            'expires_at' => date('c', $expiresAt), // ISO 8601 format
        ];
    }

    private function playbackLifetime(?int $requestedSeconds = null): int
    {
        return max(600, min(7200, $requestedSeconds
            ?? (int) config('playback.signed_url_ttl_seconds', 3600)));
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
        $securityKey = $this->configuration->getSecurityKey();
        if (!$securityKey) {
            throw new RuntimeException('Bunny playback signing is not configured.');
        }
        $token = self::advancedToken($securityKey, $directoryPath, $expiresAt, $signingData);

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
        $ready = $this->configuration->isEnabled()
            && (bool) $this->configuration->getCdnHostname()
            && (bool) $this->configuration->getSecurityKey();
        if (!$ready) {
            Log::critical('Bunny playback refused because signed delivery is incomplete.');
        }

        return $ready;
    }

    /** Sign a normalized private Storage path with its own key, never the Stream key. */
    public function storageUrl(string $filePath, int $ttl = 3600): ?string
    {
        $expires = time() + max(60, (int) $ttl);
        $securityKey = $this->configuration->getStorageSecurityKey();
        $hostname = $this->configuration->getStorageCdnHostname();
        if (!$hostname || !$securityKey) {
            Log::critical('Bunny private asset signing refused because delivery configuration is incomplete.');
            return null;
        }

        $objectPath = BunnyStoragePath::normalize((string) $filePath, $this->configuration->getStorageCdnHostname());
        if ($objectPath === null) {
            return null;
        }
        $path = '/' . $objectPath;
        $token = self::advancedToken($securityKey, $path, $expires);

        return rtrim("https://{$hostname}", '/') . $path
            . '?token=' . rawurlencode($token)
            . '&expires=' . $expires;
    }
}
