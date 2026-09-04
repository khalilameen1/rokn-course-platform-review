<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Project;
use App\Support\UnicodeText;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class CourseSectionInput
{
    public function validate(
        Request $request,
        Course $course,
        ?CourseSection $section,
        bool $videoRequired
    ): void {
        $this->normalize($request);
        $creating = $section === null;
        $sectionType = (string) ($request->input('section_type') ?: $section?->getSectionType());
        $moduleId = $request->exists('module_id')
            ? $request->integer('module_id')
            : (int) ($section?->module_id ?? 0);
        $typeChanged = !$creating && $section?->getSectionType() !== $sectionType;
        $request->validate([
            'title_ar' => $creating
                ? ['required', 'string', 'max:255']
                : ['sometimes', 'required', 'string', 'max:255'],
            'title_en' => $creating
                ? ['nullable', 'string', 'max:255']
                : ['sometimes', 'nullable', 'string', 'max:255'],
            'section_type' => $creating
                ? ['required', Rule::in(['lesson', 'project'])]
                : ['sometimes', 'required', Rule::in(['lesson', 'project'])],
            'module_id' => array_merge($creating ? ['required'] : ['sometimes', 'required'], [
                'integer',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ]),
            'order' => $creating
                ? ['nullable', 'integer', 'min:0']
                : ['sometimes', 'nullable', 'integer', 'min:0'],
            'authoring_version' => 'required|integer|min:1',
            'authoring_request_id' => $request->isMethod('post')
                ? 'required|uuid'
                : 'nullable|uuid',
        ], [
            'module_id.required' => 'اختر الوحدة التي سيظهر فيها المحتوى',
            'module_id.exists' => 'الوحدة المختارة لم تعد متاحة',
        ]);

        // Downstream mutation code receives one effective graph position while
        // optional lesson/project fields retain their original presence state.
        $request->merge([
            'title_ar' => $request->exists('title_ar')
                ? $request->input('title_ar')
                : $section?->title_ar,
            'title_en' => $request->exists('title_en')
                ? $request->input('title_en')
                : $section?->title_en,
            'section_type' => $sectionType,
            'module_id' => $moduleId,
        ]);

        switch ($sectionType) {
            case 'lesson':
                $this->validateLesson($request, $videoRequired);
                break;
            case 'project':
                $this->validateProject($request, $course, $section, $creating || $typeChanged);
                break;
        }
    }

    private function normalize(Request $request): void
    {
        $singleLine = [
            'title_ar', 'title_en',
        ];
        $multiline = [
            'lesson_description_ar', 'lesson_description_en',
            'project_requirements_ar', 'project_requirements_en',
        ];
        $normalized = [];
        foreach ($singleLine as $field) {
            if ($request->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean($request->input($field), false);
            }
        }
        foreach ($multiline as $field) {
            if ($request->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean($request->input($field));
            }
        }
        if ($normalized !== []) {
            $request->merge($normalized);
        }
    }

    private function validateLesson(Request $request, bool $videoRequired): void
    {
        $request->merge(['video_source_type' => 'bunny']);
        $request->validate([
            'bunny_video_claim' => 'nullable|string|max:4096',
            'lesson_thumbnail' => 'nullable|file|mimes:jpeg,jpg,png,webp,gif|max:2048',
            'lesson_description_ar' => $this->boundedTextRules(false),
            'lesson_description_en' => $this->boundedTextRules(false),
            'lesson_duration_minutes' => 'sometimes|nullable|integer|min:1',
            'is_opened' => 'sometimes|boolean',
        ]);
        if ($videoRequired && !$request->filled('bunny_video_claim')) {
            throw ValidationException::withMessages([
                'bunny_video_claim' => 'اختر ملف الفيديو وانتظر اكتمال رفعه',
            ]);
        }
    }

    private function validateProject(
        Request $request,
        Course $course,
        ?CourseSection $section,
        bool $contentRequired
    ): void {
        $submissionTypes = array_keys((array) config('projects.submission_types', []));
        $submissionTypeCount = max(1, count($submissionTypes));
        $request->validate([
            'project_requirements_ar' => $this->boundedTextRules($contentRequired, false),
            'project_requirements_en' => $this->boundedTextRules(false),
            'project_submission_types' => $contentRequired
                ? ['required', 'array', 'min:1', "max:{$submissionTypeCount}"]
                : ['sometimes', 'required', 'array', 'min:1', "max:{$submissionTypeCount}"],
            'project_submission_types.*' => [
                'required',
                'string',
                'distinct',
                Rule::in($submissionTypes),
            ],
            'is_graduation_project' => 'sometimes|boolean',
        ], [
            'project_submission_types.required' => 'اختر طريقة تسليم واحدة على الأقل',
            'project_submission_types.min' => 'اختر طريقة تسليم واحدة على الأقل',
            'project_submission_types.*.in' => 'أحد أنواع التسليم لم يعد متاحًا',
        ]);

        $alreadyExists = CourseSection::query()
            ->where('course_id', $course->id)
            ->where('module_id', $request->integer('module_id'))
            ->where('sectionable_type', Project::class)
            ->when($section, fn ($query) => $query->where('id', '!=', $section->id))
            ->exists();

        if ($alreadyExists) {
            throw ValidationException::withMessages([
                'module_id' => 'هذه الوحدة لها مشروع عبور بالفعل. يمكن لكل وحدة أن تحتوي مشروع عبور واحدًا فقط.',
            ]);
        }
    }

    /** @return array<int, mixed> */
    private function boundedTextRules(bool $required, bool $nullable = true): array
    {
        $rules = $required ? ['required'] : ['sometimes'];
        if ($nullable) {
            $rules[] = 'nullable';
        }
        $rules[] = 'string';
        // Even four-byte characters remain below the 65,535-byte TEXT limit.
        $rules[] = 'max:12000';

        return $rules;
    }
}
