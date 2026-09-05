<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\RewardGrantDeferred;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\Project;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\User;
use App\Models\UserDailyLearningActivity;
use App\Models\WalletTransaction;
use App\Support\DatabaseCapabilities;
use Carbon\CarbonImmutable;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;

final class LearningRewardService
{
    private const DEFAULT_REWARD_BALANCE_CAP = 1200;
    private const DEFAULT_REWARD_CONTRIBUTION_PER_COURSE = 1200;
    private const TEMPORARY_BALANCE_CAP_RETRY_HOURS = 12;

    public function __construct(
        private readonly WalletService $wallet,
        private readonly CourseChatAccessService $courseAccess
    ) {
    }

    public function configuration(): array
    {
        $settings = $this->settings();
        $welcome = RewardRule::activeFor('welcome_bonus');
        $daily = RewardRule::activeFor('daily_checkin');
        $streak = RewardRule::activeFor('streak_milestone');
        $study = RewardRule::activeFor('study_session');
        $firstProject = RewardRule::activeFor('first_project_passed');
        $courseCompletion = RewardRule::activeFor('course_completed');

        return [
            'reward_timezone' => $this->rewardTimezone(),
            'welcome_bonus_coins' => (int) ($welcome?->coins_amount ?? 0),
            'reward_balance_cap' => (int) $settings->reward_balance_cap,
            'max_reward_contribution_per_course' => (int) $settings->max_reward_contribution_per_course,
            'daily' => [
                'enabled' => $daily !== null,
                'coins' => (int) ($daily?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($daily?->rolling_30_day_cap ?? 0),
            ],
            'streak' => [
                'enabled' => $streak !== null,
                'days' => (int) ($streak?->interval_count ?? 0),
                'coins' => (int) ($streak?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($streak?->rolling_30_day_cap ?? 0),
            ],
            'study' => [
                'enabled' => $study !== null,
                'coins' => (int) ($study?->coins_amount ?? 0),
                'qualified_minutes' => (int) ($study?->interval_count ?? 0),
                'daily_cap' => (int) ($study?->daily_cap ?? 0),
                'rolling_30_day_cap' => (int) ($study?->rolling_30_day_cap ?? 0),
            ],
            'first_project' => [
                'enabled' => $firstProject !== null,
                'coins' => (int) ($firstProject?->coins_amount ?? 0),
                'lifetime_cap' => (int) ($firstProject?->rolling_30_day_cap ?? 0),
            ],
            'course_completion' => [
                'enabled' => $courseCompletion !== null,
                'coins' => (int) ($courseCompletion?->coins_amount ?? 0),
                'rolling_30_day_cap' => (int) ($courseCompletion?->rolling_30_day_cap ?? 0),
            ],
        ];
    }

    public function claimDaily(User $user): array
    {
        $dailyRule = RewardRule::activeFor('daily_checkin');
        $streakRule = RewardRule::activeFor('streak_milestone');
        $today = $this->rewardNow()->toDateString();
        $contracts = DB::transaction(function () use (
            $user,
            $today,
            $dailyRule,
            $streakRule
        ): array {
            DB::table('user_reward_checkins')->insertOrIgnore([
                'user_id' => $user->id,
                'checkin_date' => $today,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $checkin = DB::table('user_reward_checkins')
                ->where('user_id', $user->id)
                ->where('checkin_date', $today)
                ->lockForUpdate()
                ->first();
            if (!$checkin->rules_snapshotted_at) {
                DB::table('user_reward_checkins')->where('id', $checkin->id)->update([
                    'daily_rule_snapshot' => $this->encodeRuleSnapshot($dailyRule),
                    'streak_rule_snapshot' => $this->encodeRuleSnapshot($streakRule),
                    'rules_snapshotted_at' => now(),
                    'updated_at' => now(),
                ]);
                $checkin = DB::table('user_reward_checkins')->where('id', $checkin->id)->first();
            }

            return [
                'daily' => $this->decodeRuleSnapshot($checkin->daily_rule_snapshot),
                'streak' => $this->decodeRuleSnapshot($checkin->streak_rule_snapshot),
            ];
        }, 3);

        $dailyContract = $contracts['daily'];
        $transaction = $dailyContract ? $this->award(
            $user,
            (int) $dailyContract['coins_amount'],
            'daily_learning_reward',
            "daily-learning:{$user->id}:{$today}",
            (int) $dailyContract['rolling_30_day_cap'],
            RewardRule::query()->find($dailyContract['rule_id']),
            ['activity_date' => $today, 'reward_rule_id' => $dailyContract['rule_id']]
        ) : null;

        $streakDays = $this->currentCheckinStreak((int) $user->id);
        $streakContract = $contracts['streak'];
        $milestoneDays = max(2, (int) ($streakContract['interval_count'] ?? 7));
        $streakTransaction = null;
        if ($streakContract && $streakDays > 0 && $streakDays % $milestoneDays === 0) {
            $streakTransaction = $this->award(
                $user,
                (int) $streakContract['coins_amount'],
                'streak_reward',
                "streak-reward:{$user->id}:{$today}",
                (int) $streakContract['rolling_30_day_cap'],
                RewardRule::query()->find($streakContract['rule_id']),
                [
                    'reward_rule_id' => $streakContract['rule_id'],
                    'milestone_days' => $streakDays,
                    'configured_interval_days' => $milestoneDays,
                ]
            );
        }

        return $this->result($user, $transaction, [
            'current_streak_days' => $streakDays,
            'next_streak_reward_at' => $milestoneDays - ($streakDays % $milestoneDays),
            'streak_awarded' => $streakTransaction ? (int) $streakTransaction->amount : 0,
        ]);
    }

    /**
     * Credit only the newly qualified foreground watch time supplied by the
     * server-side watch-history endpoint. The daily and rolling caps make the
     * cost bounded even when a client retries aggressively.
     */
    public function recordStudy(User $user, int $qualifiedSeconds): array
    {
        $seconds = max(0, min(120, $qualifiedSeconds));
        if ($seconds === 0) {
            return $this->result($user, null);
        }

        $rule = RewardRule::activeFor('study_session');
        $today = $this->rewardNow()->toDateString();
        $activity = DB::transaction(function () use ($user, $today, $seconds, $rule) {
            // One atomic insert protects the daily row from concurrent player
            // heartbeats. The previous select-then-create sequence could race
            // and violate the (user, day) unique key under normal playback.
            if ($rule) {
                DB::table('user_daily_learning_activities')->insertOrIgnore([
                    'user_id' => $user->id,
                    'activity_date' => $today,
                    'qualified_seconds' => 0,
                    'reward_contract' => null,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $activity = UserDailyLearningActivity::query()
                ->where('user_id', $user->id)
                ->where('activity_date', $today)
                ->lockForUpdate()
                ->first();
            if (!$activity) {
                return null;
            }
            if (!is_array($activity->reward_contract)) {
                if (!$rule) {
                    return null;
                }
                $activity->reward_contract = [
                    'rule_id' => (int) $rule->id,
                    'slot_seconds' => max(60, (int) $rule->interval_count * 60),
                    'coins_per_slot' => max(0, (int) $rule->coins_amount),
                    'daily_cap' => max(0, (int) ($rule->daily_cap ?? $rule->coins_amount)),
                    'rolling_30_day_cap' => max(
                        0,
                        (int) ($rule->rolling_30_day_cap ?? $rule->coins_amount)
                    ),
                ];
            }
            $activity->qualified_seconds = (int) $activity->qualified_seconds + $seconds;
            $activity->save();

            return $activity->fresh();
        });
        if (!$activity) {
            return $this->result($user, null);
        }

        $contract = (array) $activity->reward_contract;
        $slotSeconds = max(60, (int) ($contract['slot_seconds'] ?? 60));
        $earnedSlots = intdiv((int) $activity->qualified_seconds, $slotSeconds);
        $coinsPerSlot = max(0, (int) ($contract['coins_per_slot'] ?? 0));
        $dailySlots = $coinsPerSlot > 0
            ? intdiv(max(0, (int) ($contract['daily_cap'] ?? 0)), $coinsPerSlot)
            : 0;
        $targetSlots = min($earnedSlots, $dailySlots);
        $creditedSlots = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('category', 'study_reward')
            ->where('idempotency_key', 'like', "study-reward:{$user->id}:{$today}:%")
            ->count();

        $last = null;
        $sourceRule = RewardRule::query()->find((int) ($contract['rule_id'] ?? 0));
        for ($sequence = $creditedSlots + 1; $sequence <= $targetSlots; $sequence++) {
            $last = $this->award(
                $user,
                $coinsPerSlot,
                'study_reward',
                "study-reward:{$user->id}:{$today}:{$sequence}",
                (int) ($contract['rolling_30_day_cap'] ?? 0),
                $sourceRule,
                [
                    'reward_rule_id' => (int) ($contract['rule_id'] ?? 0),
                    'activity_date' => $today,
                    'qualified_seconds' => (int) $activity->qualified_seconds,
                    'sequence' => $sequence,
                    'reward_contract' => $contract,
                ]
            );
        }

        $rewardedSlots = WalletTransaction::query()
            ->where('user_id', $user->id)
            ->where('category', 'study_reward')
            ->where('idempotency_key', 'like', "study-reward:{$user->id}:{$today}:%")
            ->count();

        return $this->result($user, $last, [
            'qualified_seconds_today' => (int) $activity->qualified_seconds,
            'rewarded_slots_today' => $rewardedSlots,
        ]);
    }

    public function awardFirstProject(
        User $user,
        Project|int $project,
        ?array $rewardContract = null,
        ?int $courseId = null
    ): array
    {
        $projectId = $project instanceof Project ? (int) $project->id : (int) $project;
        $course = $courseId ? Course::withTrashed()->find($courseId) : null;
        $course ??= $project instanceof Project ? $project->course : null;
        if ($course && $this->usesInstitutionalGrant($user, $course)) {
            return $this->result($user, null, ['excluded_for_grant' => true]);
        }

        $rule = RewardRule::activeFor('first_project_passed');
        $contract = $this->achievementContract($rewardContract, $rule);
        if (!$contract) {
            return $this->result($user, null);
        }
        $transaction = $this->award(
            $user,
            (int) $contract['coins_amount'],
            'first_project_reward',
            "first-project-reward:{$user->id}",
            (int) $contract['rolling_30_day_cap'],
            RewardRule::query()->find((int) $contract['rule_id']),
            ['project_id' => $projectId, 'reward_rule_id' => $contract['rule_id']],
            true
        );

        return $this->result($user, $transaction);
    }

    public function awardCourseCompletion(
        User $user,
        Course $course,
        ?array $rewardContract = null
    ): array
    {
        $course = $this->canonicalRewardCourse($course);
        if ($this->usesInstitutionalGrant($user, $course)) {
            return $this->result($user, null, ['excluded_for_grant' => true]);
        }

        $rule = RewardRule::activeFor('course_completed');
        $contract = $this->achievementContract($rewardContract, $rule);
        if (!$contract) {
            return $this->result($user, null);
        }
        $transaction = $this->award(
            $user,
            (int) $contract['coins_amount'],
            'course_completion_reward',
            "course-completion-reward:{$user->id}:{$course->id}",
            (int) $contract['rolling_30_day_cap'],
            RewardRule::query()->find((int) $contract['rule_id']),
            ['course_id' => $course->id, 'reward_rule_id' => $contract['rule_id']],
            true
        );

        return $this->result($user, $transaction);
    }

    private function award(
        User $user,
        int $requested,
        string $category,
        string $idempotencyKey,
        int $rollingCap,
        $source = null,
        array $metadata = [],
        bool $deferOnCap = false
    ): ?WalletTransaction {
        $requested = max(0, $requested);
        if ($requested === 0) {
            return null;
        }

        return DB::transaction(function () use (
            $user,
            $requested,
            $category,
            $idempotencyKey,
            $rollingCap,
            $source,
            $metadata,
            $deferOnCap
        ): ?WalletTransaction {
            // All reward sources share the same user aggregate lock. This keeps
            // two different simultaneous rewards from each seeing stale room
            // under the balance or rolling cap.
            $lockedUser = User::query()->lockForUpdate()->findOrFail($user->id);
            if (WalletTransaction::query()
                ->where('user_id', $lockedUser->id)
                ->where('idempotency_key', $idempotencyKey)
                ->exists()) {
                return null;
            }

            $settings = $this->settings();
            $rollingTotal = (int) WalletTransaction::query()
                ->where('user_id', $lockedUser->id)
                ->where('category', $category)
                ->where('direction', WalletTransaction::DIRECTION_CREDIT)
                ->where('occurred_at', '>=', $this->rewardNow()->subDays(30))
                ->sum('amount');
            $rollingRoom = max(0, $rollingCap - $rollingTotal);
            $balances = $this->wallet->balances($lockedUser);
            $balanceRoom = max(
                0,
                (int) $settings->reward_balance_cap - $balances['reward']
            );
            // A displayed reward is one commercial promise. Crediting a
            // smaller remainder would consume its one-time idempotency key
            // while silently paying fewer coins than the configured amount.
            if ($requested > $rollingRoom || $requested > $balanceRoom) {
                $balanceCap = max(0, (int) $settings->reward_balance_cap);
                $canEverFit = $requested <= $rollingCap && $requested <= $balanceCap;
                if ($deferOnCap && $canEverFit) {
                    $retryAt = $this->rewardNow()
                        ->addHours(self::TEMPORARY_BALANCE_CAP_RETRY_HOURS)
                        ->utc();
                    if ($requested > $rollingRoom) {
                        $rollingRetryAt = $this->rollingCapRetryAt(
                            (int) $lockedUser->id,
                            $category,
                            $requested,
                            $rollingCap,
                            $rollingTotal
                        );
                        if ($rollingRetryAt && $rollingRetryAt->greaterThan($retryAt)) {
                            $retryAt = $rollingRetryAt;
                        }
                    }

                    throw new RewardGrantDeferred($retryAt);
                }

                return null;
            }

            return $this->wallet->credit(
                $lockedUser->id,
                $requested,
                $category,
                $idempotencyKey,
                $source,
                $metadata + [
                    'requested_amount' => $requested,
                    'reward_balance_cap' => (int) $settings->reward_balance_cap,
                    'rolling_30_day_cap' => $rollingCap,
                    'reward_timezone' => $this->rewardTimezone(),
                ],
                WalletTransaction::BUCKET_REWARD
            );
        }, 3);
    }

    private function rollingCapRetryAt(
        int $userId,
        string $category,
        int $requested,
        int $rollingCap,
        int $rollingTotal
    ): ?CarbonImmutable {
        $amountThatMustExpire = max(0, $rollingTotal + $requested - $rollingCap);
        if ($amountThatMustExpire === 0) {
            return null;
        }

        $expiringAmount = 0;
        $credits = WalletTransaction::query()
            ->where('user_id', $userId)
            ->where('category', $category)
            ->where('direction', WalletTransaction::DIRECTION_CREDIT)
            ->where('occurred_at', '>=', $this->rewardNow()->subDays(30))
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get(['amount', 'occurred_at']);
        foreach ($credits as $credit) {
            $expiringAmount += max(0, (int) $credit->amount);
            if ($expiringAmount >= $amountThatMustExpire) {
                return CarbonImmutable::parse($credit->occurred_at)
                    ->setTimezone($this->rewardTimezone())
                    ->addDays(30)
                    ->addSecond()
                    ->utc();
            }
        }

        return null;
    }

    private function result(User $user, ?WalletTransaction $transaction, array $extra = []): array
    {
        // The bearer guard may retain its model instance across idempotent
        // retries (and long-running workers can do the same). The ledger is
        // authoritative even when this call did not create a transaction.
        $fresh = $user->fresh();
        $balances = $this->wallet->balances($fresh);

        return $extra + [
            'awarded' => $transaction ? (int) $transaction->amount : 0,
            'balance' => $balances['total'],
            'reward_balance' => $balances['reward'],
            'transaction_id' => $transaction?->public_id,
        ];
    }

    private function settings(): Setting
    {
        $settings = Setting::query()->first();
        if ($settings) {
            return $settings;
        }

        // Public configuration and reward reads must never create the global
        // settings row. Apart from making GET mutate state, firstOrCreate([])
        // could race on a fresh installation because the table has no natural
        // singleton key. The dashboard remains the sole explicit creator; the
        // service uses the same migration defaults until that first save.
        return (new Setting())->forceFill([
            'reward_balance_cap' => self::DEFAULT_REWARD_BALANCE_CAP,
            'max_reward_contribution_per_course' => self::DEFAULT_REWARD_CONTRIBUTION_PER_COURSE,
        ]);
    }

    private function currentCheckinStreak(int $userId): int
    {
        $dates = DB::table('user_reward_checkins')
            ->where('user_id', $userId)
            ->orderByDesc('checkin_date')
            ->pluck('checkin_date')
            ->map(fn ($date): string => (string) $date)
            ->all();

        $expected = $this->rewardNow()->startOfDay();
        $streak = 0;
        foreach ($dates as $date) {
            if ($date !== $expected->toDateString()) {
                break;
            }
            $streak++;
            $expected = $expected->subDay();
        }

        return $streak;
    }

    private function rewardTimezone(): string
    {
        return BusinessClock::timezoneName();
    }

    private function rewardNow(): CarbonImmutable
    {
        return BusinessClock::now();
    }

    private function encodeRuleSnapshot(?RewardRule $rule): ?string
    {
        if (!$rule) {
            return null;
        }

        return json_encode([
            'rule_id' => (int) $rule->id,
            'coins_amount' => max(0, (int) $rule->coins_amount),
            'interval_count' => max(1, (int) $rule->interval_count),
            'daily_cap' => $rule->daily_cap === null ? null : max(0, (int) $rule->daily_cap),
            'rolling_30_day_cap' => max(
                0,
                (int) ($rule->rolling_30_day_cap ?? $rule->coins_amount)
            ),
        ], JSON_THROW_ON_ERROR);
    }

    /** @return array<string,int|null>|null */
    private function decodeRuleSnapshot(mixed $snapshot): ?array
    {
        if (is_string($snapshot) && $snapshot !== '') {
            $snapshot = json_decode($snapshot, true, 8, JSON_THROW_ON_ERROR);
        }

        return is_array($snapshot) ? $snapshot : null;
    }

    /** @return array<string,int|null>|null */
    private function achievementContract(?array $snapshot, ?RewardRule $fallback): ?array
    {
        if ($snapshot !== null) {
            return [
                'rule_id' => max(0, (int) ($snapshot['rule_id'] ?? 0)),
                'coins_amount' => max(0, (int) ($snapshot['coins_amount'] ?? 0)),
                'rolling_30_day_cap' => max(
                    0,
                    (int) ($snapshot['rolling_30_day_cap'] ?? $snapshot['coins_amount'] ?? 0)
                ),
            ];
        }
        if (!$fallback) return null;

        return [
            'rule_id' => (int) $fallback->id,
            'coins_amount' => max(0, (int) $fallback->coins_amount),
            'rolling_30_day_cap' => max(
                0,
                (int) ($fallback->rolling_30_day_cap ?? $fallback->coins_amount)
            ),
        ];
    }

    private function usesInstitutionalGrant(User $user, Course $course): bool
    {
        $course = $this->canonicalRewardCourse($course);

        return $this->courseAccess->entitlementFor(
            (int) $user->id,
            (int) $course->id
        )['access_type'] === 'scholarship';
    }

    private function canonicalRewardCourse(Course $course): Course
    {
        if (!DatabaseCapabilities::hasTable('course_authoring_revisions')) {
            return $course;
        }

        $archive = CourseAuthoringRevision::query()
            ->where('revision_course_id', $course->id)
            ->where('status', CourseAuthoringRevision::ARCHIVED)
            ->latest('id')
            ->first();
        if ($archive) {
            $course = Course::withTrashed()->find($archive->canonical_course_id) ?? $course;
        }

        return $course;
    }
}
