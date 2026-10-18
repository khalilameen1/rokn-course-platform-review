<?php

declare(strict_types=1);

namespace App\Support;

final class CsvCell
{
    public static function safe(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $value = UnicodeText::clean($value);

        // Spreadsheet engines ignore leading spaces and invisible marks when
        // deciding whether a cell is a formula. Prefix the original value so
        // exports stay text even when opened directly in Excel or Sheets.
        if (preg_match('/^[\p{Z}\s\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{FEFF}]*[=+\-@]/u', $value) === 1) {
            return "'".$value;
        }

        return $value;
    }

    /** @param array<int, mixed> $values */
    public static function row(array $values): array
    {
        return array_map([self::class, 'safe'], $values);
    }
}
