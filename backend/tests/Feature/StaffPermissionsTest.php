<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AdminPermissionMatrix;
use App\Auth\AdminSessionIdentity;
use App\Filament\Resources\StaffMemberResource;
use App\Filament\Widgets\TodayStats;
use App\Http\Middleware\RequireAdminMfa;
use App\Models\StaffMember;
use App\Models\StaffRole;
use App\Models\User;
use App\Services\StaffRoleService;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StaffPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_only_role_restricts_even_a_legacy_administrator_in_both_dashboards(): void
    {
        $owner = $this->user('owner');
        $operator = $this->user('finance');
        $roles = app(StaffRoleService::class);
        $roles->seedDefaults();
        $roles->assign($owner, $operator->id, [(int) StaffRole::findByName('finance', 'web')->id]);
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($operator, 'web');
        $this->get('/operations/orders')->assertOk();
        $this->get(route('admin.orders.index'))->assertOk();
        $this->get('/operations/students')->assertForbidden();
        $this->get(route('admin.courses.index'))->assertForbidden();
        $this->get('/operations/health')->assertForbidden();
        self::assertFalse(TodayStats::canView());
        self::assertFalse(StaffMemberResource::canViewAny());
        $this->get(route('admin.dashboard'))->assertRedirect('/operations');
    }

    public function test_portfolio_reviewer_cannot_read_finance_or_change_roles(): void
    {
        $owner = $this->user('owner');
        $reviewer = $this->user('reviewer', 'moderator');
        $roles = app(StaffRoleService::class);
        $roles->seedDefaults();
        $roles->assign($owner, $reviewer->id, [(int) StaffRole::findByName('portfolio_reviewer', 'web')->id]);
        $matrix = app(AdminPermissionMatrix::class);
        self::assertTrue($matrix->allows($reviewer, 'admin.portfolio-preview.show', 'GET'));
        self::assertTrue($matrix->allows($reviewer, 'admin.portfolio-review.decide', 'POST'));
        self::assertFalse($matrix->allows($reviewer, 'admin.orders.index', 'GET'));
        self::assertFalse($matrix->allowsCapability($reviewer, AdminPermissionMatrix::ACCOUNT_CREDENTIALS));
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($reviewer, 'web')->get('/operations/shield/roles')->assertForbidden();
        $this->get(route('admin.portfolio-reviews.index'))->assertOk();
    }

    public function test_default_role_setup_is_repeatable_and_does_not_assign_or_expand_existing_accounts(): void
    {
        $editor = $this->user('editor', 'moderator');
        $roles = app(StaffRoleService::class);
        $roles->seedDefaults();
        $roles->seedDefaults();
        self::assertSame(4, StaffRole::query()->count());
        self::assertSame(0, StaffMember::query()->count());
        self::assertFalse(app(AdminPermissionMatrix::class)->allows($editor, 'admin.orders.index', 'GET'));
        self::assertFalse(app(AdminPermissionMatrix::class)->allows($editor, 'admin.teachers.destroy', 'DELETE'));
        self::assertTrue(app(AdminPermissionMatrix::class)->allows($editor, 'admin.teachers.index', 'GET'));
    }

    public function test_roles_cannot_be_attached_to_a_learner_or_self_downgraded(): void
    {
        $owner = $this->user('owner');
        $learner = $this->user('learner', 'client');
        $service = app(StaffRoleService::class);
        $service->seedDefaults();
        $finance = (int) StaffRole::findByName('finance', 'web')->id;
        foreach ([$learner->id, $owner->id] as $id) {
            try {
                $service->assign($owner, $id, [$finance]);
                self::fail('The invalid assignment was accepted');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('role_ids', $exception->errors());
            }
        }
        self::assertSame(0, StaffMember::query()->count());
    }

    public function test_shield_roles_and_assignment_screens_render_for_the_owner(): void
    {
        $owner = $this->user('owner');
        app(StaffRoleService::class)->seedDefaults();
        $this->withoutMiddleware(RequireAdminMfa::class);
        $this->actingAs($owner, 'web');
        Filament::setCurrentPanel(Filament::getPanel('operations'));
        foreach (['/operations/staff-members', '/operations/shield/roles', '/operations/shield/roles/create'] as $path) {
            $this->get($path)->assertOk();
        }
        self::assertFalse($owner->can('update', StaffRole::findByName('owner', 'web')));
        self::assertFalse($owner->can('delete', StaffRole::findByName('owner', 'web')));
    }

    private function user(string $name, string $role = 'admin'): User
    {
        $user = new User;
        $user->forceFill(['name_ar' => $name, 'email' => $name.'@staff.example.test', 'role' => $role, 'active' => true])->save();

        return $user;
    }
}
