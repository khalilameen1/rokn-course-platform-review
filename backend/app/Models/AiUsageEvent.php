<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AiUsageEvent extends Model
{
    public const FEATURE_PROJECT_REVIEW = 'project_review';

    protected $guarded = [];
    protected $casts = [
        'reserved_tokens' => 'integer', 'prompt_tokens' => 'integer',
        'completion_tokens' => 'integer', 'total_tokens' => 'integer',
        'reserved_cost_usd' => 'decimal:6', 'cost_usd' => 'decimal:6',
        'fx_rate_to_egp' => 'decimal:4', 'cost_egp' => 'decimal:6',
        'metadata' => 'array', 'reservation_expires_at' => 'datetime',
        'completed_at' => 'datetime',
    ];
    public function enrollment() { return $this->belongsTo(CourseEnrollment::class); }
    public function plan() { return $this->belongsTo(CourseAccessPlan::class, 'access_plan_id'); }
}
