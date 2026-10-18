<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\CoinEarningMethod;
use App\Models\Coupon;
use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\CourseCode;
use App\Models\OperatingCostPool;
use App\Models\Package;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Event;

/**
 * Captures old/new values of allow-listed fields while one audited dashboard
 * mutation runs, in the shape spatie/laravel-activitylog stores
 * (`old` and `attributes` per subject). Pattern only; no package code.
 *
 * Only Eloquent model events are observed, so query-builder bulk updates are
 * not captured. Fields are allow-listed per model: commercial terms, coin
 * amounts, coupon limits and roles. Credentials, tokens, secrets and personal
 * contact data are never listed and therefore never copied.
 */
final class AdminAuditChangeRecorder
{
    /** @var array<class-string<Model>, list<string>> */
    public const FIELDS = [
        Course::class => ['price', 'price_before_discount', 'is_coming_soon', 'is_catalog_visible'],
        CourseAccessPlan::class => [
            'price_coins', 'minimum_paid_coins', 'is_active', 'chat_enabled', 'chat_message_limit',
            'projects_enabled', 'certificate_enabled', 'project_feedback_level', 'delivery_cost_usd',
            'ai_budget_usd', 'project_feedback_budget_usd', 'project_followup_budget_usd',
        ],
        Package::class => ['price', 'coins', 'is_active', 'direct_enabled'],
        Coupon::class => ['code', 'course_id', 'balance', 'max_redemptions', 'starts_at', 'expiry_date', 'active'],
        CourseCode::class => ['type', 'course_id', 'max_uses', 'expiry_date', 'is_active', 'is_grant'],
        CoinEarningMethod::class => ['coins_amount', 'total_claim_limit', 'is_active', 'is_repeatable'],
        RewardRule::class => ['coins_amount', 'interval_count', 'daily_cap', 'rolling_30_day_cap', 'is_active'],
        OperatingCostPool::class => ['amount', 'currency', 'fx_rate_to_egp', 'is_final'],
        Setting::class => [
            'direct_checkout_discount_percent', 'max_course_promotion_percent', 'welcome_bonus_coins',
            'recommended_provider_bonus_coins', 'reward_balance_cap', 'max_reward_contribution_per_course',
            'daily_reward_coins', 'daily_reward_rolling_30_day_cap', 'streak_reward_days',
            'streak_reward_coins', 'streak_reward_rolling_30_day_cap', 'study_reward_coins',
            'study_reward_minutes', 'study_reward_daily_cap', 'study_reward_rolling_30_day_cap',
            'first_project_reward_coins', 'course_completion_reward_coins',
            'course_completion_rolling_30_day_cap', 'openrouter_usd_to_egp_rate',
        ],
        User::class => ['role', 'active'],
    ];

    private const MAX_ENTRIES = 50;

    /** Dispatcher the listener is registered on (one per application instance). */
    private static ?\WeakReference $listeningOn = null;

    private static ?self $current = null;

    /** @var list<array<string, mixed>> */
    private array $entries = [];

    public static function begin(): self
    {
        $dispatcher = Event::getFacadeRoot();
        if (self::$listeningOn?->get() !== $dispatcher) {
            foreach (['created', 'updated', 'deleted'] as $event) {
                $dispatcher->listen("eloquent.{$event}: *", static function (string $name, array $payload) use ($event): void {
                    $model = $payload[0] ?? null;
                    if (self::$current && $model instanceof Model) {
                        self::$current->capture($event, $model);
                    }
                });
            }
            self::$listeningOn = \WeakReference::create($dispatcher);
        }

        return self::$current = new self;
    }

    /** @return list<array<string, mixed>> */
    public function finish(): array
    {
        if (self::$current === $this) {
            self::$current = null;
        }

        return $this->entries;
    }

    private function capture(string $event, Model $model): void
    {
        $fields = self::FIELDS[$model::class] ?? null;
        if ($fields === null || count($this->entries) >= self::MAX_ENTRIES) {
            return;
        }

        $old = [];
        $new = [];
        if ($event === 'updated') {
            // Cast values on both sides so a boolean column reads true/false
            // before and after, not a raw 1 against a cast false.
            foreach (array_intersect($fields, array_keys($model->getChanges())) as $field) {
                $old[$field] = $this->scalar($model->getOriginal($field));
                $new[$field] = $this->scalar($model->getAttribute($field));
            }
        } else {
            $present = $event === 'deleted' ? $model->getRawOriginal() : $model->getAttributes();
            foreach ($fields as $field) {
                if (array_key_exists($field, $present)) {
                    $value = $this->scalar($event === 'deleted' ? $model->getOriginal($field) : $model->getAttribute($field));
                    $event === 'deleted' ? $old[$field] = $value : $new[$field] = $value;
                }
            }
        }
        if ($old === [] && $new === []) {
            return;
        }

        $entry = [
            'subject' => class_basename($model),
            'id' => (string) $model->getKey(),
            'event' => $event,
            'old' => $old,
            'attributes' => $new,
        ];
        // Inside a transaction the entry is kept only if it commits, so a
        // rolled-back write (stale version, failed validation) is not logged
        // as a change. Outside a transaction this runs immediately.
        $model->getConnection()->afterCommit(function () use ($entry): void {
            if (count($this->entries) < self::MAX_ENTRIES) {
                $this->entries[] = $entry;
            }
        });
    }

    private function scalar(mixed $value): string|int|float|bool|null
    {
        if ($value === null || is_scalar($value)) {
            return is_string($value) ? mb_substr($value, 0, 191) : $value;
        }
        if ($value instanceof \DateTimeInterface) {
            return $value->format(DATE_ATOM);
        }
        if ($value instanceof \BackedEnum) {
            return $value->value;
        }

        return get_debug_type($value);
    }
}
