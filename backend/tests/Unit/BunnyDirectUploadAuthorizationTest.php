<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BunnyService;
use Tests\TestCase;

final class BunnyDirectUploadAuthorizationTest extends TestCase
{
    public function test_direct_upload_authorization_never_creates_a_resource_shorter_than_one_hour(): void
    {
        config([
            'bunny.stream_api_key' => 'test-api-key',
            'bunny.library_id' => '1234',
            'bunny.direct_upload_signature_ttl_seconds' => 1800,
        ]);

        $service = new class extends BunnyService
        {
            public function isEnabled(): bool
            {
                return true;
            }
        };

        $before = time();
        $authorization = $service->directUploadAuthorization('video-guid');
        $expiresAt = (int) $authorization['headers']['AuthorizationExpire'];

        self::assertGreaterThanOrEqual($before + 3600, $expiresAt);
        self::assertLessThanOrEqual(time() + 3600, $expiresAt);
        self::assertGreaterThanOrEqual(3599, $authorization['authorization_expires_in_seconds']);
        self::assertLessThanOrEqual(3600, $authorization['authorization_expires_in_seconds']);
        self::assertSame(
            BunnyService::directUploadSignature('1234', 'test-api-key', $expiresAt, 'video-guid'),
            $authorization['headers']['AuthorizationSignature']
        );
    }

    public function test_direct_upload_authorization_is_bounded_to_the_claim_day(): void
    {
        config([
            'bunny.stream_api_key' => 'test-api-key',
            'bunny.library_id' => '1234',
            'bunny.direct_upload_signature_ttl_seconds' => 172800,
        ]);

        $service = new class extends BunnyService
        {
            public function isEnabled(): bool
            {
                return true;
            }
        };

        $before = time();
        $authorization = $service->directUploadAuthorization('video-guid');
        $expiresAt = (int) $authorization['headers']['AuthorizationExpire'];

        self::assertGreaterThanOrEqual($before + 86400, $expiresAt);
        self::assertLessThanOrEqual(time() + 86400, $expiresAt);
    }
}
