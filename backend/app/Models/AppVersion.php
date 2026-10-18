<?php

namespace App\Models;

use App\Services\AppReleaseChannelService;
use App\Services\PublicAppSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Throwable;

class AppVersion extends Model
{
    protected static function booted(): void
    {
        $invalidate = static function (): void {
            $flushReleaseProjection = static function (): void {
                try {
                    // Public settings consume the release projection. Drop the
                    // dependency first, then its consumer; the reverse order
                    // leaves a window where public settings can be rebuilt
                    // from the old release and remain stale for five minutes.
                    AppReleaseChannelService::invalidate();
                } catch (Throwable $exception) {
                    report($exception);
                }

                PublicAppSettingsService::invalidate();
            };
            try {
                if (DB::transactionLevel() > 0) {
                    DB::afterCommit($flushReleaseProjection);

                    return;
                }

                $flushReleaseProjection();
            } catch (Throwable $exception) {
                report($exception);
            }
        };
        static::saved($invalidate);
        static::deleted($invalidate);
    }

    protected $fillable = [
        'platform',
        'distribution_channel',
        'version_name',
        'version_code',
        'build_number',
        'is_force_update',
        'is_active',
        'update_message_ar',
        'update_message_en',
        'download_url',
        'release_notes_ar',
        'release_notes_en',
    ];

    protected $casts = [
        'version_code' => 'integer',
        'build_number' => 'integer',
        'is_force_update' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePlatform($query, $platform)
    {
        return $query->where('platform', $platform);
    }
}
