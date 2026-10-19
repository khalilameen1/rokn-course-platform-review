<?php

declare(strict_types=1);

namespace App\Support;

/** One transport adapter for local form dates and comma-separated email domains. */
final class CourseCodeFormData
{
    public static function payload(array $data): array
    {
        $payload = array_intersect_key($data, array_flip([
            'name', 'type', 'course_id', 'max_uses', 'description', 'start_date', 'expiry_date', 'is_active',
        ]));
        $payload['is_grant'] = (bool) ($data['is_grant'] ?? false);
        $payload['allowed_email_domains'] = collect(preg_split('/[,\r\n]+/', (string) ($data['allowed_email_domains'] ?? '')))
            ->map(fn (string $domain): string => ltrim(mb_strtolower(trim($domain)), '@'))
            ->filter()->unique()->values()->all() ?: null;
        foreach (['start_date', 'expiry_date'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = BusinessClock::localInputToUtc($payload[$field]);
            }
        }

        return $payload;
    }
}
