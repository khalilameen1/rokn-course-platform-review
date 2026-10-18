<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class SupportCaseEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'feedback_report_id', 'actor_id', 'event_type', 'from_status',
        'to_status', 'metadata',
    ];

    protected $casts = ['metadata' => 'array', 'created_at' => 'datetime'];

    public function report()
    {
        return $this->belongsTo(FeedbackReport::class, 'feedback_report_id');
    }

    public function actor()
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
