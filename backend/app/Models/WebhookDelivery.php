<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WebhookDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_DELIVERED = 'delivered';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'webhook_endpoint_id', 'outbox_event_id', 'delivery_key', 'status',
        'attempts', 'response_code', 'available_at', 'delivered_at',
        'error_fingerprint',
    ];

    protected $casts = [
        'attempts' => 'integer',
        'response_code' => 'integer',
        'available_at' => 'immutable_datetime',
        'delivered_at' => 'immutable_datetime',
    ];

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    public function outboxEvent(): BelongsTo
    {
        return $this->belongsTo(OutboxEvent::class);
    }
}
