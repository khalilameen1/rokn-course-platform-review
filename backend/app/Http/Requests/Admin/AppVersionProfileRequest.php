<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\AppVersion;
use App\Services\AppReleasePolicyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AppVersionProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(AppReleasePolicyService $releasePolicy): array
    {
        $record = $this->route('app_version');
        $existing = $record instanceof AppVersion ? $record : ($record !== null ? AppVersion::query()->whereKey($record)->firstOrFail() : null);
        $platform = (string) $this->input('platform');
        $channel = (string) $this->input('distribution_channel');
        $requiresUrl = $this->boolean('is_active') || $this->boolean('is_force_update');

        return [
            'platform' => ['required', 'in:android,ios'],
            'distribution_channel' => $platform === 'ios'
                ? ['required', Rule::in(['appstore'])]
                : ['required', Rule::in(['play', 'direct'])],
            'version_name' => ['required', 'string', 'max:40', 'regex:/^\d+(?:\.\d+){1,3}$/'],
            'version_code' => [
                Rule::requiredIf($platform === 'android'),
                'nullable',
                'integer',
                'min:1',
                Rule::unique('app_versions', 'version_code')
                    ->where(fn ($query) => $query
                        ->where('platform', 'android')
                        ->where('distribution_channel', $channel))
                    ->ignore($existing?->id),
            ],
            'build_number' => [
                Rule::requiredIf($platform === 'ios'),
                'nullable',
                'integer',
                'min:1',
                Rule::unique('app_versions', 'build_number')
                    ->where(fn ($query) => $query
                        ->where('platform', 'ios')
                        ->where('distribution_channel', $channel))
                    ->ignore($existing?->id),
            ],
            'is_force_update' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'update_message_ar' => ['nullable', 'string', 'max:500'],
            'update_message_en' => ['nullable', 'string', 'max:500'],
            'download_url' => [
                Rule::requiredIf($requiresUrl),
                'nullable',
                'string',
                'max:2048',
                'url',
                function (string $attribute, $value, $fail) use ($channel, $releasePolicy): void {
                    if (! $releasePolicy->isAllowedDownloadUrl($channel, $value)) {
                        $fail(match ($channel) {
                            'play' => 'استخدم صفحة تطبيق ركن الصحيحة على Google Play',
                            'appstore' => 'استخدم صفحة تطبيق ركن على App Store',
                            'direct' => 'استخدم رابط APK مباشرًا على rokn.app',
                            default => 'رابط التحديث لا يطابق قناة التوزيع',
                        });
                    }
                },
            ],
            'release_notes_ar' => ['nullable', 'string', 'max:5000'],
            'release_notes_en' => ['nullable', 'string', 'max:5000'],
            'authoring_request_id' => [$existing ? 'nullable' : 'required', 'uuid'],
        ] + ['editor_version' => [$existing ? 'required' : 'nullable', 'string', 'size:64']];
    }

    public function authoringData(): array
    {
        $data = $this->validated();
        unset($data['authoring_request_id'], $data['editor_version']);
        $data['is_force_update'] = $this->boolean('is_force_update');
        $data['is_active'] = $this->boolean('is_active');
        $data['version_code'] = $data['platform'] === 'android' ? (int) $data['version_code'] : null;
        $data['build_number'] = $data['platform'] === 'ios' ? (int) $data['build_number'] : null;

        return $data;
    }
}
