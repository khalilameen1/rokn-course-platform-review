<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Model;

final class CourseAccessPlan extends Model
{
    use InvalidatesCourseCatalogue;

    public const BASIC = 'basic';
    public const GUIDED = 'guided';
    public const MENTOR = 'mentor';
    public const CODES = [self::BASIC, self::GUIDED, self::MENTOR];

    public const FEEDBACK_PASS_ONLY = 'pass_only';
    public const FEEDBACK_REPORT = 'report';
    public const FEEDBACK_ENHANCED = 'enhanced';
    public const PROJECT_FEEDBACK_LEVELS = [
        self::FEEDBACK_PASS_ONLY,
        self::FEEDBACK_REPORT,
        self::FEEDBACK_ENHANCED,
    ];

    protected $fillable = [
        'course_id', 'code', 'name_ar', 'name_en', 'price_coins', 'minimum_paid_coins',
        'chat_enabled', 'chat_message_limit', 'chat_token_budget',
        'chat_attachments_enabled', 'chat_attachment_max_files',
        'project_followup_attachments_enabled', 'project_followup_attachment_max_files',
        'ai_budget_usd', 'request_reserve_usd', 'max_output_tokens',
        'project_feedback_token_budget', 'project_feedback_budget_usd',
        'project_feedback_reserve_usd',
        'project_followup_message_limit', 'project_followup_token_budget',
        'project_followup_budget_usd', 'project_followup_reserve_usd',
        'model_override', 'project_feedback_level', 'project_output_enabled',
        'certificate_enabled', 'is_active', 'sort_order',
        'projects_enabled', 'delivery_cost_usd',
    ];

    protected $casts = [
        'price_coins' => 'integer',
        'minimum_paid_coins' => 'integer',
        'chat_enabled' => 'boolean',
        'chat_message_limit' => 'integer',
        'chat_token_budget' => 'integer',
        'chat_attachments_enabled' => 'boolean',
        'chat_attachment_max_files' => 'integer',
        'project_followup_attachments_enabled' => 'boolean',
        'project_followup_attachment_max_files' => 'integer',
        'ai_budget_usd' => 'decimal:6',
        'request_reserve_usd' => 'decimal:6',
        'project_feedback_token_budget' => 'integer',
        'project_feedback_budget_usd' => 'decimal:6',
        'project_feedback_reserve_usd' => 'decimal:6',
        'project_followup_message_limit' => 'integer',
        'project_followup_token_budget' => 'integer',
        'project_followup_budget_usd' => 'decimal:6',
        'project_followup_reserve_usd' => 'decimal:6',
        'max_output_tokens' => 'integer',
        'project_output_enabled' => 'boolean',
        'certificate_enabled' => 'boolean',
        'projects_enabled' => 'boolean',
        'delivery_cost_usd' => 'decimal:6',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function course() { return $this->belongsTo(Course::class); }
    public function enrollments() { return $this->hasMany(CourseEnrollment::class, 'access_plan_id'); }

    protected static function booted(): void
    {
        static::updating(function (CourseAccessPlan $plan): void {
            if ($plan->isDirty(['course_id', 'code'])) {
                throw new \LogicException(
                    'An access plan cannot be moved or renamed after its contract is created.'
                );
            }
        });
    }
}
