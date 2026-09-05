<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ContinuousIntegrationLayoutTest extends TestCase
{
    public function test_backend_workflow_is_discoverable_from_the_monorepo_root(): void
    {
        $backend = dirname(__DIR__, 2);
        $workflow = dirname($backend).'/.github/workflows/backend-ci.yml';

        self::assertFileExists($workflow);
        self::assertFileDoesNotExist($backend.'/.github/workflows/backend-ci.yml');

        $contents = (string) file_get_contents($workflow);
        self::assertStringContainsString('working-directory: backend', $contents);
        self::assertStringContainsString('cache-dependency-path: backend/package-lock.json', $contents);
        self::assertMatchesRegularExpression('/-[ ]+["\']backend\/\*\*["\']/', $contents);
        self::assertStringContainsString('php-version: "8.4"', $contents);
        self::assertStringContainsString('PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION', $contents);
        self::assertStringContainsString('PHP_VERSION_ID;\')" -ge 80424', $contents);
        self::assertStringContainsString('tools: composer:2.10.3', $contents);
        self::assertStringContainsString('php scripts/verify-repository-secrets.php --history', $contents);
        self::assertStringContainsString('php artisan test', $contents);

        $mysqlReplay = strpos($contents, 'php artisan migrate:fresh --force --no-interaction');
        $schemaContract = strpos(
            $contents,
            'php artisan rokn:preflight --schema-only --allow-mixed-release --no-interaction'
        );
        $snapshotContract = strpos(
            $contents,
            'vendor/bin/phpunit --configuration=phpunit.mysql.xml'
        );
        $sqliteSuite = strpos($contents, '- name: Run full test suite');
        self::assertIsInt($mysqlReplay);
        self::assertIsInt($schemaContract);
        self::assertIsInt($snapshotContract);
        self::assertIsInt($sqliteSuite);
        self::assertGreaterThan($mysqlReplay, $schemaContract);
        self::assertGreaterThan($schemaContract, $snapshotContract);
        self::assertGreaterThan($snapshotContract, $sqliteSuite);
        self::assertStringContainsString('ROKN_REQUIRE_MYSQL_CONTRACT_TEST: "true"', $contents);
        $mysqlConfiguration = (string) file_get_contents($backend.'/phpunit.mysql.xml');
        self::assertStringContainsString('AccessPlanSnapshotMysqlConstraintTest.php', $mysqlConfiguration);
        self::assertStringContainsString('name="DB_CONNECTION" value="mysql"', $mysqlConfiguration);
        self::assertStringNotContainsString(':memory:', $mysqlConfiguration);

        $composer = json_decode(
            (string) file_get_contents($backend.'/composer.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        self::assertSame('^8.4.24', $composer['require']['php'] ?? null);
        self::assertSame('8.4.24', $composer['config']['platform']['php'] ?? null);
    }
}
