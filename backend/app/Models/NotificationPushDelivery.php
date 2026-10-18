<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class NotificationPushDelivery extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DISPATCHING = 'dispatching';

    public const STATUS_RETRYABLE = 'retryable';

    public const STATUS_ACCEPTED = 'accepted';

    public const STATUS_FAILED = 'failed';

    public const STATUS_UNKNOWN = 'unknown';

    public const STATUS_SUPERSEDED = 'superseded';

    protected $fillable = [
        'student_notification_id',
        'user_device_token_id',
        'token_fingerprint',
        'device_os',
        'status',
        'attempts',
        'attempted_at',
        'accepted_at',
        'failed_at',
        'failure_code',
    ];

    protected $casts = [
        'student_notification_id' => 'integer',
        'user_device_token_id' => 'integer',
        'attempts' => 'integer',
        'attempted_at' => 'datetime',
        'accepted_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function notification()
    {
        return $this->belongsTo(StudentNotification::class, 'student_notification_id');
    }
}
