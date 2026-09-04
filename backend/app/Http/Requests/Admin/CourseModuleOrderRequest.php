<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CourseModuleOrderRequest extends FormRequest
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
            'modules' => ['required', 'array'],
            'modules.*.id' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $courseId)
                ),
            ],
            'modules.*.order' => ['required', 'integer', 'min:0', 'distinct'],
            'authoring_version' => ['required', 'integer', 'min:1'],
        ];
    }
}
