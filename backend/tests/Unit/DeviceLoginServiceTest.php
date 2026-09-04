<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\User;
use App\Services\DeviceLoginService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class DeviceLoginServiceTest extends TestCase
{
    private DeviceLoginService $devices;

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('settings', function (Blueprint $table): void {
            $table->id();
            $table->string('device_login_policy')->nullable();
            $table->timestamps();
        });
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('locked_device_id')->nullable();
            $table->timestamps();
        });

        $this->devices = app(DeviceLoginService::class);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_unreadable_policy_uses_rotating_single_device_without_rejecting_the_current_device(): void
    {
        Schema::drop('settings');
        $user = $this->userLockedTo('device-current');

        $access = $this->devices->checkDeviceAccess($user, 'device-current');

        self::assertTrue($access['allowed']);
        self::assertSame('logout_others', $access['action']);
        self::assertSame(DeviceLoginService::POLICY_SINGLE, $this->devices->configuredPolicy());
    }

    public function test_unreadable_policy_requires_a_device_id_instead_of_failing_open(): void
    {
        Schema::drop('settings');

        $access = $this->devices->checkDeviceAccess(new User(), null);

        self::assertFalse($access['allowed']);
        self::assertSame('deny', $access['action']);
    }

    public function test_invalid_policy_uses_rotating_single_device_instead_of_multiple_devices(): void
    {
        $this->setPolicy('unexpected_policy');
        $user = $this->userLockedTo('old-device');

        $access = $this->devices->checkDeviceAccess($user, 'new-device');

        self::assertTrue($access['allowed']);
        self::assertSame('logout_others', $access['action']);
        self::assertSame(DeviceLoginService::POLICY_SINGLE, $this->devices->configuredPolicy());
    }

    public function test_missing_policy_value_uses_the_same_safe_fallback(): void
    {
        $this->setPolicy(null);

        $access = $this->devices->checkDeviceAccess(new User(), 'first-device');

        self::assertTrue($access['allowed']);
        self::assertSame('logout_others', $access['action']);
    }

    public function test_multiple_device_policy_remains_explicitly_permissive(): void
    {
        $this->setPolicy(DeviceLoginService::POLICY_MULTIPLE);

        $access = $this->devices->checkDeviceAccess(
            $this->userLockedTo('another-device'),
            ''
        );

        self::assertTrue($access['allowed']);
        self::assertSame('allow_multiple', $access['action']);
    }

    public function test_single_device_policy_rotates_to_a_new_legitimate_device(): void
    {
        $this->setPolicy(DeviceLoginService::POLICY_SINGLE);

        $access = $this->devices->checkDeviceAccess(
            $this->userLockedTo('old-device'),
            'new-device'
        );

        self::assertTrue($access['allowed']);
        self::assertSame('logout_others', $access['action']);
    }

    public function test_permanent_policy_rejects_a_different_device(): void
    {
        $this->setPolicy(DeviceLoginService::POLICY_SINGLE_PERMANENT);

        $access = $this->devices->checkDeviceAccess(
            $this->userLockedTo('bound-device'),
            'different-device'
        );

        self::assertFalse($access['allowed']);
        self::assertSame('deny', $access['action']);
    }

    public function test_policy_normalization_accepts_known_values_only(): void
    {
        self::assertSame(
            DeviceLoginService::POLICY_SINGLE_PERMANENT,
            DeviceLoginService::normalizePolicy('  SINGLE_DEVICE_PERMANENT ')
        );
        self::assertSame(
            DeviceLoginService::SAFE_FALLBACK_POLICY,
            DeviceLoginService::normalizePolicy('allow_everything')
        );
    }

    private function setPolicy(?string $policy): void
    {
        DB::table('settings')->insert([
            'device_login_policy' => $policy,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function userLockedTo(string $deviceId): User
    {
        return (new User())->forceFill(['locked_device_id' => $deviceId]);
    }
}
