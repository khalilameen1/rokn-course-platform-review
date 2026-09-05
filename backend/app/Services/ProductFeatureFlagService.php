<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductFeatureFlag;
use App\Support\AdminEditorVersion;
use App\Support\DatabaseCapabilities;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Throwable;

final class ProductFeatureFlagService
{
    /** @return array<string, array{default_enabled: bool, safe_default: bool, description: string}> */
    private function definitions(): array
    {
        return collect(config('product_features.definitions', []))
            ->mapWithKeys(fn (array $definition, string $key): array => [
                $key => [
                    'default_enabled' => (bool) ($definition['default_enabled'] ?? false),
                    'safe_default' => (bool) ($definition['safe_default'] ?? false),
                    'description' => (string) ($definition['description'] ?? $key),
                ],
            ])
            ->all();
    }

    /**
     * Null means the control-plane could not be read. An empty collection is a
     * valid configuration that intentionally uses the declared defaults.
     * Keeping those states distinct prevents a database/migration outage from
     * silently enabling paid or provider-backed mutations.
     *
     * @return Collection<string, ProductFeatureFlag>|null
     */
    private function rows(): ?Collection
    {
        try {
            if (!DatabaseCapabilities::hasTable('product_feature_flags')) {
                return null;
            }

            return ProductFeatureFlag::query()
                ->whereIn('key', array_keys($this->definitions()))
                ->get()
                ->keyBy('key');
        } catch (Throwable) {
            return null;
        }
    }

    public function enabled(string $key, int|string|null $subject = null): bool
    {
        $definitions = $this->definitions();
        if (!array_key_exists($key, $definitions)) {
            return false;
        }
        if ($key === 'checkout' && (bool) config('operations.disaster_recovery_mode', false)) {
            return false;
        }
        $rows = $this->rows();
        if ($rows === null) {
            return $definitions[$key]['safe_default'];
        }

        $row = $rows->get($key);
        if (!$row) {
            return $definitions[$key]['default_enabled'];
        }
        if ($row->expires_at?->isPast()) {
            return $definitions[$key]['safe_default'];
        }
        if (!$row->enabled) {
            return false;
        }

        $rollout = max(0, min(100, (int) $row->rollout_percentage));
        if ($rollout >= 100) {
            return true;
        }
        if ($rollout <= 0 || $subject === null || trim((string) $subject) === '') {
            return false;
        }

        return $this->bucket($key, (string) $subject) < $rollout;
    }

    /** @return array{contract_version: int, version: string, ttl_seconds: int, expires_at: string, flags: array<string, bool>} */
    public function clientSnapshot(int|string $subject): array
    {
        // Integer support remains for internal diagnostics and focused tests.
        // Public clients never choose it; the controller passes a server-built subject.
        $definitions = $this->definitions();
        $rows = $this->rows();
        $configurationAvailable = $rows !== null;
        $rows ??= collect();
        $flags = [];
        $versionSource = [];

        foreach ($definitions as $key => $definition) {
            $bucket = is_int($subject)
                ? max(0, min(99, $subject))
                : $this->bucket($key, (string) $subject);
            $row = $rows->get($key);
            $expired = $row?->expires_at?->isPast() ?? false;
            $enabled = !$configurationAvailable
                ? $definition['safe_default']
                : ($row
                ? (!$expired && (bool) $row->enabled)
                : $definition['default_enabled']);
            $rollout = $row ? max(0, min(100, (int) $row->rollout_percentage)) : 100;
            $flags[$key] = $enabled && $bucket < $rollout;
            if ($expired) {
                $flags[$key] = $definition['safe_default'];
            }
            $versionSource[$key] = [
                $configurationAvailable,
                $enabled, $rollout, $expired, $row?->updated_at?->getTimestamp() ?? 0,
            ];
        }

        $disasterRecoveryMode = (bool) config('operations.disaster_recovery_mode', false);
        if ($disasterRecoveryMode && array_key_exists('checkout', $flags)) {
            $flags['checkout'] = false;
        }
        $versionSource['runtime'] = ['disaster_recovery_mode' => $disasterRecoveryMode];

        $ttl = max(60, (int) config('product_features.client_ttl_seconds', 300));

        return [
            'contract_version' => 1,
            'version' => hash('sha256', json_encode($versionSource, JSON_THROW_ON_ERROR)),
            'ttl_seconds' => $ttl,
            'expires_at' => now()->addSeconds($ttl)->toIso8601String(),
            'flags' => $flags,
        ];
    }

    public function subjectForRequest(Request $request): string
    {
        $installation = strtolower(trim((string) $request->header('X-Rokn-Installation')));
        if (preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $installation
        ) === 1) {
            return 'installation:'.$installation;
        }

        $userId = $request->user('api')?->getAuthIdentifier();
        if ($userId !== null) {
            return 'user:'.(string) $userId;
        }

        return 'anonymous:'.hash(
            'sha256',
            (string) $request->ip().'|'.(string) $request->userAgent()
        );
    }

    /** @return array<string, array{enabled: bool, rollout_percentage: int, owner: ?string, reason: ?string, expires_at: ?string, safe_default: bool, description: string, editor_version: string}> */
    public function operationalSnapshot(): array
    {
        $rows = $this->rows();
        $configurationAvailable = $rows !== null;
        $rows ??= collect();
        $result = [];
        foreach ($this->definitions() as $key => $definition) {
            $row = $rows->get($key);
            $expired = $row?->expires_at?->isPast() ?? false;
            $result[$key] = [
                'enabled' => !$configurationAvailable
                    ? $definition['safe_default']
                    : ($row
                    ? (!$expired && (bool) $row->enabled)
                    : $definition['default_enabled']),
                'rollout_percentage' => $row ? (int) $row->rollout_percentage : 100,
                'owner' => $row?->owner,
                'reason' => $configurationAvailable
                    ? $row?->reason
                    : 'Feature control is unavailable; safe defaults are active.',
                'expires_at' => $row?->expires_at?->toIso8601String(),
                'safe_default' => $definition['safe_default'],
                'description' => $definition['description'],
                'editor_version' => $this->editorVersion($key, $row),
            ];
        }

        return $result;
    }

    public function editorVersion(string $key, ?ProductFeatureFlag $flag): string
    {
        if (!$flag) {
            return hash('sha256', 'product-feature:missing:'.$key);
        }

        return AdminEditorVersion::for($flag, [
            'key', 'enabled', 'rollout_percentage', 'owner', 'reason', 'expires_at',
        ]);
    }

    private function bucket(string $key, string $subject): int
    {
        return (int) (hexdec(substr(hash('sha256', $key.'|'.$subject), 0, 8)) % 100);
    }
}
