<?php

namespace App\Models;

use App\Services\PortfolioModerationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class PortfolioItem extends Model
{
    protected static function booted(): void
    {
        static::creating(function (PortfolioItem $item): void {
            if (! $item->public_id) {
                $item->public_id = (string) Str::uuid();
            }
        });
        // Capture a pre-publication approval before the first change moves the draft.
        static::saving(fn (PortfolioItem $item) => PortfolioModerationService::preserveLegacyPublication((int) $item->user_id));
        static::deleting(fn (PortfolioItem $item) => PortfolioModerationService::preserveLegacyPublication((int) $item->user_id));
        static::saved(function (PortfolioItem $item): void {
            if (($item->is_public || $item->getRawOriginal('is_public'))
                && ($item->wasRecentlyCreated || $item->wasChanged([
                    'title', 'description', 'role', 'tools', 'external_url', 'completed_at',
                    'is_public', 'is_featured', 'sort_order', 'course_id', 'deletion_started_at',
                    'hidden_at',
                ]))) {
                PortfolioModerationService::invalidate((int) $item->user_id);
            }
        });
        static::deleted(function (PortfolioItem $item): void {
            if ($item->is_public) {
                PortfolioModerationService::invalidate((int) $item->user_id);
            }
        });
    }

    protected $fillable = [
        'user_id',
        'client_request_id',
        'request_fingerprint',
        'course_id',
        'source_project_id',
        'title',
        'description',
        'slug',
        'role',
        'tools',
        'external_url',
        'completed_at',
        'is_public',
        'hidden_at',
        'cover_media_id',
        'is_featured',
        'sort_order',
        'expected_media_count',
        'deletion_started_at',
    ];

    protected $casts = [
        'tools' => 'array',
        'completed_at' => 'date',
        'is_public' => 'boolean',
        'hidden_at' => 'datetime',
        'cover_media_id' => 'integer',
        'is_featured' => 'boolean',
        'sort_order' => 'integer',
        'expected_media_count' => 'integer',
        'deletion_started_at' => 'datetime',
    ];

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('deletion_started_at');
    }

    public function scopeShareable(Builder $query): Builder
    {
        return $query->available()
            ->where('is_public', true)
            ->whereNull('hidden_at')
            ->whereHas('mediaFiles', fn ($media) => $media->available());
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<PortfolioMedia, $this> */
    public function mediaFiles(): HasMany
    {
        return $this->hasMany(PortfolioMedia::class)
            ->orderBy('sort_order')
            ->orderBy('id');
    }

    /** @return BelongsTo<Course, $this> */
    public function course(): BelongsTo
    {
        return $this->belongsTo(Course::class)->withTrashed();
    }

    /** @return BelongsTo<Project, $this> */
    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'source_project_id');
    }
}
