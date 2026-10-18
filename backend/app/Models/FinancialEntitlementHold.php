<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FinancialEntitlementHold extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_RESOLVED = 'resolved';

    public const RESOLUTION_REPAID = 'repaid';

    public const RESOLUTION_WAIVED = 'waived';

    protected $fillable = [
        'public_id',
        'user_id',
        'course_id',
        'course_order_id',
        'source_order_id',
        'enrollment_id',
        'enrollment_deactivated_at',
        'plan_reverted_at',
        'certificate_id',
        'certificate_revoked_at',
        'resolved_by',
        'status',
        'entitlement_scope',
        'reason',
        'resolution',
        'resolution_note',
        'held_at',
        'resolved_at',
    ];

    protected $casts = [
        'held_at' => 'datetime',
        'enrollment_deactivated_at' => 'datetime',
        'plan_reverted_at' => 'datetime',
        'certificate_revoked_at' => 'datetime',
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

    public function courseOrder()
    {
        return $this->belongsTo(Order::class, 'course_order_id');
    }

    public function sourceOrder()
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    public function enrollment()
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    public function certificate()
    {
        return $this->belongsTo(Certificate::class);
    }

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
