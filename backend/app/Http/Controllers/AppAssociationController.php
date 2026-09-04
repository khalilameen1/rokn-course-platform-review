<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\AppReleasePolicyService;
use Illuminate\Http\JsonResponse;

final class AppAssociationController extends Controller
{
    public function __construct(private readonly AppReleasePolicyService $releasePolicy)
    {
    }

    public function android(): JsonResponse
    {
        $package = trim((string) config('app_links.android_package'));
        $fingerprints = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => strtoupper(trim((string) $value)),
            (array) config('app_links.android_sha256_fingerprints', [])
        ))));

        abort_if(
            !$this->validAndroidPackage($package)
                || $fingerprints === []
                || array_filter($fingerprints, fn (string $value): bool => !$this->validAndroidFingerprint($value)) !== [],
            404
        );

        return response()->json([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => $package,
                'sha256_cert_fingerprints' => $fingerprints,
            ],
        ]], 200, $this->headers(), JSON_UNESCAPED_SLASHES);
    }

    public function apple(): JsonResponse
    {
        $appIds = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('app_links.apple_app_ids', [])
        ))));

        abort_if(
            $appIds === []
                || array_filter($appIds, fn (string $value): bool => !$this->validAppleAppId($value)) !== [],
            404
        );

        return response()->json([
            'applinks' => [
                'apps' => [],
                'details' => array_map(static fn (string $appId): array => [
                    'appID' => $appId,
                    'paths' => [
                        '/home',
                        '/profile',
                        '/wallet',
                        '/support/*',
                        '/course/*',
                    ],
                ], $appIds),
            ],
        ], 200, $this->headers(), JSON_UNESCAPED_SLASHES);
    }

    private function headers(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Cache-Control' => 'public, max-age=3600, stale-while-revalidate=86400',
            'X-Content-Type-Options' => 'nosniff',
            'X-Rokn-Mobile-Contract' => (string) max(1, (int) config('mobile_contract.current_version', 1)),
            'X-Rokn-App-Identity' => $this->releasePolicy->publicContractIdentity(),
        ];
    }

    private function validAndroidPackage(string $package): bool
    {
        return preg_match('/\A[A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z][A-Za-z0-9_]*)+\z/', $package) === 1;
    }

    private function validAndroidFingerprint(string $fingerprint): bool
    {
        return preg_match('/\A(?:[0-9A-F]{2}:){31}[0-9A-F]{2}\z/', $fingerprint) === 1;
    }

    private function validAppleAppId(string $appId): bool
    {
        return preg_match('/\A[A-Z0-9]{10}\.(?:[A-Za-z0-9-]+\.)+[A-Za-z0-9-]+\z/', $appId) === 1;
    }
}
