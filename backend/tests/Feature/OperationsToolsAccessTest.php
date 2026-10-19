<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminSessionIdentity;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\StaffRoleService;
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
        session()->forget(AdminSessionIdentity::SESSION_KEY);
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

    public function test_status_distinguishes_recording_configuration_from_running_worker_evidence(): void
    {
        $owner = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $this->withoutMiddleware(RequireAdminMfa::class)->actingAs($owner, 'web');
        foreach ([false, true] as $enabled) {
            config(['operations.horizon_enabled' => $enabled, 'pulse.enabled' => $enabled]);
            $this->get('/operations/system-status')->assertOk()
                ->assertSee($enabled ? 'حفظ مؤشرات الطوابير مفعّل' : 'حفظ مؤشرات الطوابير غير مفعّل')
                ->assertSee('حالة العامل الفعلية والمهام الجارية والفاشلة تظهر داخل Horizon')
                ->assertSee($enabled ? 'التسجيل مفعّل' : 'التسجيل غير مفعّل')
                ->assertSee(url('/operations/queues'), false)->assertSee(url('/operations/health'), false);
        }
    }

    public function test_operations_reader_can_open_pulse_but_cannot_manage_finance(): void
    {
        $owner = new User;
        $owner->forceFill(['role' => 'admin', 'active' => true])->save();
        $operator = new User;
        $operator->forceFill(['role' => 'admin', 'active' => true])->save();
        $roles = app(StaffRoleService::class);
        $roles->seedDefaults();
        $role = StaffRole::query()->create(['name' => 'operations_reader', 'guard_name' => 'web']);
        $role->givePermissionTo('operations.read');
        $roles->assign($owner, (int) $operator->id, [(int) $role->id]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($operator, 'web')->get('/operations/health')->assertOk();
        $this->get('/operations/system-status')->assertOk()->assertSee('التسجيل غير مفعّل');
        $this->get('/operations/orders')->assertForbidden();
    }
}
