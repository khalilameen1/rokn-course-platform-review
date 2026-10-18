<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class AiEntitlementUsage extends Model
{
    public const FEATURE_COURSE_CHAT = 'course_chat';

    public const FEATURE_PROJECT_FEEDBACK = 'project_feedback';

    public const FEATURE_PROJECT_FOLLOWUP = 'project_followup';

    public const FEATURES = [
        self::FEATURE_COURSE_CHAT,
        self::FEATURE_PROJECT_FEEDBACK,
        self::FEATURE_PROJECT_FOLLOWUP,
    ];

    protected $fillable = [
        'enrollment_id',
        'access_plan_id',
        'feature',
        'used_requests',
        'reserved_requests',
        'unanswered_provider_requests',
        'unanswered_provider_last_at',
        'provider_exposure_paused_until',
        'used_tokens',
        'reserved_tokens',
        'used_cost_usd',
        'reserved_cost_usd',
    ];

    protected $casts = [
        'used_requests' => 'integer', 'reserved_requests' => 'integer',
        'unanswered_provider_requests' => 'integer',
        'unanswered_provider_last_at' => 'datetime',
        'provider_exposure_paused_until' => 'datetime',
        'used_tokens' => 'integer', 'reserved_tokens' => 'integer',
        'used_cost_usd' => 'decimal:6', 'reserved_cost_usd' => 'decimal:6',
    ];

    /** @return BelongsTo<CourseEnrollment, $this> */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(CourseEnrollment::class);
    }

    /** @return BelongsTo<CourseAccessPlan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(CourseAccessPlan::class, 'access_plan_id');
    }
}
