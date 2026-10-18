<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function enrollment()
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
