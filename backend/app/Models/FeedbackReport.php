<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

final class FeedbackReport extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id', 'client_request_id', 'request_fingerprint',
        'guest_access_hash', 'requester_email', 'user_id', 'course_id',
        'lesson_id', 'order_id', 'assigned_to', 'category', 'status',
        'priority', 'message', 'screen_key', 'platform', 'app_version',
        'build_number', 'os_major', 'locale', 'screen_size', 'font_scale',
        'device_tier', 'network_type', 'context', 'ip_hash', 'user_agent',
        'resolved_at', 'version', 'first_response_due_at',
        'last_user_message_at', 'last_staff_message_at', 'closed_at',
        'reopened_at', 'retention_until', 'resolution_kind',
    ];

    protected $casts = [
        'context' => 'array',
        'resolved_at' => 'datetime',
        'first_response_due_at' => 'datetime',
        'last_user_message_at' => 'datetime',
        'last_staff_message_at' => 'datetime',
        'closed_at' => 'datetime',
        'reopened_at' => 'datetime',
        'retention_until' => 'datetime',
        'version' => 'integer',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function course()
    {
        return $this->belongsTo(Course::class);
    }

    public function lesson()
    {
        return $this->belongsTo(Lesson::class);
    }

    public function assignee()
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function attachments()
    {
        return $this->hasMany(FeedbackAttachment::class);
    }

    public function messages()
    {
        return $this->hasMany(SupportCaseMessage::class);
    }

    public function events()
    {
        return $this->hasMany(SupportCaseEvent::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
