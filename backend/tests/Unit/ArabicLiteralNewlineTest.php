<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class ArabicLiteralNewlineTest extends TestCase
{
    public function test_arabic_php_strings_do_not_hide_newlines_inside_single_quotes(): void
    {
        $violations = [];
        $appDirectory = dirname(__DIR__, 2).'/app';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($appDirectory));

        /** @var SplFileInfo $file */
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }

            foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
                if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                $literal = $token[1];
                if (
                    !str_starts_with($literal, "'")
                    || !str_contains($literal, '\\n')
                    || preg_match('/[\x{0600}-\x{06FF}]/u', $literal) !== 1
                ) {
                    continue;
                }

                $relativePath = str_replace('\\', '/', substr($file->getPathname(), strlen($appDirectory) + 1));
                $violations[] = $relativePath.':'.$token[2];
            }
        }

        self::assertSame(
            [],
            $violations,
            'Arabic text in a PHP single-quoted string renders \\n literally: '.implode(', ', $violations)
        );
    }
}
