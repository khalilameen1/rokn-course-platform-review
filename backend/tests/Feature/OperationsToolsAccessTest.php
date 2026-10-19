<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\RequireAdminMfa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class OperationsToolsAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_operational_tools_require_staff_and_mfa_even_on_local_environment(): void
    {
        $this->app->detectEnvironment(fn () => 'local');
        foreach (['/operations/queues', '/operations/health'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
        $admin = new User;
        $admin->forceFill(['email' => 'ops@example.test', 'role' => 'admin', 'active' => true])->save();
        $this->actingAs($admin, 'web');
        foreach (['/operations/queues', '/operations/health'] as $path) {
            $this->get($path)->assertRedirect(route('admin.mfa.setup'));
        }
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->get('/operations/health')->assertOk();
        self::assertTrue(Gate::forUser($admin)->allows('viewPulse'));
        $admin->forceFill(['role' => 'moderator'])->save();
        session()->forget(\App\Auth\AdminSessionIdentity::SESSION_KEY);
        foreach (['/operations/queues', '/operations/health'] as $path) {
            $this->get($path)->assertForbidden();
        }
    }

    public function test_horizon_covers_each_current_queue_and_preserves_lease_headroom(): void
    {
        $supervisor = config('horizon.defaults.supervisor-1');
        self::assertEqualsCanonicalizing(config('operations.queue_heartbeat_required_queues'), $supervisor['queue']);
        self::assertGreaterThan(300, $supervisor['timeout']);
        self::assertLessThan(config('queue.connections.redis.retry_after'), $supervisor['timeout']);
    }
}
