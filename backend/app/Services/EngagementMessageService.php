<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AdminNotification;
use App\Models\RewardRule;
use App\Models\Setting;
use App\Support\RoknAppLink;

final class EngagementMessageService
{
    /** @return array<string, mixed>|null */
    public function publicMessage(string $systemKey, array $variables = []): ?array
    {
        $message = AdminNotification::query()
            ->available()
            ->where('system_key', $systemKey)
            ->first();

        if (!$message) {
            return null;
        }

        return $this->serialize($message, $variables);
    }

    /**
     * Resolve one immutable delivery snapshot. A configured but inactive
     * template suppresses that automated message; an unknown key keeps legacy
     * call sites working with their explicit fallback copy.
     *
     * @param array<string,mixed> $variables
     * @param array<string,mixed> $fallback
     * @return array<string,mixed>|null
     */
    public function notificationPayload(string $systemKey, array $variables, array $fallback): ?array
    {
        $configured = AdminNotification::query()->where('system_key', $systemKey)->first();
        if (!$configured) {
            return $fallback;
        }

        $available = AdminNotification::query()
            ->available()
            ->whereKey($configured->id)
            ->first();
        if (!$available) {
            return null;
        }

        $payload = $this->serialize($available, $variables);

        return [
            ...$fallback,
            'title_ar' => $payload['title_ar'] ?: ($fallback['title_ar'] ?? ''),
            'title_en' => $payload['title_en'] ?: ($fallback['title_en'] ?? ''),
            'message_ar' => $payload['description_ar'] ?: ($fallback['message_ar'] ?? ''),
            'message_en' => $payload['description_en'] ?: ($fallback['message_en'] ?? ''),
            'action_label_ar' => $payload['action_label_ar'] ?: ($fallback['action_label_ar'] ?? null),
            'action_label_en' => $payload['action_label_en'] ?: ($fallback['action_label_en'] ?? null),
            'image_url' => $payload['image_url'] ?: ($fallback['image_url'] ?? null),
            'template_link' => $payload['link'] ?: null,
        ];
    }

    /** @return array<string,mixed> */
    private function serialize(AdminNotification $message, array $variables): array
    {
        $coins = $variables['coins'] ?? $this->welcomeCoins();
        $variables['coins'] = max(0, (int) $coins);

        return [
            'id' => (string) $message->id,
            'key' => (string) $message->system_key,
            'surface' => (string) $message->surface,
            'title_ar' => $this->render((string) $message->title_ar, $variables, true),
            'title_en' => $this->render((string) $message->title_en, $variables, false),
            'description_ar' => $this->render((string) $message->description_ar, $variables, true),
            'description_en' => $this->render((string) $message->description_en, $variables, false),
            'action_label_ar' => $this->render((string) $message->action_label_ar, $variables, true),
            'action_label_en' => $this->render((string) $message->action_label_en, $variables, false),
            'secondary_action_label_ar' => $this->render((string) $message->secondary_action_label_ar, $variables, true),
            'secondary_action_label_en' => $this->render((string) $message->secondary_action_label_en, $variables, false),
            'link' => $this->safeTemplateLink($message->link),
            'image_url' => $message->public_image_url,
            'coins' => (int) $variables['coins'],
            'dismissible' => (bool) $message->is_dismissible,
            'cooldown_hours' => (int) $message->cooldown_hours,
            'version' => optional($message->updated_at)->toIso8601String(),
        ];
    }

    private function welcomeCoins(): int
    {
        return RewardRule::configuredAmount(
            'welcome_bonus',
            (int) (Setting::query()->value('welcome_bonus_coins')
                ?? config('social_auth.welcome_bonus_coins', 20))
        );
    }

    private function render(string $value, array $variables, bool $arabic): string
    {
        $replacements = [];
        foreach ($variables as $key => $replacement) {
            $text = (string) $replacement;
            if ($arabic && is_numeric($replacement)) {
                $text = strtr($text, [
                    '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
                    '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
                ]);
            }
            $replacements['{' . $key . '}'] = $text;
        }

        return trim(strtr($value, $replacements));
    }

    private function safeTemplateLink(mixed $value): ?string
    {
        $link = trim((string) $value);
        if ($link === '') {
            return null;
        }

        return RoknAppLink::normalize($link);
    }

}
