<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class FeedbackAttachment extends Model
{
    protected $fillable = [
        'feedback_report_id', 'support_case_message_id', 'disk', 'path',
        'mime_type', 'size_bytes', 'width', 'height', 'sha256', 'scan_status',
    ];

    public function report()
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }

    public function message()
    {
        return $this->belongsTo(SupportCaseMessage::class, 'support_case_message_id');
    }
}
