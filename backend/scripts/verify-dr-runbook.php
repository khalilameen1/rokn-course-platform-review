<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$paths = [
    'command' => $root.'/app/Console/Commands/VerifyRestoreDrill.php',
    'runbook' => $root.'/docs/DISASTER_RECOVERY_RUNBOOK.md',
    'manifest' => $root.'/database/migration-baseline-manifest.json',
];
$contents = [];
$missing = [];

foreach ($paths as $label => $path) {
    if (! is_file($path) || ! is_readable($path)) {
        $missing[] = "file: {$label} ({$path})";

        continue;
    }
    $value = file_get_contents($path);
    if (! is_string($value)) {
        $missing[] = "read: {$label} ({$path})";

        continue;
    }
    $contents[$label] = $value;
}

if ($missing === []) {
    $requiredCommandTokens = [
        'ops:verify-restore',
        'rokn_restore_verify_',
        'artifact_sha256',
        'schema_fingerprint',
        'DROP DATABASE IF EXISTS',
    ];
    $requiredRunbookTokens = [
        'ops:verify-restore',
        '--confirm=RESTORE_',
        'First reconciled-baseline cutover',
        'database/migration-baseline-manifest.json',
        'artifact_sha256',
        'schema_fingerprint',
        'productionUpgradeVerified',
        'does not verify',
        'production upgrade',
        'second operator',
    ];
    foreach ($requiredCommandTokens as $token) {
        if (! str_contains($contents['command'], $token)) {
            $missing[] = 'command: '.$token;
        }
    }
    foreach ($requiredRunbookTokens as $token) {
        if (! str_contains($contents['runbook'], $token)) {
            $missing[] = 'runbook: '.$token;
        }
    }

    try {
        $manifest = json_decode(
            $contents['manifest'],
            true,
            512,
            JSON_THROW_ON_ERROR
        );
    } catch (JsonException $exception) {
        $manifest = null;
        $missing[] = 'manifest: valid JSON';
    }
    if (! is_array($manifest)) {
        $missing[] = 'manifest: JSON object';
    } elseif (($manifest['firstCutoverRequiresRestoreDrillEvidence'] ?? null) !== true) {
        $missing[] = 'manifest: firstCutoverRequiresRestoreDrillEvidence=true';
    }
}

if ($missing !== []) {
    fwrite(
        STDERR,
        "DR verification contract is incomplete:\n- ".implode("\n- ", $missing)."\n"
    );
    exit(1);
}

echo "DR restore verification and first-cutover evidence contracts are present.\n";
