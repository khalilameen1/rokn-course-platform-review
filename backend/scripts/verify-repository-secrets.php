<?php

declare(strict_types=1);

use Rokn\Tooling\RepositorySecretScanner;

require_once __DIR__.'/RepositorySecretScanner.php';

$root = dirname(__DIR__);
$scanHistory = false;

foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--root=')) {
        $root = substr($argument, strlen('--root='));
    }

    if ($argument === '--history') {
        $scanHistory = true;
    }
}

/**
 * @param  list<string>  $arguments
 */
$runGit = static function (array $arguments) use ($root): string {
    $process = proc_open(
        array_merge(['git', '-C', $root], $arguments),
        [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );

    if (! is_resource($process)) {
        fwrite(STDERR, "Repository secret scan could not start Git.\n");
        exit(2);
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 || $output === false) {
        fwrite(STDERR, "Repository secret scan could not enumerate files.\n");

        if (is_string($error) && trim($error) !== '') {
            fwrite(STDERR, trim($error)."\n");
        }

        exit(2);
    }

    return $output;
};

// Git renders grep paths relative to `git -C`, while cat-file addresses blobs
// from the repository root. Preserve that boundary when this Laravel project
// lives inside a monorepo (for example `backend/`).
$gitPathPrefix = trim($runGit(['rev-parse', '--show-prefix']));
$reviewedHistoryFixtures = [
    'private_key_material' => [
        'tests/Feature/ProductionPreflightTest.php' =>
            '-----BEGIN'.' PRIVATE KEY-----\nfixture\n-----END'.' PRIVATE KEY-----',
        'tests/Feature/ProductionCapabilityTest.php' =>
            '-----BEGIN'.' PRIVATE KEY-----\nfixture\n-----END'.' PRIVATE KEY-----',
    ],
    // These three exact, quoted PHP literals were reviewed in commit 78aaaa2.
    // They are synthetic parser inputs on reserved .invalid hosts, not live
    // credentials. Current files remain unconditionally scanned. In history,
    // remove only these complete literals at this path and rescan the remainder.
    'credentialed_connection_url' => [
        'tests/Feature/RestoreDrillConnectionIsolationTest.php' => array_map(
            static fn (string $suffix): string => "'".implode('', ['mysql://', $suffix])."'",
            [
                'url_user:fixture-only@url-host.invalid:3307/url_primary?read[database]=production&write[database]=production',
                'url_user:fixture-only@url-host.invalid:3307/url_primary',
                'fixture:fixture-only@unused.invalid/rokn_restore_verify_primary',
            ]
        ),
    ],
];

$output = $runGit(['ls-files', '--cached', '--others', '--exclude-standard', '-z']);
$paths = array_values(array_filter(explode("\0", $output), static fn (string $path): bool => $path !== ''));
$scanner = new RepositorySecretScanner();
$issues = $scanner->scanFiles($root, $paths);
$additionalFileCount = 0;
$rootWorkflowPath = dirname($root).'/'.'.github/workflows/backend-ci.yml';
if (is_file($rootWorkflowPath)) {
    $additionalFileCount++;
    $logicalPath = '.github/workflows/backend-ci.yml';
    $contents = (string) file_get_contents($rootWorkflowPath);

    foreach ($scanner->scanPathNames([$logicalPath]) as $issue) {
        $issues[] = $issue;
    }

    foreach ($scanner->scanContents($contents) as $rule) {
        $issues[] = ['path' => $logicalPath, 'rule' => $rule];
    }
}

if ($scanHistory) {
    $historyOutput = $runGit(['log', '--all', '--format=', '--name-only', '--', '.']);
    $historyPaths = preg_split('/\R/', $historyOutput) ?: [];
    $historyPaths = array_values(array_filter(array_map(
        static function (string $path) use ($gitPathPrefix): string {
            $path = trim($path);

            return $gitPathPrefix !== '' && str_starts_with($path, $gitPathPrefix)
                ? substr($path, strlen($gitPathPrefix))
                : $path;
        },
        $historyPaths
    ), static fn (string $path): bool => $path !== ''));

    foreach ($scanner->scanPathNames($historyPaths) as $issue) {
        $issues[] = [
            'path' => 'history:'.$issue['path'],
            'rule' => $issue['rule'],
        ];
    }

    $commits = preg_split('/\R/', trim($runGit(['rev-list', '--all']))) ?: [];
    $commits = array_values(array_filter(
        $commits,
        static fn (string $commit): bool => preg_match('/^[0-9a-f]{40}$/', $commit) === 1
    ));

    foreach ($scanner->historyContentPatterns() as $rule => $pattern) {
        foreach (array_chunk($commits, 50) as $commitChunk) {
            $process = proc_open(
                array_merge(
                    ['git', '-C', $root, 'grep', '-l', '-E', '-e', $pattern],
                    $commitChunk,
                    ['--']
                ),
                [
                    1 => ['pipe', 'w'],
                    2 => ['pipe', 'w'],
                ],
                $pipes
            );

            if (! is_resource($process)) {
                fwrite(STDERR, "Repository secret history scan could not start Git.\n");
                exit(2);
            }

            $matches = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if ($exitCode === 1) {
                continue;
            }

            if ($exitCode !== 0 || $matches === false) {
                fwrite(STDERR, "Repository secret history scan could not inspect blobs.\n");

                if (is_string($error) && trim($error) !== '') {
                    fwrite(STDERR, trim($error)."\n");
                }

                exit(2);
            }

            foreach (preg_split('/\R/', trim($matches)) ?: [] as $match) {
                if ($match === '') {
                    continue;
                }

                if (preg_match('/^([0-9a-f]{40}):(.*)$/', $match, $parts) !== 1) {
                    fwrite(STDERR, "Repository secret history scan returned an invalid blob path.\n");
                    exit(2);
                }

                $commit = $parts[1];
                $path = $parts[2];

                $reviewedFixtures = $reviewedHistoryFixtures[$rule][$path] ?? null;
                if (is_string($reviewedFixtures) || is_array($reviewedFixtures)) {
                    $blob = $runGit(['cat-file', 'blob', $commit.':'.$gitPathPrefix.$path]);
                    $withoutFixture = str_replace($reviewedFixtures, '', $blob, $replacementCount);

                    if ($replacementCount > 0 && ! in_array($rule, $scanner->scanContents($withoutFixture), true)) {
                        continue;
                    }
                }

                if ($rule === 'named_secret_assignment') {
                    $blob = $runGit(['cat-file', 'blob', $commit.':'.$gitPathPrefix.$path]);

                    if (in_array('non_placeholder_secret_assignment', $scanner->scanContents($blob), true)) {
                        $issues[] = [
                            'path' => 'history:'.$path,
                            'rule' => 'non_placeholder_secret_assignment',
                        ];
                    }

                    continue;
                }

                $issues[] = [
                    'path' => 'history:'.$path,
                    'rule' => $rule,
                ];
            }
        }
    }
}

if ($issues !== []) {
    $deduplicatedIssues = [];

    foreach ($issues as $issue) {
        $deduplicatedIssues[$issue['path'].'|'.$issue['rule']] = $issue;
    }

    $issues = array_values($deduplicatedIssues);
    usort($issues, static fn (array $left, array $right): int => [$left['path'], $left['rule']] <=> [$right['path'], $right['rule']]);

    fwrite(STDERR, "Repository secret scan failed. Values are intentionally redacted.\n");

    foreach ($issues as $issue) {
        fwrite(STDERR, sprintf("- %s [%s]\n", $issue['path'], $issue['rule']));
    }

    exit(1);
}

fwrite(STDOUT, sprintf("Repository secret scan passed (%d current files%s).\n", count($paths) + $additionalFileCount, $scanHistory ? ' plus history paths' : ''));
