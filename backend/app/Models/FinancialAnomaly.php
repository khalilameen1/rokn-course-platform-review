<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FinancialAnomaly extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const TYPE_PAID_FLOOR_SHORTFALL = 'paid_floor_shortfall';

    protected $fillable = [
        'public_id', 'user_id', 'course_id', 'enrollment_id', 'order_id',
        'type', 'status', 'expected_paid_coins', 'actual_paid_coins',
        'metadata', 'detected_at', 'resolved_by', 'resolved_at', 'resolution_note',
    ];

    protected $casts = [
        'expected_paid_coins' => 'integer',
        'actual_paid_coins' => 'integer',
        'metadata' => 'array',
        'detected_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class);
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
