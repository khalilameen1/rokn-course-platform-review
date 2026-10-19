<?php

declare(strict_types=1);

namespace App\Support;

/** Shared input boundary for validated task forms; the owner receives UTC instants. */
final class RewardMethodInput
{
    public static function fromLocalForm(array $data): array
    {
        unset($data['authoring_request_id'], $data['editor_version']);
        foreach (['title_ar', 'title_en'] as $field) {
            $data[$field] = trim((string) $data[$field]);
        }
        foreach (['action_key', 'campaign_key', 'action_url'] as $field) {
            $value = trim((string) ($data[$field] ?? ''));
            $data[$field] = $value !== '' ? $value : null;
        }
        foreach (['starts_at', 'ends_at'] as $field) {
            $data[$field] = BusinessClock::localInputToUtc($data[$field] ?? null);
        }
        $data['coins_amount'] = (int) $data['coins_amount'];
        $data['requires_external_visit'] = (bool) ($data['requires_external_visit'] ?? false);
        $data['verification_delay_seconds'] = (int) ($data['verification_delay_seconds'] ?? 0);
        $data['total_claim_limit'] = filled($data['total_claim_limit'] ?? null) ? (int) $data['total_claim_limit'] : null;
        $data['sort_order'] = (int) ($data['sort_order'] ?? 100);
        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $data['is_repeatable'] = false;

        return $data;
    }
}
