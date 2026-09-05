<?php

namespace App\Models;

use App\Support\PublicDiskUrl;
use Illuminate\Database\Eloquent\Model;
use App\Services\StoredFileDeletionService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class Photo extends Model
{
    /**
     * @var array
     */
    protected $fillable = ['path', 'type'];

    protected static function boot()
    {
        parent::boot();
        static::deleted(function (Photo $photo): void {
            $path = (string) $photo->path;
            // Reserve cleanup while the owner deletion can still roll back.
            // StoredFileDeletionService defers only the broker dispatch until
            // commit and its worker checks references again before deleting.
            if (!Photo::query()->where('path', $path)->exists()) {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $path);
            }
        });
        static::saved(fn (Photo $photo) => $photo->invalidateCourseCatalogue());
        static::deleted(fn (Photo $photo) => $photo->invalidateCourseCatalogue());
    }

    /**
     * @param $query
     * @return mixed
     */
    public function scopeFeatured($query)
    {
        return $query->where('type', 'featured');
    }

    /**
     * Gets the owning models.
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function photoable()
    {
        return $this->morphTo();
    }

    /**
     * @return string
     */
    public function assetPath()
    {
        return PublicDiskUrl::from($this->path);
    }

    private function invalidateCourseCatalogue(): void
    {
        $owner = $this->photoable;
        $affectsCatalogue = $owner instanceof Course
            || ($owner instanceof User
                && in_array(strtolower((string) $owner->role), ['teacher', 'admin'], true)
                && $owner->teachingCourses()->exists());
        if (!$affectsCatalogue) {
            return;
        }
        $increment = static function (): void {
            try {
                Cache::add(
                    'courses:catalog-revision',
                    max(1, (int) floor(microtime(true) * 1000)),
                    now()->addYears(10)
                );
                Cache::increment('courses:catalog-revision');
            } catch (\Throwable) {
                // Image persistence must not depend on the cache service.
            }
        };
        DB::transactionLevel() > 0 ? DB::afterCommit($increment) : $increment();
    }
}
