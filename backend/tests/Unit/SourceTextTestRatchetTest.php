<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Tests that read application source and assert on substrings break on
 * refactors and prove nothing about behaviour. Existing ones are a baseline
 * (the PHPStan/ESLint baseline-file ratchet): they may be converted and
 * removed from this list, but no new test file may start doing it.
 */
final class SourceTextTestRatchetTest extends TestCase
{
    /** @var list<string> */
    private const BASELINE = [];

    public function test_no_new_test_asserts_on_application_source_text(): void
    {
        $root = dirname(__DIR__, 2);
        $readsSource = '/(file_get_contents|File::get)\(\s*(app_path|resource_path|base_path|dirname\(__DIR__|__DIR__)/';
        $sourcePath = '/app_path\(|resource_path\(|[\'"]\/?app\/|\/resources\/views|[\'"]\/?routes\/(web|api)/';
        $offenders = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/tests'));
        foreach ($files as $file) {
            if (! $file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $relative = 'tests/'.str_replace('\\', '/', substr($file->getPathname(), strlen($root.'/tests') + 1));
            if ($relative === 'tests/Unit/SourceTextTestRatchetTest.php' || in_array($relative, self::BASELINE, true)) {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match($readsSource, $contents) === 1 && preg_match($sourcePath, $contents) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'Test behaviour through HTTP, services or rendered views instead of reading source text.'
        );
    }
}
