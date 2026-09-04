<?php

declare(strict_types=1);

namespace Rokn\Tooling;

final class RepositorySecretScanner
{
    /** @var list<string> */
    private const SECRET_NAMES = [
        'APP_KEY',
        'DB_PASSWORD',
        'MYSQL_ROOT_PASSWORD',
        'MAIL_PASSWORD',
        'TRANSACTIONAL_MAIL_PASSWORD',
        'REDIS_PASSWORD',
        'AWS_ACCESS_KEY_ID',
        'AWS_SECRET_ACCESS_KEY',
        'PUBLIC_AWS_SECRET_ACCESS_KEY',
        'FCM_SERVER_KEY',
        'FIREBASE_CREDENTIALS',
        'GOOGLE_MAPS_API_KEY',
        'GOOGLE_MAPS_BROWSER_KEY',
        'GOOGLE_CLIENT_SECRET',
        'FACEBOOK_CLIENT_SECRET',
        'TIKTOK_CLIENT_SECRET',
        'APPLE_CLIENT_SECRET',
        'PUSHER_APP_SECRET',
        'KASHIER_API_KEY',
        'KASHIER_SECRET_KEY',
        'KASHIER_WEBHOOK_SECRET',
        'KASHIER_LIVE_API_KEY',
        'KASHIER_LIVE_SECRET_KEY',
        'KASHIER_TEST_API_KEY',
        'KASHIER_TEST_SECRET_KEY',
        'BUNNY_API_KEY',
        'BUNNY_LIBRARY_API_KEY',
        'BUNNY_STREAM_API_KEY',
        'BUNNY_STREAM_WEBHOOK_SECRET',
        'BUNNY_STORAGE_PASSWORD',
        'BUNNY_TOKEN_AUTH_KEY',
        'BUNNY_STORAGE_TOKEN_AUTH_KEY',
        'OPENROUTER_API_KEY',
        'REWARD_TOMBSTONE_HMAC_KEY',
        'WHATSAPP_ACCESS_TOKEN',
        'WHATSAPP_APP_SECRET',
        'WHATSAPP_WEBHOOK_SECRET',
        'WHATSPIE_API_KEY',
        'UPLOAD_KEY_PASSWORD',
        'UPLOAD_STORE_PASSWORD',
        'ROKN_SMOKE_FIXTURE_TOKEN',
        'ROKN_SMOKE_PASSWORD',
        'NIGHTWATCH_TOKEN',
    ];

    /** @var list<string> */
    private const AUDITED_NONSECRET_NAMES = [
        'OPENROUTER_GLOBAL_DAILY_TOKEN_BUDGET',
        'OPENROUTER_GLOBAL_MONTHLY_TOKEN_BUDGET',
        'WHATSAPP_LINK_TOKEN_MINUTES',
    ];

    /**
     * @param  list<string>  $relativePaths
     * @return list<array{path: string, rule: string}>
     */
    public function scanFiles(string $root, array $relativePaths): array
    {
        $resolvedRoot = realpath($root);

        if ($resolvedRoot === false) {
            throw new \InvalidArgumentException('Repository root does not exist.');
        }

        $rootPrefix = rtrim(str_replace('\\', '/', $resolvedRoot), '/').'/';
        $issues = [];

        foreach (array_values(array_unique($relativePaths)) as $relativePath) {
            $normalizedPath = ltrim(str_replace('\\', '/', $relativePath), '/');
            $absolutePath = realpath($resolvedRoot.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $normalizedPath));

            if ($absolutePath === false || ! is_file($absolutePath)) {
                continue;
            }

            $normalizedAbsolutePath = str_replace('\\', '/', $absolutePath);

            if (! str_starts_with($normalizedAbsolutePath, $rootPrefix)) {
                continue;
            }

            if ($rule = $this->sensitivePathRule($normalizedPath)) {
                $issues[] = ['path' => $normalizedPath, 'rule' => $rule];
            }

            $contents = file_get_contents($absolutePath);

            if ($contents === false) {
                throw new \RuntimeException('Repository secret scan could not read a tracked file.');
            }

            foreach ($this->scanContents($contents) as $rule) {
                $issues[] = ['path' => $normalizedPath, 'rule' => $rule];
            }
        }

        $deduplicated = [];

        foreach ($issues as $issue) {
            $deduplicated[$issue['path'].'|'.$issue['rule']] = $issue;
        }

        $issues = array_values($deduplicated);
        usort($issues, static fn (array $left, array $right): int => [$left['path'], $left['rule']] <=> [$right['path'], $right['rule']]);

        return $issues;
    }

    /**
     * @return list<string>
     */
    public function scanContents(string $contents): array
    {
        $rules = [];

        foreach ($this->contentRules() as $rule => $pattern) {
            if (preg_match($pattern, $contents) === 1) {
                $rules[] = $rule;
            }
        }

        if ($this->containsSecretAssignment($contents)) {
            $rules[] = 'non_placeholder_secret_assignment';
        }

        return array_values(array_unique($rules));
    }

    /**
     * @return list<string>
     */
    public function secretNames(): array
    {
        return self::SECRET_NAMES;
    }

    /**
     * Returns secret-like environment or GitHub Secret names not covered by
     * the scanner's explicit classification.
     *
     * @return list<string>
     */
    public function findUncoveredSecretNames(string $contents): array
    {
        $candidates = [];

        if (preg_match_all('/^[ \t]*["\']?([A-Z][A-Z0-9_]*)["\']?[ \t]*(?:=(?!>)|:)/m', $contents, $assignments) !== false) {
            foreach ($assignments[1] as $name) {
                if ($this->isSecretLikeName((string) $name)) {
                    $candidates[(string) $name] = true;
                }
            }
        }

        if (preg_match_all('/\$\{\{\s*secrets\.([A-Z][A-Z0-9_]*)\s*\}\}/', $contents, $githubSecrets) !== false) {
            foreach ($githubSecrets[1] as $name) {
                $candidates[(string) $name] = true;
            }
        }

        $covered = array_fill_keys(array_merge(self::SECRET_NAMES, self::AUDITED_NONSECRET_NAMES), true);
        $uncovered = array_values(array_diff(array_keys($candidates), array_keys($covered)));
        sort($uncovered);

        return $uncovered;
    }

    /**
     * @param  list<string>  $relativePaths
     * @return list<array{path: string, rule: string}>
     */
    public function scanPathNames(array $relativePaths): array
    {
        $issues = [];

        foreach (array_values(array_unique($relativePaths)) as $relativePath) {
            $normalizedPath = ltrim(str_replace('\\', '/', $relativePath), '/');

            if ($rule = $this->sensitivePathRule($normalizedPath)) {
                $issues[] = ['path' => $normalizedPath, 'rule' => $rule];
            }
        }

        usort($issues, static fn (array $left, array $right): int => [$left['path'], $left['rule']] <=> [$right['path'], $right['rule']]);

        return $issues;
    }

    /**
     * Patterns are POSIX extended regular expressions for `git grep -E`.
     *
     * @return array<string, string>
     */
    public function historyContentPatterns(): array
    {
        return [
            'private_key_material' => '-----BEGIN( RSA| EC| OPENSSH| DSA| ENCRYPTED)? PRIVATE KEY-----',
            'aws_access_key' => '(AKIA|ASIA)[0-9A-Z]{16}',
            'github_access_token' => '(gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})',
            'slack_access_token' => 'xox[baprs]-[A-Za-z0-9-]{20,}',
            'stripe_live_secret' => '(sk|rk)_live_[A-Za-z0-9]{16,}',
            'firebase_legacy_server_key' => 'AAAA[A-Za-z0-9_-]{7,}:[A-Za-z0-9_-]{20,}',
            'google_api_key' => 'AIza[0-9A-Za-z_-]{35}',
            'credentialed_connection_url' => '(mysql|postgres|postgresql|redis)://[^:/[:space:]]+:[^@/[:space:]]+@',
            'named_secret_assignment' => '('.implode('|', self::SECRET_NAMES).')["\']?[[:space:]]*(=|:)',
        ];
    }

    private function sensitivePathRule(string $path): ?string
    {
        $lowerPath = strtolower($path);
        $basename = basename($lowerPath);
        $allowedEnvironmentExamples = [
            '.env.example',
            '.env.production.example',
            '.env.testing',
        ];

        if (str_starts_with($basename, '.env') && ! in_array($lowerPath, $allowedEnvironmentExamples, true)) {
            return 'environment_file';
        }

        if ($basename === 'info.txt') {
            return 'hosting_credentials_file';
        }

        if (preg_match('/(?:^|\/)(?:id_rsa|id_ed25519|service[-_]?account[^\/]*)$/i', $path) === 1) {
            return 'credential_file';
        }

        if (preg_match('/\.(?:key|pem|p12|pfx|jks|mobileprovision)$/i', $path) === 1) {
            return 'private_key_file';
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function contentRules(): array
    {
        return [
            'private_key_material' => '/-----BEGIN(?: RSA| EC| OPENSSH| DSA| ENCRYPTED)? PRIVATE KEY-----/',
            'aws_access_key' => '/\b(?:AKIA|ASIA)[0-9A-Z]{16}\b/',
            'github_access_token' => '/\b(?:gh[pousr]_[A-Za-z0-9_]{20,}|github_pat_[A-Za-z0-9_]{20,})\b/',
            'slack_access_token' => '/\bxox[baprs]-[A-Za-z0-9-]{20,}\b/',
            'stripe_live_secret' => '/\b(?:sk|rk)_live_[A-Za-z0-9]{16,}\b/',
            'firebase_legacy_server_key' => '/\bAAAA[A-Za-z0-9_-]{7,}:[A-Za-z0-9_-]{20,}\b/',
            'google_api_key' => '/\bAIza[0-9A-Za-z_-]{35}\b/',
            'credentialed_connection_url' => '/\b(?:mysql|postgres(?:ql)?|redis):\/\/[^:\s\/]+:[^@\s\/]+@/i',
        ];
    }

    private function containsSecretAssignment(string $contents): bool
    {
        $names = implode('|', array_map(
            static fn (string $name): string => preg_quote($name, '/'),
            self::SECRET_NAMES
        ));

        if (preg_match_all('/^[ \t]*(?:(?:export|set|const|let|var)[ \t]+|-[ \t]+)?["\']?(?:'.$names.')["\']?[ \t]*(?:=(?![=>])|:)[ \t]*([^\r\n]*)$/mi', $contents, $lineMatches) !== false) {
            foreach ($lineMatches[1] as $value) {
                if (! $this->isPlaceholder($this->assignmentValue((string) $value))) {
                    return true;
                }
            }
        }

        $commandPattern = '/^[ \t]*env[ \t]+["\']?(?:'.$names.')["\']?[ \t]*=(?![=>])[ \t]*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|(\$\{\{[^\r\n]*?\}\}|\$\{[^}\r\n]+\}|[^\s,;\]}]+))/mi';

        if (preg_match_all($commandPattern, $contents, $commandMatches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) !== false) {
            foreach ($commandMatches as $match) {
                $value = $match[1] ?? $match[2] ?? $match[3] ?? '';

                if (! $this->isPlaceholder((string) $value)) {
                    return true;
                }
            }
        }

        $jsonPattern = '/(?:[,{])[ \t]*["\']?(?:'.$names.')["\']?[ \t]*:[ \t]*(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\'|([^,}\r\n]*))/mi';

        if (preg_match_all($jsonPattern, $contents, $jsonMatches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL) === false) {
            return false;
        }

        foreach ($jsonMatches as $match) {
            $value = $match[1] ?? $match[2] ?? $match[3] ?? '';

            if (! $this->isPlaceholder((string) $value)) {
                return true;
            }
        }

        return false;
    }

    private function isPlaceholder(string $value): bool
    {
        $normalized = trim($value, " \t\n\r\0\x0B\"'");

        if ($normalized === '' || preg_match('/^(?:null|false|none|n\/a)$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:\.{3}|<[^<>\r\n]+>)$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\$\{\{\s*(?:secrets|vars)\.[A-Z][A-Z0-9_]*\s*\}\}$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\$\{[A-Z][A-Z0-9_]*\}$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^\/(?:run|var\/run)\/secrets\/[A-Za-z0-9._\/-]+$/', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^(?:example(?:[-_ ].+)?|placeholder(?:[-_ ].+)?|dummy(?:[-_ ].+)?|fake(?:[-_ ].+)?|test(?:ing)?(?:[-_ ].+)?|ci(?:[-_ ].+)?|(?:replace[-_ ]+(?:me|this|value|before[-_ ]deploy))(?:[-_ ].+)?|change[-_ ]?me(?:[-_ ].+)?|your[-_ ].+|generate[-_ ].+|use[-_ ]a[-_ ]strong[-_ ].+|[A-Z][A-Z0-9_]*(?:_HERE|_PLACEHOLDER))$/i', $normalized) === 1) {
            return true;
        }

        if (preg_match('/^base64:A{20,}={0,2}$/', $normalized) === 1) {
            return true;
        }

        return false;
    }

    private function assignmentValue(string $value): string
    {
        $normalized = trim((string) preg_replace('/[;,]+$/', '', trim($value)));

        if (preg_match('/^(?:"((?:\\\\.|[^"\\\\])*)"|\'((?:\\\\.|[^\'\\\\])*)\')/', $normalized, $match) === 1) {
            return (string) ($match[1] !== '' ? $match[1] : ($match[2] ?? ''));
        }

        return $normalized;
    }

    private function isSecretLikeName(string $name): bool
    {
        return $name === 'APP_KEY'
            || preg_match('/(?:^|_)(?:PASSWORD|SECRET(?:S)?|API_KEY|TOKEN)(?:_|$)/', $name) === 1
            || str_ends_with($name, '_CREDENTIALS')
            || str_ends_with($name, '_HMAC_KEY')
            || in_array($name, ['AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'], true);
    }
}
