<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

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

    public function report()
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }

    public function author()
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function attachments()
    {
        return $this->hasMany(FeedbackAttachment::class, 'support_case_message_id');
    }
}
