<?php

declare(strict_types=1);

namespace App\Support;

/** Non-negative, six-decimal USD amounts used by the AI reservation ledger. */
final class AiUsageCost
{
    public static function micros($value): int
    {
        return max(0, (int) round(((float) $value) * 1_000_000));
    }

    public static function format(int $micros): string
    {
        return number_format(max(0, $micros) / 1_000_000, 6, '.', '');
    }
}
