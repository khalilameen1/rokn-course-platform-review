<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\Package;
use App\Models\RewardRule;
use App\Models\Setting;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

final readonly class AdminEconomyReadService
{
    public function __construct(private SocialAuthProviderRegistry $socialProviders)
    {
    }

    /** @return array{methods:LengthAwarePaginator,setting:?Setting,rewardRules:\Illuminate\Support\Collection,rewardEvents:array<string,string>,socialProviderLabels:array<string,string>} */
    public function rewards(): array
    {
        $methods = CoinEarningMethod::query()
            // The welcome gift is authored through the bounded reward-rule
            // family below. Its historical method row is only the immutable
            // audit source used by login and must not appear as a second task.
            ->where(function ($query): void {
                $query->whereNull('action_key')->orWhere('action_key', '!=', 'register');
            })
            ->withCount('userEarnings')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(12)
            ->withQueryString();
        $rewardEvents = RewardRule::EVENTS;
        // event_key is unique and restricted to EVENTS, so this result is
        // structurally bounded rather than an ever-growing dashboard list.
        $rewardRules = RewardRule::query()
            ->whereIn('event_key', array_keys($rewardEvents))
            ->orderBy('sort_order')
            ->orderBy('id')
            ->limit(count($rewardEvents))
            ->get();

        return [
            'methods' => $methods,
            'setting' => Setting::query()->first(),
            'rewardRules' => $rewardRules,
            'rewardEvents' => $rewardEvents,
            'socialProviderLabels' => $this->socialProviders->labels(),
        ];
    }

    public function packages(): LengthAwarePaginator
    {
        return Package::query()
            ->withCount('orders')
            ->withSum(
                ['orders as confirmed_net_amount' => static fn ($orders) => $orders->financiallyEffective()],
                'gateway_net_amount'
            )
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(30)
            ->withQueryString();
    }
}
