<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use App\Services\PortfolioModerationService;

class PortfolioMedia extends Model
{
    protected $table = 'portfolio_media';

    protected $fillable = [
        'portfolio_item_id',
        'public_id',
        'client_request_id',
        'file_path',
        'content_sha256',
        'mime_type',
        'size_bytes',
        'original_name',
        'file_type',
        'caption',
        'thumbnail_path',
        'width',
        'height',
        'duration_seconds',
        'sort_order',
        'deletion_lease_id',
        'deletion_started_at',
    ];

    protected $casts = [
        'size_bytes' => 'integer',
        'deletion_started_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function (PortfolioMedia $media): void {
            if (!$media->public_id) $media->public_id = (string) Str::uuid();
        });
        $invalidate = static function (PortfolioMedia $media): void {
            $item = $media->portfolioItem()->first();
            if ($item?->is_public) PortfolioModerationService::invalidate((int) $item->user_id);
        };
        static::saved(function (PortfolioMedia $media) use ($invalidate): void {
            if ($media->wasRecentlyCreated || $media->wasChanged([
                'public_id', 'file_path', 'file_type', 'content_sha256', 'caption',
                'width', 'height', 'duration_seconds', 'sort_order', 'deletion_lease_id',
            ])) $invalidate($media);
        });
        static::deleted($invalidate);
    }

    public function scopeAvailable(Builder $query): Builder
    {
        return $query->whereNull('deletion_lease_id');
    }

    public function portfolioItem()
    {
        return $this->belongsTo(PortfolioItem::class);
    }
}
