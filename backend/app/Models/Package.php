<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

class Package extends Model
{
    protected $fillable = [
        'name_ar', 'name_en', 'price', 'coins',
        'is_active', 'direct_enabled', 'sort_order',
        'google_product_id', 'apple_product_id',
        'google_enabled', 'apple_enabled',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'coins' => 'integer',
        'is_active' => 'boolean',
        'direct_enabled' => 'boolean',
        'google_enabled' => 'boolean',
        'apple_enabled' => 'boolean',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (Package $package): void {
            $hasIssuedStoreContract = trim((string) $package->getOriginal('google_product_id')) !== ''
                || trim((string) $package->getOriginal('apple_product_id')) !== '';
            if ($hasIssuedStoreContract && $package->isDirty('coins')) {
                throw new \DomainException(
                    'عدد عملات منتج متجر منشور ثابت. أنشئ باقة ومنتجًا جديدين.'
                );
            }
            foreach (['google_product_id', 'apple_product_id'] as $productField) {
                if (
                    trim((string) $package->getOriginal($productField)) !== ''
                    && $package->isDirty($productField)
                ) {
                    throw new \DomainException(
                        'معرّف منتج المتجر المنشور ثابت. عطّل المنتج وأنشئ باقة جديدة.'
                    );
                }
            }
        });

        $invalidatePublicPackages = static function (): void {
            $forget = static fn (): bool => Cache::forget('public-packages:v2');
            try {
                if (DB::transactionLevel() > 0) {
                    DB::afterCommit($forget);
                    return;
                }

                $forget();
            } catch (Throwable $exception) {
                // Package persistence is authoritative. Cache failure is
                // observable but cannot turn a committed finance edit into a
                // retry that creates a second package.
                report($exception);
            }
        };
        static::saved($invalidatePublicPackages);
        static::deleted($invalidatePublicPackages);
    }

    public function scopePurchasable($query)
    {
        return $query->where(function ($channels): void {
            $channels->where('direct_enabled', true)
                ->orWhere(function ($google): void {
                    $google->where('google_enabled', true)
                        ->whereNotNull('google_product_id')
                        ->where('google_product_id', '!=', '');
                })
                ->orWhere(function ($apple): void {
                    $apple->where('apple_enabled', true)
                        ->whereNotNull('apple_product_id')
                        ->where('apple_product_id', '!=', '');
                });
        });
    }

    /** @return array{direct:bool,google:bool,apple:bool} */
    public function availableChannels(): array
    {
        return [
            'direct' => (bool) $this->direct_enabled,
            'google' => (bool) $this->google_enabled
                && trim((string) $this->google_product_id) !== '',
            'apple' => (bool) $this->apple_enabled
                && trim((string) $this->apple_product_id) !== '',
        ];
    }

    public function hasPurchasableChannel(): bool
    {
        return in_array(true, $this->availableChannels(), true);
    }

    /**
     * Get the purchases of this package.
     */
    public function purchases()
    {
        return $this->belongsToMany(User::class, 'package_user')
                    ->withPivot('order_id', 'price', 'coins', 'created_at')
                    ->withTimestamps();
    }

    public function storePurchases()
    {
        return $this->hasMany(StorePurchase::class);
    }

    public function orders()
    {
        return $this->hasMany(Order::class);
    }
}
