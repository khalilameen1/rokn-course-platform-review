<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CourseChatTurn extends Model
{
    public const QUEUED = 'queued';
    public const STREAMING = 'streaming';
    public const COMPLETED = 'completed';
    public const FAILED = 'failed';
    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'completed_at' => 'datetime',
        'expires_at' => 'datetime',
    ];
}
