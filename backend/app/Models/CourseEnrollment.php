<?php

namespace App\Models;

use App\Support\CourseAccessPlanSnapshot;
use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class CourseEnrollment extends Model
{
    use HasFactory, InvalidatesCourseCatalogue;

    /**
     * Catalogue cards only depend on the number of active student
     * enrollments. Progress/completion and entitlement bookkeeping update the
     * same row frequently; invalidating every catalogue page for those writes
     * turns one learner finishing a section into a cache stampede for all
     * learners without changing anything shown on a course card.
     */
    public function shouldInvalidateCourseCatalogue(): bool
    {
        if ($this->wasRecentlyCreated || !$this->exists) {
            return true;
        }

        return $this->wasChanged([
            'course_id',
            'user_id',
            'is_active',
            'expires_at',
        ]);
    }

    protected $fillable = [
        'user_id',
        'course_id',
        'order_id',
        'access_plan_id',
        'access_plan_snapshot',
        'access_plan_order_id',
        'enrolled_at',
        'expires_at',
        'is_active',
        'access_granted_at',
        'completed_curriculum_revision',
        'curriculum_completed_at',
    ];

    protected $casts = [
        'enrolled_at' => 'datetime',
        'expires_at' => 'datetime',
        'access_granted_at' => 'datetime',
        'curriculum_completed_at' => 'immutable_datetime',
        'completed_curriculum_revision' => 'integer',
        'is_active' => 'boolean',
        'access_plan_snapshot' => 'array',
        'access_plan_order_id' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    /**
     * Get the user for this enrollment.
     */
    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    /**
     * Get the course for this enrollment.
     */
    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    /**
     * Get the order for this enrollment.
     */
    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function accessPlan()
    {
        return $this->belongsTo(CourseAccessPlan::class, 'access_plan_id');
    }

    public function accessPlanOrder()
    {
        return $this->belongsTo(Order::class, 'access_plan_order_id');
    }

    public function aiUsage()
    {
        return $this->hasOne(AiEntitlementUsage::class, 'enrollment_id');
    }

    /** One enrollment owns one independent budget aggregate per AI feature. */
    public function aiUsages()
    {
        return $this->hasMany(AiEntitlementUsage::class, 'enrollment_id');
    }

    public function financialHolds()
    {
        return $this->hasMany(FinancialEntitlementHold::class, 'enrollment_id');
    }

    protected static function booted(): void
    {
        static::saving(function (CourseEnrollment $enrollment): void {
            if (
                $enrollment->exists
                && $enrollment->getOriginal('completed_curriculum_revision') !== null
                && $enrollment->isDirty([
                    'completed_curriculum_revision',
                    'curriculum_completed_at',
                ])
            ) {
                throw new \LogicException('Earned curriculum completion is immutable.');
            }

            if (
                !$enrollment->exists
                || $enrollment->isDirty(['access_plan_id', 'access_plan_snapshot'])
            ) {
                CourseAccessPlanSnapshot::assertValidForPlan(
                    $enrollment->access_plan_id !== null
                        ? (int) $enrollment->access_plan_id
                        : null,
                    $enrollment->access_plan_snapshot
                );
            }

            if (
                !(bool) $enrollment->is_active
                || !$enrollment->order_id
            ) {
                return;
            }

            $held = FinancialEntitlementHold::query()
                ->where('user_id', $enrollment->user_id)
                ->where('course_id', $enrollment->course_id)
                ->where('course_order_id', $enrollment->order_id)
                ->where('status', FinancialEntitlementHold::STATUS_ACTIVE)
                ->where('entitlement_scope', 'course')
                ->exists();
            if ($held) {
                throw new \DomainException(
                    'Course access is on hold while its source payment is under financial review.'
                );
            }
        });
    }

    /**
     * Scope for active enrollments.
     */
    public function scopeActive($query)
    {
        return $query
            ->where('is_active', true)
            ->where(function ($active): void {
                $active->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            });
    }

    /**
     * Scope for expired enrollments.
     */
    public function scopeExpired($query)
    {
        return $query->where('expires_at', '<', now());
    }

    /**
     * Check if enrollment is active.
     */
    public function isActive()
    {
        return $this->is_active && ($this->expires_at === null || $this->expires_at > now());
    }

    /**
     * Check if enrollment is expired.
     */
    public function isExpired()
    {
        return $this->expires_at !== null && $this->expires_at < now();
    }

    /**
     * Grant access to the course.
     */
    public function grantAccess()
    {
        $this->update([
            'is_active' => true,
            'access_granted_at' => now()
        ]);
    }

    /**
     * Revoke access to the course.
     */
    public function revokeAccess()
    {
        $this->update([
            'is_active' => false
        ]);
    }
}
