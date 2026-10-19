<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\Course;
use App\Services\BunnyDirectUploadService;
use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CourseVideoUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['title' => UnicodeText::clean($this->input('title'), false)]);
    }

    public function rules(): array
    {
        $course = $this->route('course');
        abort_unless($course instanceof Course, 404);

        return [
            'title' => 'required|string|max:255',
            'size' => 'required|integer|min:1|max:'.BunnyDirectUploadService::MAX_BYTES,
            'mime' => ['required', 'string', Rule::in(BunnyDirectUploadService::MIMES)],
            'original_name' => ['required', 'string', 'max:255'],
            'idempotency_key' => ['required', 'uuid'],
            'authoring_version' => ['required', 'integer', 'min:1'],
            'section_id' => [
                'nullable', 'integer',
                Rule::exists('course_sections', 'id')->where(fn ($query) => $query->where('course_id', $course->id)->whereNull('deleted_at')),
            ],
        ];
    }
}
