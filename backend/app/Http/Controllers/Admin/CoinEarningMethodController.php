<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\CoinEarningMethod;
use App\Models\Setting;
use App\Models\RewardRule;
use App\Services\AdminAuthoringCreateIntentService;
use App\Services\AdminEconomyReadService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Services\SocialAuthProviderRegistry;
use App\Support\BusinessClock;
use App\Support\AdminSingletonLock;

class CoinEarningMethodController extends Controller
{
    public function index(AdminEconomyReadService $economy)
    {
        $data = $economy->rewards();
        $methods = $data['methods'];
        $setting = $data['setting'];
        $rewardRules = $data['rewardRules'];
        $rewardEvents = $data['rewardEvents'];
        $socialProviderLabels = $data['socialProviderLabels'];
        $settingsEditorVersion = $this->settingsEditorVersion($setting);
        $rewardRuleEditorVersions = $rewardRules->mapWithKeys(
            fn (RewardRule $rule): array => [$rule->id => $this->rewardRuleEditorVersion($rule)]
        );
        $methodEditorVersions = $methods->getCollection()->mapWithKeys(
            fn (CoinEarningMethod $method): array => [$method->id => $this->methodEditorVersion($method)]
        );
        return view('admin.coin_earning_methods.index', compact(
            'methods', 'setting', 'rewardRules', 'rewardEvents', 'socialProviderLabels',
            'settingsEditorVersion', 'rewardRuleEditorVersions', 'methodEditorVersions'
        ));
    }

    public function updateSettings(Request $request)
    {
        $validated = $request->validate([
            // Both columns are MySQL TEXT. A 12k-character ceiling remains
            // below 65,535 bytes even when every character uses four UTF-8
            // bytes, so oversized Arabic copy is rejected before the write.
            'how_to_use_coins_ar' => 'nullable|string|max:12000',
            'how_to_use_coins_en' => 'nullable|string|max:12000',
            'reward_balance_cap' => 'required|integer|min:0|max:1000000',
            'max_reward_contribution_per_course' => 'required|integer|min:0|max:1000000',
            'recommended_social_provider' => [
                'required',
                'string',
                Rule::in(app(SocialAuthProviderRegistry::class)->declared()->all()),
            ],
            'recommended_provider_bonus_coins' => 'required|integer|min:0|max:1000000',
            'recommended_provider_badge_ar' => 'nullable|string|max:255',
            'recommended_provider_badge_en' => 'nullable|string|max:255',
            'editor_version' => 'required|string|size:64',
        ]);

        $editorVersion = (string) $validated['editor_version'];
        unset($validated['editor_version']);
        DB::transaction(function () use ($validated, $editorVersion): void {
            AdminSingletonLock::acquire('settings');
            $setting = Setting::query()->lockForUpdate()->first();
            if (!$setting) {
                if (!hash_equals($this->settingsEditorVersion(null), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'تغيّرت قواعد العملات منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                    ]);
                }
                $setting = Setting::query()->create([]);
            } elseif (!hash_equals($this->settingsEditorVersion($setting), $editorVersion)) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت قواعد العملات منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
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
            $setting->update($validated);
        }, 3);
        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قواعد ومكافآت العملات بنجاح');
    }

    public function create()
    {
        return view('admin.coin_earning_methods.create');
    }

    public function store(Request $request, AdminAuthoringCreateIntentService $createIntents)
    {
        $payload = $this->methodPayload($request);
        DB::transaction(function () use ($request, $payload, $createIntents): void {
            AdminSingletonLock::acquire('settings');
            $this->ensureExecutableCoinMethod($payload, $this->lockedSettings());
            $method = CoinEarningMethod::create($payload);
            $createIntents->completeRedirect(
                $request,
                route('admin.coin-earning-methods.index'),
                302,
                CoinEarningMethod::class,
                $method->id
            );
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم إضافة طريقة ربح العملات بنجاح');
    }

    public function edit(CoinEarningMethod $coinEarningMethod)
    {
        $editorVersion = $this->methodEditorVersion($coinEarningMethod);
        return view('admin.coin_earning_methods.edit', compact('coinEarningMethod', 'editorVersion'));
    }

    public function update(Request $request, CoinEarningMethod $coinEarningMethod)
    {
        $payload = $this->methodPayload($request, $coinEarningMethod);
        $editorVersion = (string) $request->input('editor_version');
        try {
            DB::transaction(function () use ($coinEarningMethod, $payload, $editorVersion): void {
                AdminSingletonLock::acquire('settings');
                $settings = $this->lockedSettings();
                $locked = CoinEarningMethod::query()
                    ->whereKey($coinEarningMethod->id)
                    ->lockForUpdate()
                    ->firstOrFail();
                if (!hash_equals($this->methodEditorVersion($locked), $editorVersion)) {
                    throw ValidationException::withMessages([
                        'editor_version' => 'تغيّرت المهمة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                    ]);
                }
                $this->ensureExecutableCoinMethod($payload, $settings);
                $locked->update($payload);
            }, 3);
        } catch (\DomainException $exception) {
            throw ValidationException::withMessages([
                'coin_earning_method' => [$exception->getMessage()],
            ]);
        }

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث طريقة ربح العملات بنجاح');
    }

    public function destroy(Request $request, CoinEarningMethod $coinEarningMethod)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($coinEarningMethod, $validated): void {
            $locked = CoinEarningMethod::query()
                ->whereKey($coinEarningMethod->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (!hash_equals($this->methodEditorVersion($locked), (string) $validated['editor_version'])) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت المهمة منذ فتح الصفحة\nأعد تحميلها قبل الحذف',
                ]);
            }
            $locked->delete();
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف طريقة ربح العملات بنجاح');
    }

    public function storeRewardRule(
        Request $request,
        AdminAuthoringCreateIntentService $createIntents
    )
    {
        $payload = $this->rewardRulePayload($request);
        DB::transaction(function () use ($request, $payload, $createIntents): void {
            AdminSingletonLock::acquire('settings');
            $settings = $this->lockedSettings();
            $this->ensureExecutableRewardRule($payload, $settings);
            $rule = RewardRule::create($payload);
            $createIntents->completeRedirect(
                $request,
                route('admin.coin-earning-methods.index'),
                302,
                RewardRule::class,
                $rule->id
            );
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تمت إضافة قاعدة المكافأة وربطها بالحدث.');
    }

    public function updateRewardRule(Request $request, RewardRule $rewardRule)
    {
        $request->validate(['editor_version' => 'required|string|size:64']);
        $payload = $this->rewardRulePayload($request, $rewardRule);
        DB::transaction(function () use ($request, $rewardRule, $payload): void {
            AdminSingletonLock::acquire('settings');
            $settings = $this->lockedSettings();
            $locked = RewardRule::query()->whereKey($rewardRule->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals(
                $this->rewardRuleEditorVersion($locked),
                (string) $request->input('editor_version')
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت قاعدة المكافأة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ',
                ]);
            }
            $this->ensureExecutableRewardRule($payload, $settings);
            $locked->update($payload);
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم تحديث قاعدة المكافأة.');
    }

    public function destroyRewardRule(Request $request, RewardRule $rewardRule)
    {
        $validated = $request->validate(['editor_version' => 'required|string|size:64']);
        DB::transaction(function () use ($rewardRule, $validated): void {
            $locked = RewardRule::query()->whereKey($rewardRule->id)
                ->lockForUpdate()->firstOrFail();
            if (!hash_equals(
                $this->rewardRuleEditorVersion($locked),
                (string) $validated['editor_version']
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => 'تغيّرت قاعدة المكافأة منذ فتح الصفحة\nأعد تحميلها قبل الحذف',
                ]);
            }
            $locked->delete();
        }, 3);

        return redirect()->route('admin.coin-earning-methods.index')
            ->with('success', 'تم حذف القاعدة وإيقاف مكافأتها فورًا.');
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

    /** @return array<string, mixed> */
    private function methodPayload(Request $request, ?CoinEarningMethod $existing = null): array
    {
        $campaignKey = Rule::unique('coin_earning_methods', 'campaign_key');
        if ($existing) {
            $campaignKey->ignore($existing->id);
        }
        $validated = $request->validate([
            'title_ar' => ['required', 'string', 'max:255'],
            'title_en' => ['required', 'string', 'max:255'],
            'coins_amount' => ['required', 'integer', 'min:1'],
            'action_key' => ['required', 'string', 'max:255', 'not_in:register'],
            'campaign_key' => [
                'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:-]+$/', $campaignKey,
            ],
            'action_url' => ['nullable', 'url', 'max:2000'],
            'requires_external_visit' => ['nullable', 'boolean'],
            'verification_delay_seconds' => ['nullable', 'integer', 'min:0', 'max:300'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after:starts_at'],
            'total_claim_limit' => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'is_active' => ['nullable', 'boolean'],
            'authoring_request_id' => [$existing ? 'nullable' : 'required', 'uuid'],
            'editor_version' => [$existing ? 'required' : 'nullable', 'string', 'size:64'],
        ]);

        unset($validated['authoring_request_id'], $validated['editor_version']);
        foreach (['title_ar', 'title_en'] as $field) {
            $validated[$field] = trim((string) $validated[$field]);
        }
        foreach (['action_key', 'campaign_key', 'action_url'] as $field) {
            $value = trim((string) ($validated[$field] ?? ''));
            $validated[$field] = $value !== '' ? $value : null;
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $validated[$field] = BusinessClock::localInputToUtc($validated[$field] ?? null);
        }
        $validated['coins_amount'] = (int) $validated['coins_amount'];
        $validated['requires_external_visit'] = $request->boolean('requires_external_visit');
        $validated['verification_delay_seconds'] = (int) ($validated['verification_delay_seconds'] ?? 0);
        $validated['total_claim_limit'] = filled($validated['total_claim_limit'] ?? null)
            ? (int) $validated['total_claim_limit']
            : null;
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 100);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['is_repeatable'] = false;

        $this->ensureUsableDestination($validated, $existing);

        return $validated;
    }

    private function rewardRulePayload(Request $request, ?RewardRule $existing = null): array
    {
        $eventRule = Rule::in(array_keys(RewardRule::EVENTS));
        $uniqueRule = Rule::unique('reward_rules', 'event_key');
        if ($existing) {
            $uniqueRule->ignore($existing->id);
        }

        $validated = $request->validate([
            'event_key' => ['required', 'string', $eventRule, $uniqueRule],
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'coins_amount' => 'required|integer|min:0|max:1000000',
            'interval_count' => 'nullable|integer|min:1|max:1440',
            'daily_cap' => 'nullable|integer|min:0|max:1000000',
            'rolling_30_day_cap' => 'nullable|integer|min:0|max:1000000',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'nullable|boolean',
            'authoring_request_id' => [$existing ? 'nullable' : 'required', 'uuid'],
        ]);
        unset($validated['authoring_request_id']);
        $validated['is_active'] = $request->boolean('is_active');
        $validated['sort_order'] = (int) ($validated['sort_order'] ?? 100);
        $validated['coins_amount'] = (int) $validated['coins_amount'];

        $event = (string) $validated['event_key'];
        $usesInterval = in_array($event, ['streak_milestone', 'study_session'], true);
        if ($usesInterval && !filled($validated['interval_count'] ?? null)) {
            throw ValidationException::withMessages([
                'interval_count' => ['أدخل مدة الاستمرارية أو الدراسة لهذه القاعدة.'],
            ]);
        }
        if ($event === 'streak_milestone' && (int) $validated['interval_count'] < 2) {
            throw ValidationException::withMessages([
                'interval_count' => ['مدة الاستمرارية تبدأ من يومين.'],
            ]);
        }
        $validated['interval_count'] = $usesInterval
            ? (int) $validated['interval_count']
            : 1;
        $validated['daily_cap'] = $event === 'study_session'
            && filled($validated['daily_cap'] ?? null)
                ? (int) $validated['daily_cap']
                : null;
        $validated['rolling_30_day_cap'] = $event !== 'welcome_bonus'
            && filled($validated['rolling_30_day_cap'] ?? null)
                ? (int) $validated['rolling_30_day_cap']
                : null;

        if (
            $validated['is_active']
            && $validated['coins_amount'] > 0
            && in_array($event, ['daily_checkin', 'streak_milestone', 'study_session', 'course_completed'], true)
            && $validated['rolling_30_day_cap'] === null
        ) {
            throw ValidationException::withMessages([
                'rolling_30_day_cap' => ['أدخل حدًا خلال 30 يومًا حتى تظل تكلفة المكافأة منضبطة.'],
            ]);
        }

        return $validated;
    }

    /** @param array<string, mixed> $payload */
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

    /** @param array<string, mixed> $payload */
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

    /** @param array<string, mixed> $payload */
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

    private function settingsEditorVersion(?Setting $setting): string
    {
        return hash('sha256', json_encode([
            (string) ($setting?->how_to_use_coins_ar ?? ''),
            (string) ($setting?->how_to_use_coins_en ?? ''),
            (int) ($setting?->reward_balance_cap ?? 1200),
            (int) ($setting?->max_reward_contribution_per_course ?? 1200),
            (string) ($setting?->recommended_social_provider ?? config('social_auth.recommended_provider')),
            (int) ($setting?->recommended_provider_bonus_coins ?? 0),
            (string) ($setting?->recommended_provider_badge_ar ?? ''),
            (string) ($setting?->recommended_provider_badge_en ?? ''),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function methodEditorVersion(CoinEarningMethod $method): string
    {
        return hash('sha256', json_encode([
            (string) $method->title_ar,
            (string) $method->title_en,
            (int) $method->coins_amount,
            (string) $method->action_key,
            (string) $method->campaign_key,
            (string) $method->action_url,
            (bool) $method->requires_external_visit,
            (int) $method->verification_delay_seconds,
            $method->starts_at?->toIso8601String(),
            $method->ends_at?->toIso8601String(),
            $method->total_claim_limit === null ? null : (int) $method->total_claim_limit,
            (int) $method->sort_order,
            (bool) $method->is_active,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function rewardRuleEditorVersion(RewardRule $rule): string
    {
        return hash('sha256', json_encode([
            (string) $rule->event_key,
            (string) $rule->title_ar,
            (string) $rule->title_en,
            (int) $rule->coins_amount,
            (int) $rule->interval_count,
            $rule->daily_cap === null ? null : (int) $rule->daily_cap,
            $rule->rolling_30_day_cap === null ? null : (int) $rule->rolling_30_day_cap,
            (bool) $rule->is_active,
            (int) $rule->sort_order,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}
