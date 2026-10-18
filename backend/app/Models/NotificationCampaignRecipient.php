<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class NotificationCampaignRecipient extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_DELIVERING = 'delivering';

    public const STATUS_INBOX = 'inbox';

    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'notification_campaign_id',
        'user_id',
        'status',
        'attempts',
        'resolution_code',
        'claimed_at',
        'resolved_at',
    ];

    protected $casts = [
        'notification_campaign_id' => 'integer',
        'user_id' => 'integer',
        'attempts' => 'integer',
        'claimed_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    /** @return BelongsTo<NotificationCampaign, $this> */
    public function campaign(): BelongsTo
    {
        return $this->belongsTo(NotificationCampaign::class, 'notification_campaign_id');
    }
}
