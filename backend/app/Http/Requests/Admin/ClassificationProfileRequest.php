<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/** Retained HTTP compatibility; the active Filament editor owns its schema. */
final class ClassificationProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name_ar' => 'required|string|max:255',
            'name_en' => 'required|string|max:255',
            'show_on_home' => 'nullable|boolean',
            'home_order' => 'required|integer|min:0|max:10000',
            'course_ids' => 'nullable|array|max:500',
            'course_ids.*' => 'integer|distinct|exists:courses,id',
            ...($this->isMethod('POST') ? ['authoring_request_id' => 'required|uuid'] : ['editor_version' => 'required|string|size:64']),
        ];
    }
}
