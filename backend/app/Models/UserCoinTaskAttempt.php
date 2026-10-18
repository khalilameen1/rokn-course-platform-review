<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserCoinTaskAttempt extends Model
{
    public const STATUS_STARTED = 'started';

    public const STATUS_CLAIMED = 'claimed';

    protected $fillable = [
        'public_id',
        'user_id',
        'coin_earning_method_id',
        'status',
        'started_at',
        'claim_available_at',
        'claimed_at',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'claim_available_at' => 'datetime',
        'claimed_at' => 'datetime',
        'metadata' => 'array',
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
