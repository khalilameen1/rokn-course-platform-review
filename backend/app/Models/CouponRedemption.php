<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CouponRedemption extends Model
{
    protected $fillable = [
        'coupon_id',
        'user_id',
        'course_id',
        'order_id',
        'coupon_code',
        'discount_percentage',
        'discount_coins',
        'redeemed_at',
    ];

    protected $casts = [
        'discount_percentage' => 'integer',
        'discount_coins' => 'integer',
        'redeemed_at' => 'datetime',
    ];

    /** @return BelongsTo<Coupon, $this> */
    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
