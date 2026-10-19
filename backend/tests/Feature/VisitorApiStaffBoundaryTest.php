<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\StaffRole;
use App\Models\User;
use App\Services\StaffRoleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class VisitorApiStaffBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_operations_reader_uses_the_api_guard_without_a_dashboard_cookie(): void
    {
        $owner = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $operator = User::query()->forceCreate(['role' => 'moderator', 'active' => true]);
        $service = app(StaffRoleService::class);
        $service->seedDefaults();
        $role = StaffRole::query()->create(['name' => 'visitor-operations-reader', 'guard_name' => 'web']);
        $role->givePermissionTo('operations.read');
        $service->assign($owner, $operator->id, [$role->id]);
        $this->assertGuest('web');
        $this->actingAs($operator, 'api')->getJson('/api/v1/visitors/recent')->assertOk()->assertJsonPath('success', true);
        $this->getJson('/api/v1/visitors/stats')->assertOk()->assertJsonPath('data.total_visitors', 0);
        $this->assertGuest('web');
    }

    public function test_finance_only_role_cannot_inherit_visitor_access_from_its_legacy_admin_kind(): void
    {
        $owner = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $operator = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $service = app(StaffRoleService::class);
        $service->seedDefaults();
        $service->assign($owner, $operator->id, [(int) StaffRole::findByName('finance', 'web')->id]);
        $this->actingAs($operator, 'api')->getJson('/api/v1/visitors/recent')->assertForbidden();
        $this->getJson('/api/v1/visitors/stats')->assertForbidden();
    }

    public function test_learner_and_inactive_admin_do_not_gain_access_or_html_redirects(): void
    {
        foreach ([['role' => 'client', 'active' => true], ['role' => 'admin', 'active' => false]] as $attributes) {
            $this->actingAs(User::query()->forceCreate($attributes), 'api')
                ->getJson('/api/v1/visitors/recent')->assertForbidden()->assertHeader('Content-Type', 'application/json');
        }
    }
}
