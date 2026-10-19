<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class CourseCodeIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string'],
            'name' => ['nullable', 'string'],
            'type' => ['nullable', 'string'],
            'course_id' => ['nullable', 'integer'],
            'lesson_id' => ['nullable', 'integer'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['nullable', 'string'],
        ];
    }
}
