<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Support\Str;
use PHPUnit\Framework\TestCase;

final class MigrationDependencyOrderingTest extends TestCase
{
    public function test_forward_foreign_keys_are_explicitly_guarded(): void
    {
        $files = glob(dirname(__DIR__, 2).'/database/migrations/*.php') ?: [];
        sort($files, SORT_STRING);

        /** @var array<string, string> $createdBy */
        $createdBy = [];
        /** @var array<string, string> $upBodies */
        $upBodies = [];

        foreach ($files as $file) {
            $code = $this->withoutComments((string) file_get_contents($file));
            $upBodies[$file] = $this->upBody($code);

            preg_match_all(
                '/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/',
                $upBodies[$file],
                $matches
            );
            foreach ($matches[1] as $table) {
                $createdBy[$table] ??= basename($file);
            }
        }

        $violations = [];
        foreach ($upBodies as $file => $body) {
            foreach ($this->foreignTargets($body) as $target) {
                if (! isset($createdBy[$target])) {
                    $violations[] = basename($file)." references missing table {$target}";

                    continue;
                }

                if (strcmp($createdBy[$target], basename($file)) <= 0) {
                    continue;
                }

                $guard = '/Schema::hasTable\(\s*[\'\"]'.preg_quote($target, '/').'[\'\"]\s*\)/';
                if (! preg_match($guard, $body)) {
                    $violations[] = basename($file)
                        ." references {$target} before {$createdBy[$target]} without a compatibility guard";
                }
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    private function withoutComments(string $code): string
    {
        $clean = '';
        foreach (token_get_all($code) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            $clean .= is_array($token) ? $token[1] : $token;
        }

        return $clean;
    }

    private function upBody(string $code): string
    {
        $start = strpos($code, 'function up');
        if ($start === false) {
            return '';
        }

        $end = strpos($code, 'function down', $start);

        return substr($code, $start, $end === false ? null : $end - $start);
    }

    /** @return list<string> */
    private function foreignTargets(string $body): array
    {
        $targets = [];
        preg_match_all('/->(?:on|constrained)\(\s*[\'\"]([^\'\"]+)[\'\"]\s*\)/', $body, $explicit);
        foreach ($explicit[1] as $target) {
            $targets[] = $target;
        }

        preg_match_all(
            '/foreignId\(\s*[\'\"]([^\'\"]+)_id[\'\"]\s*\)(?:(?!;).)*?->constrained\(\s*\)/s',
            $body,
            $implicit
        );
        foreach ($implicit[1] as $base) {
            $targets[] = Str::plural($base);
        }

        return array_values(array_unique($targets));
    }
}
