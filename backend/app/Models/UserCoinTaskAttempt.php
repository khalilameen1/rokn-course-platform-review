<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function method()
    {
        return $this->belongsTo(CoinEarningMethod::class, 'coin_earning_method_id');
    }
}
