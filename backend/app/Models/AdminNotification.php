<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\HasPhoto;
use App\Traits\HasTranslate;

class AdminNotification extends Model
{
    use HasTranslate, HasPhoto;

    public const SURFACES = [
        'guest_prompt' => 'قبل تسجيل الدخول',
        'transactional' => 'بعد إجراء مؤكد',
        'retention' => 'الاحتفاظ والعودة',
        'announcement' => 'جديد ومقترحات',
    ];

    public const SYSTEM_KEYS = [
        'guest_registration_prompt',
        'welcome_bonus_received',
        'coin_offer',
        'learning_nudge',
        'course_enrolled',
        'institutional_grant',
        'package_purchased',
        'coins_claimed',
        'whatsapp_connected',
        'course_completed',
        'certificate_ready',
        'project_update',
        'new_course_lesson',
        'course_update',
        'course_promotion',
        'new_course',
        'continue_course',
        'support_case_update',
    ];

    public function isSystemTemplate(): bool
    {
        return in_array((string) $this->system_key, self::SYSTEM_KEYS, true);
    }

    public function getPublicImageUrlAttribute(): ?string
    {
        $image = trim((string) $this->image);
        if ($image === '') {
            return null;
        }
        if (filter_var($image, FILTER_VALIDATE_URL)) {
            return str_starts_with(strtolower($image), 'https://') ? $image : null;
        }

        $base = rtrim((string) config('app.url'), '/');
        return str_starts_with(strtolower($base), 'https://')
            ? $base . '/' . ltrim($image, '/')
            : null;
    }

    protected $fillable = [
        'system_key',
        'surface',
        'title_ar',
        'title_en',
        'description_ar',
        'description_en',
        'action_label_ar',
        'action_label_en',
        'secondary_action_label_ar',
        'secondary_action_label_en',
        'link',
        'is_active',
        'is_dismissible',
        'priority',
        'cooldown_hours',
        'starts_at',
        'ends_at',
        'authoring_request_id',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'is_dismissible' => 'boolean',
        'priority' => 'integer',
        'cooldown_hours' => 'integer',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
    ];

    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)
            ->where(function ($active): void {
                $active->whereNull('starts_at')->orWhere('starts_at', '<=', now());
            })
            ->where(function ($active): void {
                $active->whereNull('ends_at')->orWhere('ends_at', '>', now());
            });
    }
}
