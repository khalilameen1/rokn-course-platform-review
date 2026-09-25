<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\ProductFeatureFlag;
use App\Support\AdminSingletonLock;
use App\Support\BusinessClock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AdminProductFeatureAuthoringService
{
    public function __construct(private readonly ProductFeatureFlagService $features)
    {
    }

    /** @param array<string, mixed> $validated Validated editor fields including editor_version. */
    public function update(string $feature, array $validated, string $owner): void
    {
        abort_unless(array_key_exists($feature, config('product_features.definitions', [])), 404);
        $expiresAt = BusinessClock::localInputToUtc($validated['expires_at'] ?? null);
        if ($expiresAt !== null && !$expiresAt->isAfter(BusinessClock::utcNow())) {
            throw ValidationException::withMessages([
                'expires_at' => 'وقت انتهاء الميزة يجب أن يكون في المستقبل',
            ]);
        }

        DB::transaction(function () use ($feature, $validated, $owner, $expiresAt): void {
            AdminSingletonLock::acquire('product-feature:'.$feature);
            $flag = ProductFeatureFlag::query()->where('key', $feature)->lockForUpdate()->first();
            if (!hash_equals(
                $this->features->editorVersion($feature, $flag),
                (string) $validated['editor_version']
            )) {
                throw ValidationException::withMessages([
                    'editor_version' => ["تغيّر قرار تشغيل الميزة منذ فتح الصفحة\nأعد تحميلها قبل الحفظ"],
                ]);
            }

            ProductFeatureFlag::query()->updateOrCreate(['key' => $feature], [
                'enabled' => (bool) $validated['enabled'],
                'rollout_percentage' => (int) $validated['rollout_percentage'],
                'owner' => $owner,
                'reason' => trim((string) $validated['reason']),
                'expires_at' => $expiresAt,
            ]);
        }, 3);
    }
}
