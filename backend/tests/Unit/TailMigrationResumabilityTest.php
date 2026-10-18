<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TailMigrationResumabilityTest extends TestCase
{
    public function test_forward_tail_ddl_can_resume_after_an_interrupted_deployment(): void
    {
        $backend = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents($backend.'/database/migration-baseline-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $tailStartsAt = (string) $manifest['tailStartsAt'];
        $violations = [];

        foreach (glob($backend.'/database/migrations/*.php') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $timestamp = substr($name, 0, 17);
            if (strcmp($timestamp, $tailStartsAt) < 0) {
                continue;
            }

            $code = $this->withoutComments((string) file_get_contents($file));
            $up = $this->upBody($code);
            preg_match_all(
                '/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/',
                $up,
                $created
            );
            foreach ($created[1] as $table) {
                $guard = '/Schema::hasTable\(\s*[\'\"]'.preg_quote($table, '/').'[\'\"]\s*\)/';
                if (preg_match($guard, $up) !== 1) {
                    $violations[] = basename($file)." creates {$table} without a resume guard";
                }
            }

            $ddlOperations = preg_match_all('/Schema::(?:table|create|rename|drop)\s*\(/', $up);
            if ($ddlOperations > 1 && preg_match('/\$withinTransaction\s*=\s*false/', $code) !== 1) {
                $violations[] = basename($file).' contains multi-step DDL without an explicit non-transactional contract';
            }

            if (preg_match('/->insert\s*\(/', $up) === 1) {
                $violations[] = basename($file).' contains a non-idempotent seed insert';
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_forward_tail_is_independent_from_mutable_application_code(): void
    {
        $backend = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents($backend.'/database/migration-baseline-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $tailStartsAt = (string) $manifest['tailStartsAt'];
        $violations = [];

        foreach (glob($backend.'/database/migrations/*.php') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            if (strcmp(substr($name, 0, 17), $tailStartsAt) < 0) {
                continue;
            }

            $code = $this->withoutComments((string) file_get_contents($file));
            if (preg_match('/^use\s+App\\\\/m', $code) === 1) {
                $violations[] = basename($file).' imports mutable application code';
            }
            if (preg_match('/\bapp\s*\(\s*[A-Za-z_\\\\]+::class\s*\)/', $code) === 1) {
                $violations[] = basename($file).' resolves a mutable application service';
            }
            if (preg_match('/^use\s+Illuminate\\\\Database\\\\Eloquent\\\\/m', $code) === 1) {
                $violations[] = basename($file).' boots through Eloquent instead of the schema/query layer';
            }
        }

        self::assertSame([], $violations, implode(PHP_EOL, $violations));
    }

    public function test_same_timestamp_migrations_do_not_depend_on_a_sibling_table(): void
    {
        $backend = dirname(__DIR__, 2);
        $manifest = json_decode(
            (string) file_get_contents($backend.'/database/migration-baseline-manifest.json'),
            true,
            flags: JSON_THROW_ON_ERROR
        );
        $tailStartsAt = (string) $manifest['tailStartsAt'];
        $groups = [];

        foreach (glob($backend.'/database/migrations/*.php') ?: [] as $file) {
            $name = pathinfo($file, PATHINFO_FILENAME);
            $timestamp = substr($name, 0, 17);
            if (strcmp($timestamp, $tailStartsAt) < 0) {
                continue;
            }
            $code = $this->withoutComments((string) file_get_contents($file));
            preg_match_all('/Schema::create\(\s*[\'\"]([^\'\"]+)[\'\"]/', $code, $created);
            $groups[$timestamp][] = [
                'name' => basename($file),
                'code' => $code,
                'created' => $created[1],
            ];
        }

        $violations = [];
        foreach ($groups as $siblings) {
            if (count($siblings) < 2) {
                continue;
            }
            foreach ($siblings as $owner) {
                foreach ($owner['created'] as $table) {
                    foreach ($siblings as $candidate) {
                        if ($candidate['name'] === $owner['name']) {
                            continue;
                        }
                        if (preg_match('/[\'\"]'.preg_quote($table, '/').'[\'\"]/', $candidate['code']) === 1) {
                            $violations[] = $candidate['name']." depends on {$table} created by same-timestamp ".$owner['name'];
                        }
                    }
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
}
