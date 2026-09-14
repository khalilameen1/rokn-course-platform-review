<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CourseCheckout extends Model
{
    protected $guarded = ['id'];
    protected $casts = ['terms' => 'array', 'expires_at' => 'immutable_datetime',
        'authorized_at' => 'immutable_datetime', 'completed_at' => 'immutable_datetime'];

    protected static function booted(): void
    {
        static::updating(function (self $checkout): void {
            foreach (['public_id', 'user_id', 'course_id', 'terms', 'terms_hash', 'channel', 'package_id', 'expires_at'] as $field) {
                if ($checkout->isDirty($field)) throw new \LogicException('Checkout terms are immutable');
            }
        });
    }
}
