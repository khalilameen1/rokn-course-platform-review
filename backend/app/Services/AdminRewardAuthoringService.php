<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\CoinEarningMethod;
use App\Models\CourseAccessPlan;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Support\AdminSingletonLock;
use App\Support\RewardConfigurationVersion;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Owns reward configuration writes, locked admission and commercial invariants. */
final class AdminRewardAuthoringService
{
    public function __construct(private readonly CoursePlanEconomicsService $economics)
    {
    }

    /** @param array<string, mixed> $validated Validated settings fields, without editor metadata. */
    public function updateSettings(array $validated, string $editorVersion): void
    {
        DB::transaction(function () use ($validated, $editorVersion): void {
            AdminSingletonLock::acquire('settings');
            $setting = Setting::query()->lockForUpdate()->first();
            if (!$setting) {
                if (!hash_equals(RewardConfigurationVersion::settings(null), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => "تغيّرت قواعد العملات منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                    ]);
                }
                $setting = Setting::query()->create([]);
            } elseif (!hash_equals(RewardConfigurationVersion::settings($setting), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّرت قواعد العملات منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                ]);
            }
            $proposed = clone $setting;
            $proposed->forceFill($validated);
            $activeRules = RewardRule::query()->active()->orderBy('id')->lockForUpdate()->get();
            $activeMethods = CoinEarningMethod::query()
                ->where('is_active', true)
                ->where(function ($query): void {
                    $query->whereNull('action_key')->orWhere('action_key', '!=', 'register');
                })
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $this->ensureRewardsFitProposedBalanceCap($proposed, $activeRules, $activeMethods);
            if (array_key_exists('max_course_promotion_percent', $validated)
                && (int) $validated['max_course_promotion_percent'] !== (int) $setting->max_course_promotion_percent) {
                $economics = $this->economics;
                CourseAccessPlan::query()->where('is_active', true)->orderBy('id')
                    ->chunkById(200, function ($plans) use ($economics, $validated): void {
                        foreach ($plans as $plan) {
                            try {
                                $economics->assertCommercialFloor(
                                    $plan->getAttributes(), $plan->code, (int) $validated['max_course_promotion_percent']
                                );
                            } catch (ValidationException $exception) {
                                throw ValidationException::withMessages([
                                    'max_course_promotion_percent' => [
                                        "راجع تسعير الكورس {$plan->course_id} قبل زيادة الخصم أو تغيير حدّه",
                                    ],
                                ]);
                            }
                        }
                    });
            }
            $setting->update($validated);
        }, 3);
    }

    /**
     * @param array<string, mixed> $payload Validated, normalized editor values.
     * @param Closure(CoinEarningMethod):void $completeIntent Runs inside the same transaction.
     */
    public function createMethod(array $payload, Closure $completeIntent): CoinEarningMethod
    {
        return DB::transaction(function () use ($payload, $completeIntent): CoinEarningMethod {
            AdminSingletonLock::acquire('settings');
            $this->ensureUsableDestination($payload);
            $this->ensureExecutableCoinMethod($payload, $this->lockedSettings());
            $method = CoinEarningMethod::create($payload);
            $completeIntent($method);

            return $method;
        }, 3);
    }

    /**
     * @param array<string, mixed> $payload Validated, normalized editor values.
     * @param Closure(RewardRule):void $completeIntent Runs inside the same transaction.
     */
    public function createRule(array $payload, Closure $completeIntent): RewardRule
    {
        return DB::transaction(function () use ($payload, $completeIntent): RewardRule {
            AdminSingletonLock::acquire('settings');
            $settings = $this->lockedSettings();
            $this->ensureExecutableRewardRule($payload, $settings);
            $rule = RewardRule::create($payload);
            $completeIntent($rule);

            return $rule;
        }, 3);
    }

    /** @param array<string, mixed> $payload Validated, normalized editor values. */
    public function updateMethod(CoinEarningMethod $coinEarningMethod, array $payload, string $editorVersion): void
    {
        try {
            DB::transaction(function () use ($coinEarningMethod, $payload, $editorVersion): void {
                AdminSingletonLock::acquire('settings');
                $settings = $this->lockedSettings();
                $locked = CoinEarningMethod::query()
                    ->whereKey($coinEarningMethod->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (!hash_equals(RewardConfigurationVersion::method($locked), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => "تغيّرت المهمة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                    ]);
                }
                $this->ensureUsableDestination($payload, $locked);
                $this->ensureExecutableCoinMethod($payload, $settings);
                $locked->update($payload);
            }, 3);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'coin_earning_method' => [$exception->getMessage()],
            ]);
        }
    }

    public function deleteMethod(CoinEarningMethod $coinEarningMethod, string $editorVersion): void
    {
        DB::transaction(function () use ($coinEarningMethod, $editorVersion): void {
            $locked = CoinEarningMethod::query()
                ->whereKey($coinEarningMethod->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (!hash_equals(RewardConfigurationVersion::method($locked), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّرت المهمة منذ فتح الصفحة\nأعد تحميلها قبل الحذف",
                ]);
            }
            $locked->delete();
        }, 3);
    }

    /** @param array<string, mixed> $payload Validated, normalized editor values. */
    public function updateRule(RewardRule $rewardRule, array $payload, string $editorVersion): void
    {
        DB::transaction(function () use ($rewardRule, $payload, $editorVersion): void {
            AdminSingletonLock::acquire('settings');
            $settings = $this->lockedSettings();
            $locked = RewardRule::query()->whereKey($rewardRule->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals(
                RewardConfigurationVersion::rule($locked),
                $editorVersion
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّرت قاعدة المكافأة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ",
                ]);
            }
            $this->ensureExecutableRewardRule($payload, $settings);
            $locked->update($payload);
        }, 3);
    }

    public function deleteRule(RewardRule $rewardRule, string $editorVersion): void
    {
        DB::transaction(function () use ($rewardRule, $editorVersion): void {
            $locked = RewardRule::query()->whereKey($rewardRule->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals(
                RewardConfigurationVersion::rule($locked),
                $editorVersion
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => "تغيّرت قاعدة المكافأة منذ فتح الصفحة\nأعد تحميلها قبل الحذف",
                ]);
            }
            $locked->delete();
        }, 3);
    }

    private function ensureUsableDestination(array $payload, ?CoinEarningMethod $existing = null): void
    {
        $method = $existing ? clone $existing : new CoinEarningMethod();
        $method->forceFill($payload);
        // A broken or retired destination must never prevent an administrator
        // from stopping the task. Destination readiness matters only while the
        // task is exposed to learners.
        if (!$method->is_active) {
            return;
        }
        if (!$method->hasUsableDestination()) {
            throw ValidationException::withMessages([
                'action_url' => [
                    'أضف رابط HTTPS موثوقًا أو أضف رابط الحساب المطابق من إعدادات التطبيق',
                ],
            ]);
        }
    }

    private function ensureExecutableRewardRule(array $payload, ?Setting $settings): void
    {
        if (!(bool) ($payload['is_active'] ?? false)) return;

        $amount = max(0, (int) ($payload['coins_amount'] ?? 0));
        if ($amount === 0) return;

        $event = (string) ($payload['event_key'] ?? '');
        $rollingCap = array_key_exists('rolling_30_day_cap', $payload)
            && $payload['rolling_30_day_cap'] !== null
                ? max(0, (int) $payload['rolling_30_day_cap'])
                : null;
        $dailyCap = array_key_exists('daily_cap', $payload) && $payload['daily_cap'] !== null
            ? max(0, (int) $payload['daily_cap'])
            : null;

        // A zero cap is an explicit kill switch. Positive caps, however, must
        // be large enough to fund one indivisible configured reward.
        $this->ensurePositiveCapFundsAmount(
            $amount,
            $rollingCap,
            'rolling_30_day_cap',
            'الحد لا يكفي لمنح المكافأة مرة واحدة.'
        );
        if ($event === 'study_session') {
            $this->ensurePositiveCapFundsAmount(
                $amount,
                $dailyCap,
                'daily_cap',
                'الحد اليومي لا يكفي لمنح مكافأة دراسة واحدة.'
            );
        }

        $disabledByEventCap = $event === 'study_session'
            ? $rollingCap === 0 || $dailyCap === 0
            : $event !== 'welcome_bonus' && $rollingCap === 0;
        if ($disabledByEventCap) return;

        $promised = $amount;
        if ($event === 'welcome_bonus') {
            $promised += max(0, (int) ($settings?->recommended_provider_bonus_coins ?? 0));
        }
        $balanceCap = max(0, (int) ($settings?->reward_balance_cap ?? 1200));
        $this->ensurePositiveCapFundsAmount(
            $promised,
            $balanceCap,
            'coins_amount',
            'المكافأة أكبر من أقصى رصيد مكافآت ولن يمكن صرفها.'
        );
    }

    private function ensureExecutableCoinMethod(array $payload, ?Setting $settings): void
    {
        if (!(bool) ($payload['is_active'] ?? false)) return;

        $amount = max(0, (int) ($payload['coins_amount'] ?? 0));
        $balanceCap = max(0, (int) ($settings?->reward_balance_cap ?? 1200));
        $this->ensurePositiveCapFundsAmount(
            $amount,
            $balanceCap,
            'coins_amount',
            'مكافأة المهمة أكبر من أقصى رصيد مكافآت ولن يمكن صرفها.'
        );
    }

    private function lockedSettings(): ?Setting
    {
        return Setting::query()->lockForUpdate()->first();
    }

    private function ensurePositiveCapFundsAmount(
        int $amount,
        ?int $cap,
        string $field,
        string $message
    ): void {
        if ($cap !== null && $cap > 0 && $cap < $amount) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
    }

    private function ensureRewardsFitProposedBalanceCap(
        Setting $settings,
        iterable $rules,
        iterable $methods
    ): void {
        $balanceCap = max(0, (int) $settings->reward_balance_cap);
        if ($balanceCap === 0) return;

        foreach ($rules as $rule) {
            $payload = $rule->toArray();
            $amount = max(0, (int) ($payload['coins_amount'] ?? 0));
            if ($amount === 0 || $this->rewardIsDisabledByEventCap($payload)) continue;

            $promised = $amount + ((string) $rule->event_key === 'welcome_bonus'
                ? max(0, (int) $settings->recommended_provider_bonus_coins)
                : 0);
            if ($promised <= $balanceCap) continue;

            $field = (string) $rule->event_key === 'welcome_bonus' && $amount <= $balanceCap
                ? 'recommended_provider_bonus_coins'
                : 'reward_balance_cap';
            throw ValidationException::withMessages([
                $field => ['أقصى رصيد المكافآت لا يكفي لقاعدة «'.(string) $rule->title_ar.'».'],
            ]);
        }

        foreach ($methods as $method) {
            $amount = max(0, (int) $method->coins_amount);
            if ($amount > $balanceCap) {
                throw ValidationException::withMessages([
                    'reward_balance_cap' => [
                        'أقصى رصيد المكافآت لا يكفي لمهمة «'.(string) $method->title_ar.'».',
                    ],
                ]);
            }
        }
    }

    private function rewardIsDisabledByEventCap(array $payload): bool
    {
        $event = (string) ($payload['event_key'] ?? '');
        $rollingCap = $payload['rolling_30_day_cap'] ?? null;
        $dailyCap = $payload['daily_cap'] ?? null;

        return $event === 'study_session'
            ? ($rollingCap !== null && (int) $rollingCap === 0)
                || ($dailyCap !== null && (int) $dailyCap === 0)
            : $event !== 'welcome_bonus' && $rollingCap !== null && (int) $rollingCap === 0;
    }
}
