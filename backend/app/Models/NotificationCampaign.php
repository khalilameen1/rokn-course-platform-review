<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationCampaign extends Model
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_DELIVERING = 'delivering';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'delivery_key',
        'notification_type',
        'audience',
        'course_id',
        'notifiable_type',
        'notifiable_id',
        'user_ids',
        'exclude_user_ids',
        'authored_by',
        'title_ar',
        'title_en',
        'message_ar',
        'message_en',
        'action_label_ar',
        'action_label_en',
        'link',
        'image_url',
        'status',
        'recipients_count',
        'inbox_count',
        'retry_count',
        'scheduled_at',
        'selection_cursor',
        'selection_finished_at',
        'resolved_count',
        'skipped_count',
        'queued_at',
        'coordinator_finished_at',
        'completed_at',
        'failed_at',
        'failure_code',
    ];

    protected $casts = [
        'course_id' => 'integer',
        'notifiable_id' => 'integer',
        'user_ids' => 'array',
        'exclude_user_ids' => 'array',
        'authored_by' => 'integer',
        'recipients_count' => 'integer',
        'inbox_count' => 'integer',
        'retry_count' => 'integer',
        'scheduled_at' => 'datetime',
        'selection_cursor' => 'integer',
        'selection_finished_at' => 'datetime',
        'resolved_count' => 'integer',
        'skipped_count' => 'integer',
        'queued_at' => 'datetime',
        'coordinator_finished_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function notifications()
    {
        return $this->hasMany(StudentNotification::class, 'delivery_key', 'delivery_key');
    }

    public function recipients()
    {
        return $this->hasMany(NotificationCampaignRecipient::class);
    }

    /**
     * Enrolled and explicitly selected learners may still open a published
     * course which is hidden from catalogue discovery. Public promotion may
     * not target that course.
     */
    public function canDeliverHiddenCourse(): bool
    {
        return $this->audience === 'enrolled'
            || count((array) $this->user_ids) > 0;
    }
}
