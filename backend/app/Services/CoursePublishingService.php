<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Course;
use App\Models\CourseAccessPlan;
use App\Models\Lesson;
use App\Models\LessonMediaState;
use App\Models\Project;
use Illuminate\Support\Facades\Storage;

class CoursePublishingService
{
    public function __construct(
        private readonly CertificateTextTemplateService $certificateTemplates
    ) {
    }

    /**
     * A coming-soon card may be announced before the course content exists,
     * but never before its public identity is complete.
     */
    public function auditCatalogCard(Course $course): array
    {
        $course->loadMissing(['photo', 'teachers', 'teacher', 'classifications']);
        $issues = [];

        if (trim((string) ($course->name_ar ?: $course->name_en)) === '') {
            $issues[] = 'أضف اسمًا واضحًا للكورس.';
        }
        if (trim((string) ($course->description_ar ?: $course->description_en)) === '') {
            $issues[] = 'أضف وصفًا مختصرًا للكورس.';
        }
        if (!$course->photo && empty($course->getRawOriginal('image'))) {
            $issues[] = 'أضف غلافًا للكورس.';
        } elseif ($course->photo && !$this->storedFileExists('public', (string) $course->photo->path)) {
            $issues[] = 'غلاف الكورس غير موجود في التخزين.';
        }
        $assignedTeachers = $course->teachers->isNotEmpty()
            ? $course->teachers
            : collect([$course->teacher])->filter();
        if ($assignedTeachers->isEmpty()) {
            $issues[] = 'اربط الكورس بمدرب واحد على الأقل.';
        } elseif (!$assignedTeachers->contains(fn ($teacher) => (bool) $teacher->active)) {
            $issues[] = 'فعّل محاضرًا واحدًا على الأقل قبل نشر الكورس.';
        } elseif (!$assignedTeachers->contains(fn ($teacher) =>
            (bool) $teacher->active
            && trim((string) (
                $teacher->name_ar ?: $teacher->name_en ?: $teacher->getRawOriginal('name')
            )) !== ''
        )) {
            $issues[] = 'أكمل اسم محاضر واحد على الأقل قبل إظهار الكورس.';
        }
        if ($course->classifications->isEmpty()) {
            $issues[] = 'اختر تصنيفًا واحدًا على الأقل.';
        }

        return [
            'ready' => $issues === [],
            'issues' => array_values(array_unique($issues)),
            'issue_details' => $this->describeIssues($issues),
        ];
    }

    /**
     * Audit the learner-facing course contract before a draft is published.
     *
     * Existing published courses are never changed automatically. This audit is
     * used only when an administrator explicitly moves a draft to published.
     */
    public function audit(Course $course): array
    {
        $course->loadMissing([
            'photo',
            'teachers',
            'teacher',
            'classifications',
            'accessPlans',
            'activePdfs',
            'modules.sections.sectionable',
            'sections.sectionable',
        ]);

        $issues = [];
        $warnings = [];
        $reelsCount = 0;
        $projectsCount = 0;
        $attachmentsCount = $course->activePdfs->count();

        if (trim((string) ($course->name_ar ?: $course->name_en)) === '') {
            $issues[] = 'أضف اسمًا واضحًا للكورس.';
        }
        if (trim((string) ($course->description_ar ?: $course->description_en)) === '') {
            $issues[] = 'أضف وصفًا مختصرًا يوضح نتيجة الكورس.';
        }
        if (!$course->photo && empty($course->getRawOriginal('image'))) {
            $issues[] = 'أضف غلافًا للكورس.';
        } elseif ($course->photo && !$this->storedFileExists('public', (string) $course->photo->path)) {
            $issues[] = 'غلاف الكورس غير موجود في التخزين.';
        }
        $assignedTeachers = $course->teachers->isNotEmpty()
            ? $course->teachers
            : collect([$course->teacher])->filter();
        if ($assignedTeachers->isEmpty()) {
            $issues[] = 'اربط الكورس بمدرب واحد على الأقل.';
        } elseif (!$assignedTeachers->contains(fn ($teacher) => (bool) $teacher->active)) {
            $issues[] = 'فعّل محاضرًا واحدًا على الأقل قبل نشر الكورس.';
        } else {
            $activeTeachers = $assignedTeachers->filter(fn ($teacher) => (bool) $teacher->active);
            if (!$activeTeachers->contains(fn ($teacher) => trim((string) (
                $teacher->name_ar ?: $teacher->name_en ?: $teacher->getRawOriginal('name')
            )) !== '')) {
                $issues[] = 'أكمل اسم محاضر واحد على الأقل قبل النشر.';
            }
            if (!$activeTeachers->contains(fn ($teacher) => trim((string) (
                $teacher->bio_ar ?: $teacher->bio_en ?: $teacher->getRawOriginal('bio')
            )) !== '')) {
                $warnings[] = 'أضف نبذة قصيرة عن المحاضر لتكتمل صفحة الكورس.';
            }
        }
        if ($course->classifications->isEmpty()) {
            $issues[] = 'اختر تصنيفًا واحدًا على الأقل حتى يظهر الكورس في مكانه الصحيح.';
        }
        if ($course->modules->isEmpty()) {
            $issues[] = 'أنشئ وحدة واحدة على الأقل.';
        }

        $this->auditAccessPlans($course, $issues, $warnings);

        // A certificate wording is an editorial claim, not a cosmetic
        // fallback. If a configured key was removed or its text is empty,
        // stop publication instead of silently issuing the generic wording.
        if ($this->certificateTemplates->forCourse($course) === null) {
            $issues[] = 'اختر صياغة شهادة صالحة قبل النشر.';
        }

        $lessons = $course->modules
            ->flatMap(fn ($module) => $module->sections)
            ->map(fn ($section) => $section->sectionable)
            ->filter(fn ($sectionable) => $sectionable instanceof Lesson)
            ->unique(fn (Lesson $lesson) => (int) $lesson->id)
            ->values();
        $mediaStates = LessonMediaState::query()
            ->whereIn('lesson_id', $lessons->pluck('id'))
            ->get()
            ->keyBy('lesson_id');
        $lessons->each(fn (Lesson $lesson) => $lesson->setRelation(
            'mediaState',
            $mediaStates->get($lesson->id)
        ));
        foreach ($course->activePdfs as $pdf) {
            if (!$this->storedFileExists((string) $pdf->storage_disk, (string) $pdf->file_path)) {
                $issues[] = "ملف «{$pdf->title}» غير موجود في التخزين";
            }
        }

        // The player keys progression, playback and submissions by these
        // immutable identities. Reusing one content row in two places (or
        // publishing an orphan) makes one paid step overwrite another.
        $moduleIds = [];
        $sectionIds = [];
        $contentIds = [];
        foreach ($course->modules as $module) {
            $moduleId = (int) $module->id;
            if ($moduleId < 1 || isset($moduleIds[$moduleId])) {
                $issues[] = 'توجد وحدة بهوية مفقودة أو مكررة؛ أعد حفظ خريطة الكورس.';
            }
            $moduleIds[$moduleId] = true;

            foreach ($module->sections as $section) {
                $sectionId = (int) $section->id;
                if ($sectionId < 1 || isset($sectionIds[$sectionId])) {
                    $issues[] = 'توجد خطوة بهوية مفقودة أو مكررة؛ أعد حفظ خريطة الكورس.';
                }
                $sectionIds[$sectionId] = true;

                $type = $section->getSectionType();
                $contentId = (int) $section->sectionable_id;
                $contentKey = $type . ':' . $contentId;
                if ($contentId < 1 || $section->sectionable === null) {
                    $issues[] = 'توجد خطوة غير مرتبطة بمحتواها؛ احذفها أو أعد إضافتها.';
                } elseif (isset($contentIds[$contentKey])) {
                    $issues[] = 'نفس المحتوى مضاف أكثر من مرة؛ احذف النسخة المكررة قبل النشر.';
                }
                $contentIds[$contentKey] = true;
            }
        }

        foreach ($course->modules->sortBy('order')->values() as $index => $module) {
            $moduleLabel = trim((string) ($module->title_ar ?: $module->title_en)) ?: 'الوحدة ' . ($index + 1);
            if (trim((string) ($module->title_ar ?: $module->title_en)) === '') {
                $issues[] = 'أضف عنوانًا للوحدة ' . ($index + 1);
            }
            $sections = $module->sections->sortBy('order')->values();
            $reels = $sections->filter(fn ($section) => $section->getSectionType() === 'lesson');
            $projects = $sections->filter(fn ($section) => $section->getSectionType() === 'project');
            $reelsCount += $reels->count();
            $projectsCount += $projects->count();
            if ($reels->isEmpty()) {
                $issues[] = "{$moduleLabel}: أضف مقطعًا تعليميًا واحدًا على الأقل";
            }

            foreach ($sections as $section) {
                $sectionTitle = trim((string) ($section->title_ar ?: $section->title_en));
                if ($sectionTitle === '') {
                    $issues[] = "{$moduleLabel}: يوجد جزء بلا عنوان";
                }

                if (!in_array($section->getSectionType(), ['lesson', 'project'], true)) {
                    $issues[] = "{$moduleLabel}: المحتوى «{$sectionTitle}» ليس مقطعًا أو مشروع عبور";
                }

            }

            foreach ($reels as $reel) {
                $lesson = $reel->sectionable;
                $reelTitle = trim((string) ($reel->title_ar ?: $reel->title_en));
                if ($reelTitle === '') {
                    $issues[] = "{$moduleLabel}: أضف عنوانًا للمقطع";
                    $reelTitle = 'بلا عنوان';
                }
                if (!$lesson instanceof Lesson || !$this->lessonHasPlayableVideo($lesson)) {
                    $issues[] = "{$moduleLabel}: المقطع «{$reelTitle}» لا يحتوي على فيديو صالح";
                }
                if ($lesson instanceof Lesson) {
                    $mediaState = $lesson->mediaState;
                    if (!$mediaState || !$mediaState->last_reconciled_at) {
                        $issues[] = "{$moduleLabel}: افحص تشغيل الفيديو «{$reelTitle}» فعليًا قبل النشر";
                    } elseif (
                        $mediaState->status !== 'ready'
                        || !hash_equals(
                            strtolower(trim((string) $lesson->bunny_video_id)),
                            strtolower(trim((string) $mediaState->provider_media_id))
                        )
                        || $mediaState->integrity_status === 'quarantined'
                        || $mediaState->quarantined_at !== null
                        || $mediaState->hasBlockingIntegrityIssue()
                    ) {
                        $issues[] = "{$moduleLabel}: الفيديو «{$reelTitle}» غير جاهز للمشاهدة";
                    } elseif ((int) $mediaState->duration_seconds < 1) {
                        // Playback rejects ready rows without provider-derived
                        // duration. A manually typed display duration cannot
                        // make that media generation playable.
                        $issues[] = "{$moduleLabel}: لم تكتمل مدة الفيديو «{$reelTitle}»";
                    } elseif ($mediaState->integrity_status !== 'healthy') {
                        $warnings[] = "{$moduleLabel}: راجع صورة ومدة وجودة الفيديو «{$reelTitle}»";
                    }
                }
            }

            foreach ($projects as $projectSection) {
                $project = $projectSection->sectionable;
                $projectTitle = trim((string) ($projectSection->title_ar ?: $projectSection->title_en)) ?: 'مشروع بلا عنوان';
                if (!$project instanceof Project) {
                    $issues[] = "{$moduleLabel}: المشروع «{$projectTitle}» غير مرتبط بمحتواه";
                    continue;
                }
                if (trim((string) ($project->requirements_text_ar ?: $project->requirements_text_en)) === '') {
                    $issues[] = "{$moduleLabel}: اكتب المطلوب في المشروع «{$projectTitle}»";
                }
            }

            if ($projects->count() > 1) {
                $issues[] = "{$moduleLabel}: يمكن إضافة مشروع عبور واحد فقط؛ قسّم المشاريع على وحدات مستقلة.";
            } elseif ($projects->isNotEmpty() && $sections->last()?->getSectionType() !== 'project') {
                $issues[] = "{$moduleLabel}: يجب أن يكون مشروع العبور آخر جزء في الوحدة.";
            }
        }

        if ($course->attachment_prompt_enabled && $attachmentsCount === 0) {
            $issues[] = 'نافذة المرفقات مفعلة لكن الكورس لا يحتوي على مرفقات.';
        }
        if ($course->attachment_prompt_enabled && $attachmentsCount > 0) {
            $promptFrequency = (string) ($course->attachment_prompt_frequency
                ?: config('course_attachments.prompt.default_frequency', 'once_per_course'));
            if (!array_key_exists(
                $promptFrequency,
                (array) config('course_attachments.prompt.frequencies', [])
            )) {
                $issues[] = 'اختر متى يتكرر تنبيه المرفقات.';
            }
            $orderedModules = $course->modules->sortBy('order')->values();
            $promptModule = $orderedModules->first();
            $firstLesson = $promptModule?->sections
                ->sortBy('order')
                ->first(fn ($section) => $section->getSectionType() === 'lesson')
                ?->sectionable;
            $firstDurationSeconds = $firstLesson instanceof Lesson
                ? max(
                    (int) $firstLesson->duration_minutes * 60,
                    (int) ($firstLesson->mediaState?->duration_seconds ?? 0)
                )
                : 0;
            if (
                $firstDurationSeconds > 0
                && (int) $course->attachment_prompt_at_seconds >= $firstDurationSeconds
            ) {
                $issues[] = 'موعد نافذة المرفقات يأتي بعد نهاية أول مقطع.';
            }
        }

        if ($course->awards_badge && !in_array($course->badge_track, ['professional', 'freelance'], true)) {
            $issues[] = 'الشارات متاحة فقط للمسار المهني أو الفريلانس؛ صحح سياسة الشارة.';
        }

        $graduationProjectsCount = $course->sections->filter(function ($section): bool {
            return $section->getSectionType() === 'project'
                && $section->sectionable instanceof Project
                && (bool) $section->sectionable->is_graduation_project;
        })->count();
        if ($graduationProjectsCount > 1) {
            $issues[] = 'حدد مشروع تخرج واحدًا فقط، وهو مشروع آخر وحدة.';
        }

        if ($graduationProjectsCount === 1) {
            $lastModule = $course->modules->sortBy('order')->last();
            $lastSection = $lastModule?->sections->sortBy('order')->last();
            $graduationProject = $lastSection?->sectionable;
            if (!$graduationProject instanceof Project || !$graduationProject->is_graduation_project) {
                $issues[] = 'مشروع التخرج - إن اخترته - يجب أن يكون آخر جزء في آخر وحدة.';
            }
        }

        return [
            'ready' => $issues === [],
            'issues' => array_values(array_unique($issues)),
            'issue_details' => $this->describeIssues($issues),
            'warnings' => array_values(array_unique($warnings)),
            'counts' => [
                'modules' => $course->modules->count(),
                'reels' => $reelsCount,
                'projects' => $projectsCount,
                'attachments' => $attachmentsCount,
            ],
        ];
    }

    private function lessonHasPlayableVideo(Lesson $lesson): bool
    {
        // The current mobile player and signed-delivery contract are Bunny-only.
        // A syntactically valid legacy YouTube URL is not production-playable.
        return $lesson->video_source_type === 'bunny'
            && trim((string) $lesson->bunny_video_id) !== '';
    }

    private function storedFileExists(string $disk, string $path): bool
    {
        if ($disk === '' || $path === '') return false;
        try {
            return Storage::disk($disk)->exists($path);
        } catch (\Throwable) {
            return false;
        }
    }

    /** @param array<int, string> $issues @param array<int, string> $warnings */
    private function auditAccessPlans(Course $course, array &$issues, array &$warnings): void
    {
        $plans = $course->accessPlans->keyBy('code');
        $missing = array_diff(CourseAccessPlan::CODES, $plans->keys()->all());
        if ($missing !== [] || $plans->count() !== count(CourseAccessPlan::CODES)) {
            $issues[] = 'أكمل فئات الكورس الثلاث قبل النشر.';
            return;
        }

        $previousPrice = null;
        foreach (CourseAccessPlan::CODES as $code) {
            $plan = $plans->get($code);
            if (!$plan?->is_active) {
                $issues[] = 'فعّل فئات الكورس الثلاث قبل النشر.';
                continue;
            }
            if (trim((string) $plan->name_ar) === '') {
                $issues[] = 'أضف اسمًا واضحًا لكل فئة سعرية.';
            }

            $price = max(0, (int) $plan->price_coins);
            if ($previousPrice !== null && $price < $previousPrice) {
                $issues[] = 'رتّب أسعار الفئات من الأقل إلى الأعلى.';
            }
            $previousPrice = $price;

            $feedback = (string) $plan->project_feedback_level;
            $hasProjectCost = in_array($feedback, [
                CourseAccessPlan::FEEDBACK_REPORT,
                CourseAccessPlan::FEEDBACK_ENHANCED,
            ], true);
            $hasVariableCost = (bool) $plan->chat_enabled || $hasProjectCost;
            if (
                (int) $plan->minimum_paid_coins > $price
                || ($hasVariableCost && (int) $plan->minimum_paid_coins <= 0)
            ) {
                $issues[] = "الفئة «{$plan->name_ar}» تحتاج حدًا مدفوعًا صالحًا لتغطية خدماتها";
            }

            // AI limits, token budgets and attachment ceilings are enforced by
            // the administrator-owned global plan policy when these rows are
            // saved. They are not course-authoring requirements and must not
            // leave a moderator blocked by controls that are intentionally
            // absent from the course editor.
        }

    }

    /**
     * Keep readiness wording and its editing destination owned by one service.
     * The dashboard can render the same audit without matching Arabic copy in
     * every Blade view.
     *
     * @param array<int, string> $issues
     * @return list<array{message:string,area:string}>
     */
    private function describeIssues(array $issues): array
    {
        return collect($issues)->unique()->values()->map(function (string $message): array {
            $area = match (true) {
                str_contains($message, 'غلاف') => 'image',
                str_contains($message, 'فئة'),
                str_contains($message, 'فئات'),
                str_contains($message, 'أسعار'),
                str_contains($message, 'حدًا مدفوعًا') => 'plans',
                str_contains($message, 'شهادة') => 'certificate',
                str_contains($message, 'نافذة') => 'settings',
                str_contains($message, 'مرفق'),
                str_contains($message, 'ملف') => 'attachments',
                str_contains($message, 'فيديو'),
                str_contains($message, 'مقطع'),
                str_contains($message, 'مدة') => 'media',
                str_contains($message, 'وحدة'),
                str_contains($message, 'جزء'),
                str_contains($message, 'محتوى'),
                str_contains($message, 'مشروع') => 'content',
                str_contains($message, 'محاضر'),
                str_contains($message, 'تصنيف'),
                str_contains($message, 'شارة') => 'settings',
                default => 'details',
            };

            return ['message' => $message, 'area' => $area];
        })->all();
    }
}
