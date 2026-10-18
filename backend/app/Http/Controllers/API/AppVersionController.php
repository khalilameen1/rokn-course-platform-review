<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\AppVersion;
use App\Services\ApiResponseService;
use App\Services\AppReleasePolicyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;

final class AppVersionController extends Controller
{
    public function __construct(
        private readonly ApiResponseService $responses,
        private readonly AppReleasePolicyService $releasePolicy,
    ) {}

    public function checkVersion(Request $request): JsonResponse
    {
        $platform = (string) $request->input('platform');
        $rules = [
            'platform' => ['required', 'in:android,ios'],
            'version' => $platform === 'android'
                ? ['required', 'integer', 'min:1']
                : ['required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){1,3}$/'],
            // New iOS clients send the monotonic CFBundleVersion. Older clients
            // remain supported through the marketing-version fallback below.
            'build_number' => $platform === 'ios'
                ? ['nullable', 'integer', 'min:1']
                : ['prohibited'],
            'distribution_channel' => $platform === 'ios'
                ? ['nullable', Rule::in(['appstore'])]
                : ['nullable', Rule::in(['play', 'direct'])],
            // Additive negotiation fields are optional so released clients
            // built before this contract continue to receive update policy.
            'api_contract_version' => ['sometimes', 'integer', 'min:1', 'max:1000'],
            'capabilities' => ['sometimes', 'array', 'max:32'],
            'capabilities.*' => ['string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
        ];
        $validated = $request->validate($rules);
        $clientCompatibility = $this->clientCompatibility($validated);

        /** @var Collection<int, AppVersion> $versions */
        $versions = $this->releasePolicy->activeVersions(
            (string) $validated['platform'],
            isset($validated['distribution_channel'])
                ? (string) $validated['distribution_channel']
                : null,
        );

        if ($versions->isEmpty()) {
            return $this->noUpdateResponse($clientCompatibility);
        }

        if ($validated['platform'] === 'android') {
            return $this->androidResponse(
                $versions,
                (int) $validated['version'],
                $clientCompatibility,
            );
        }

        return $this->iosResponse(
            $versions,
            (string) $validated['version'],
            isset($validated['build_number']) ? (int) $validated['build_number'] : null,
            $clientCompatibility,
        );
    }

    /**
     * @param  Collection<int, AppVersion>  $versions
     * @param  array{compatible: bool, client_version: int, missing_capabilities: list<string>}  $clientCompatibility
     */
    private function androidResponse(
        Collection $versions,
        int $currentCode,
        array $clientCompatibility,
    ): JsonResponse {
        /** @var AppVersion|null $latest */
        $latest = $versions
            ->filter(fn (AppVersion $version): bool => $version->version_code !== null)
            ->sortByDesc(fn (AppVersion $version): int => (int) $version->version_code)
            ->first();

        if (! $latest) {
            return $this->noUpdateResponse($clientCompatibility);
        }

        $updateRequired = (int) $latest->version_code > $currentCode;
        $forceUpdate = $updateRequired && (! $clientCompatibility['compatible'] || $versions->contains(
            fn (AppVersion $version): bool => $version->version_code !== null
                && (int) $version->version_code > $currentCode
                && $version->is_force_update,
        ));

        $minimumSupportedCode = $versions
            ->filter(fn (AppVersion $version): bool => $version->version_code !== null && $version->is_force_update
            )
            ->max(fn (AppVersion $version): int => (int) $version->version_code);

        return $this->versionResponse(
            $latest,
            $updateRequired,
            $forceUpdate,
            $minimumSupportedCode !== null ? (int) $minimumSupportedCode : null,
            null,
            $clientCompatibility,
        );
    }

    /**
     * @param  Collection<int, AppVersion>  $versions
     * @param  array{compatible: bool, client_version: int, missing_capabilities: list<string>}  $clientCompatibility
     */
    private function iosResponse(
        Collection $versions,
        string $currentVersion,
        ?int $currentBuild,
        array $clientCompatibility,
    ): JsonResponse {
        /** @var AppVersion $latest */
        $latest = $versions->sort(function (AppVersion $left, AppVersion $right): int {
            $leftBuild = $left->build_number;
            $rightBuild = $right->build_number;

            if ($leftBuild !== null && $rightBuild !== null && $leftBuild !== $rightBuild) {
                return (int) $rightBuild <=> (int) $leftBuild;
            }
            if (($leftBuild !== null) !== ($rightBuild !== null)) {
                return $leftBuild !== null ? -1 : 1;
            }

            return version_compare((string) $right->version_name, (string) $left->version_name);
        })->first();

        if ($currentBuild !== null && $latest->build_number !== null) {
            $updateRequired = (int) $latest->build_number > $currentBuild;
            $forceUpdate = $updateRequired && (! $clientCompatibility['compatible'] || $versions->contains(
                fn (AppVersion $version): bool => $version->build_number !== null
                    && (int) $version->build_number > $currentBuild
                    && $version->is_force_update,
            ));
        } else {
            // Backwards compatibility for clients released before build_number
            // was added to the request contract.
            $updateRequired = version_compare((string) $latest->version_name, $currentVersion, '>');
            $forceUpdate = $updateRequired && (! $clientCompatibility['compatible'] || $versions->contains(
                fn (AppVersion $version): bool => version_compare((string) $version->version_name, $currentVersion, '>')
                    && $version->is_force_update,
            ));
        }

        $minimumSupportedBuild = $versions
            ->filter(fn (AppVersion $version): bool => $version->build_number !== null && $version->is_force_update
            )
            ->max(fn (AppVersion $version): int => (int) $version->build_number);

        return $this->versionResponse(
            $latest,
            $updateRequired,
            $forceUpdate,
            null,
            $minimumSupportedBuild !== null ? (int) $minimumSupportedBuild : null,
            $clientCompatibility,
        );
    }

    private function versionResponse(
        AppVersion $latest,
        bool $updateRequired,
        bool $forceUpdate,
        ?int $minimumSupportedVersionCode,
        ?int $minimumSupportedBuildNumber,
        array $clientCompatibility,
    ): JsonResponse {
        return $this->withPolicyHeaders($this->responses->success(
            [
                'update_required' => $updateRequired,
                'is_force_update' => $forceUpdate,
                'contract_version' => max(1, (int) config('mobile_contract.current_version', 1)),
                'minimum_supported_version_code' => $minimumSupportedVersionCode,
                'minimum_supported_build_number' => $minimumSupportedBuildNumber,
                'latest_version' => $latest->version_name,
                'latest_version_code' => $latest->version_code,
                'latest_build_number' => $latest->build_number,
                'distribution_channel' => $latest->distribution_channel,
                'policy_configured' => true,
                'client_compatible' => $clientCompatibility['compatible'],
                'update_message' => app()->getLocale() === 'ar'
                    ? $latest->update_message_ar
                    : ($latest->update_message_en ?: $latest->update_message_ar),
                'download_url' => $latest->download_url,
                'release_notes' => app()->getLocale() === 'ar'
                    ? $latest->release_notes_ar
                    : ($latest->release_notes_en ?: $latest->release_notes_ar),
                'api_contract' => $this->apiContract($clientCompatibility),
            ],
            'تم تحميل سياسة إصدار التطبيق'
        ));
    }

    /** @param array{compatible: bool, client_version: int, missing_capabilities: list<string>} $clientCompatibility */
    private function noUpdateResponse(array $clientCompatibility): JsonResponse
    {
        return $this->withPolicyHeaders($this->responses->success(
            [
                'update_required' => false,
                'is_force_update' => false,
                'contract_version' => max(1, (int) config('mobile_contract.current_version', 1)),
                'minimum_supported_version_code' => null,
                'minimum_supported_build_number' => null,
                'policy_configured' => false,
                'client_compatible' => $clientCompatibility['compatible'],
                'api_contract' => $this->apiContract($clientCompatibility),
            ],
            'التطبيق محدّث'
        ));
    }

    /**
     * @param  array{compatible: bool, client_version: int, missing_capabilities: list<string>}  $clientCompatibility
     * @return array{current_version: int, minimum_supported_version: int, client_version: int, compatible: bool, missing_capabilities: list<string>, canonical_base: string, legacy_base: string, capabilities: list<string>}
     */
    private function apiContract(array $clientCompatibility): array
    {
        return [
            'current_version' => max(1, (int) config('mobile_contract.current_version', 1)),
            'minimum_supported_version' => max(1, (int) config('mobile_contract.minimum_supported_version', 1)),
            'client_version' => $clientCompatibility['client_version'],
            'compatible' => $clientCompatibility['compatible'],
            'missing_capabilities' => $clientCompatibility['missing_capabilities'],
            'canonical_base' => '/api/v1',
            // Existing APKs keep /api until their declared minimum release is
            // retired. New code and generated links always use /api/v1.
            'legacy_base' => '/api',
            'capabilities' => array_values((array) config('mobile_contract.capabilities', [])),
        ];
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array{compatible: bool, client_version: int, missing_capabilities: list<string>}
     */
    private function clientCompatibility(array $validated): array
    {
        $clientVersion = max(1, (int) ($validated['api_contract_version'] ?? 1));
        $minimumVersion = max(1, (int) config('mobile_contract.minimum_supported_version', 1));
        $reportedCapabilities = array_values(array_unique(array_map(
            static fn ($value): string => trim((string) $value),
            (array) ($validated['capabilities'] ?? []),
        )));
        $requiredCapabilities = array_values(array_unique(array_filter(array_map(
            static fn ($value): string => trim((string) $value),
            (array) config('mobile_contract.required_capabilities', []),
        ))));
        $missing = array_values(array_diff($requiredCapabilities, $reportedCapabilities));

        return [
            'compatible' => $clientVersion >= $minimumVersion && $missing === [],
            'client_version' => $clientVersion,
            'missing_capabilities' => $missing,
        ];
    }

    private function withPolicyHeaders(JsonResponse $response): JsonResponse
    {
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Vary', 'Accept-Language');

        return $response;
    }
}
