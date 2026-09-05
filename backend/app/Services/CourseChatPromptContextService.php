<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\Lesson;
use App\Support\UnicodeText;
use Illuminate\Support\Facades\Cache;

final readonly class CourseChatPromptContextService
{
    public function __construct(
        private CourseStagedAuthoringService $stagedAuthoring,
        private AiPromptPolicy $promptPolicy,
        private OpenRouterService $openRouter
    ) {
    }

    public function currentLesson(int $lessonId, Course $course): ?Lesson
    {
        $currentId = $this->stagedAuthoring->currentLearnerEntityMap(
            Lesson::class,
            [$lessonId]
        )[$lessonId] ?? $lessonId;

        return Lesson::query()
            ->whereKey($currentId)
            ->where('list_id', $course->id)
            ->whereHas('courseSection', fn ($sections) =>
                $sections->where('course_id', $course->id)
            )
            ->first();
    }

    public function courseBrief(Course $course): string
    {
        $version = $this->version($course);

        return Cache::remember(
            sprintf('course-chat:brief:v6:%d:%s', $course->id, $version),
            now()->addHours(12),
            function () use ($course): string {
                $courseName = UnicodeText::clean(
                    $course->name_ar ?: $course->name_en,
                    false
                );
                $description = UnicodeText::limit(UnicodeText::clean((string) (
                    $course->description_ar ?: $course->description_en
                )), 600);

                return $this->promptPolicy->courseChat(
                    $courseName,
                    $this->outline($course),
                    $description
                );
            }
        );
    }

    public function currentLessonPrompt(string $title, string $description): string
    {
        return $this->promptPolicy->currentLesson($title, $description);
    }

    public function model(): string
    {
        return $this->openRouter->configuredModel();
    }

    public function version(Course $course): string
    {
        return $this->promptPolicy->version('course-chat', [
            'name_ar' => (string) $course->name_ar,
            'name_en' => (string) $course->name_en,
            'description_ar' => (string) $course->description_ar,
            'description_en' => (string) $course->description_en,
            'authoring_version' => (string) $course->authoring_version,
        ]);
    }

    private function outline(Course $course): string
    {
        $course->loadMissing([
            'modules' => fn ($modules) => $modules
                ->select(['id', 'course_id', 'title', 'title_ar', 'title_en', 'order'])
                ->orderBy('order'),
            'modules.sections' => fn ($sections) => $sections
                ->select([
                    'id', 'course_id', 'module_id', 'title', 'title_ar', 'title_en',
                    'section_type', 'sectionable_type', 'order',
                ])
                ->orderBy('order'),
        ]);

        $lines = [];
        foreach ($course->modules as $module) {
            $moduleTitle = UnicodeText::clean((string) $module->title, false);
            if ($moduleTitle !== '') {
                $lines[] = 'الوحدة: ' . $moduleTitle;
            }
            foreach ($module->sections as $section) {
                $sectionTitle = UnicodeText::clean((string) $section->title, false);
                if ($sectionTitle === '') {
                    continue;
                }
                $lines[] = ($section->getSectionType() === 'project' ? 'مشروع: ' : 'مقطع: ')
                    . $sectionTitle;
            }
        }

        return UnicodeText::limit(implode("\n", $lines), 1200);
    }
}
