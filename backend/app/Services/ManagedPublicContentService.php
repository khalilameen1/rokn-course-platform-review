<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\About;
use App\Support\DatabaseCapabilities;

final class ManagedPublicContentService
{
    private const FIELDS = [
        'about' => ['ar' => 'about_ar', 'en' => 'about_en'],
        'privacy' => ['ar' => 'privacy_ar', 'en' => 'privacy_en'],
        'terms' => ['ar' => 'policy_ar', 'en' => 'policy_en'],
    ];

    // Seed prompts are not published policy text. Keep genuine dashboard edits.
    private const SEED_PLACEHOLDERS = [
        'about_ar' => 'ركن منصة تعليمية تعتمد على خطوات قصيرة وتطبيق عملي.',
        'about_en' => 'Rokn is a learning platform built around short, practical steps.',
        'privacy_ar' => 'راجع سياسة الخصوصية المنشورة داخل التطبيق.',
        'privacy_en' => 'See the privacy policy published in the application.',
        'policy_ar' => 'راجع شروط الاستخدام المنشورة داخل التطبيق.',
        'policy_en' => 'See the terms of use published in the application.',
    ];

    public function body(string $page, string $locale): ?string
    {
        $field = self::FIELDS[$page][$locale === 'en' ? 'en' : 'ar'] ?? null;
        if ($field === null || !DatabaseCapabilities::hasTable('abouts')) {
            return null;
        }

        $value = trim((string) (About::query()->value($field) ?? ''));

        return $value !== '' && $value !== (self::SEED_PLACEHOLDERS[$field] ?? null)
            ? $value
            : null;
    }
}
