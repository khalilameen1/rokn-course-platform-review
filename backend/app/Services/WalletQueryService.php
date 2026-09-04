<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use App\Models\User;
use App\Models\WalletTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class WalletQueryService
{
    public function __construct(private WalletService $wallet) {}

    /**
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        try {
            $setting = Cache::remember('wallet:public-settings:v2', 30, fn () =>
                Setting::query()->first()
            ) ?? new Setting();
        } catch (Throwable) {
            $setting = Setting::query()->first() ?? new Setting();
        }

        // Every wallet writer serializes on the user row. Read the aggregate
        // behind the same lock so the balance and ledger tail always describe
        // one committed wallet state rather than two adjacent transactions.
        [$balances, $recent] = DB::transaction(function () use ($user): array {
            $freshUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $balances = $this->wallet->balances($freshUser);
            $recent = WalletTransaction::query()
                ->where('user_id', $user->id)
                ->latest('id')
                ->limit(10)
                ->get()
                ->map(fn (WalletTransaction $transaction): array => $this->payload($transaction));

            return [$balances, $recent];
        }, 3);

        $totalBalance = $balances['total'];
        $purchasedBalance = $balances['paid'];
        $rewardBalance = $balances['reward'];
        $rewardContributionCap = max(0, (int) ($setting->max_reward_contribution_per_course ?? 1200));
        $courseSpendableBalance = $purchasedBalance + min($rewardBalance, $rewardContributionCap);

        return [
            'balance' => $totalBalance,
            'total_balance' => $totalBalance,
            'purchased_balance' => $purchasedBalance,
            'reward_balance' => $rewardBalance,
            'course_spendable_balance' => $courseSpendableBalance,
            'reward_contribution_cap_per_course' => $rewardContributionCap,
            'breakdown' => [
                'total_balance' => $totalBalance,
                'purchased_balance' => $purchasedBalance,
                'reward_balance' => $rewardBalance,
                'course_spendable_balance' => $courseSpendableBalance,
                'reward_contribution_cap_per_course' => $rewardContributionCap,
            ],
            'spend_policy' => 'reward_first_then_paid',
            'coin_rules' => $setting->how_to_use_coins,
            'currency_type' => 'rokn_coins',
            'is_withdrawable' => false,
            'recent_transactions' => $recent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function transactions(User $user, int $perPage): array
    {
        $transactions = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->latest('id')
            ->paginate($perPage);

        return [
            'transactions' => $transactions->getCollection()
                ->map(fn (WalletTransaction $item): array => $this->payload($item)),
            'pagination' => [
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
                'total' => $transactions->total(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(WalletTransaction $transaction): array
    {
        $labels = $this->transactionLabels($transaction);

        return [
            'id' => $transaction->public_id,
            'direction' => $transaction->direction,
            'category' => $transaction->category,
            'label_ar' => $labels['ar'],
            'label_en' => $labels['en'],
            'bucket' => $transaction->bucket,
            'amount' => $transaction->amount,
            'paid_coins' => $transaction->paid_amount,
            'reward_coins' => $transaction->reward_amount,
            'balance_after' => $transaction->balance_after,
            'purchased_balance_after' => $transaction->paid_balance_after,
            'reward_balance_after' => $transaction->reward_balance_after,
            'source' => $transaction->source_type ? [
                'type' => class_basename($transaction->source_type),
                'id' => $transaction->source_id,
            ] : null,
            'metadata' => $transaction->metadata,
            'occurred_at' => $transaction->occurred_at?->toIso8601String(),
        ];
    }

    /** @return array{ar:string,en:string} */
    private function transactionLabels(WalletTransaction $transaction): array
    {
        return match ((string) $transaction->category) {
            'package_purchase' => ['ar' => 'شحن رصيد', 'en' => 'Balance top-up'],
            'welcome_bonus' => ['ar' => 'هدية ترحيبية', 'en' => 'Welcome gift'],
            'task_reward' => ['ar' => 'مكافأة مهمة', 'en' => 'Task reward'],
            'daily_learning_reward' => ['ar' => 'مكافأة يومية', 'en' => 'Daily reward'],
            'streak_reward' => ['ar' => 'مكافأة الاستمرارية', 'en' => 'Streak reward'],
            'study_reward' => ['ar' => 'مكافأة التعلّم', 'en' => 'Learning reward'],
            'first_project_reward' => ['ar' => 'مكافأة أول مشروع', 'en' => 'First project reward'],
            'course_completion_reward' => ['ar' => 'مكافأة إتمام كورس', 'en' => 'Course completion reward'],
            'course_purchase' => ['ar' => 'فتح كورس', 'en' => 'Course access'],
            'course_full_track_upgrade' => ['ar' => 'ترقية دعم الكورس', 'en' => 'Course support upgrade'],
            'package_reversal' => ['ar' => 'مراجعة عملية شحن', 'en' => 'Top-up review'],
            'package_reversal_resolution' => ['ar' => 'تسوية عملية شحن', 'en' => 'Top-up resolution'],
            default => $transaction->direction === WalletTransaction::DIRECTION_CREDIT
                ? ['ar' => 'إضافة إلى الرصيد', 'en' => 'Balance credit']
                : ['ar' => 'استخدام من الرصيد', 'en' => 'Balance debit'],
        };
    }
}
