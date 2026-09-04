<?php

declare(strict_types=1);

namespace App\Models;

use App\Traits\InvalidatesCourseCatalogue;
use Illuminate\Database\Eloquent\Model;

final class LessonMediaState extends Model
{
    use InvalidatesCourseCatalogue;

    protected $fillable = [
        'lesson_id', 'provider', 'provider_media_id', 'status', 'protocol',
        'duration_seconds', 'available_qualities', 'manifest', 'last_probe_at',
        'last_error_code', 'last_error_message', 'retry_count',
        'integrity_status', 'integrity_issues', 'last_reconciled_at',
        'quarantined_at',
        'probe_generation',
    ];

    protected $casts = [
        'duration_seconds' => 'integer',
        'available_qualities' => 'array',
        'manifest' => 'array',
        'integrity_issues' => 'array',
        'last_probe_at' => 'datetime',
        'last_reconciled_at' => 'datetime',
        'quarantined_at' => 'datetime',
        'retry_count' => 'integer',
        'probe_generation' => 'integer',
    ];

    /** Every provider-media generation owns all of its derived health fields. */
    public static function resetForGeneration(string $providerMediaId, string $status = 'processing'): array
    {
        return [
            'provider' => 'bunny',
            'provider_media_id' => $providerMediaId,
            'status' => $status,
            'protocol' => 'hls',
            'duration_seconds' => null,
            'available_qualities' => ['auto'],
            'manifest' => null,
            'last_probe_at' => null,
            'last_error_code' => null,
            'last_error_message' => null,
            'retry_count' => 0,
            'integrity_status' => 'unknown',
            'integrity_issues' => null,
            'last_reconciled_at' => null,
            'quarantined_at' => null,
        ];
    }

    public function shouldInvalidateCourseCatalogue(): bool
    {
        return $this->wasRecentlyCreated || $this->wasChanged([
            'provider_media_id',
            'status',
            'duration_seconds',
            'integrity_status',
            'last_reconciled_at',
            'quarantined_at',
        ]);
    }
}
