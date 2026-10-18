<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PlaybackCapabilityService;
use Tests\TestCase;

final class PlaybackCapabilityServiceTest extends TestCase
{
    public function test_it_keeps_only_a_small_non_identifying_capability_summary(): void
    {
        $service = new PlaybackCapabilityService;
        $normalized = $service->normalize([
            'app_version' => '1.4.0 build 12',
            'os' => 'android',
            'os_version' => '15',
            'supports_hls' => true,
            'max_height' => 1080,
            'max_bitrate_kbps' => 8500,
            'data_saver' => true,
            'network_type' => 'cellular',
            'device_id' => 'must-not-survive',
            'ip_address' => '192.0.2.4',
        ]);

        self::assertSame('1.4.0build12', $normalized['app_version']);
        self::assertSame('android', $normalized['os']);
        self::assertSame('cellular', $normalized['connection']);
        self::assertArrayNotHasKey('device_id', $normalized);
        self::assertArrayNotHasKey('ip_address', $normalized);

        $policy = $service->networkPolicy($normalized);
        self::assertSame('data_saver', $policy['mode']);
        self::assertSame(480, $policy['max_height']);
        self::assertSame(1200, $policy['max_bitrate_kbps']);
        self::assertSame('adaptive_hls_data_saver', $service->playbackReason($normalized, $policy));
    }

    public function test_an_explicit_non_hls_client_receives_an_honest_compatibility_reason(): void
    {
        $service = new PlaybackCapabilityService;
        $capabilities = $service->normalize(['supports_hls' => false, 'os' => 'other']);
        $policy = $service->networkPolicy($capabilities);

        self::assertFalse($policy['compatible']);
        self::assertSame('adaptive_hls_support_unconfirmed', $service->playbackReason($capabilities, $policy));
    }
}
