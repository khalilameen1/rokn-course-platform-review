<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Course;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CourseSectionOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $course = $this->route('course');
        abort_unless($course instanceof Course, 404);

        return [
            'sections' => 'required|array',
            'sections.*.id' => ['required', 'integer', 'distinct',
                Rule::exists('course_sections', 'id')->where(fn ($query) => $query->where('course_id', $course->id)->whereNull('deleted_at'))],
            'sections.*.order' => 'required|integer|min:0',
            'sections.*.module_id' => ['nullable', 'integer',
                Rule::exists('course_modules', 'id')->where(fn ($query) => $query->where('course_id', $course->id))],
            'authoring_version' => 'required|integer|min:1',
        ];
    }

    public function messages(): array
    {
        return ['sections.*.module_id.exists' => 'الوحدة المختارة لم تعد متاحة'];
    }
}
