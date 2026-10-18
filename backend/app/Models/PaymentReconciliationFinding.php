<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentReconciliationFinding extends Model
{
    public const STATE_OPEN = 'open';

    public const STATE_RESOLVED = 'resolved';

    public const STATE_IGNORED = 'ignored';

    protected $fillable = [
        'provider',
        'order_id',
        'order_ref',
        'fingerprint',
        'kind',
        'local_status',
        'local_financial_status',
        'provider_status',
        'provider_transaction_id',
        'state',
        'attempts',
        'first_seen_at',
        'last_seen_at',
        'resolved_at',
        'resolved_by',
        'resolution_note',
        'evidence',
    ];

    protected $casts = [
        'order_id' => 'integer',
        'attempts' => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'resolved_by' => 'integer',
        'evidence' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('state', self::STATE_OPEN);
    }
}
