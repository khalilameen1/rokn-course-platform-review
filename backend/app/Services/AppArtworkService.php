<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DesignSetting;

/** Dashboard-owned artwork, shared by public settings and level fallbacks. */
final class AppArtworkService
{
    private ?array $levelUrls = null;

    public function forget(): void
    {
        $this->levelUrls = null;
    }

    public const ASSETS = [
        'coin' => ['label' => 'رمز العملة', 'file' => 'coin.png'],
        'coin_stack' => ['label' => 'صورة رصيد العملات', 'file' => 'coin-stack.png'],
        'badge_junior' => ['label' => 'شارة Junior الافتراضية', 'file' => 'junior.png'],
        'badge_mid' => ['label' => 'شارة Mid-level الافتراضية', 'file' => 'mid-level.png'],
        'badge_senior' => ['label' => 'شارة Senior الافتراضية', 'file' => 'senior.png'],
    ];

    /** @return array<string, string> */
    public function urls(?DesignSetting $settings = null): array
    {
        $settings ??= DesignSetting::getDefaultSettings();
        $urls = [];
        foreach (self::ASSETS as $key => $asset) {
            $urls[$key] = $settings->getAttribute($key.'_image_url')
                ?: asset('assets/app-artwork/v1/'.$asset['file']);
        }
        return $urls;
    }

    public function levelDefault(int $order): string
    {
        $key = $order <= 1 ? 'badge_junior' : ($order === 2 ? 'badge_mid' : 'badge_senior');
        $this->levelUrls ??= $this->urls();
        return $this->levelUrls[$key];
    }
}
