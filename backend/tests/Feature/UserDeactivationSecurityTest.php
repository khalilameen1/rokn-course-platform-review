<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\UsersController;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class UserDeactivationSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->unique();
            $table->string('password')->nullable();
            $table->string('role')->default('client');
            $table->boolean('active')->default(true);
            $table->unsignedBigInteger('profile_revision')->default(0);
            $table->string('api_token')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('api_tokens', function (Blueprint $table): void {
            $table->string('token')->primary();
            $table->unsignedBigInteger('user_id');
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expired_at');
            $table->uuid('session_id')->nullable()->unique();
            $table->uuid('device_id')->nullable();
            $table->string('platform', 16)->nullable();
            $table->string('device_class', 12)->nullable();
            $table->string('app_version', 32)->nullable();
            $table->string('app_build', 16)->nullable();
            $table->string('auth_provider', 24)->nullable();
            $table->string('auth_provider_user_id', 191)->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
        });
        Schema::create('user_device_tokens', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('device_token')->unique();
            $table->string('device_type')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('user_device_tokens');
        Schema::dropIfExists('api_tokens');
        Schema::dropIfExists('users');
        parent::tearDown();
    }

    public function test_admin_deactivation_atomically_revokes_all_mobile_credentials(): void
    {
        $user = User::query()->forceCreate([
            'name' => 'Student',
            'email' => 'student@rokn.test',
            'role' => 'client',
            'active' => true,
            'api_token' => 'retired-legacy-token',
        ]);
        $user->generateApiToken();
        $user->generateApiToken();
        self::assertSame(2, $user->apiTokens()->count());
        $user->refresh();

        app(UsersController::class)->deactive(
            Request::create('/dashboard/users/'.$user->id.'/deactive', 'POST', [
                'expected_active' => true,
                'state_version' => app(\App\Services\StudentAccountStateService::class)
                    ->editorVersion($user),
            ]),
            $user,
            app(\App\Services\StudentAccountStateService::class)
        );

        $user->refresh();
        self::assertFalse((bool) $user->active);
        self::assertNull($user->getRawOriginal('api_token'));
        self::assertSame(0, $user->apiTokens()->count());
    }
}
