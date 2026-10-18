<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCheckoutRejected;
use App\Models\Package;
use App\Models\Setting;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
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

    /**
     * A course buys only its missing credit at the dashboard's published rate.
     * The package remains the pricing basis, not a new catalogue denomination.
     * Brick Math is already shipped by Laravel; all proportional money is rounded
     * once to currency precision instead of multiplying binary floating points.
     * Store products and standalone package purchases keep their full contract.
     *
     * A gateway charge below its fixed fee loses money, so a shortfall worth
     * less than the configured minimum cash charge is rounded up to the fewest
     * whole coins at the same rate that reach it. The surplus is ordinary paid
     * credit: the funding order credits `coins` and the course debits only the
     * shortfall. `shortfall_coins` keeps the exact missing amount visible.
     *
     * @return array<string, mixed>
     */
    public function courseFundingPayload(Package $package, int $coins): array
    {
        if ($coins < 1 || (int) $package->coins < 1) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::CHECKOUT_FUNDING_AMOUNT_INVALID);
        }
        $payload = $this->packagePayload($package);
        if (! $payload['channels']['direct']) {
            throw new CourseCheckoutRejected(CourseCheckoutRejected::CHECKOUT_PACKAGE_UNAVAILABLE);
        }
        $shortfall = $coins;
        $coins = max($shortfall, $this->coinsForMinimumCharge($package, (float) $payload['direct_price']));
        $proportional = static fn (string $amount): float => max(0.01,
            BigDecimal::of($amount)->multipliedBy($coins)
                ->dividedBy((int) $package->coins, 2, RoundingMode::HalfUp)->toFloat()
        );

        return array_replace($payload, [
            'funding_mode' => 'exact_shortfall',
            'pricing_basis' => [
                'coins' => (int) $package->coins,
                'price' => (float) $package->price,
                'direct_price' => $payload['direct_price'],
            ],
            'coins' => $coins,
            'shortfall_coins' => $shortfall,
            'price' => $proportional((string) $package->price),
            'direct_price' => $proportional(number_format($payload['direct_price'], 2, '.', '')),
        ]);
    }

    /** Minimum Kashier cash charge for course funding, in EGP; 0 disables it. */
    public function minimumCourseFundingAmount(): float
    {
        return round(max(0, (float) config('kashier.minimum_course_funding_amount', 0)), 2);
    }

    /** Fewest whole coins whose cash value at this package's direct rate reaches the minimum. */
    private function coinsForMinimumCharge(Package $package, float $directPrice): int
    {
        $minimum = $this->minimumCourseFundingAmount();
        if ($minimum <= 0 || $directPrice <= 0) {
            return 0;
        }

        return BigDecimal::of(number_format($minimum, 2, '.', ''))
            ->multipliedBy((int) $package->coins)
            ->dividedBy(number_format($directPrice, 2, '.', ''), 0, RoundingMode::Up)
            ->toInt();
    }

    /**
     * The three smallest purchasable packages that cover a coin shortfall,
     * offered beside a refused purchase or an upgrade quote.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function recommendedPackages(int $minimumCoins): Collection
    {
        return Package::query()
            ->where('is_active', true)
            ->where('coins', '>=', $minimumCoins)
            ->where('coins', '>', 0)
            ->where('price', '>', 0)
            ->purchasable()
            ->orderBy('coins')
            ->limit(3)
            ->get()
            ->map(fn (Package $package): array => $this->packagePayload($package));
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
