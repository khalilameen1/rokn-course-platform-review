<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AiConversationContext extends Model
{
    protected $guarded = [];

    protected $casts = [
        'covered_through_id' => 'integer',
        'expires_at' => 'datetime',
    ];
}
