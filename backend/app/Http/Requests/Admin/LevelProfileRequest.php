<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class LevelProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => 'required|string|max:255', 'name_en' => 'required|string|max:255',
            'description_ar' => 'nullable|string|max:1000', 'description_en' => 'nullable|string|max:1000',
            'badge_image' => 'nullable|image|mimes:jpeg,png,jpg,webp|max:4096',
            'order' => 'nullable|integer|min:1|max:1000',
            ...($this->isMethod('POST') ? ['authoring_request_id' => 'required|uuid'] : ['editor_version' => 'required|string|size:64']),
        ];
    }
}
