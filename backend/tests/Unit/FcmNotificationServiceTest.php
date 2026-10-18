<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\FcmNotificationService;
use Illuminate\Support\Facades\Cache;
use Kreait\Firebase\Contract\Messaging;
use Kreait\Firebase\Exception\Messaging\ApiConnectionFailed;
use Mockery;
use Tests\TestCase;

final class FcmNotificationServiceTest extends TestCase
{
    public function test_connection_loss_after_provider_start_is_not_retried_blindly(): void
    {
        Cache::clear();
        $messaging = Mockery::mock(Messaging::class);
        $messaging->shouldReceive('send')
            ->once()
            ->andThrow(new ApiConnectionFailed('connection lost'));
        $this->app->instance(Messaging::class, $messaging);

        $user = new User;
        $user->forceFill([
            'id' => 7,
            'active' => true,
            'role' => 'client',
            'notifications_status' => true,
            'marketing_notifications_enabled' => true,
            'preferred_locale' => 'ar',
        ]);
        $token = new UserDeviceToken;
        $token->forceFill([
            'id' => 11,
            'user_id' => 7,
            'device_token' => 'device-token',
            'device_type' => 'android',
        ]);

        $result = FcmNotificationService::sendToDeviceDetailed(
            $user,
            $token,
            'عنوان',
            'Title',
            'رسالة',
            'Message',
            null,
            ['notification_type' => 'service_notice']
        );

        self::assertFalse($result['accepted']);
        self::assertFalse($result['retryable']);
        self::assertTrue($result['unknown']);
        self::assertSame('provider_outcome_unknown', $result['failure_code']);
    }
}
