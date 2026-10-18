<?php

declare(strict_types=1);

/*
 * Verify the immutable reconciled migration baseline, then prove that the
 * forward-only tail can be applied to a disposable database. This is a CI
 * contract test; it does not claim that a production backup was upgraded.
 */

const DEFAULT_MANIFEST = 'database/migration-baseline-manifest.json';
const DEFAULT_EVIDENCE = 'storage/app/migration-upgrade-evidence.json';
const MIGRATION_PATTERN = '/^(\d{4}_\d{2}_\d{2})(?:_(\d{6}))?_.+\.php$/D';

$root = dirname(__DIR__);
$options = getopt('', ['manifest:', 'evidence:', 'manifest-only']);
$manifestPath = absolutePath(
    $root,
    (string) ($options['manifest'] ?? DEFAULT_MANIFEST)
);
$evidencePath = absolutePath(
    $root,
    (string) ($options['evidence'] ?? DEFAULT_EVIDENCE)
);
$manifestOnly = array_key_exists('manifest-only', $options);

try {
    $manifest = readManifest($manifestPath);
    $migrationFiles = collectMigrationFiles($root);
    [$baselineFiles, $tailFiles] = partitionMigrations(
        $migrationFiles,
        $manifest['cutoff']
    );
    verifyFrozenBaseline($baselineFiles, $manifest);

    if ($manifestOnly) {
        printf(
            "Migration baseline manifest verified: %d frozen files through %s (%s).\n",
            $manifest['fileCount'],
            $manifest['cutoff'],
            $manifest['aggregateSha256']
        );
        exit(0);
    }

    verifyBaselineAndTail(
        $root,
        $baselineFiles,
        $tailFiles,
        $manifest,
        $evidencePath
    );
} catch (Throwable $exception) {
    fwrite(
        STDERR,
        'Migration baseline/tail verification failed: '
        .$exception->getMessage()
        .PHP_EOL
    );
    exit(1);
}

/** @return array{baseline:string,cutoff:string,tailStartsAt:string,fileCount:int,aggregateSha256:string,aggregateFormat:string,firstCutoverRequiresRestoreDrillEvidence:bool} */
function readManifest(string $path): array
{
    if (! is_file($path) || ! is_readable($path)) {
        throw new RuntimeException("Migration baseline manifest is not readable: {$path}");
    }

    try {
        $manifest = json_decode(
            (string) file_get_contents($path),
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        throw new RuntimeException(
            'Migration baseline manifest is not valid JSON.',
            0,
            $exception
        );
    }

    if (! is_array($manifest)) {
        throw new RuntimeException('Migration baseline manifest must be a JSON object.');
    }

    $required = [
        'baseline',
        'cutoff',
        'tailStartsAt',
        'fileCount',
        'aggregateSha256',
        'aggregateFormat',
        'firstCutoverRequiresRestoreDrillEvidence',
    ];
    foreach ($required as $key) {
        if (! array_key_exists($key, $manifest)) {
            throw new RuntimeException("Migration baseline manifest is missing [{$key}].");
        }
    }

    if (! is_string($manifest['baseline']) || trim($manifest['baseline']) === '') {
        throw new RuntimeException('Manifest baseline must be a nonempty string.');
    }
    foreach (['cutoff', 'tailStartsAt'] as $key) {
        if (
            ! is_string($manifest[$key])
            || preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}$/D', $manifest[$key]) !== 1
        ) {
            throw new RuntimeException("Manifest {$key} is not a migration timestamp.");
        }
    }
    if (strcmp($manifest['cutoff'], $manifest['tailStartsAt']) >= 0) {
        throw new RuntimeException('Manifest cutoff must be before tailStartsAt.');
    }
    if (! is_int($manifest['fileCount']) || $manifest['fileCount'] < 1) {
        throw new RuntimeException('Manifest fileCount must be a positive integer.');
    }
    if (
        ! is_string($manifest['aggregateSha256'])
        || preg_match('/^[a-f0-9]{64}$/D', $manifest['aggregateSha256']) !== 1
    ) {
        throw new RuntimeException('Manifest aggregateSha256 must be lowercase SHA-256.');
    }
    if ($manifest['aggregateFormat'] !== 'relative-path<TAB>sha256(lf-normalized-file)<LF>') {
        throw new RuntimeException('Manifest aggregateFormat is unsupported.');
    }
    if ($manifest['firstCutoverRequiresRestoreDrillEvidence'] !== true) {
        throw new RuntimeException(
            'The first reconciled-baseline cutover must require restore-drill evidence.'
        );
    }

    /** @var array{baseline:string,cutoff:string,tailStartsAt:string,fileCount:int,aggregateSha256:string,aggregateFormat:string,firstCutoverRequiresRestoreDrillEvidence:bool} $manifest */
    return $manifest;
}

/**
 * @return list<array{absolute:string,relative:string,name:string,timestamp:string}>
 */
function collectMigrationFiles(string $root): array
{
    $directory = $root.DIRECTORY_SEPARATOR.'database'.DIRECTORY_SEPARATOR.'migrations';
    $paths = glob($directory.DIRECTORY_SEPARATOR.'*.php');
    if ($paths === false || $paths === []) {
        throw new RuntimeException('No application migrations were found.');
    }

    $files = [];
    $names = [];
    foreach ($paths as $path) {
        $basename = basename($path);
        if (preg_match(MIGRATION_PATTERN, $basename, $matches) !== 1) {
            throw new RuntimeException("Migration filename has no valid timestamp: {$basename}");
        }

        $name = pathinfo($basename, PATHINFO_FILENAME);
        if (isset($names[$name])) {
            throw new RuntimeException("Duplicate migration name: {$name}");
        }
        $names[$name] = true;

        $files[] = [
            'absolute' => $path,
            'relative' => 'database/migrations/'.$basename,
            'name' => $name,
            'timestamp' => $matches[1].'_'.(($matches[2] ?? '') !== '' ? $matches[2] : '000000'),
        ];
    }

    usort(
        $files,
        static fn (array $left, array $right): int => strcmp(
            $left['relative'],
            $right['relative']
        )
    );

    return $files;
}

/**
 * @param  list<array{absolute:string,relative:string,name:string,timestamp:string}>  $files
 * @return array{
 *   list<array{absolute:string,relative:string,name:string,timestamp:string}>,
 *   list<array{absolute:string,relative:string,name:string,timestamp:string}>
 * }
 */
function partitionMigrations(array $files, string $cutoff): array
{
    $baseline = [];
    $tail = [];
    foreach ($files as $file) {
        if (strcmp($file['timestamp'], $cutoff) <= 0) {
            $baseline[] = $file;
        } else {
            $tail[] = $file;
        }
    }

    return [$baseline, $tail];
}

/**
 * @param  list<array{absolute:string,relative:string,name:string,timestamp:string}>  $files
 * @param  array{fileCount:int,aggregateSha256:string}  $manifest
 */
function verifyFrozenBaseline(array $files, array $manifest): void
{
    $actualCount = count($files);
    $actualAggregate = aggregateMigrationHash($files);

    if (
        $actualCount !== $manifest['fileCount']
        || ! hash_equals($manifest['aggregateSha256'], $actualAggregate)
    ) {
        throw new RuntimeException(sprintf(
            'Frozen migration baseline changed. Expected count/hash %d/%s; got %d/%s. '
            .'Do not edit, delete, rename, or backdate migrations at/before the cutoff.',
            $manifest['fileCount'],
            $manifest['aggregateSha256'],
            $actualCount,
            $actualAggregate
        ));
    }
}

/**
 * @param  list<array{absolute:string,relative:string,name:string,timestamp:string}>  $files
 */
function aggregateMigrationHash(array $files): string
{
    $context = hash_init('sha256');
    foreach ($files as $file) {
        $contents = file_get_contents($file['absolute']);
        if (! is_string($contents)) {
            throw new RuntimeException("Could not hash {$file['relative']}.");
        }
        // Git may materialize text files with CRLF on Windows and LF in CI.
        // Freeze source content, not checkout-specific line-ending bytes.
        $fileHash = hash('sha256', str_replace(["\r\n", "\r"], "\n", $contents));
        hash_update($context, $file['relative']."\t".$fileHash."\n");
    }

    return hash_final($context);
}

/**
 * @param  list<array{absolute:string,relative:string,name:string,timestamp:string}>  $baselineFiles
 * @param  list<array{absolute:string,relative:string,name:string,timestamp:string}>  $tailFiles
 * @param  array{baseline:string,cutoff:string,tailStartsAt:string,fileCount:int,aggregateSha256:string}  $manifest
 */
function verifyBaselineAndTail(
    string $root,
    array $baselineFiles,
    array $tailFiles,
    array $manifest,
    string $evidencePath
): void {
    foreach ($tailFiles as $file) {
        if (strcmp($file['timestamp'], $manifest['tailStartsAt']) < 0) {
            throw new RuntimeException(
                "Migration {$file['relative']} falls in the gap between cutoff and tailStartsAt."
            );
        }
    }

    $temporary = createTemporaryDirectory('rokn-migration-gate-');
    $baselineDirectory = $temporary.DIRECTORY_SEPARATOR.'baseline';
    $tailDirectory = $temporary.DIRECTORY_SEPARATOR.'tail';
    $failureDirectory = $temporary.DIRECTORY_SEPARATOR.'failure';
    $database = $temporary.DIRECTORY_SEPARATOR.'verification.sqlite';
    $configCache = $temporary.DIRECTORY_SEPARATOR.'config.php';
    $viewsDirectory = $temporary.DIRECTORY_SEPARATOR.'views';
    $relativeTemporary = relativePath($root, $temporary);

    foreach ([$baselineDirectory, $tailDirectory, $failureDirectory, $viewsDirectory] as $directory) {
        if (! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create temporary directory: {$directory}");
        }
    }
    if (! touch($database)) {
        throw new RuntimeException('Could not create the disposable SQLite database.');
    }

    copyMigrations($baselineFiles, $baselineDirectory);
    copyMigrations($tailFiles, $tailDirectory);

    $environment = [
        'APP_ENV' => 'testing',
        'APP_DEBUG' => 'false',
        'APP_KEY' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=',
        'APP_LOCALE' => 'en',
        'APP_FALLBACK_LOCALE' => 'en',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => $database,
        'CACHE_DRIVER' => 'array',
        'SESSION_DRIVER' => 'array',
        'QUEUE_CONNECTION' => 'sync',
        'MAIL_MAILER' => 'array',
        'LOG_CHANNEL' => 'null',
        // Laravel treats only slash-prefixed cache paths as absolute. Use a
        // root-relative path so Windows drive-letter paths are not prefixed
        // with the application root a second time.
        'APP_CONFIG_CACHE' => $relativeTemporary.'/config.php',
        'APP_EVENTS_CACHE' => $relativeTemporary.'/events.php',
        'APP_PACKAGES_CACHE' => $relativeTemporary.'/packages.php',
        'APP_SERVICES_CACHE' => $relativeTemporary.'/services.php',
        'VIEW_COMPILED_PATH' => $viewsDirectory,
    ];
    $artisan = [PHP_BINARY, $root.DIRECTORY_SEPARATOR.'artisan'];
    $steps = [];

    $runArtisan = static function (
        array $arguments,
        bool $expectSuccess = true
    ) use (&$steps, $artisan, $root, $environment, $temporary): string {
        [$exitCode, $stdout, $stderr] = runProcess(
            [...$artisan, ...$arguments],
            $root,
            $environment
        );
        $displayCommand = str_replace(
            [$temporary, str_replace('\\', '/', $temporary)],
            '<temporary>',
            implode(' ', $arguments)
        );
        $steps[] = [
            'command' => $displayCommand,
            'exit' => $exitCode,
        ];
        if (($exitCode === 0) !== $expectSuccess) {
            throw new RuntimeException(sprintf(
                'Unexpected exit code %d for [%s]: %s',
                $exitCode,
                implode(' ', $arguments),
                boundedOutput($stdout.$stderr)
            ));
        }

        return $stdout.$stderr;
    };

    try {
        $runArtisan([
            'migrate:fresh',
            '--force',
            '--no-interaction',
            '--realpath',
            '--path='.$baselineDirectory,
        ]);
        assertMigrationsRecorded($database, array_column($baselineFiles, 'name'));
        assertMigrationsAbsent($database, array_column($tailFiles, 'name'));

        if ($tailFiles !== []) {
            $runArtisan([
                'migrate',
                '--force',
                '--no-interaction',
                '--realpath',
                '--path='.$tailDirectory,
            ]);
        }
        assertMigrationsRecorded($database, array_column($tailFiles, 'name'));
        $afterTail = migrationLedger($database);

        if ($tailFiles !== []) {
            $runArtisan([
                'migrate',
                '--force',
                '--no-interaction',
                '--realpath',
                '--path='.$tailDirectory,
            ]);
        }
        if (migrationLedger($database) !== $afterTail) {
            throw new RuntimeException('A second tail migration changed the migration ledger.');
        }

        $failureName = '2099_01_01_000000_intentional_migration_gate_failure';
        $failureFile = $failureDirectory.DIRECTORY_SEPARATOR.$failureName.'.php';
        $failureSource = <<<'PHP'
<?php
use Illuminate\Database\Migrations\Migration;
return new class extends Migration {
    public function up(): void { throw new RuntimeException('intentional migration gate failure'); }
    public function down(): void {}
};
PHP;
        if (file_put_contents($failureFile, $failureSource) === false) {
            throw new RuntimeException('Could not create the intentional failure migration.');
        }
        $runArtisan([
            'migrate',
            '--force',
            '--no-interaction',
            '--realpath',
            '--path='.$failureDirectory,
        ], false);
        assertMigrationsAbsent($database, [$failureName]);

        $runArtisan(['config:cache', '--no-interaction']);
        if ($tailFiles !== []) {
            $runArtisan([
                'migrate',
                '--force',
                '--no-interaction',
                '--realpath',
                '--path='.$tailDirectory,
            ]);
        }
        if (migrationLedger($database) !== $afterTail) {
            throw new RuntimeException('A fresh application process changed the migration ledger.');
        }

        writeJsonFile($evidencePath, [
            'kind' => 'reconciled-baseline-forward-tail-verification',
            'productionUpgradeVerified' => false,
            'scope' => 'Disposable SQLite contract test only',
            'manifest' => [
                'baseline' => $manifest['baseline'],
                'cutoff' => $manifest['cutoff'],
                'tailStartsAt' => $manifest['tailStartsAt'],
                'fileCount' => $manifest['fileCount'],
                'aggregateSha256' => $manifest['aggregateSha256'],
            ],
            'tail' => [
                'fileCount' => count($tailFiles),
                'migrations' => array_values(array_column($tailFiles, 'name')),
            ],
            'checks' => [
                'frozenBaselineHash' => true,
                'baselineReplay' => true,
                'tailApplied' => true,
                'tailIdempotent' => true,
                'failedMigrationNotRecorded' => true,
                'freshProcessRestartClean' => true,
            ],
            'steps' => $steps,
            'verifiedAtUtc' => gmdate(DATE_ATOM),
        ]);

        printf(
            "Migration gate passed: %d frozen baseline files and %d forward-only tail files. Evidence: %s\n",
            count($baselineFiles),
            count($tailFiles),
            $evidencePath
        );
    } finally {
        if (is_file($configCache)) {
            @unlink($configCache);
        }
        removeOwnedTemporaryDirectory($temporary);
    }
}

/**
 * @param  list<array{absolute:string,relative:string,name:string,timestamp:string}>  $files
 */
function copyMigrations(array $files, string $destination): void
{
    foreach ($files as $file) {
        $target = $destination.DIRECTORY_SEPARATOR.basename($file['absolute']);
        if (! copy($file['absolute'], $target)) {
            throw new RuntimeException("Could not materialize {$file['relative']}.");
        }
    }
}

/** @return array{int,string,string} */
function runProcess(array $command, string $workingDirectory, array $environment): array
{
    $stdoutPath = tempnam(sys_get_temp_dir(), 'rokn-gate-out-');
    $stderrPath = tempnam(sys_get_temp_dir(), 'rokn-gate-err-');
    if ($stdoutPath === false || $stderrPath === false) {
        if (is_string($stdoutPath)) {
            @unlink($stdoutPath);
        }
        if (is_string($stderrPath)) {
            @unlink($stderrPath);
        }
        throw new RuntimeException('Could not allocate process output files.');
    }

    $descriptors = [
        1 => ['file', $stdoutPath, 'wb'],
        2 => ['file', $stderrPath, 'wb'],
    ];
    $inherited = getenv();
    if (! is_array($inherited)) {
        $inherited = [];
    }
    $process = proc_open(
        $command,
        $descriptors,
        $pipes,
        $workingDirectory,
        array_replace($inherited, $environment)
    );
    if (! is_resource($process)) {
        @unlink($stdoutPath);
        @unlink($stderrPath);
        throw new RuntimeException('Could not start process: '.implode(' ', $command));
    }

    $exitCode = proc_close($process);
    $stdout = (string) file_get_contents($stdoutPath);
    $stderr = (string) file_get_contents($stderrPath);
    @unlink($stdoutPath);
    @unlink($stderrPath);

    return [$exitCode, $stdout, $stderr];
}

/** @param list<string> $expected */
function assertMigrationsRecorded(string $database, array $expected): void
{
    if ($expected === []) {
        return;
    }

    $recorded = array_fill_keys(array_keys(migrationLedger($database)), true);
    $missing = array_values(array_filter(
        $expected,
        static fn (string $name): bool => ! isset($recorded[$name])
    ));
    if ($missing !== []) {
        throw new RuntimeException(
            "Expected migrations were not recorded:\n- ".implode("\n- ", $missing)
        );
    }
}

/** @param list<string> $unexpected */
function assertMigrationsAbsent(string $database, array $unexpected): void
{
    if ($unexpected === []) {
        return;
    }

    $recorded = array_fill_keys(array_keys(migrationLedger($database)), true);
    $present = array_values(array_filter(
        $unexpected,
        static fn (string $name): bool => isset($recorded[$name])
    ));
    if ($present !== []) {
        throw new RuntimeException(
            "Unexpected migrations were recorded:\n- ".implode("\n- ", $present)
        );
    }
}

/** @return array<string,int> */
function migrationLedger(string $database): array
{
    $pdo = new PDO('sqlite:'.$database, null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $rows = $pdo->query(
        'SELECT migration, batch FROM migrations ORDER BY migration'
    )->fetchAll();
    $ledger = [];
    foreach ($rows as $row) {
        $ledger[(string) $row['migration']] = (int) $row['batch'];
    }

    return $ledger;
}

function createTemporaryDirectory(string $prefix): string
{
    $base = rtrim(sys_get_temp_dir(), '\\/');
    for ($attempt = 0; $attempt < 10; $attempt++) {
        $path = $base.DIRECTORY_SEPARATOR.$prefix.bin2hex(random_bytes(8));
        if (@mkdir($path, 0700)) {
            return $path;
        }
    }

    throw new RuntimeException('Could not create an owned temporary directory.');
}

function removeOwnedTemporaryDirectory(string $directory): void
{
    $resolvedDirectory = realpath($directory);
    $resolvedBase = realpath(sys_get_temp_dir());
    if ($resolvedDirectory === false || $resolvedBase === false) {
        return;
    }

    $basePrefix = rtrim($resolvedBase, '\\/').DIRECTORY_SEPARATOR;
    if (! str_starts_with($resolvedDirectory, $basePrefix)) {
        throw new RuntimeException('Refusing to remove a directory outside the system temp root.');
    }
    if (! str_starts_with(basename($resolvedDirectory), 'rokn-migration-gate-')) {
        throw new RuntimeException('Refusing to remove an unowned temporary directory.');
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $resolvedDirectory,
            FilesystemIterator::SKIP_DOTS
        ),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iterator as $item) {
        $path = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            if (! @unlink($path) && file_exists($path)) {
                throw new RuntimeException("Could not remove temporary file: {$path}");
            }
        } elseif (! @rmdir($path) && is_dir($path)) {
            throw new RuntimeException("Could not remove temporary directory: {$path}");
        }
    }
    if (! @rmdir($resolvedDirectory) && is_dir($resolvedDirectory)) {
        throw new RuntimeException("Could not remove temporary directory: {$resolvedDirectory}");
    }
}

function writeJsonFile(string $path, array $payload): void
{
    $directory = dirname($path);
    if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
        throw new RuntimeException("Could not create evidence directory: {$directory}");
    }
    $encoded = json_encode(
        $payload,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    if (file_put_contents($path, $encoded.PHP_EOL) === false) {
        throw new RuntimeException("Could not write verification evidence: {$path}");
    }
}

function absolutePath(string $root, string $path): string
{
    if ($path === '') {
        throw new RuntimeException('An empty path was provided.');
    }
    if (
        preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1
        || str_starts_with($path, '/')
        || str_starts_with($path, '\\\\')
    ) {
        return $path;
    }

    return $root.DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
}

function relativePath(string $from, string $to): string
{
    $fromResolved = realpath($from);
    $toResolved = realpath($to);
    if ($fromResolved === false || $toResolved === false) {
        throw new RuntimeException('Could not resolve paths for portable cache placement.');
    }

    $fromNormalized = str_replace('\\', '/', $fromResolved);
    $toNormalized = str_replace('\\', '/', $toResolved);
    $fromDrive = preg_match('/^[A-Za-z]:/', $fromNormalized) === 1
        ? strtolower(substr($fromNormalized, 0, 2))
        : '';
    $toDrive = preg_match('/^[A-Za-z]:/', $toNormalized) === 1
        ? strtolower(substr($toNormalized, 0, 2))
        : '';
    if ($fromDrive !== $toDrive) {
        throw new RuntimeException(
            'The application and temporary directory must be on the same drive.'
        );
    }

    if ($fromDrive !== '') {
        $fromNormalized = substr($fromNormalized, 2);
        $toNormalized = substr($toNormalized, 2);
    }
    $fromParts = array_values(array_filter(
        explode('/', trim($fromNormalized, '/')),
        static fn (string $part): bool => $part !== ''
    ));
    $toParts = array_values(array_filter(
        explode('/', trim($toNormalized, '/')),
        static fn (string $part): bool => $part !== ''
    ));

    $common = 0;
    $limit = min(count($fromParts), count($toParts));
    while ($common < $limit) {
        $left = $fromParts[$common];
        $right = $toParts[$common];
        $same = $fromDrive !== ''
            ? strcasecmp($left, $right) === 0
            : $left === $right;
        if (! $same) {
            break;
        }
        $common++;
    }

    $relative = [
        ...array_fill(0, count($fromParts) - $common, '..'),
        ...array_slice($toParts, $common),
    ];

    return $relative === [] ? '.' : implode('/', $relative);
}

function boundedOutput(string $output): string
{
    $limit = 12000;
    if (strlen($output) <= $limit) {
        return $output;
    }

    return "[output truncated]\n".substr($output, -$limit);
}
