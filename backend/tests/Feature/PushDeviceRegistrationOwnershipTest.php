<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\ClientDeviceContext;
use App\Models\User;
use App\Models\UserDeviceToken;
use App\Services\PushDeviceRegistrationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PushDeviceRegistrationOwnershipTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    public function test_rotating_an_installation_retires_the_old_owner_without_enabling_notifications(): void
    {
        $old = $this->learner('old@example.test');
        $next = $this->learner('next@example.test');
        $installation = (string) Str::uuid();
        $owner = app(PushDeviceRegistrationService::class);
        $owner->register($old->id, new ClientDeviceContext(
            deviceId: $installation, deviceToken: 'old-push', deviceOs: 'android 15'
        ));
        $owner->register($next->id, new ClientDeviceContext(
            deviceId: $installation, deviceToken: 'new-push', deviceType: 'phone', deviceOs: 'iOS 18'
        ));
        $token = UserDeviceToken::query()->sole();
        self::assertSame($next->id, $token->user_id);
        self::assertSame('new-push', $token->device_token);
        self::assertSame($installation, $token->device_id);
        self::assertSame('ios', $token->device_os);
        self::assertSame('ios', $next->fresh()->device_os);
        self::assertFalse((bool) $next->fresh()->notifications_status);
    }

    public function test_same_native_token_moves_to_the_current_account_without_duplicate_rows(): void
    {
        $first = $this->learner('first@example.test');
        $second = $this->learner('second@example.test');
        $owner = app(PushDeviceRegistrationService::class);
        $device = new ClientDeviceContext(deviceToken: 'shared-native-token', deviceOs: 'android');
        $owner->register($first->id, $device);
        $owner->register($second->id, $device);
        self::assertSame($second->id, UserDeviceToken::query()->sole()->user_id);
    }

    public function test_failed_replacement_restores_the_old_installation_binding(): void
    {
        $user = $this->learner('rollback@example.test');
        $id = (string) Str::uuid();
        $owner = app(PushDeviceRegistrationService::class);
        $owner->register($user->id, new ClientDeviceContext(deviceId: $id, deviceToken: 'old-token'));
        UserDeviceToken::creating(static fn () => throw new \RuntimeException('push write failed'));
        try {
            $owner->register($user->id, new ClientDeviceContext(deviceId: $id, deviceToken: 'new-token'));
            self::fail('The replacement failure must remain observable to the caller.');
        } catch (\RuntimeException $error) {
            self::assertSame('push write failed', $error->getMessage());
        }
        self::assertSame('old-token', UserDeviceToken::query()->sole()->device_token);
        self::assertSame($id, UserDeviceToken::query()->sole()->device_id);
    }

    public function test_inactive_deleted_or_missing_accounts_cannot_receive_a_registration(): void
    {
        $user = $this->learner('disabled@example.test');
        $user->forceFill(['active' => false])->save();
        $owner = app(PushDeviceRegistrationService::class);
        $device = new ClientDeviceContext(deviceToken: 'unowned-push', deviceOs: 'ios');
        $owner->register($user->id, $device);
        $user->forceFill(['active' => true])->save();
        $user->deleteQuietly();
        $owner->register($user->id, $device);
        $owner->register($user->id + 100, $device);
        self::assertSame(0, UserDeviceToken::query()->count());
    }

    public function test_device_os_can_be_updated_without_inventing_a_push_token(): void
    {
        $user = $this->learner('no-push@example.test');
        app(PushDeviceRegistrationService::class)->register($user->id,
            new ClientDeviceContext(deviceOs: ' Android 16 ', deviceId: 'not-a-uuid'));
        self::assertSame('android', $user->fresh()->device_os);
        self::assertSame(0, UserDeviceToken::query()->count());
        self::assertFalse((bool) $user->fresh()->notifications_status);
    }

    public function test_http_refresh_uses_the_bearers_installation_instead_of_the_supplied_one(): void
    {
        $user = $this->learner('bearer@example.test');
        $authenticatedDevice = (string) Str::uuid();
        $bearer = $user->generateApiToken(session: ['device_id' => $authenticatedDevice]);
        $this->withToken($bearer)->postJson('/api/v1/user/device-token', [
            'device_token' => 'bearer-push', 'device_os' => 'android',
            'device_id' => (string) Str::uuid(),
        ])->assertOk();
        self::assertFalse((bool) $user->fresh()->notifications_status);
        self::assertSame($authenticatedDevice, UserDeviceToken::query()->sole()->device_id);
    }

    private function learner(string $email): User
    {
        return User::query()->forceCreate([
            'name' => 'Device learner', 'email' => $email, 'password' => 'unused',
            'role' => 'client', 'active' => true, 'notifications_status' => false,
        ]);
    }
}
