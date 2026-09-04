<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CoursePdfOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $course = $this->route('course');
        $courseId = $course instanceof Course ? $course->id : (int) $course;

        return [
            'order' => ['required', 'array'],
            'order.*' => [
                'integer',
                'distinct',
                Rule::exists('course_pdfs', 'id')->where(
                    fn ($query) => $query->where('course_id', $courseId)
                ),
            ],
            'authoring_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
