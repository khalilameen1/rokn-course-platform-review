<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Contact extends Model
{
    public const TYPE_ACCOUNT_DELETION = 'account_deletion';

    public const RESOLUTION_PENDING = 'pending';

    public const RESOLUTION_PROCESSING = 'processing';

    public const RESOLUTION_CLOSED = 'closed';

    public const RESOLUTION_FULFILLED = 'fulfilled';

    // Workflow/audit columns are deliberately omitted. Only trusted server
    // code may assign them with forceFill(); public contact payloads cannot.
    protected $fillable = [
        'client_request_id',
        'request_fingerprint',
        'name',
        'email',
        'phone',
        'message',
        'read',
    ];

    protected $casts = [
        'read' => 'boolean',
        'resolved_at' => 'datetime',
        'resolution_metadata' => 'array',
    ];

    public function resolver()
    {
        return $this->belongsTo(User::class, 'resolved_by')->withTrashed();
    }

    public function resolvedUser()
    {
        return $this->belongsTo(User::class, 'resolved_user_id')->withTrashed();
    }

    public function isAccountDeletionRequest(): bool
    {
        return $this->request_type === self::TYPE_ACCOUNT_DELETION
            || str_starts_with((string) $this->message, '[ACCOUNT_DELETION_REQUEST]');
    }

    public function isResolved(): bool
    {
        return in_array($this->resolution_status, [self::RESOLUTION_CLOSED, self::RESOLUTION_FULFILLED], true)
            && $this->resolved_at !== null;
    }

    public function isProcessing(): bool
    {
        return $this->resolution_status === self::RESOLUTION_PROCESSING;
    }
}
