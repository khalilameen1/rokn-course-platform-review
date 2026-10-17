<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id')->withTrashed();
    }
}
