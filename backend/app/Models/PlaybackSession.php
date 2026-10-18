<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class PlaybackSession extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'user_id', 'lesson_id', 'course_section_id', 'last_sequence',
        'last_position_seconds', 'duration_seconds', 'started_at',
        'last_heartbeat_at', 'ended_at', 'event_type', 'end_reason',
        'source_protocol', 'effective_quality', 'source_host', 'playback_rate',
        'recovery_count', 'last_error_code', 'diagnostics', 'client_name',
        'app_version', 'os_family', 'os_version', 'connection_type',
        'client_capabilities', 'playback_reason', 'source_expires_at',
        'started_playing_at', 'startup_latency_ms', 'buffer_count',
        'buffer_duration_ms', 'effective_bitrate_kbps', 'metrics_rolled_up_at',
    ];

    protected $casts = [
        'last_sequence' => 'integer',
        'last_position_seconds' => 'integer',
        'duration_seconds' => 'integer',
        'playback_rate' => 'float',
        'recovery_count' => 'integer',
        'startup_latency_ms' => 'integer',
        'buffer_count' => 'integer',
        'buffer_duration_ms' => 'integer',
        'effective_bitrate_kbps' => 'integer',
        'diagnostics' => 'array',
        'client_capabilities' => 'array',
        'started_at' => 'datetime',
        'started_playing_at' => 'datetime',
        'last_heartbeat_at' => 'datetime',
        'ended_at' => 'datetime',
        'source_expires_at' => 'datetime',
        'metrics_rolled_up_at' => 'datetime',
    ];
}
