<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class PublicContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'about_ar' => 'nullable|string|max:100000',
            'about_en' => 'nullable|string|max:100000',
            'privacy_ar' => 'nullable|string|max:100000',
            'privacy_en' => 'nullable|string|max:100000',
            'policy_ar' => 'nullable|string|max:100000',
            'policy_en' => 'nullable|string|max:100000',
            'editor_version' => 'required|string|size:64',
        ];
    }
}
