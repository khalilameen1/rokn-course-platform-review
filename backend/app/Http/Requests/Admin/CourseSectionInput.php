<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Data\CourseSectionEdit;
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
    ): CourseSectionEdit {
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

        $fields = match ($sectionType) {
            'lesson' => $this->validateLesson($request, $videoRequired),
            'project' => $this->validateProject($request, $course, $section, $creating || $typeChanged),
        };

        return $this->edit($request, $fields);
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

    private function validateLesson(Request $request, bool $videoRequired): array
    {
        $request->merge(['video_source_type' => 'bunny']);
        $fields = $request->validate([
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

        return $fields;
    }

    private function validateProject(
        Request $request,
        Course $course,
        ?CourseSection $section,
        bool $contentRequired
    ): array {
        $submissionTypes = array_keys((array) config('projects.submission_types', []));
        $submissionTypeCount = max(1, count($submissionTypes));
        $fields = $request->validate([
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

        return $fields;
    }

    /** Build content changes from validated fields only, preserving key presence. */
    private function edit(Request $request, array $fields): CourseSectionEdit
    {
        $lessonChanges = $this->changes($fields, [
            'lesson_description_ar' => 'description_ar',
            'lesson_description_en' => 'description_en',
            'lesson_duration_minutes' => 'duration_minutes',
        ]);
        if (isset($lessonChanges['duration_minutes'])) {
            $lessonChanges['duration_minutes'] = (int) $lessonChanges['duration_minutes'];
        }
        if (array_key_exists('is_opened', $fields)) {
            $lessonChanges['is_opened'] = $request->boolean('is_opened');
        }
        $projectChanges = $this->changes($fields, [
            'project_requirements_ar' => 'requirements_text_ar',
            'project_requirements_en' => 'requirements_text_en',
        ]);
        if (array_key_exists('is_graduation_project', $fields)) {
            $projectChanges['is_graduation_project'] = $request->boolean('is_graduation_project');
        }

        return new CourseSectionEdit(
            type: (string) $request->input('section_type'),
            moduleId: $request->integer('module_id'),
            titleAr: $request->input('title_ar'),
            expectedVersion: $request->integer('authoring_version'),
            titleEn: $request->input('title_en'),
            // Empty order has the same meaning as omission: append on create,
            // preserve the current locked position on update.
            order: $request->filled('order') ? $request->integer('order') : null,
            lessonChanges: $lessonChanges,
            projectChanges: $projectChanges,
            projectSubmissionTypes: $fields['project_submission_types'] ?? null,
            videoClaim: isset($fields['bunny_video_claim']) && $request->filled('bunny_video_claim')
                ? (string) $fields['bunny_video_claim'] : null,
            thumbnail: $fields['lesson_thumbnail'] ?? null,
            requestId: $request->string('authoring_request_id')->toString() ?: null
        );
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
