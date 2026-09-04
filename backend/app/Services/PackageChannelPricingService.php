<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Package;
use App\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Throwable;

final class PackageChannelPricingService
{
    public function directDiscountPercent(): float
    {
        $load = static function (): float {
            return min(50, max(0, (float) (
                Setting::query()->value('direct_checkout_discount_percent') ?? 10
            )));
        };

        try {
            return (float) Cache::remember('packages:direct-discount:v2', 60, $load);
        } catch (Throwable) {
            return $load();
        }
    }

    public function directPrice(Package $package, ?float $discountPercent = null): float
    {
        $price = max(0, (float) $package->price);
        $discount = $discountPercent ?? $this->directDiscountPercent();
        $discounted = $price * (1 - (min(50, max(0, $discount)) / 100));

        return round(max(0.01, $discounted), 2);
    }

    /** @return array<string, mixed> */
    public function packagePayload(Package $package, ?float $discountPercent = null): array
    {
        $discountPercent ??= $this->directDiscountPercent();
        $channels = $package->availableChannels();

        return [
            'id' => $package->id,
            'name' => $package->name_ar,
            'name_ar' => $package->name_ar,
            'name_en' => $package->name_en,
            'price' => (float) $package->price,
            'direct_price' => $channels['direct']
                ? $this->directPrice($package, $discountPercent)
                : null,
            'direct_discount_percent' => $discountPercent,
            'coins' => (int) $package->coins,
            'store_products' => [
                'google' => $channels['google']
                    ? $package->google_product_id
                    : null,
                'apple' => $channels['apple']
                    ? $package->apple_product_id
                    : null,
            ],
            'channels' => $channels,
        ];
    }
}
