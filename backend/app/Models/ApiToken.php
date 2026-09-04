<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class ApiToken extends Model
{
    protected $guarded = [];

    public $timestamps = false;

    protected $primaryKey = 'token';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $casts = [
        'issued_at' => 'immutable_datetime',
        'expired_at' => 'immutable_datetime',
        'last_used_at' => 'immutable_datetime',
        'revoked_at' => 'immutable_datetime',
    ];

    public function __construct(array $attributes = [])
    {
        parent::__construct($attributes);
        $this->setTable((string) config('multiple-tokens-auth.table', 'api_tokens'));
    }

    public function shouldExtendLife(): bool
    {
        if ($this->hasExpired()) {
            return false;
        }

        return $this->expired_at->isBefore(
            now()->addDays((int) config('multiple-tokens-auth.token.extend_life_at', 10))
        );
    }

    public function hasExpired(): bool
    {
        return $this->expired_at === null || $this->expired_at->isPast();
    }

    public function scopeWhereHasExpired(Builder $query): Builder
    {
        return $query->where('expired_at', '<=', now());
    }

    public function scopeWhereHasNotExpired(Builder $query): Builder
    {
        return $query->where('expired_at', '>', now())->whereNull('revoked_at');
    }

    public function revoke(): void
    {
        $this->forceFill(['revoked_at' => now()])->save();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
