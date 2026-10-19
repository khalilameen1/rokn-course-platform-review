<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class PathProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title_ar' => 'required|string|max:255',
            'title_en' => 'required|string|max:255',
            'interest_ids' => 'nullable|array',
            'interest_ids.*' => 'integer|distinct|exists:classifications,id',
            'course_ids' => 'nullable|array|max:200',
            'course_ids.*' => 'integer|distinct|exists:courses,id',
            ...($this->isMethod('POST')
                ? ['authoring_request_id' => 'required|uuid']
                : ['editor_version' => 'required|string|size:64']),
        ];
    }
}
