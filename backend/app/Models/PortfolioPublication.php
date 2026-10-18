<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last admin-approved portfolio content. It stays live while a newer
 * draft waits for review and is replaced only by the next approval.
 */
final class PortfolioPublication extends Model
{
    protected $fillable = [
        'user_id', 'revision', 'content_hash', 'content', 'approved_by', 'published_at',
    ];

    protected $casts = [
        'revision' => 'integer',
        'published_at' => 'datetime',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
