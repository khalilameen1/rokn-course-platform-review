<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class OperationalIncident extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    protected $fillable = [
        'code', 'category', 'severity', 'status', 'summary', 'affected_count',
        'occurrence_count', 'metadata', 'first_seen_at', 'last_seen_at',
        'last_alerted_at', 'resolved_at',
    ];

    protected $casts = [
        'affected_count' => 'integer',
        'occurrence_count' => 'integer',
        'metadata' => 'array',
        'first_seen_at' => 'datetime',
        'last_seen_at' => 'datetime',
        'last_alerted_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];
}
