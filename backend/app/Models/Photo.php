<?php

namespace App\Models;

use App\Services\CourseCatalogueRevisionService;
use App\Services\StoredFileDeletionService;
use App\Support\PublicDiskUrl;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Photo extends Model
{
    /**
     * @var array
     */
    protected $fillable = ['path', 'preview_path', 'type'];

    protected static function boot()
    {
        parent::boot();
        static::deleted(function (Photo $photo): void {
            // Reserve cleanup while the owner deletion can still roll back.
            // StoredFileDeletionService defers only the broker dispatch until
            // commit and its worker checks references again before deleting.
            foreach (array_unique(array_filter([$photo->path, $photo->preview_path])) as $path) {
                app(StoredFileDeletionService::class)->deleteOrQueue('public', $path);
            }
        });
        static::saved(fn (Photo $photo) => $photo->invalidateCourseCatalogue());
        static::deleted(fn (Photo $photo) => $photo->invalidateCourseCatalogue());
    }

    /**
     * @return mixed
     */
    public function scopeFeatured($query)
    {
        return $query->where('type', 'featured');
    }

    /**
     * Gets the owning models.
     */
    public function photoable(): MorphTo
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
        if (! $affectsCatalogue) {
            return;
        }
        app(CourseCatalogueRevisionService::class)->invalidateAfterCommit();
    }
}
