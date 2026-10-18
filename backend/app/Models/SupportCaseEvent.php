<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SupportCaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'feedback_report_id', 'actor_id', 'event_type', 'from_status',
        'to_status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    /** @return BelongsTo<FeedbackReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
