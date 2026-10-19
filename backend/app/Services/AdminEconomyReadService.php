<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\Package;
use App\Models\Setting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final readonly class AdminEconomyReadService
{
    public function __construct(private SocialAuthProviderRegistry $socialProviders) {}

    /** @return array{methods:LengthAwarePaginator,setting:?Setting,socialProviderLabels:array<string,string>} */
    public function rewards(): array
    {
        $methods = CoinEarningMethod::query()
            // The welcome gift is authored through the bounded reward-rule
            // family below. Its historical method row is only the immutable
            // audit source used by login and must not appear as a second task.
            ->where(function ($query): void {
                $query->whereNull('action_key')->orWhereNotIn('action_key', CoinEarningMethod::AUTOMATIC_ACTION_KEYS);
            })
            ->withCount('userEarnings')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(12)
            ->withQueryString();

        return [
            'methods' => $methods,
            'setting' => Setting::query()->first(),
            'socialProviderLabels' => $this->socialProviders->labels(),
        ];
    }

    /** @return Builder<Package> */
    public function packagesQuery(): Builder
    {
        return Package::query()
            ->withCount('orders')
            ->orderBy('sort_order')
            ->orderBy('id');
    }
}
