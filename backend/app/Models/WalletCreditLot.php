<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WalletCreditLot extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_FROZEN = 'frozen';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_REPAID = 'repaid';

    public const STATUS_WAIVED = 'waived';

    public const STATUS_LEGACY_REVIEW = 'legacy_review';

    protected $fillable = [
        'public_id',
        'user_id',
        'source_order_id',
        'credit_transaction_id',
        'original_amount',
        'remaining_amount',
        'recovered_amount',
        'status',
        'credited_at',
        'frozen_at',
        'resolved_at',
        'metadata',
    ];

    protected $casts = [
        'original_amount' => 'integer',
        'remaining_amount' => 'integer',
        'recovered_amount' => 'integer',
        'credited_at' => 'datetime',
        'frozen_at' => 'datetime',
        'resolved_at' => 'datetime',
        'metadata' => 'array',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function sourceOrder()
    {
        // A source checkout is immutable financial provenance. If an old
        // operational order is soft-deleted, reports and reversals must still
        // be able to attribute the coins it funded.
        return $this->belongsTo(Order::class, 'source_order_id')->withTrashed();
    }

    /** @return BelongsTo<WalletTransaction, $this> */
    public function creditTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class, 'credit_transaction_id');
    }

    /** @return HasMany<WalletDebitAllocation, $this> */
    public function allocations(): HasMany
    {
        return $this->hasMany(WalletDebitAllocation::class, 'credit_lot_id');
    }
}
