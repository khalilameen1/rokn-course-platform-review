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
        $request->validate([
            'title_ar' => 'required|string|max:255',
            'title_en' => 'nullable|string|max:255',
            'section_type' => 'required|in:lesson,project',
            'module_id' => [
                'required',
                'integer',
                Rule::exists('course_modules', 'id')->where(
                    fn ($query) => $query->where('course_id', $course->id)
                ),
            ],
            'order' => 'nullable|integer|min:0',
            'authoring_version' => 'required|integer|min:1',
            'authoring_request_id' => $request->isMethod('post')
                ? 'required|uuid'
                : 'nullable|uuid',
        ], [
            'module_id.required' => 'اختر الوحدة التي سيظهر فيها المحتوى',
            'module_id.exists' => 'الوحدة المختارة لم تعد متاحة',
        ]);

        switch ((string) $request->input('section_type')) {
            case 'lesson':
                $this->validateLesson($request, $videoRequired);
                break;
            case 'project':
                $this->validateProject($request, $course, $section);
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
            'lesson_duration_minutes' => 'nullable|integer|min:1',
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
        ?CourseSection $section
    ): void {
        $submissionTypes = array_keys((array) config('projects.submission_types', []));
        $submissionTypeCount = max(1, count($submissionTypes));
        $request->validate([
            'project_requirements_ar' => 'required|string',
            'project_requirements_en' => 'nullable|string',
            'project_submission_types' => "required|array|min:1|max:{$submissionTypeCount}",
            'project_submission_types.*' => [
                'required',
                'string',
                'distinct',
                Rule::in($submissionTypes),
            ],
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
}
