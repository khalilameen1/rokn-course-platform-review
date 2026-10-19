<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class PackageProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        foreach (['is_active', 'direct_enabled', 'google_enabled', 'apple_enabled'] as $field) {
            $this->merge([$field => $this->boolean($field)]);
        }
    }

    public function rules(): array
    {
        $package = $this->route('package');

        return [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'price' => 'required|numeric|min:0.01',
            'coins' => 'required|integer|min:1',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'is_active' => 'required|boolean',
            'direct_enabled' => 'required|boolean',
            'google_product_id' => [
                'nullable', 'required_if:google_enabled,1', 'string', 'max:191',
                'regex:/^[a-z0-9._]+$/',
                Rule::unique('packages', 'google_product_id')->ignore($package?->id),
            ],
            'apple_product_id' => [
                'nullable', 'required_if:apple_enabled,1', 'string', 'max:191',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('packages', 'apple_product_id')->ignore($package?->id),
            ],
            'google_enabled' => 'required|boolean',
            'apple_enabled' => 'required|boolean',
            'authoring_request_id' => [$package ? 'nullable' : 'required', 'uuid'],
        ] + ['editor_version' => [$package ? 'required' : 'nullable', 'string', 'size:64']];
    }

    public function authoringData(): array
    {
        $data = $this->validated();
        unset($data['authoring_request_id'], $data['editor_version']);
        $data['sort_order'] = (int) ($data['sort_order'] ?? 100);

        return $data;
    }
}
