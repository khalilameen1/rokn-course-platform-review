<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppVersion;
use App\Support\AdminSingletonLock;
use App\Support\AppVersionEditorVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Release writes shared by the dashboard and the explicit direct-release bootstrap. */
final class AppReleaseAuthoringService
{
    public function __construct(private readonly AppReleasePolicyService $releasePolicy)
    {
    }

    /** @param array<string, mixed> $data Validated and normalized release fields.
     *  @param callable(AppVersion):void $complete Completes the dashboard receipt in this transaction.
     */
    public function create(array $data, callable $complete): AppVersion
    {
        return DB::transaction(function () use ($data, $complete): AppVersion {
            AdminSingletonLock::acquire('app-release:'.$data['platform']);
            $this->assertIdentity($data);
            $this->assertDownloadPolicy($data);
            $version = AppVersion::query()->create($data);
            $complete($version);

            return $version;
        }, 3);
    }

    /** @param array<string, mixed> $data Validated and normalized release fields. */
    public function update(int $id, array $data, string $editorVersion): void
    {
        DB::transaction(function () use ($id, $data, $editorVersion): void {
            $version = AppVersion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($version, $editorVersion);
            $this->assertIdentity($data, $version);
            $this->assertDownloadPolicy($data);
            $version->update($data);
        }, 3);
    }

    /** Returns false when the release must be deactivated before deletion. */
    public function delete(int $id, string $editorVersion): bool
    {
        return DB::transaction(function () use ($id, $editorVersion): bool {
            $version = AppVersion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($version, $editorVersion);
            if ($version->is_active) return false;
            $version->delete();

            return true;
        }, 3);
    }

    /** Returns false when incomplete release facts prevent activation. */
    public function toggleActive(int $id, string $editorVersion): bool
    {
        return DB::transaction(function () use ($id, $editorVersion): bool {
            $version = AppVersion::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertCurrentVersion($version, $editorVersion);
            if (!$version->is_active && !$this->isActivatable($version)) return false;
            $version->is_active = !$version->is_active;
            $version->save();

            return true;
        }, 3);
    }

    /** Returns true for creation, false for an exact already-active retry. Never overwrites. */
    public function bootstrapDirect(string $name, int $code, string $url): bool
    {
        return DB::transaction(function () use ($name, $code, $url): bool {
            AdminSingletonLock::acquire('app-release:android');
            $existing = AppVersion::query()->where('platform', 'android')
                ->where('distribution_channel', AppReleasePolicyService::CHANNEL_DIRECT)
                ->where('version_code', $code)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->version_name === $name && $existing->download_url === $url && $existing->is_active) {
                    return false;
                }
                throw ValidationException::withMessages([
                    'version_code' => 'That direct versionCode already exists with different or inactive release facts; review it in the dashboard.',
                ]);
            }
            $data = [
                'platform' => 'android', 'distribution_channel' => AppReleasePolicyService::CHANNEL_DIRECT,
                'version_name' => $name, 'version_code' => $code, 'build_number' => null,
                'is_force_update' => false, 'is_active' => true, 'download_url' => $url,
            ];
            $this->assertIdentity($data);
            $this->assertDownloadPolicy($data);
            AppVersion::query()->create($data);

            return true;
        }, 3);
    }

    private function assertCurrentVersion(AppVersion $version, string $editorVersion): void
    {
        if (!hash_equals(AppVersionEditorVersion::for($version), $editorVersion)) {
            throw ValidationException::withMessages([
                'editor_version' => "تغيّر إصدار التطبيق منذ فتح الصفحة\nأعد تحميلها قبل المتابعة",
            ]);
        }
    }

    private function assertDownloadPolicy(array $data): void
    {
        $url = $data['download_url'] ?? null;
        if (!$data['is_active'] && !$data['is_force_update'] && ($url === null || trim($url) === '')) return;
        if (!$this->releasePolicy->isAllowedDownloadUrl($data['distribution_channel'], $url)) {
            throw ValidationException::withMessages([
                'download_url' => match ($data['distribution_channel']) {
                    'play' => 'استخدم صفحة تطبيق ركن الصحيحة على Google Play',
                    'appstore' => 'استخدم صفحة تطبيق ركن على App Store',
                    'direct' => 'استخدم رابط APK مباشرًا على rokn.app',
                    default => 'رابط التحديث لا يطابق قناة التوزيع',
                },
            ]);
        }
    }

    private function assertIdentity(array $data, ?AppVersion $existing = null): void
    {
        if ($existing) {
            $immutableChanged = $existing->platform !== $data['platform']
                || $existing->distribution_channel !== $data['distribution_channel']
                || $existing->version_name !== $data['version_name']
                || (int) $existing->version_code !== (int) $data['version_code']
                || (int) $existing->build_number !== (int) $data['build_number'];
            if ($immutableChanged) {
                throw ValidationException::withMessages([
                    'version_name' => 'هوية الإصدار والقناة والرقم الداخلي لا تتغير بعد إنشائه. أنشئ إصدارًا جديدًا.',
                ]);
            }
        } else {
            $platform = (string) $data['platform'];
            $channel = (string) $data['distribution_channel'];
            $identifierColumn = $platform === 'android' ? 'version_code' : 'build_number';
            $candidate = (int) $data[$identifierColumn];
            $channelMaximum = (int) AppVersion::query()
                ->where('platform', $platform)
                ->where('distribution_channel', $channel)
                ->max($identifierColumn);
            $platformMaximum = (int) AppVersion::query()
                ->where('platform', $platform)
                ->max($identifierColumn);
            $sameBuildVersionNames = AppVersion::query()
                ->where('platform', $platform)
                ->where($identifierColumn, $candidate)
                ->pluck('version_name')
                ->map(fn ($name): string => (string) $name)
                ->unique();
            if (
                $sameBuildVersionNames->isNotEmpty()
                && !$sameBuildVersionNames->contains($data['version_name'])
            ) {
                throw ValidationException::withMessages([
                    'version_name' => 'نفس رقم البناء يجب أن يحمل نفس اسم الإصدار في كل قنوات التوزيع.',
                ]);
            }
            // Play and direct may publish the same Android build identity, but
            // neither channel may introduce a lower build than users could
            // already have installed from the other one.
            if (
                ($channelMaximum > 0 && $candidate <= $channelMaximum)
                || ($platformMaximum > 0 && $candidate < $platformMaximum)
            ) {
                $minimum = max($channelMaximum + 1, $platformMaximum);
                throw ValidationException::withMessages([
                    $identifierColumn => "استخدم رقمًا لا يقل عن {$minimum}. الرجوع إلى رقم أقدم يمنع التحديث فوق النسخة المثبتة.",
                ]);
            }
        }

    }

    private function isActivatable(AppVersion $version): bool
    {
        $channel = (string) $version->distribution_channel;
        $hasIdentifier = $version->platform === 'android'
            ? in_array($channel, ['play', 'direct'], true) && (int) $version->version_code > 0
            : $channel === 'appstore' && (int) $version->build_number > 0;

        return $hasIdentifier
            && $this->releasePolicy->isAllowedDownloadUrl($channel, $version->download_url);
    }
}
