<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Spatie\Permission\Models\Role;

final class StaffRole extends Role
{
    /** Staff roles attach only to staff subjects, never to the learner User model. */
    public function users(): BelongsToMany
    {
        return $this->morphedByMany(StaffMember::class, 'model', config('permission.table_names.model_has_roles'), 'role_id', 'model_id');
    }
}
