<?php

namespace App\Models;

use App\Support\RoknLocale;
use Illuminate\Database\Eloquent\Model;

class StudentNotification extends Model
{
    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'student_notifications';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'user_id',
        'delivery_key',
        'notification_type',
        'notifiable_type',
        'notifiable_id',
        'title_ar',
        'title_en',
        'message_ar',
        'message_en',
        'link',
        'image_url',
        'action_label_ar',
        'action_label_en',
        'is_read',
        'read_at',
        'push_attempted_at',
        'push_attempts',
        'push_sent_at',
        'push_failed_at',
        'push_failure_code',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'is_read' => 'boolean',
        'read_at' => 'datetime',
        'push_attempted_at' => 'datetime',
        'push_attempts' => 'integer',
        'push_sent_at' => 'datetime',
        'push_failed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    /**
     * Get the user that owns the notification.
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    /**
     * Get the notifiable entity (polymorphic).
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function notifiable()
    {
        return $this->morphTo();
    }

    public function pushDeliveries()
    {
        return $this->hasMany(NotificationPushDelivery::class, 'student_notification_id');
    }

    /**
     * Scope a query to only include unread notifications.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    /**
     * Scope a query to only include read notifications.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    /**
     * Scope a query to filter by notification type.
     *
     * @param \Illuminate\Database\Eloquent\Builder $query
     * @param string $type
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeByType($query, $type)
    {
        return $query->where('notification_type', $type);
    }

    /**
     * Mark the notification as read.
     *
     * @return bool
     */
    public function markAsRead()
    {
        if ($this->is_read) {
            return true;
        }

        $readAt = now();
        $updated = static::query()
            ->whereKey($this->getKey())
            ->where('is_read', false)
            ->update([
                'is_read' => true,
                'read_at' => $readAt,
            ]);
        if ($updated === 1) {
            $this->forceFill(['is_read' => true, 'read_at' => $readAt]);

            return true;
        }

        // A concurrent tap may have won the conditional update. Reflect its
        // first-read timestamp instead of overwriting it with this request.
        $current = $this->fresh();
        if (!$current) {
            return false;
        }
        $this->setRawAttributes($current->getAttributes(), true);

        return (bool) $this->is_read;
    }

    /**
     * Get the localized title based on current locale.
     *
     * @return string
     */
    public function getLocalizedTitle()
    {
        return RoknLocale::isArabic()
            ? ($this->title_ar ?: $this->title_en)
            : ($this->title_en ?: $this->title_ar);
    }

    /**
     * Get the localized message based on current locale.
     *
     * @return string
     */
    public function getLocalizedMessage()
    {
        return RoknLocale::isArabic()
            ? ($this->message_ar ?: $this->message_en)
            : ($this->message_en ?: $this->message_ar);
    }
}

