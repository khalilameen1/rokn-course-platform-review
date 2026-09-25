<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;

/** Resolves provider and delivery configuration; never performs remote IO. */
class BunnyConfiguration
{
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
    public function getApiKey(): ?string
    {
        return config('bunny.stream_api_key')
            ?: $this->getSettings()?->bunny_api_key_secret
            ?: $this->getSettings()?->bunny_api_key;
    }

    /**
     * Get the library ID
     */
    public function getLibraryId(): ?string
    {
        return config('bunny.library_id') ?: $this->getSettings()?->bunny_library_id;
    }

    /**
     * Get the CDN hostname
     */
    public function getCdnHostname(): ?string
    {
        return $this->validHostname(
            config('bunny.cdn_hostname') ?: $this->getSettings()?->bunny_cdn_hostname
        );
    }

    /**
     * Get the storage zone name
     */
    public function getStorageZoneName(): ?string
    {
        return config('bunny.storage_zone') ?: $this->getSettings()?->bunny_storage_zone_name;
    }

    /**
     * Get the storage password (API Key for storage)
     */
    public function getStoragePassword(): ?string
    {
        return config('bunny.storage_password')
            ?: $this->getSettings()?->bunny_storage_password_secret
            ?: $this->getSettings()?->bunny_storage_password;
    }

    public function getStorageCdnHostname(): ?string
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

    public function getStorageSecurityKey(): ?string
    {
        $key = trim((string) config('bunny.storage_token_auth_key'));

        return $key !== '' ? $key : null;
    }

    public function getSecurityKey(): ?string
    {
        return config('bunny.token_auth_key')
            ?: $this->getSettings()?->bunny_security_key_secret;
    }
}
