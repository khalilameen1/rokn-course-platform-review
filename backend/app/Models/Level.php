<?php

namespace App\Models;

use App\Services\AppArtworkService;
use App\Support\PublicDiskUrl;
use App\Traits\HasPhoto;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Level extends Model
{
    use HasFactory, HasPhoto;

    protected $fillable = [
        'name_ar',
        'name_en',
        'badge_image',
        'description_ar',
        'description_en',
        'order',
        'authoring_request_id',
    ];

    /**
     * Get the courses for this level.
     */
    public function courses(): HasMany
    {
        return $this->hasMany(Course::class);
    }

    /**
     * Get the users who have earned this level badge.
     */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_level')
            ->withPivot('id', 'earned_at', 'course_id')
            ->withTimestamps();
    }

    /**
     * Scope for ordered levels.
     */
    public function scopeOrdered($query)
    {
        return $query->orderBy('order');
    }

    /**
     * Get the full URL for the badge image.
     *
     * @return string|null
     */
    public function getBadgeImageUrlAttribute()
    {
        if ($this->badge_image) {
            if (filter_var($this->badge_image, FILTER_VALIDATE_URL)) {
                return $this->badge_image;
            }
            if (str_starts_with(ltrim($this->badge_image, '/'), 'assets/')) {
                if (in_array(ltrim($this->badge_image, '/'), [
                    'assets/img/badges/junior.png',
                    'assets/img/badges/mid-level.png',
                    'assets/img/badges/senior.png',
                ], true)) {
                    return app(AppArtworkService::class)->levelDefault((int) $this->order);
                }

                return asset(ltrim($this->badge_image, '/'));
            }

            return PublicDiskUrl::from((string) $this->badge_image);
        }

        // Fallback to HasPhoto image if available
        if ($this->image) {
            return $this->image;
        }

        return app(AppArtworkService::class)->levelDefault((int) $this->order);
    }
}
