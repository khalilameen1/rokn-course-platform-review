<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class FeedbackAttachment extends Model
{
    protected $fillable = [
        'feedback_report_id', 'support_case_message_id', 'disk', 'path',
        'mime_type', 'size_bytes', 'width', 'height', 'sha256', 'scan_status',
    ];

    /** @return BelongsTo<FeedbackReport, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }

    /** @return BelongsTo<SupportCaseMessage, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(SupportCaseMessage::class, 'support_case_message_id');
    }
}
