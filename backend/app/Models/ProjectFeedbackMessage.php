<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ProjectFeedbackMessage extends Model
{
    public const QUEUED = 'queued';

    public const SENT = 'sent';

    public const STREAMING = 'streaming';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    protected $guarded = [];

    protected $casts = [
        'reserved_tokens' => 'integer',
        'completed_at' => 'datetime',
        'provider_annotations' => 'array',
    ];

    /** @return BelongsTo<ProjectFeedbackThread, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(ProjectFeedbackThread::class, 'thread_id');
    }

    /** @return BelongsTo<AiUsageEvent, $this> */
    public function usageEvent(): BelongsTo
    {
        return $this->belongsTo(AiUsageEvent::class, 'usage_event_id');
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
