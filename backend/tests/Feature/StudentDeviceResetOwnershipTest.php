<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\UsersController;
use App\Models\Setting;
use App\Models\User;
use App\Services\DeviceLoginService;
use App\Services\StudentAccountStateService;
use App\Support\StudentEditorVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StudentDeviceResetOwnershipTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_revokes_bearers_and_push_registrations_and_advances_profile_version(): void
    {
        $user = $this->studentWithDevice();
        $version = StudentEditorVersion::device($user);
        $revision = (int) $user->profile_revision;
        app(StudentAccountStateService::class)->resetDevice($user, DeviceLoginService::POLICY_SINGLE_PERMANENT, $version);
        self::assertNull($user->fresh()->locked_device_id);
        self::assertSame(0, $user->apiTokens()->count());
        self::assertSame(0, $user->deviceTokens()->count());
        self::assertSame($revision + 1, (int) $user->fresh()->profile_revision);
        try {
            app(StudentAccountStateService::class)->resetDevice($user, DeviceLoginService::POLICY_SINGLE_PERMANENT, $version);
            self::fail('A repeated reset must not advance state again.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('state_version', $error->errors());
        }
        self::assertSame($revision + 1, (int) $user->fresh()->profile_revision);
    }

    public function test_changed_policy_or_stale_device_version_cannot_revoke_credentials(): void
    {
        $user = $this->studentWithDevice();
        $version = StudentEditorVersion::device($user);
        $settings = Setting::query()->firstOrFail();
        $settings->fill(['device_login_policy' => DeviceLoginService::POLICY_MULTIPLE])->save();
        try {
            app(StudentAccountStateService::class)->resetDevice($user, DeviceLoginService::POLICY_SINGLE_PERMANENT, $version);
            self::fail('The reset policy must be checked from current settings.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('expected_policy', $error->errors());
        }
        $settings->fill(['device_login_policy' => DeviceLoginService::POLICY_SINGLE_PERMANENT])->save();
        $user->forceFill(['profile_revision' => (int) $user->profile_revision + 1])->save();
        try {
            app(StudentAccountStateService::class)->resetDevice($user, DeviceLoginService::POLICY_SINGLE_PERMANENT, $version);
            self::fail('A stale device editor must not revoke a newer session state.');
        } catch (ValidationException $error) {
            self::assertArrayHasKey('state_version', $error->errors());
        }
        self::assertNotNull($user->fresh()->locked_device_id);
        self::assertSame(1, $user->apiTokens()->count());
        self::assertSame(1, $user->deviceTokens()->count());
    }

    public function test_outer_rollback_restores_device_and_all_credentials(): void
    {
        $user = $this->studentWithDevice();
        $version = StudentEditorVersion::device($user);
        try {
            DB::transaction(function () use ($user, $version): never {
                app(StudentAccountStateService::class)->resetDevice($user, DeviceLoginService::POLICY_SINGLE_PERMANENT, $version);
                self::assertSame(0, $user->apiTokens()->count());
                throw new \RuntimeException('Outer operation failed');
            });
            self::fail('Device reset must join the enclosing transaction.');
        } catch (\RuntimeException $error) {
            self::assertSame('Outer operation failed', $error->getMessage());
        }
        self::assertSame($version, StudentEditorVersion::device($user->fresh()));
        self::assertSame(1, $user->apiTokens()->count());
        self::assertSame(1, $user->deviceTokens()->count());
    }

    private function studentWithDevice(): User
    {
        $this->app->bind(UsersController::class, static function (): never {
            throw new \LogicException('Account state commands must not resolve an HTTP controller.');
        });
        $settings = Setting::query()->firstOrCreate([]);
        $settings->fill(['device_login_policy' => DeviceLoginService::POLICY_SINGLE_PERMANENT])->save();
        $deviceId = (string) Str::uuid();
        $user = User::query()->forceCreate([
            'name_ar' => 'Student', 'email' => Str::uuid().'@rokn.test', 'password' => 'test-only',
            'role' => 'client', 'active' => true, 'locked_device_id' => $deviceId,
        ]);
        $user->generateApiToken();
        $user->deviceTokens()->create(['device_token' => 'test-push-token', 'device_id' => $deviceId, 'device_type' => 'android']);

        return $user->fresh();
    }
}
