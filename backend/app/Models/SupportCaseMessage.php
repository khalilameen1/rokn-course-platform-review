<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SupportCaseMessage extends Model
{
    public const AUTHOR_LEARNER = 'learner';

    public const AUTHOR_STAFF = 'staff';

    public const AUTHOR_SYSTEM = 'system';

    public const VISIBILITY_CUSTOMER = 'customer';

    public const VISIBILITY_INTERNAL = 'internal';

    protected $fillable = [
        'public_id', 'feedback_report_id', 'author_id', 'author_type',
        'visibility', 'body', 'client_request_id', 'request_fingerprint',
    ];

    /** @return BelongsTo<FeedbackReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }

    /** @return BelongsTo<User, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    /** @return HasMany<FeedbackAttachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(FeedbackAttachment::class, 'support_case_message_id');
    }
}
