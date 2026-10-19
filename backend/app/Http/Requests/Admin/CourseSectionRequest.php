<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\CourseSectionEdit;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\Project;
use App\Support\UnicodeText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/** One Laravel validation pass produces the existing detached section edit. */
final class CourseSectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
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
            if ($this->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean($this->input($field), false);
            }
        }
        foreach ($multiline as $field) {
            if ($this->input($field) !== null) {
                $normalized[$field] = UnicodeText::clean($this->input($field));
            }
        }
        if ($normalized !== []) {
            $this->merge($normalized);
        }
    }

    public function rules(): array
    {
        $course = $this->course();
        $section = $this->section();
        $creating = $section === null;
        $contentRequired = $creating || $section->getSectionType() !== $this->sectionType();
        $rules = [
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
            'authoring_request_id' => $this->isMethod('post')
                ? 'required|uuid'
                : 'nullable|uuid',
        ];
        $submissionTypes = array_keys((array) config('projects.submission_types', []));
        $submissionTypeCount = max(1, count($submissionTypes));

        return $rules + match ($this->sectionType()) {
            'lesson' => [
                'bunny_video_claim' => [Rule::requiredIf($this->videoRequired()), 'nullable', 'string', 'max:4096'],
                'lesson_thumbnail' => 'nullable|file|mimes:jpeg,jpg,png,webp,gif|max:2048',
                'lesson_description_ar' => $this->boundedTextRules(false),
                'lesson_description_en' => $this->boundedTextRules(false),
                'lesson_duration_minutes' => 'sometimes|nullable|integer|min:1',
                'is_opened' => 'sometimes|boolean',
            ],
            'project' => [
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
            ],
            default => [],
        };
    }

    public function messages(): array
    {
        return [
            'module_id.required' => 'اختر الوحدة التي سيظهر فيها المحتوى',
            'module_id.exists' => 'الوحدة المختارة لم تعد متاحة',
            'bunny_video_claim.required' => 'اختر ملف الفيديو وانتظر اكتمال رفعه',
            'project_submission_types.required' => 'اختر طريقة تسليم واحدة على الأقل',
            'project_submission_types.min' => 'اختر طريقة تسليم واحدة على الأقل',
            'project_submission_types.*.in' => 'أحد أنواع التسليم لم يعد متاحًا',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->sectionType() !== 'project') {
                return;
            }
            $section = $this->section();
            $alreadyExists = CourseSection::query()->where('course_id', $this->course()->id)
                ->where('module_id', $this->moduleId())->where('sectionable_type', Project::class)
                ->when($section, fn ($query) => $query->where('id', '!=', $section->id))->exists();
            if ($alreadyExists) {
                $validator->errors()->add('module_id', 'هذه الوحدة لها مشروع عبور بالفعل. يمكن لكل وحدة أن تحتوي مشروع عبور واحدًا فقط.');
            }
        }];
    }

    public function authoringEdit(): CourseSectionEdit
    {
        $fields = $this->validated();
        $section = $this->section();
        $lessonChanges = $this->changes($fields, [
            'lesson_description_ar' => 'description_ar', 'lesson_description_en' => 'description_en',
            'lesson_duration_minutes' => 'duration_minutes',
        ]);
        if (isset($lessonChanges['duration_minutes'])) {
            $lessonChanges['duration_minutes'] = (int) $lessonChanges['duration_minutes'];
        }
        if (array_key_exists('is_opened', $fields)) {
            $lessonChanges['is_opened'] = (bool) $fields['is_opened'];
        }
        $projectChanges = $this->changes($fields, [
            'project_requirements_ar' => 'requirements_text_ar', 'project_requirements_en' => 'requirements_text_en',
        ]);
        if (array_key_exists('is_graduation_project', $fields)) {
            $projectChanges['is_graduation_project'] = (bool) $fields['is_graduation_project'];
        }

        return new CourseSectionEdit(
            type: $fields['section_type'] ?? $section?->getSectionType(),
            moduleId: isset($fields['module_id']) ? (int) $fields['module_id'] : (int) $section?->module_id,
            titleAr: array_key_exists('title_ar', $fields) ? $fields['title_ar'] : $section?->title_ar,
            expectedVersion: (int) $fields['authoring_version'],
            titleEn: array_key_exists('title_en', $fields) ? $fields['title_en'] : $section?->title_en,
            order: filled($fields['order'] ?? null) ? (int) $fields['order'] : null,
            lessonChanges: $lessonChanges, projectChanges: $projectChanges,
            projectSubmissionTypes: $fields['project_submission_types'] ?? null,
            videoClaim: filled($fields['bunny_video_claim'] ?? null) ? (string) $fields['bunny_video_claim'] : null,
            thumbnail: $fields['lesson_thumbnail'] ?? null,
            requestId: $fields['authoring_request_id'] ?? null,
        );
    }

    private function course(): Course
    {
        $course = $this->route('course');
        abort_unless($course instanceof Course, 404);

        return $course;
    }

    private function section(): ?CourseSection
    {
        $section = $this->route('section');

        return $section instanceof CourseSection ? $section : null;
    }

    private function sectionType(): string
    {
        $type = $this->input('section_type') ?: $this->section()?->getSectionType();

        return is_string($type) ? $type : '';
    }

    private function moduleId(): int
    {
        return $this->exists('module_id') ? $this->integer('module_id') : (int) $this->section()?->module_id;
    }

    private function videoRequired(): bool
    {
        $section = $this->section();
        $content = $section?->getSectionType() === 'lesson' ? $section->sectionable : null;

        return ! ($content instanceof Lesson && trim((string) $content->bunny_video_id) !== '');
    }

    /** @param array<string,string> $mapping */
    private function changes(array $fields, array $mapping): array
    {
        $changes = [];
        foreach ($mapping as $input => $attribute) {
            if (array_key_exists($input, $fields)) {
                $changes[$attribute] = $fields[$input];
            }
        }

        return $changes;
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
