<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\CourseCheckoutRejected;
use App\Models\Package;
use App\Models\Setting;
use App\Support\Money;
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
        $price = Money::ofEgp($package->price)->max(0);
        $discount = min(50, max(0, $discountPercent ?? $this->directDiscountPercent()));

        return $price->mul(Money::ofEgp(100)->sub($discount))->div(100)->max('0.01')->toFloat();
    }

    /**
     * A course buys only its missing credit at the dashboard's published rate.
     * The package remains the pricing basis, not a new catalogue denomination.
     * All proportional money goes through Money (Brick Math) and is rounded
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
        $directPrice = Money::ofEgp($payload['direct_price']);
        $coins = max($shortfall, $this->coinsForMinimumCharge($package, $directPrice));
        $proportional = static fn (Money $amount): float => $amount->mul($coins)
            ->div((int) $package->coins)->max('0.01')->toFloat();

        return array_replace($payload, [
            'funding_mode' => 'exact_shortfall',
            'pricing_basis' => [
                'coins' => (int) $package->coins,
                'price' => Money::ofEgp($package->price)->toFloat(),
                'direct_price' => $payload['direct_price'],
            ],
            'coins' => $coins,
            'shortfall_coins' => $shortfall,
            'price' => $proportional(Money::ofEgp($package->price)),
            'direct_price' => $proportional($directPrice),
        ]);
    }

    /** Minimum Kashier cash charge for course funding, in EGP; 0 disables it. */
    public function minimumCourseFundingAmount(): Money
    {
        return Money::ofEgp(config('kashier.minimum_course_funding_amount', 0))->max(0)->toScale();
    }

    /** Fewest whole coins whose cash value at this package's direct rate reaches the minimum. */
    private function coinsForMinimumCharge(Package $package, Money $directPrice): int
    {
        $minimum = $this->minimumCourseFundingAmount();
        if ($minimum->isNegativeOrZero() || $directPrice->isNegativeOrZero()) {
            return 0;
        }

        return $minimum->mul((int) $package->coins)
            ->div($directPrice->toScale(), 0, RoundingMode::Up)
            ->toBigDecimal()
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
            'price' => Money::ofEgp($package->price)->toFloat(),
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
