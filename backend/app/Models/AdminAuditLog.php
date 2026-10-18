<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = [
        'route_parameters' => 'array',
        'request_fields' => 'array',
        'changes' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
