<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CourseCodeBulkRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'action' => 'required|in:delete,activate,deactivate',
            'selected_codes' => 'required|array|min:1',
            'selected_codes.*' => 'integer|distinct|exists:course_codes,id',
            'editor_versions' => 'required|array',
            'editor_versions.*' => 'required|string|size:64',
        ];
    }
}
