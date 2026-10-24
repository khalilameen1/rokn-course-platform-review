<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\StaffAccess;
use App\Filament\Pages\FinancialOverview;
use App\Filament\Widgets\OverviewCourseCoinsChart;
use App\Filament\Widgets\OverviewRevenueChart;
use App\Http\Requests\Admin\CourseRequest;
use App\Http\Requests\Admin\SupportCaseStateRequest;
use App\Http\Requests\Admin\UserRequest;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\AdminSupportCaseReadService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

final class GranularStaffWorkflowParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_finance_reader_can_open_financial_page_and_charts_but_content_editor_cannot(): void
    {
        $finance = $this->operator(['finance.read']);
        $this->actingAs($finance, 'web');
        self::assertTrue(FinancialOverview::canAccess());
        self::assertTrue(OverviewRevenueChart::canView());
        self::assertTrue(OverviewCourseCoinsChart::canView());

        $this->actingAs($this->operator(['content.read']), 'web');
        self::assertFalse(FinancialOverview::canAccess());
        self::assertFalse(OverviewRevenueChart::canView());
        self::assertFalse(OverviewCourseCoinsChart::canView());
    }

    public function test_student_manager_authorizes_the_retained_request_without_becoming_a_legacy_admin(): void
    {
        $request = UserRequest::create('/admin/users/1', 'PUT');
        $manager = $this->operator(['students.manage']);
        $request->setUserResolver(fn () => $manager);
        self::assertTrue($request->authorize());
        $reader = $this->operator(['students.read']);
        $request->setUserResolver(fn () => $reader);
        self::assertFalse($request->authorize());
    }

    public function test_course_cost_input_preserves_granular_owner_value_and_drops_editor_value(): void
    {
        foreach ([true, false] as $owner) {
            $operator = $this->operator($owner
                ? array_keys(StaffAccess::permissionLabels())
                : ['content.read', 'content.manage']);
            $request = new class extends CourseRequest
            {
                public function prepare(): void
                {
                    $this->prepareForValidation();
                }
            };
            $request->merge(['access_plans' => ['basic' => ['delivery_cost_usd' => '1.25']]]);
            $request->setUserResolver(fn () => $operator);
            $request->prepare();
            self::assertSame($owner ? '1.25' : null, $request->input('access_plans.basic.delivery_cost_usd'));
        }
    }

    public function test_support_options_and_validation_share_the_same_permission_boundary(): void
    {
        $agent = $this->operator(['support.read', 'support.manage']);
        $reader = $this->operator(['support.read']);
        $finance = $this->operator(['finance.read'], 'admin');
        $legacy = User::query()->forceCreate(['role' => 'admin', 'active' => true]);
        $inactive = $this->operator(['support.manage']);
        $inactive->forceFill(['active' => false])->save();
        $assignees = app(AdminSupportCaseReadService::class)->assignees()->modelKeys();
        self::assertContains($agent->id, $assignees);
        self::assertContains($legacy->id, $assignees);
        foreach ([$reader, $finance, $inactive] as $denied) {
            self::assertNotContains($denied->id, $assignees);
        }
        $rules = (new SupportCaseStateRequest)->rules();
        foreach ([$agent, $legacy, $reader, $finance, $inactive] as $candidate) {
            $validation = Validator::make(['assigned_to' => $candidate->id], ['assigned_to' => $rules['assigned_to']]);
            self::assertSame(in_array($candidate->id, $assignees, true), $validation->passes());
        }
    }

    private function operator(array $permissions, string $role = 'moderator'): User
    {
        $user = User::query()->forceCreate(['role' => $role, 'active' => true]);
        $member = StaffMember::query()->create(['user_id' => $user->id]);
        foreach ($permissions as $permission) {
            $member->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
