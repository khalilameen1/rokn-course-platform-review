<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class StorePurchase extends Model
{
    public const PROVIDER_GOOGLE = 'google';
    public const PROVIDER_APPLE = 'apple';

    protected $fillable = [
        'public_id',
        'user_id',
        'package_id',
        'order_id',
        'provider',
        'product_id',
        'external_transaction_id',
        'purchase_token_hash',
        'purchase_token',
        'environment',
        'status',
        'provider_payload',
        'verified_at',
        'finalized_at',
        'finalization_retry_at',
    ];

    protected $hidden = ['purchase_token', 'purchase_token_hash', 'provider_payload'];

    protected $casts = [
        'purchase_token' => 'encrypted',
        'provider_payload' => 'array',
        'verified_at' => 'datetime',
        'finalized_at' => 'datetime',
        'finalization_retry_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function package()
    {
        return $this->belongsTo(Package::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }
}
