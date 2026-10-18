<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class WalletTransaction extends Model
{
    public const DIRECTION_CREDIT = 'credit';

    public const DIRECTION_DEBIT = 'debit';

    public const BUCKET_PAID = 'paid';

    public const BUCKET_REWARD = 'reward';

    public const BUCKET_MIXED = 'mixed';

    public const BUCKET_LEGACY_REWARD = 'legacy_reward';

    protected $fillable = [
        'public_id',
        'user_id',
        'direction',
        'category',
        'bucket',
        'amount',
        'paid_amount',
        'reward_amount',
        'balance_after',
        'paid_balance_after',
        'reward_balance_after',
        'source_type',
        'source_id',
        'idempotency_key',
        'metadata',
        'occurred_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'paid_amount' => 'integer',
        'reward_amount' => 'integer',
        'balance_after' => 'integer',
        'paid_balance_after' => 'integer',
        'reward_balance_after' => 'integer',
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return MorphTo<Model, $this> */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return HasOne<WalletCreditLot, $this> */
    public function paidCreditLot(): HasOne
    {
        return $this->hasOne(WalletCreditLot::class, 'credit_transaction_id');
    }

    /** @return HasMany<WalletDebitAllocation, $this> */
    public function paidDebitAllocations(): HasMany
    {
        return $this->hasMany(WalletDebitAllocation::class, 'wallet_transaction_id');
    }
}
