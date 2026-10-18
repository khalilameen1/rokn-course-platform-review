<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class WebhookEndpoint extends Model
{
    protected $fillable = [
        'name', 'url', 'secret', 'events', 'is_active', 'timeout_seconds',
    ];

    protected $casts = [
        'secret' => 'encrypted',
        'events' => 'array',
        'is_active' => 'boolean',
        'timeout_seconds' => 'integer',
    ];

    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function accepts(string $topic): bool
    {
        $events = $this->events ?: ['*'];

        return in_array('*', $events, true) || in_array($topic, $events, true);
    }
}
