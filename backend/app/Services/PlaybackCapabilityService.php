<?php

declare(strict_types=1);

namespace App\Services;

final class PlaybackCapabilityService
{
    /**
     * Keep this contract deliberately small. It is a playback compatibility
     * summary, not a device fingerprint, and must never contain an IP address,
     * advertising identifier or hardware identifier.
     *
     * @return array<string, string>
     */
    public static function validationRules(string $prefix = 'client_capabilities'): array
    {
        return [
            $prefix => 'nullable|array',
            "{$prefix}.app_version" => 'nullable|string|max:32',
            "{$prefix}.os" => 'nullable|string|in:android,ios,other',
            "{$prefix}.os_version" => 'nullable|string|max:32',
            "{$prefix}.supports_hls" => 'nullable|boolean',
            "{$prefix}.supports_dash" => 'nullable|boolean',
            "{$prefix}.supports_mp4" => 'nullable|boolean',
            "{$prefix}.max_height" => 'nullable|integer|in:360,480,720,1080',
            "{$prefix}.max_bitrate_kbps" => 'nullable|integer|min:128|max:50000',
            "{$prefix}.data_saver" => 'nullable|boolean',
            "{$prefix}.connection" => 'nullable|string|in:wifi,cellular,ethernet,unknown',
            // Accepted as a compatibility alias while clients converge on
            // `connection`; only the normalized value is persisted.
            "{$prefix}.network_type" => 'nullable|string|in:wifi,cellular,ethernet,unknown',
        ];
    }

    /** @return array<string, bool|int|string> */
    public function normalize(?array $capabilities): array
    {
        if (! $capabilities) {
            return [];
        }

        $normalized = [];
        $this->copyString($normalized, $capabilities, 'app_version', 32);
        $this->copyEnum($normalized, $capabilities, 'os', ['android', 'ios', 'other']);
        $this->copyString($normalized, $capabilities, 'os_version', 32);
        foreach (['supports_hls', 'supports_dash', 'supports_mp4', 'data_saver'] as $key) {
            if (array_key_exists($key, $capabilities)) {
                $normalized[$key] = filter_var($capabilities[$key], FILTER_VALIDATE_BOOL);
            }
        }

        if (isset($capabilities['max_height']) && in_array((int) $capabilities['max_height'], [360, 480, 720, 1080], true)) {
            $normalized['max_height'] = (int) $capabilities['max_height'];
        }
        if (isset($capabilities['max_bitrate_kbps'])) {
            $normalized['max_bitrate_kbps'] = max(128, min(50000, (int) $capabilities['max_bitrate_kbps']));
        }

        $connection = $capabilities['connection'] ?? $capabilities['network_type'] ?? null;
        if (is_string($connection) && in_array($connection, ['wifi', 'cellular', 'ethernet', 'unknown'], true)) {
            $normalized['connection'] = $connection;
        }

        return $normalized;
    }

    /** @return array<string, bool|int|string|null> */
    public function networkPolicy(array $capabilities): array
    {
        $connection = (string) ($capabilities['connection'] ?? 'unknown');
        $dataSaver = (bool) ($capabilities['data_saver'] ?? false);
        $deviceHeight = isset($capabilities['max_height']) ? (int) $capabilities['max_height'] : null;
        $deviceBitrate = isset($capabilities['max_bitrate_kbps'])
            ? (int) $capabilities['max_bitrate_kbps']
            : null;

        if ($dataSaver) {
            $mode = 'data_saver';
            $policyHeight = (int) config('playback.data_saver_max_height', 480);
            $policyBitrate = (int) config('playback.data_saver_max_bitrate_kbps', 1200);
        } elseif ($connection === 'cellular') {
            $mode = 'cellular_balanced';
            $policyHeight = (int) config('playback.cellular_max_height', 720);
            $policyBitrate = (int) config('playback.cellular_max_bitrate_kbps', 2500);
        } else {
            $mode = 'adaptive';
            $policyHeight = 1080;
            $policyBitrate = 50000;
        }

        $maxHeight = min($policyHeight, $deviceHeight ?? $policyHeight);
        $maxBitrate = min($policyBitrate, $deviceBitrate ?? $policyBitrate);
        $supportsHls = $capabilities['supports_hls'] ?? null;

        return [
            'mode' => $mode,
            'connection' => $connection,
            'adaptive' => true,
            'required_protocol' => 'hls',
            'compatible' => $supportsHls !== false,
            'max_height' => $maxHeight,
            'max_bitrate_kbps' => $maxBitrate,
            'preferred_quality' => 'auto',
        ];
    }

    public function playbackReason(array $capabilities, array $policy): string
    {
        if ($capabilities === []) {
            return 'adaptive_hls_capabilities_unknown';
        }
        if (($capabilities['supports_hls'] ?? null) === false) {
            return 'adaptive_hls_support_unconfirmed';
        }
        if (($policy['mode'] ?? null) === 'data_saver') {
            return 'adaptive_hls_data_saver';
        }
        if (($policy['mode'] ?? null) === 'cellular_balanced') {
            return 'adaptive_hls_cellular';
        }
        if (($policy['max_height'] ?? 1080) < 1080 || ($policy['max_bitrate_kbps'] ?? 50000) < 50000) {
            return 'adaptive_hls_device_limited';
        }

        return 'adaptive_hls_default';
    }

    /** @return array<string, mixed> */
    public function sessionAttributes(array $capabilities, ?string $client, string $playbackReason): array
    {
        $client = is_string($client) ? strtolower(trim($client)) : '';
        $client = (string) preg_replace('/[^a-z0-9_.-]/', '-', $client);

        return [
            'client_name' => $client !== '' ? mb_substr($client, 0, 24) : null,
            'app_version' => $capabilities['app_version'] ?? null,
            'os_family' => $capabilities['os'] ?? 'other',
            'os_version' => $capabilities['os_version'] ?? null,
            'connection_type' => $capabilities['connection'] ?? 'unknown',
            'client_capabilities' => $capabilities ?: null,
            'playback_reason' => $playbackReason,
        ];
    }

    /** @param array<string, bool|int|string> $target */
    private function copyString(array &$target, array $source, string $key, int $length): void
    {
        if (! isset($source[$key]) || ! is_scalar($source[$key])) {
            return;
        }

        $value = trim((string) $source[$key]);
        if (in_array($key, ['app_version', 'os_version'], true)) {
            $value = (string) preg_replace('/[^a-zA-Z0-9._+\-]/', '', $value);
        }
        if ($value !== '') {
            $target[$key] = mb_substr($value, 0, $length);
        }
    }

    /** @param array<string, bool|int|string> $target */
    private function copyEnum(array &$target, array $source, string $key, array $allowed): void
    {
        if (isset($source[$key]) && is_string($source[$key]) && in_array($source[$key], $allowed, true)) {
            $target[$key] = $source[$key];
        }
    }
}
