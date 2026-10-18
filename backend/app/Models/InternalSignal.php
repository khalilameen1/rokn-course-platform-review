<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class InternalSignal extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_HANDLED = 'handled';

    protected $fillable = [
        'signal_key', 'type', 'aggregate_type', 'aggregate_id',
        'payload_fingerprint', 'payload', 'status', 'attempts',
        'available_at', 'dispatched_at', 'locked_at', 'lease_id',
        'handled_at', 'last_error_fingerprint',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'dispatched_at' => 'immutable_datetime',
        'locked_at' => 'immutable_datetime',
        'handled_at' => 'immutable_datetime',
    ];
}
