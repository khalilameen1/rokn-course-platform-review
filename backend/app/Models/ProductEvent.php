<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class ProductEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'event_id', 'user_id', 'actor_key', 'session_key', 'event_name',
        'source', 'screen_key', 'campaign_key', 'course_id', 'module_id',
        'lesson_id', 'project_id', 'milestone', 'value', 'occurred_at',
        'received_at',
    ];

    protected $casts = [
        'occurred_at' => 'immutable_datetime',
        'received_at' => 'immutable_datetime',
        'user_id' => 'integer',
        'course_id' => 'integer',
        'module_id' => 'integer',
        'lesson_id' => 'integer',
        'project_id' => 'integer',
        'milestone' => 'integer',
        'value' => 'integer',
    ];
}
