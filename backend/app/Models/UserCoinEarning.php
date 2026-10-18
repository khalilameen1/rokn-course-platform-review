<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCoinEarning extends Model
{
    protected $fillable = [
        'user_id',
        'coin_earning_method_id',
        'amount',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CoinEarningMethod, $this> */
    public function method(): BelongsTo
    {
        return $this->belongsTo(CoinEarningMethod::class, 'coin_earning_method_id');
    }
}
