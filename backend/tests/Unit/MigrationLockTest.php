<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MigrationLockTest extends TestCase
{
    public function test_merged_migrations_are_never_edited_or_removed(): void
    {
        $root = dirname(__DIR__, 2);
        $lock = json_decode((string) file_get_contents($root.'/database/migration-lock.json'), true, 512, JSON_THROW_ON_ERROR);
        $drift = [];
        foreach ((array) $lock['migrations'] as $name => $checksum) {
            $path = $root.'/database/migrations/'.$name;
            if (! is_file($path)) {
                $drift[] = "missing: {$name}";

                continue;
            }
            $normalized = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($path));
            if (! hash_equals((string) $checksum, hash('sha256', $normalized))) {
                $drift[] = "edited: {$name}";
            }
        }

        self::assertSame(
            [],
            $drift,
            'Shipped migrations are append-only (Flyway checksum rule). Restore the file and add a new migration.'
        );
    }
}
