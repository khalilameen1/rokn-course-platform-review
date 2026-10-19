<?php

declare(strict_types=1);

namespace App\Policies;

use App\Auth\AdminPermissionMatrix;
use App\Models\StaffRole;
use App\Models\User;

final class RolePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    public function view(User $user, StaffRole $role): bool
    {
        return $this->create($user);
    }

    public function create(User $user): bool
    {
        return $user->active && app(AdminPermissionMatrix::class)->isAdministrator($user);
    }

    public function update(User $user, StaffRole $role): bool
    {
        return $role->name !== 'owner' && $this->create($user);
    }

    public function delete(User $user, StaffRole $role): bool
    {
        return $role->name !== 'owner' && $this->create($user);
    }

    public function deleteAny(User $user): bool
    {
        return false;
    }
}
