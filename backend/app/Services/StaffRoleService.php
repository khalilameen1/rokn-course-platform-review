<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AdminPermissionMatrix;
use App\Auth\StaffAccess;
use App\Models\StaffMember;
use App\Models\StaffRole;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

final class StaffRoleService
{
    public function seedDefaults(): void
    {
        DB::transaction(function (): void {
            foreach (array_keys(StaffAccess::permissionLabels()) as $permission) {
                Permission::findOrCreate($permission, 'web');
            }
            foreach ([
                'owner' => array_keys(StaffAccess::permissionLabels()),
                'finance' => ['finance.read', 'finance.manage'],
                'portfolio_reviewer' => ['portfolio.read', 'portfolio.manage'],
                'content_editor' => ['content.read', 'content.manage', 'projects.read', 'projects.manage'],
            ] as $name => $permissions) {
                $role = StaffRole::findOrCreate($name, 'web');
                if ($role->wasRecentlyCreated || $name === 'owner') {
                    $role->givePermissionTo($permissions);
                }
            }
        }, 3);
    }

    /** @param list<int> $roleIds */
    public function assign(User $actor, int $userId, array $roleIds): StaffMember
    {
        abort_unless(app(AdminPermissionMatrix::class)->isAdministrator($actor), 403);

        return DB::transaction(function () use ($actor, $userId, $roleIds): StaffMember {
            $user = User::query()->lockForUpdate()->findOrFail($userId);
            $roles = StaffRole::query()->where('guard_name', 'web')->whereIn('id', $roleIds)->get();
            if (! in_array($user->role, ['admin', 'moderator'], true) || ! $user->active
                || count($roleIds) === 0 || $roles->count() !== count(array_unique($roleIds))) {
                throw ValidationException::withMessages(['role_ids' => 'اختر حساب فريق نشطًا وأدوارًا صالحة']);
            }
            if ($actor->is($user) && ! $roles->contains('name', 'owner')) {
                throw ValidationException::withMessages(['role_ids' => 'لا يمكنك تقليل صلاحيات حسابك من جلسته الحالية']);
            }
            $member = StaffMember::query()->firstOrCreate(['user_id' => $user->id]);
            $member->syncRoles($roles);
            request()->attributes->remove('staff-permissions:'.$userId);

            return $member;
        }, 3);
    }

    /** @return array<int,string> */
    public function eligibleAccounts(): array
    {
        return User::query()->whereIn('role', ['admin', 'moderator'])->where('active', true)
            ->orderBy('email')->pluck('email', 'id')->all();
    }

    /** @return array<int,string> */
    public function roles(): array
    {
        return StaffRole::query()->where('guard_name', 'web')->orderBy('name')->pluck('name', 'id')->all();
    }
}
