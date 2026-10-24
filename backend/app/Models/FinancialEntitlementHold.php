<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
        'enrollment_order_id',
        'plan_restore_order_id',
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

    /** @return BelongsTo<Order, $this> */
    public function courseOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'course_order_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function sourceOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'source_order_id');
    }

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /** @return BelongsTo<Certificate, $this> */
    public function certificate(): BelongsTo
    {
        return $this->belongsTo(Certificate::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }
}
