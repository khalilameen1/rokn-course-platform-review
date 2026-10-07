<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Rokn\Tooling\RepositorySecretScanner;

require_once dirname(__DIR__, 2).'/scripts/RepositorySecretScanner.php';

final class RepositorySecretScannerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rokn-secret-scan-'.bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700, true));
    }

    protected function tearDown(): void
    {
        $this->deleteDirectory($this->directory);

        parent::tearDown();
    }

    public function test_it_reports_secret_material_without_returning_the_value(): void
    {
        $privateKey = '-----BEGIN'.' PRIVATE KEY-----'.PHP_EOL.'not-a-real-key'.PHP_EOL.'-----END'.' PRIVATE KEY-----';
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'credentials.txt', $privateKey);

        $issues = (new RepositorySecretScanner())->scanFiles($this->directory, ['credentials.txt']);

        self::assertSame([
            ['path' => 'credentials.txt', 'rule' => 'private_key_material'],
        ], $issues);
        self::assertStringNotContainsString('not-a-real-key', json_encode($issues, JSON_THROW_ON_ERROR));
    }

    public function test_it_scans_large_files_and_mixed_binary_content(): void
    {
        $contents = "DB_"."PASSWORD=ordinaryProductionPassword123!\n".str_repeat("\0", 2_100_000);
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'large-config.bin', $contents);

        $issues = (new RepositorySecretScanner())->scanFiles($this->directory, ['large-config.bin']);

        self::assertSame([
            ['path' => 'large-config.bin', 'rule' => 'non_placeholder_secret_assignment'],
        ], $issues);
    }

    public function test_it_covers_minified_project_secrets_and_modern_provider_credentials(): void
    {
        $contents = implode("\n", [
            json_encode(
                ['OPENROUTER_'.'API_KEY' => 'ordinaryProductionPassword123!'],
                JSON_THROW_ON_ERROR
            ),
            '-----BEGIN '.'ENCRYPTED PRIVATE KEY-----',
            'ASIA'.str_repeat('A', 16),
            'github_'.'pat_'.str_repeat('A', 24),
        ]);

        self::assertSame([
            'private_key_material',
            'aws_access_key',
            'github_access_token',
            'non_placeholder_secret_assignment',
        ], (new RepositorySecretScanner())->scanContents($contents));
    }

    public function test_it_covers_shell_source_code_and_compose_assignments(): void
    {
        $scanner = new RepositorySecretScanner();
        $assignments = [
            'export DB_'.'PASSWORD=ordinaryProductionPassword123!',
            '- DB_'.'PASSWORD=ordinaryProductionPassword123!',
            "const DB_"."PASSWORD = 'ordinaryProductionPassword123!';",
            'env DB_'.'PASSWORD=ordinaryProductionPassword123! command',
        ];

        foreach ($assignments as $contents) {
            self::assertSame(
                ['non_placeholder_secret_assignment'],
                $scanner->scanContents($contents)
            );
        }

        self::assertSame([], $scanner->scanContents('env DB_'.'PASSWORD=${DB_PASSWORD} command'));
    }

    public function test_it_rejects_sensitive_paths_and_real_assignments(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'.env_copy', "DB_"."PASSWORD=latestProductionPassword123!\n");

        $issues = (new RepositorySecretScanner())->scanFiles($this->directory, ['.env_copy']);

        self::assertSame([
            ['path' => '.env_copy', 'rule' => 'environment_file'],
            ['path' => '.env_copy', 'rule' => 'non_placeholder_secret_assignment'],
        ], $issues);
    }

    public function test_it_does_not_treat_root_as_a_placeholder_password(): void
    {
        file_put_contents($this->directory.DIRECTORY_SEPARATOR.'.env.root', "DB_"."PASSWORD=root\n");

        $issues = (new RepositorySecretScanner())->scanFiles($this->directory, ['.env.root']);

        self::assertSame([
            ['path' => '.env.root', 'rule' => 'environment_file'],
            ['path' => '.env.root', 'rule' => 'non_placeholder_secret_assignment'],
        ], $issues);
    }

    public function test_reviewed_examples_and_ci_placeholders_are_allowed(): void
    {
        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.'.env.production.example',
            "APP_"."KEY=\nDB_"."PASSWORD=replace-me\nMAIL_"."PASSWORD=ci-only-not-a-secret\n"
        );

        $issues = (new RepositorySecretScanner())->scanFiles($this->directory, ['.env.production.example']);

        self::assertSame([], $issues);
    }

    public function test_only_exact_reviewed_secret_references_are_allowed(): void
    {
        $scanner = new RepositorySecretScanner();
        $accepted = implode("\n", [
            'APP_'.'KEY=${{ secrets.APP_KEY }}',
            'DB_'.'PASSWORD=${DB_PASSWORD}',
            'FIREBASE_'.'CREDENTIALS=/run/secrets/firebase-service-account.json',
            'MAIL_'.'PASSWORD=...',
            'KASHIER_LIVE_'.'API_KEY=<PLACEHOLDER>',
            'KASHIER_LIVE_'.'SECRET_KEY=${KASHIER_LIVE_SECRET_KEY}',
        ]);

        self::assertSame([], $scanner->scanContents($accepted));
        self::assertSame(
            ['non_placeholder_secret_assignment'],
            $scanner->scanContents('APP_'.'KEY=prefix-${{ secrets.APP_KEY }}')
        );
        self::assertSame(
            ['non_placeholder_secret_assignment'],
            $scanner->scanContents('FIREBASE_'.'CREDENTIALS=/run/secrets/firebase-service-account.json.backup!')
        );
    }

    public function test_every_declared_secret_like_name_is_explicitly_classified(): void
    {
        $root = dirname(__DIR__, 2);
        $contents = (string) file_get_contents($root.'/.env.example')
            ."\n".(string) file_get_contents($root.'/.env.production.example')
            ."\n".(string) file_get_contents(dirname($root).'/.github/workflows/backend-ci.yml');
        $scanner = new RepositorySecretScanner();

        self::assertSame([], $scanner->findUncoveredSecretNames($contents));
        self::assertSame(
            ['NEW_PROVIDER_CLIENT_SECRET'],
            $scanner->findUncoveredSecretNames('NEW_PROVIDER_CLIENT_SECRET=ordinaryProductionPassword123!')
        );
    }

    public function test_historical_sensitive_paths_are_detected_without_reading_their_values(): void
    {
        $issues = (new RepositorySecretScanner())->scanPathNames([
            'app/Http/Resources/.env_copy',
            'info.txt',
            'docs/architecture.md',
        ]);

        self::assertSame([
            ['path' => 'app/Http/Resources/.env_copy', 'rule' => 'environment_file'],
            ['path' => 'info.txt', 'rule' => 'hosting_credentials_file'],
        ], $issues);
    }

    public function test_signing_and_provisioning_files_are_sensitive_paths(): void
    {
        self::assertSame([
            ['path' => 'secrets/Rokn.mobileprovision', 'rule' => 'private_key_file'],
            ['path' => 'secrets/release-signing.jks', 'rule' => 'private_key_file'],
        ], (new RepositorySecretScanner())->scanPathNames([
            'secrets/release-signing.jks',
            'secrets/Rokn.mobileprovision',
        ]));
    }

    public function test_history_content_patterns_cover_provider_tokens_and_private_keys(): void
    {
        $patterns = (new RepositorySecretScanner())->historyContentPatterns();

        self::assertSame([
            'private_key_material',
            'aws_access_key',
            'github_access_token',
            'slack_access_token',
            'stripe_live_secret',
            'firebase_legacy_server_key',
            'google_api_key',
            'credentialed_connection_url',
            'named_secret_assignment',
        ], array_keys($patterns));
    }

    public function test_history_scan_detects_an_ordinary_deleted_password_without_disclosing_it(): void
    {
        $this->runCommand(['git', 'init', '--quiet'], $this->directory);
        $this->runCommand(['git', 'config', 'user.email', 'security-test@rokn.invalid'], $this->directory);
        $this->runCommand(['git', 'config', 'user.name', 'Rokn Security Test'], $this->directory);
        file_put_contents(
            $this->directory.DIRECTORY_SEPARATOR.'old-config.txt',
            "export DB_"."PASSWORD=ordinaryProductionPassword123!\n"
        );
        $this->runCommand(['git', 'add', 'old-config.txt'], $this->directory);
        $this->runCommand(['git', 'commit', '--quiet', '-m', 'historical fixture'], $this->directory);
        self::assertTrue(@unlink($this->directory.DIRECTORY_SEPARATOR.'old-config.txt'));
        $this->runCommand(['git', 'add', '--all'], $this->directory);
        $this->runCommand(['git', 'commit', '--quiet', '-m', 'delete historical fixture'], $this->directory);

        [$exitCode, $output] = $this->runCommand([
            PHP_BINARY,
            dirname(__DIR__, 2).'/scripts/verify-repository-secrets.php',
            '--root='.$this->directory,
            '--history',
        ], $this->directory, false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'history:old-config.txt [non_placeholder_secret_assignment]',
            $output
        );
        self::assertStringNotContainsString('ordinaryProductionPassword123!', $output);
    }

    public function test_history_scan_resolves_blobs_when_backend_is_nested_in_a_monorepo(): void
    {
        $this->runCommand(['git', 'init', '--quiet'], $this->directory);
        $this->runCommand(['git', 'config', 'user.email', 'security-test@rokn.invalid'], $this->directory);
        $this->runCommand(['git', 'config', 'user.name', 'Rokn Security Test'], $this->directory);
        $backend = $this->directory.DIRECTORY_SEPARATOR.'backend';
        self::assertTrue(mkdir($backend, 0700, true));
        file_put_contents(
            $backend.DIRECTORY_SEPARATOR.'old-config.txt',
            "export DB_"."PASSWORD=ordinaryProductionPassword123!\n"
        );
        $this->runCommand(['git', 'add', 'backend/old-config.txt'], $this->directory);
        $this->runCommand(['git', 'commit', '--quiet', '-m', 'nested historical fixture'], $this->directory);
        self::assertTrue(unlink($backend.DIRECTORY_SEPARATOR.'old-config.txt'));
        $this->runCommand(['git', 'add', '--all'], $this->directory);
        $this->runCommand(['git', 'commit', '--quiet', '-m', 'delete nested fixture'], $this->directory);

        [$exitCode, $output] = $this->runCommand([
            PHP_BINARY,
            dirname(__DIR__, 2).'/scripts/verify-repository-secrets.php',
            '--root='.$backend,
            '--history',
        ], $backend, false);

        self::assertSame(1, $exitCode);
        self::assertStringContainsString(
            'history:old-config.txt [non_placeholder_secret_assignment]',
            $output
        );
        self::assertStringNotContainsString('ordinaryProductionPassword123!', $output);
    }

    #[DataProvider('reviewedUrlHistoryCases')]
    public function test_reviewed_url_history_is_exact_path_scoped_and_does_not_exempt_current_files(
        string $path,
        string $contents,
        bool $keepCurrent,
        bool $nested,
        int $expectedExit,
        string $expectedIssue
    ): void {
        $this->runCommand(['git', 'init', '--quiet'], $this->directory);
        $this->runCommand(['git', 'config', 'user.email', 'security-test@rokn.invalid'], $this->directory);
        $this->runCommand(['git', 'config', 'user.name', 'Rokn Security Test'], $this->directory);
        $root = $nested ? $this->directory.DIRECTORY_SEPARATOR.'backend' : $this->directory;
        $file = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $path);
        self::assertTrue(mkdir(dirname($file), 0700, true));
        file_put_contents($file, $contents);
        $this->runCommand(['git', 'add', '--all'], $this->directory);
        $this->runCommand(['git', 'commit', '--quiet', '-m', 'reviewed URL fixture policy'], $this->directory);

        if (! $keepCurrent) {
            self::assertTrue(unlink($file));
            $this->runCommand(['git', 'add', '--all'], $this->directory);
            $this->runCommand(['git', 'commit', '--quiet', '-m', 'delete fixture'], $this->directory);
        }

        [$exitCode, $output] = $this->runCommand([
            PHP_BINARY,
            dirname(__DIR__, 2).'/scripts/verify-repository-secrets.php',
            '--root='.$root,
            '--history',
        ], $root, false);

        self::assertSame($expectedExit, $exitCode, $output);
        self::assertStringContainsString($expectedIssue, $output);
        self::assertStringNotContainsString('fixture-only', $output);
        self::assertStringNotContainsString('ordinaryProductionPassword123!', $output);
    }

    public function test_cloud_apple_signin_key_is_classified_as_secret_not_public_config(): void
    {
        $scanner = new RepositorySecretScanner();
        self::assertSame(
            ['non_placeholder_secret_assignment'],
            $scanner->scanContents('APPLE_PRIVATE_'.'KEY_BASE64=ordinaryProductionPassword123!')
        );
        self::assertSame([], $scanner->scanContents('APPLE_PRIVATE_'.'KEY_BASE64='));
    }

    public static function reviewedUrlHistoryCases(): array
    {
        $path = 'tests/Feature/RestoreDrillConnectionIsolationTest.php';
        $quotedUrl = static fn (string $suffix): string => "'".implode('', ['mysql://', $suffix])."'";
        $plain = $quotedUrl('url_user:fixture-only@url-host.invalid:3307/url_primary');
        $reviewed = implode("\n", [
            $quotedUrl('url_user:fixture-only@url-host.invalid:3307/url_primary?read[database]=production&write[database]=production'),
            $plain,
            $quotedUrl('fixture:fixture-only@unused.invalid/rokn_restore_verify_primary'),
        ]);
        $historyIssue = 'history:'.$path.' [credentialed_connection_url]';

        return [
            'all exact reviewed literals in history' => [$path, $reviewed, false, false, 0, 'Repository secret scan passed'],
            'reviewed literals in nested backend history' => [$path, $reviewed, false, true, 0, 'Repository secret scan passed'],
            'current files are not exempt' => [$path, $reviewed, true, false, 1, "\n- ".$path.' [credentialed_connection_url]'],
            'same literal at another path is not reviewed' => ['tests/Feature/OtherTest.php', $reviewed, false, false, 1, 'history:tests/Feature/OtherTest.php [credentialed_connection_url]'],
            'additional credential at reviewed path is rejected' => [$path, $reviewed."\n".$quotedUrl('user:ordinaryProductionPassword123!@production.invalid/db'), false, false, 1, $historyIssue],
            'changed fixture password is rejected' => [$path, str_replace('fixture-only', 'ordinaryProductionPassword123!', $plain), false, false, 1, $historyIssue],
            'changed fixture host is rejected' => [$path, str_replace('url-host.invalid', 'production.invalid', $plain), false, false, 1, $historyIssue],
            'extended fixture URL is not the reviewed literal' => [$path, substr($plain, 0, -1)."/other'", false, false, 1, $historyIssue],
            'other secret rules remain active at reviewed path' => [$path, $reviewed."\nDB_"."PASSWORD=ordinaryProductionPassword123!", false, false, 1, 'history:'.$path.' [non_placeholder_secret_assignment]'],
            'existing single private key fixture is preserved' => ['tests/Feature/ProductionPreflightTest.php', '-----BEGIN'.' PRIVATE KEY-----\\nfixture\\n-----END'.' PRIVATE KEY-----', false, true, 0, 'Repository secret scan passed'],
        ];
    }

    /**
     * @param  list<string>  $command
     * @return array{int, string}
     */
    private function runCommand(array $command, string $directory, bool $mustSucceed = true): array
    {
        $process = proc_open(
            $command,
            [
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $directory
        );

        self::assertIsResource($process);
        $output = (string) stream_get_contents($pipes[1]).(string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        if ($mustSucceed) {
            self::assertSame(0, $exitCode, $output);
        }

        return [$exitCode, $output];
    }

    private function deleteDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        @chmod($directory, 0777);

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.DIRECTORY_SEPARATOR.$entry;
            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } elseif (is_file($path)) {
                @chmod($path, 0666);
                unlink($path);
            }
        }

        rmdir($directory);
    }
}
