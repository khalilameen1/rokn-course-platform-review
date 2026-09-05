<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class UserWhatsAppConnection extends Model
{
    protected $table = 'user_whatsapp_connections';

    protected $fillable = [
        'user_id',
        'phone_e164',
        'declared_at',
        'ownership_verified',
        'verified_at',
        'marketing_opt_in',
        'marketing_consent_at',
        'marketing_withdrawn_at',
        'consent_version',
        'consent_source',
    ];

    protected $casts = [
        'declared_at' => 'datetime',
        'ownership_verified' => 'boolean',
        'verified_at' => 'datetime',
        'marketing_opt_in' => 'boolean',
        'marketing_consent_at' => 'datetime',
        'marketing_withdrawn_at' => 'datetime',
    ];

    public function scopeVerified(Builder $query): Builder
    {
        return $query->where('ownership_verified', true)->whereNotNull('verified_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
