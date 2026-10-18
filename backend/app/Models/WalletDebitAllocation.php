<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WalletDebitAllocation extends Model
{
    protected $fillable = [
        'wallet_transaction_id',
        'credit_lot_id',
        'course_order_id',
        'amount',
        'entitlement_scope',
        'allocated_at',
    ];

    protected $casts = [
        'amount' => 'integer',
        'allocated_at' => 'datetime',
    ];

    /** @return BelongsTo<WalletTransaction, $this> */
    public function walletTransaction(): BelongsTo
    {
        return $this->belongsTo(WalletTransaction::class);
    }

    /** @return BelongsTo<WalletCreditLot, $this> */
    public function creditLot(): BelongsTo
    {
        return $this->belongsTo(WalletCreditLot::class, 'credit_lot_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function courseOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'course_order_id');
    }
}
