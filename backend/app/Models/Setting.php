<?php

namespace App\Models;

use App\Support\RoknLocale;

use Illuminate\Database\Eloquent\Model;
use App\Services\PublicAppSettingsService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;
class Setting extends Model
{
    private const DERIVED_CACHE_KEYS = [
        'auth-methods:dynamic:v2',
        'learning:sequence-settings:v2',
        'packages:direct-discount:v2',
        'public-packages:v2',
        'wallet:public-settings:v2',
    ];

    private const DEFAULT_COIN_RULES_AR = "استخدم عملات ركن عند شراء الكورسات والخدمات داخل التطبيق\nتظهر لك القيمة المتاحة قبل تأكيد الشراء";
    private const DEFAULT_COIN_RULES_EN = "Use Rokn Coins for courses and services inside the app\nYou will see the available amount before confirming a purchase";

    protected $fillable = [
        'site_name_ar',
        'site_name_en',
        'email',
        'phone',
        'currency_code',
        'direct_checkout_discount_percent',
        'seo_meta_title_ar',
        'seo_meta_description_ar',
        'seo_meta_title_en',
        'seo_meta_description_en',
        'google_maps_key',
        'contact',
        'english_translation',
        'device_login_policy',
        'enforce_course_section_order',
        'bunny_enabled',
        'bunny_library_id',
        'bunny_cdn_hostname',
        'bunny_storage_zone_name',
        'bunny_api_key_secret',
        'bunny_storage_password_secret',
        'bunny_security_key_secret',
        'android_app_url',
        'ios_app_url',
        'support_whatsapp_url',
        'how_to_use_coins_ar',
        'how_to_use_coins_en',
        'welcome_bonus_coins',
        'recommended_social_provider',
        'recommended_provider_bonus_coins',
        'recommended_provider_badge_ar',
        'recommended_provider_badge_en',
        'reward_balance_cap',
        'max_reward_contribution_per_course',
        'daily_reward_coins',
        'daily_reward_rolling_30_day_cap',
        'streak_reward_days',
        'streak_reward_coins',
        'streak_reward_rolling_30_day_cap',
        'openrouter_usd_to_egp_rate',
        'study_reward_coins',
        'study_reward_minutes',
        'study_reward_daily_cap',
        'study_reward_rolling_30_day_cap',
        'first_project_reward_coins',
        'course_completion_reward_coins',
        'course_completion_rolling_30_day_cap',
        'ai_global_daily_request_limit',
        'ai_global_daily_token_budget',
        'ai_global_monthly_token_budget',
        'ai_plan_policy',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'english_translation' => 'boolean',
        'direct_checkout_discount_percent' => 'decimal:2',
        'enforce_course_section_order' => 'boolean',
        'bunny_enabled' => 'boolean',
        'bunny_api_key_secret' => 'encrypted',
        'bunny_storage_password_secret' => 'encrypted',
        'bunny_security_key_secret' => 'encrypted',
        'welcome_bonus_coins' => 'integer',
        'recommended_provider_bonus_coins' => 'integer',
        'reward_balance_cap' => 'integer',
        'max_reward_contribution_per_course' => 'integer',
        'daily_reward_coins' => 'integer',
        'daily_reward_rolling_30_day_cap' => 'integer',
        'streak_reward_days' => 'integer',
        'streak_reward_coins' => 'integer',
        'streak_reward_rolling_30_day_cap' => 'integer',
        'openrouter_usd_to_egp_rate' => 'decimal:4',
        'study_reward_coins' => 'integer',
        'study_reward_minutes' => 'integer',
        'study_reward_daily_cap' => 'integer',
        'study_reward_rolling_30_day_cap' => 'integer',
        'first_project_reward_coins' => 'integer',
        'course_completion_reward_coins' => 'integer',
        'course_completion_rolling_30_day_cap' => 'integer',
        'ai_global_daily_request_limit' => 'integer',
        'ai_global_daily_token_budget' => 'integer',
        'ai_global_monthly_token_budget' => 'integer',
        'ai_plan_policy' => 'array',
    ];

    protected $hidden = [
        'bunny_api_key',
        'bunny_storage_password',
        'bunny_api_key_secret',
        'bunny_storage_password_secret',
        'bunny_security_key_secret',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            PublicAppSettingsService::invalidate();
            self::invalidateDerivedCaches();
        };

        static::saved($invalidate);
        static::deleted($invalidate);
    }

    /**
     * Settings feed several small read caches in otherwise unrelated product
     * areas. Treat them as one projection family: a dashboard save becomes
     * visible to login, wallet, checkout and learning on the same commit.
     */
    private static function invalidateDerivedCaches(): void
    {
        $forget = static function (): void {
            foreach (self::DERIVED_CACHE_KEYS as $key) {
                Cache::forget($key);
            }
        };

        try {
            if (DB::transactionLevel() > 0) {
                DB::afterCommit($forget);
                return;
            }

            $forget();
        } catch (Throwable $exception) {
            // A cache outage must not roll back a valid settings change, but
            // unlike a silent stale value it remains visible operationally.
            report($exception);
        }
    }

    /**
     * Legacy plaintext credentials are migration inputs only. Returning null
     * prevents any runtime service from silently falling back to them while
     * the migration command can still inspect getRawOriginal().
     */
    public function getBunnyApiKeyAttribute(): ?string
    {
        return null;
    }

    public function getBunnyStoragePasswordAttribute(): ?string
    {
        return null;
    }

    /**
     * Returns how_to_use_coins in the request locale language.
     */
    public function getHowToUseCoinsAttribute(): ?string
    {
        $arabic = trim((string) ($this->attributes['how_to_use_coins_ar'] ?? ''));
        $english = trim((string) ($this->attributes['how_to_use_coins_en'] ?? ''));

        if (!RoknLocale::isArabic()) {
            return $english !== '' ? $english : ($arabic !== '' ? $arabic : self::DEFAULT_COIN_RULES_EN);
        }

        return $arabic !== '' ? $arabic : ($english !== '' ? $english : self::DEFAULT_COIN_RULES_AR);
    }

    /**
     * Check if Bunny.net integration is enabled and configured
     *
     * @return bool
     */
    public function isBunnyConfigured(): bool
    {
        return $this->bunny_enabled 
            && !empty(config('bunny.stream_api_key') ?: $this->bunny_api_key_secret)
            && !empty(config('bunny.library_id') ?: $this->bunny_library_id);
    }
}
