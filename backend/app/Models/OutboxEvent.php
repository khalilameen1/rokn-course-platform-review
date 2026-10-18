<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class OutboxEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_BLOCKED = 'blocked';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'event_key', 'topic', 'aggregate_type', 'aggregate_id', 'payload',
        'status', 'attempts', 'available_at', 'dispatched_at', 'locked_at', 'delivered_at',
        'last_error_fingerprint',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'available_at' => 'immutable_datetime',
        'dispatched_at' => 'immutable_datetime',
        'locked_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }
}
