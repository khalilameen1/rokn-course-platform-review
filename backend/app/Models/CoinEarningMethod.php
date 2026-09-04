<?php

namespace App\Models;

use App\Services\PublicAppSettingsService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Schema;

class CoinEarningMethod extends Model
{
    use SoftDeletes;

    public static function bootSoftDeletes(): void
    {
        if (
            Schema::hasTable('coin_earning_methods')
            && Schema::hasColumn('coin_earning_methods', 'deleted_at')
        ) {
            static::addGlobalScope(new SoftDeletingScope);
        }
    }

    private const TRUSTED_ACTION_HOSTS = [
        'wa.me',
        'whatsapp.com',
        'instagram.com',
        'tiktok.com',
        'facebook.com',
        'fb.com',
        'youtube.com',
        'youtu.be',
        'rokn.app',
    ];

    protected $fillable = [
        'title_ar',
        'title_en',
        'coins_amount',
        'action_key',
        'campaign_key',
        'action_url',
        'requires_external_visit',
        'verification_delay_seconds',
        'starts_at',
        'ends_at',
        'total_claim_limit',
        'sort_order',
        'is_active',
        'is_repeatable',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_repeatable' => 'boolean',
        'requires_external_visit' => 'boolean',
        'verification_delay_seconds' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'total_claim_limit' => 'integer',
        'sort_order' => 'integer',
    ];

    protected static function booted(): void
    {
        static::updating(function (CoinEarningMethod $method): void {
            $hasStarted = $method->attempts()->exists() || $method->userEarnings()->exists();
            if ($hasStarted && $method->isDirty([
                'action_key',
                'campaign_key',
                'coins_amount',
                'starts_at',
                'requires_external_visit',
                'verification_delay_seconds',
            ])) {
                throw new \DomainException(
                    'بدأت هذه الحملة بالفعل. أوقفها وأنشئ حملة جديدة لتغيير عقد المكافأة.'
                );
            }

            if ($method->isDirty('total_claim_limit') && $method->total_claim_limit !== null) {
                $claimed = $method->userEarnings()->count();
                if ((int) $method->total_claim_limit < $claimed) {
                    throw new \DomainException('سقف الحملة لا يمكن أن يقل عن المطالبات المنفذة.');
                }
            }
        });
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true)
            ->where(function ($window): void {
                $window->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($window): void {
                $window->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }

    /**
     * Tasks exposed to learners have a stable action identity and a real
     * reward. Keep this catalogue boundary separate from `active()` because
     * the registration reward is consumed internally during social login.
     */
    public function scopeLearnerTask($query)
    {
        return $query->active()
            ->whereNotNull('action_key')
            ->where('action_key', '!=', '')
            ->where('action_key', '!=', 'register')
            ->where('coins_amount', '>', 0);
    }

    public function isLearnerTask(): bool
    {
        $actionKey = trim((string) $this->action_key);

        return $this->isAvailableNow()
            && $actionKey !== ''
            && $actionKey !== 'register'
            && (int) $this->coins_amount > 0;
    }

    public function userEarnings()
    {
        return $this->hasMany(UserCoinEarning::class, 'coin_earning_method_id');
    }

    public function attempts()
    {
        return $this->hasMany(UserCoinTaskAttempt::class);
    }

    public function hasClaimCapacity(): bool
    {
        return $this->total_claim_limit === null
            || $this->userEarnings()->count() < (int) $this->total_claim_limit;
    }

    public function isAvailableNow(): bool
    {
        return (bool) $this->is_active
            && ($this->starts_at === null || !$this->starts_at->isFuture())
            && ($this->ends_at === null || $this->ends_at->isFuture());
    }

    public function resolvedActionUrl(): ?string
    {
        $url = trim((string) $this->action_url);
        if ($url === '') {
            $channel = $this->socialChannel();
            if ($channel !== null) {
                $settings = app(PublicAppSettingsService::class)->snapshot();
                $url = trim((string) ($settings['social_media'][$channel] ?? ''));
            }
        }

        return self::isTrustedActionUrl($url) ? $url : null;
    }

    public function learnerTitleAr(): string
    {
        $key = strtolower(trim((string) $this->action_key));
        $title = trim((string) ($this->title_ar ?: $this->title_en));

        if (!$this->titleRevealsMechanism($title) && $title !== '') return $title;

        if (str_contains($key, 'coin_guide')) return 'تعرّف إلى رصيد ركن';
        if (str_contains($key, 'instagram')) return 'تابع ركن على Instagram';
        if (str_contains($key, 'tiktok')) return 'تابع ركن على TikTok';
        if (str_contains($key, 'facebook')) return 'تابع ركن على Facebook';
        if (str_contains($key, 'youtube')) return 'تابع ركن على YouTube';
        if ($key === 'link_whatsapp') return 'اربط واتسابك بركن';

        return 'مهمة مكافأة';
    }

    public function learnerTitleEn(): string
    {
        $key = strtolower(trim((string) $this->action_key));
        $title = trim((string) ($this->title_en ?: $this->title_ar));

        if (!$this->titleRevealsMechanism($title) && $title !== '') return $title;

        if (str_contains($key, 'coin_guide')) return 'Learn about your Rokn balance';
        if (str_contains($key, 'instagram')) return 'Follow Rokn on Instagram';
        if (str_contains($key, 'tiktok')) return 'Follow Rokn on TikTok';
        if (str_contains($key, 'facebook')) return 'Follow Rokn on Facebook';
        if (str_contains($key, 'youtube')) return 'Follow Rokn on YouTube';
        if ($key === 'link_whatsapp') return 'Link WhatsApp to Rokn';

        return 'Reward task';
    }

    public function hasUsableDestination(): bool
    {
        if ($this->action_key === 'link_whatsapp') {
            return (bool) config('whatsapp.enabled')
                && preg_match(
                    '/\A[1-9][0-9]{7,14}\z/D',
                    trim((string) config('whatsapp.linking.bot_phone'))
                ) === 1;
        }

        return !$this->requires_external_visit || $this->resolvedActionUrl() !== null;
    }

    public static function isTrustedActionUrl(?string $value): bool
    {
        $parts = parse_url(trim((string) $value));
        if (!is_array($parts) || strtolower((string) ($parts['scheme'] ?? '')) !== 'https') {
            return false;
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return false;
        }

        $host = strtolower((string) ($parts['host'] ?? ''));
        foreach (self::TRUSTED_ACTION_HOSTS as $root) {
            if ($host === $root || str_ends_with($host, ".{$root}")) {
                return true;
            }
        }

        return false;
    }

    private function socialChannel(): ?string
    {
        $key = strtolower(trim((string) $this->action_key));
        foreach (['instagram', 'tiktok', 'facebook', 'youtube'] as $channel) {
            if ($key === $channel || str_contains($key, $channel)) {
                return $channel;
            }
        }

        return null;
    }

    private function titleRevealsMechanism(string $title): bool
    {
        return preg_match(
            '/(?:افتح|فتح).*(?:ارجع|عد|المطالبة)|(?:ارجع|عد).*(?:استلم|طالب)|open.*return.*claim/iu',
            $title
        ) === 1;
    }
}
