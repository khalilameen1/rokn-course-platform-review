<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class AiInputAttachment extends Model
{
    public const PURPOSE_COURSE_CHAT = 'course_chat';

    public const PURPOSE_PROJECT_SUBMISSION = 'project_submission';

    public const PURPOSE_PROJECT_FOLLOWUP = 'project_followup';

    public const OWNER_COURSE_CHAT_TURN = 'course_chat_turn';

    public const OWNER_PROJECT_SUBMISSION = 'project_submission';

    public const OWNER_PROJECT_FEEDBACK_MESSAGE = 'project_feedback_message';

    public const ALLOCATING = 'allocating';

    public const READY = 'ready';

    public const DELETING = 'deleting';

    protected $guarded = [];

    protected $casts = [
        'size_bytes' => 'integer',
        'provider_annotations' => 'array',
        'processed_at' => 'datetime',
    ];

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
