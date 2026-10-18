<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AccountFileDeletion extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_FAILED = 'failed';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'user_id', 'disk', 'path_hash', 'path', 'status', 'attempts',
        'available_at', 'completed_at', 'last_error',
    ];

    protected $casts = [
        'path' => 'encrypted',
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
}
