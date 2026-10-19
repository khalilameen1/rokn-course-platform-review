<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Permission\Traits\HasRoles;

/** Authorization subject, not another login identity. users.role remains account kind. */
final class StaffMember extends Model
{
    use HasRoles;

    protected $fillable = ['user_id'];

    protected string $guard_name = 'web';

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
