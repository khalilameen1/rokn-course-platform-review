<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CourseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => 'nullable|string|max:120',
            'classification_id' => 'nullable|integer|exists:classifications,id',
            'state' => 'nullable|string|in:active,archived,all',
        ];
    }
}
