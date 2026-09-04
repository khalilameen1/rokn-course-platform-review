<?php

namespace App\Models;

use App\Services\PublicAppSettingsService;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class DesignSetting extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'logo_url',
        'icon_url',
        'name_ar',
        'name_en',
        'slogan_1_ar',
        'slogan_1_en',
        'slogan_2_ar',
        'slogan_2_en',
        'slogan_3_ar',
        'slogan_3_en',
        'color_1',
        'color_2',
        'color_3',
        'color_4',
        'header_background',
        'home_background_url',
        'facebook_url',
        'youtube_url',
        'instagram_url',
        'tiktok_url',
        'whatsapp_url',
        'telegram_url',
        'powered_by',
        'show_how_platform_works',
        'how_platform_works_title_ar',
        'how_platform_works_title_en',
        'how_platform_works_video_link',
    ];

    protected $casts = [
        'powered_by' => 'array',
        'show_how_platform_works' => 'boolean',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        $invalidate = static function (): void {
            PublicAppSettingsService::invalidate();
        };
        static::saved($invalidate);
        static::deleted($invalidate);
        static::restored($invalidate);
    }

    public function getNameAttribute()
    {
        return $this->name_ar ?: $this->name_en;
    }

    public function setNameAttribute($value)
    {
        $this->attributes['name_ar'] = $value;
        $this->attributes['name_en'] = $value;
    }

    public function getSlogan1Attribute()
    {
        return $this->slogan_1_ar ?: $this->slogan_1_en;
    }

    public function getSlogan2Attribute()
    {
        return $this->slogan_2_ar ?: $this->slogan_2_en;
    }

    public function getSlogan3Attribute()
    {
        return $this->slogan_3_ar ?: $this->slogan_3_en;
    }

    public function getSlogansArray(): array
    {
        return [
            'slogan_1' => $this->slogan_1_ar,
            'slogan_2' => $this->slogan_2_ar,
            'slogan_3' => $this->slogan_3_ar,
        ];
    }

    public function getColorsArray(): array
    {
        return [
            'color_1' => $this->color_1,
            'color_2' => $this->color_2,
            'color_3' => $this->color_3,
            'color_4' => $this->color_4,
        ];
    }

    public function getSocialMediaArray(): array
    {
        return [
            'facebook' => $this->facebook_url,
            'youtube' => $this->youtube_url,
            'instagram' => $this->instagram_url,
            'tiktok' => $this->tiktok_url,
            'whatsapp' => $this->whatsapp_url,
            'telegram' => $this->telegram_url,
        ];
    }

    public function getBackgroundImagesArray(): array
    {
        return [
            'header' => $this->header_background,
            'home' => $this->home_background_url,
        ];
    }

    /**
     * Return persisted design settings or safe, brand-consistent defaults.
     *
     * The fallback is deliberately free of external URLs and legal copy. Those
     * values must be configured explicitly rather than silently exposing stale
     * partner links or policy text.
     */
    public static function getDefaultSettings(): self
    {
        $settings = static::query()->first();

        if ($settings) {
            return $settings;
        }

        $settings = new static();
        $settings->forceFill([
            'logo_url' => null,
            'icon_url' => null,
            'name_ar' => 'ركن',
            'name_en' => 'Rokn',
            'slogan_1_ar' => 'اسكرول واتعلم',
            'slogan_1_en' => 'Scroll and learn',
            'slogan_2_ar' => 'محتوى تعليمي خطوة بخطوة',
            'slogan_2_en' => 'Learning, one step at a time',
            'slogan_3_ar' => 'شاهد وطبّق وكمل من مكانك',
            'slogan_3_en' => 'Watch, practise, and continue anywhere',
            'color_1' => '#2F7DF6',
            'color_2' => '#0B1020',
            'color_3' => '#F8FAFC',
            'color_4' => '#D6B45F',
            'header_background' => null,
            'home_background_url' => null,
            'facebook_url' => null,
            'youtube_url' => null,
            'instagram_url' => null,
            'tiktok_url' => null,
            'whatsapp_url' => null,
            'telegram_url' => null,
            'powered_by' => [],
            'show_how_platform_works' => false,
            'how_platform_works_title_ar' => null,
            'how_platform_works_title_en' => null,
            'how_platform_works_video_link' => null,
        ]);

        return $settings;
    }
}
