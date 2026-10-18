<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

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

    /** @return BelongsTo<Lesson, $this> */
    public function lesson(): BelongsTo
    {
        return $this->belongsTo(Lesson::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    /** @return HasMany<FeedbackAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(FeedbackAttachment::class);
    }

    /** @return HasMany<SupportCaseMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(SupportCaseMessage::class);
    }

    /** @return HasMany<SupportCaseEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SupportCaseEvent::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
