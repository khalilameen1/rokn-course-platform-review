<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Console\Commands\VerifyRestoreDrill;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;
use RuntimeException;
use Tests\TestCase;

final class RestoreDrillConnectionIsolationTest extends TestCase
{
    #[DataProvider('connectionCases')]
    public function test_mysql_client_and_laravel_share_a_resolved_single_endpoint(?string $url): void
    {
        $source = [
            'driver' => 'mysql',
            'url' => $url,
            'host' => 'fallback.invalid',
            'port' => 3306,
            'database' => 'fallback_primary',
            'username' => 'fallback_user',
            'password' => 'fixture-only',
            'read' => ['host' => 'production-reader.invalid', 'database' => 'production'],
            'write' => ['host' => 'production-writer.invalid', 'database' => 'production'],
        ];
        config(['database.connections.mysql' => $source]);
        $method = new ReflectionMethod(VerifyRestoreDrill::class, 'mysqlConnectionConfig');
        $resolved = $method->invoke(app(VerifyRestoreDrill::class));

        self::assertSame($url === null ? 'fallback_primary' : 'url_primary', $resolved['database']);
        self::assertSame($url === null ? 'fallback.invalid' : 'url-host.invalid', $resolved['host']);
        self::assertSame($url === null ? 'fallback_user' : 'url_user', $resolved['username']);
        self::assertArrayNotHasKey('url', $resolved);
        self::assertArrayNotHasKey('read', $resolved);
        self::assertArrayNotHasKey('write', $resolved);
        self::assertSame($source, config('database.connections.mysql'));

        config(['database.connections.restore_verify' => [...$resolved, 'database' => 'rokn_restore_verify_fixture']]);
        try {
            $connection = DB::connection('restore_verify');
            self::assertSame('rokn_restore_verify_fixture', $connection->getDatabaseName());
            self::assertSame($resolved['host'], $connection->getConfig('host'));
            self::assertArrayNotHasKey('read', $connection->getConfig());
            self::assertArrayNotHasKey('write', $connection->getConfig());
        } finally {
            DB::purge('restore_verify');
        }
    }

    public static function connectionCases(): array
    {
        // Deliberately synthetic credentials, assembled rather than committed
        // as a credential-bearing URL. The parser still receives a full URL.
        $url = implode('', ['mysql://', 'url_user', ':fixture-only@url-host.invalid:3307/url_primary']);

        return [
            'individual fields' => [null],
            'DATABASE_URL overrides fallback fields' => [$url],
            'URL query options cannot reintroduce replicas' => [$url.'?read[database]=production&write[database]=production'],
        ];
    }

    public function test_resolved_primary_name_is_rejected_before_any_restore(): void
    {
        $dump = storage_path('fixture.sql');
        File::put($dump, '-- fixture only');
        config(['database.connections.mysql.url' => implode('', [
            'mysql://', 'fixture', ':fixture-only@unused.invalid/rokn_restore_verify_primary',
        ])]);

        $this->artisan('ops:verify-restore', [
            '--dump' => $dump,
            '--database' => 'rokn_restore_verify_primary',
            '--confirm' => 'RESTORE_rokn_restore_verify_primary',
        ])->expectsOutput('Refusing to restore over the configured primary database.')->assertFailed();
    }

    #[DataProvider('unsupportedTransports')]
    public function test_unsupported_transport_is_rejected_before_any_client_runs(mixed $host, string $socket): void
    {
        config(['database.connections.mysql' => [
            'driver' => 'mysql', 'url' => null, 'host' => $host, 'unix_socket' => $socket,
        ]]);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Restore verification requires one explicit TCP host without a Unix socket.');

        $method = new ReflectionMethod(VerifyRestoreDrill::class, 'mysqlConnectionConfig');
        $method->invoke(app(VerifyRestoreDrill::class));
    }

    public static function unsupportedTransports(): array
    {
        return [
            'socket would override the TCP host' => ['restore-host.invalid', '/tmp/mysql.sock'],
            'host list is not one CLI endpoint' => [['first.invalid', 'second.invalid'], ''],
            'localhost can select a PDO Unix socket' => ['localhost', ''],
            'empty host is not an explicit endpoint' => ['', ''],
        ];
    }

    #[DataProvider('databaseIdentities')]
    public function test_actual_pdo_database_identity_must_match_before_migrations(?string $actual, bool $accepted): void
    {
        $connection = $this->createMock(ConnectionInterface::class);
        $connection->expects(self::once())->method('selectOne')
            ->with('SELECT DATABASE() AS database_name')
            ->willReturn((object) ['database_name' => $actual]);
        if (!$accepted) {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage('Restore connection is not bound to the disposable database.');
        }

        $method = new ReflectionMethod(VerifyRestoreDrill::class, 'assertRestoredDatabase');
        $method->invoke(app(VerifyRestoreDrill::class), $connection, 'rokn_restore_verify_fixture');
    }

    public static function databaseIdentities(): array
    {
        return [
            'disposable database' => ['rokn_restore_verify_fixture', true],
            'production misrouting' => ['production', false],
            'no selected database' => [null, false],
        ];
    }
}
