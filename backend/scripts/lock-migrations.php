<?php

declare(strict_types=1);

/*
 * Append-only migration lock, after Flyway's checksum validation: every
 * migration that has been merged keeps its checksum, so an edit to a
 * shipped migration fails CI instead of silently diverging from databases
 * that already ran it.
 *
 * Usage (from backend/):
 *   php scripts/lock-migrations.php           verify, exit 1 on drift
 *   php scripts/lock-migrations.php --write   add new migrations to the lock
 *
 * --write never changes an existing entry; a changed checksum must be
 * resolved by restoring the file and adding a new migration instead.
 */

$root = dirname(__DIR__);
$lockPath = $root.'/database/migration-lock.json';
$directory = $root.'/database/migrations';

$files = glob($directory.'/*.php') ?: [];
sort($files, SORT_STRING);
$current = [];
foreach ($files as $file) {
    $normalized = str_replace(["\r\n", "\r"], "\n", (string) file_get_contents($file));
    $current[basename($file)] = hash('sha256', $normalized);
}

$locked = is_file($lockPath)
    ? (array) json_decode((string) file_get_contents($lockPath), true, 512, JSON_THROW_ON_ERROR)
    : [];
$entries = (array) ($locked['migrations'] ?? []);

$problems = [];
foreach ($entries as $name => $checksum) {
    if (! array_key_exists($name, $current)) {
        $problems[] = "missing locked migration: {$name}";
    } elseif (! hash_equals((string) $checksum, $current[$name])) {
        $problems[] = "edited locked migration: {$name} (add a new migration instead)";
    }
}

if (in_array('--write', $argv, true)) {
    if ($problems !== []) {
        fwrite(STDERR, implode("\n", $problems)."\n");
        exit(1);
    }
    $added = array_diff_key($current, $entries);
    $entries += $added;
    ksort($entries, SORT_STRING);
    file_put_contents($lockPath, json_encode([
        'format' => 'sha256 of the LF-normalized file, keyed by migration file name',
        'migrations' => $entries,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
    fwrite(STDOUT, sprintf("locked %d new migration(s), %d total\n", count($added), count($entries)));
    exit(0);
}

if ($problems !== []) {
    fwrite(STDERR, implode("\n", $problems)."\n");
    exit(1);
}
fwrite(STDOUT, sprintf("migration lock ok: %d locked, %d unlocked new\n", count($entries), count(array_diff_key($current, $entries))));
