<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Classification;
use App\Models\Course;
use App\Models\CourseAuthoringRevision;
use App\Models\CourseRating;
use App\Models\DesignSetting;
use App\Models\Level;
use App\Models\Path;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

final readonly class AdminCoursePageService
{
    public function __construct(
        private ArabicSearchNormalizer $searchNormalizer,
        private CourseAccessPlanService $accessPlans,
        private CourseCommercialReportService $commercialReports,
        private CourseDurationService $durations,
        private CourseFinancialLedgerReportService $financialLedger,
        private CourseLearningHealthService $learningHealth,
        private CoursePublishingService $publishing,
        private CourseStagedAuthoringService $stagedAuthoring,
        private AdminCourseReportService $reports,
        private CertificateTextTemplateService $certificateTemplates,
        private AdminCourseOutlinePresenter $outline,
        private AdminCoursePdfPresenter $pdfPresenter
    ) {
    }

    /** @param array<string, mixed> $filters @return array<string, mixed> */
    public function index(array $filters, bool $administrator): array
    {
        $filters['state'] = $administrator ? (string) ($filters['state'] ?? 'active') : 'active';
        $query = ($administrator && $filters['state'] !== 'active'
                ? Course::withTrashed()
                : Course::query())
            ->with(['photo', 'classifications', 'accessPlans'])
            ->withCount([
                'sections',
                'lessons as preview_steps_count' => fn ($lessons) => $lessons->where('is_opened', true),
            ])
            ->whereNotIn('courses.id', CourseAuthoringRevision::query()->select('revision_course_id'));

        $this->applyState($query, (string) $filters['state'], $administrator);
        $this->applySearch($query, (string) ($filters['search'] ?? ''));
        if (!empty($filters['classification_id'])) {
            $query->whereHas(
                'classifications',
                fn ($classifications) => $classifications->whereKey((int) $filters['classification_id'])
            );
        }
        if ($administrator) {
            $query->withCount(['activeEnrollments', 'ratings'])->withAvg('ratings', 'rating');
        }

        $courses = $query->latest('updated_at')->latest('id')->paginate(24)->withQueryString();
        $courseIds = collect($courses->getCollection()->modelKeys())->map(fn ($id): int => (int) $id);
        $activeRevisions = CourseAuthoringRevision::query()
            ->where('status', CourseAuthoringRevision::DRAFT)
            ->whereIn('canonical_course_id', $courseIds)
            ->get(['canonical_course_id', 'revision_course_id'])
            ->keyBy('canonical_course_id');
        $drafts = Course::query()
            ->whereIn('id', $activeRevisions->pluck('revision_course_id'))
            ->get()
            ->keyBy('id');
        $authoringCourses = $courses->getCollection()->mapWithKeys(function (Course $course) use (
            $activeRevisions,
            $drafts
        ): array {
            $revision = $activeRevisions->get((int) $course->id);
            $draft = $revision ? $drafts->get((int) $revision->revision_course_id) : null;

            return [(int) $course->id => $draft ?: $course];
        });
        if ($administrator) {
            $summaries = $this->financialLedger->courseSummaries(
                collect($courses->getCollection()->modelKeys())
            );
            $courses->getCollection()->each(function (Course $course) use ($summaries): void {
                $summary = $summaries->get((int) $course->id, []);
                $course->setAttribute('total_coins_spent', (int) ($summary['total_coins'] ?? 0));
                $course->setAttribute('paid_coins_spent', (int) ($summary['paid_coins'] ?? 0));
                $course->setAttribute('reward_coins_spent', (int) ($summary['reward_coins'] ?? 0));
                $course->setAttribute('coin_ledger_incomplete_orders', (int) ($summary['incomplete_orders'] ?? 0));
            });
        }

        return [
            'courses' => $courses,
            'courseAuthoringEntryIds' => $authoringCourses->map(
                fn (Course $authoringCourse): int => (int) $authoringCourse->id
            ),
            'courseHasActiveDrafts' => $activeRevisions->keys()
                ->mapWithKeys(fn ($courseId): array => [(int) $courseId => true]),
            'designSettings' => DesignSetting::getDefaultSettings(),
            'canViewFinance' => $administrator,
            'classificationOptions' => Classification::query()
                ->orderBy('home_order')->orderBy('name_ar')->orderBy('id')
                ->get(['id', 'name_ar', 'name_en']),
            'filters' => $filters,
        ];
    }

    /** @return array<string, mixed> */
    public function show(
        Course $course,
        bool $administrator,
        bool $canManageHero,
        int $commercialPage = 1,
        bool $loadCommercialReport = true
    ): array {
        // A canonical URL remains a stable bookmark, but once a moderator has
        // saved a working revision the studio must resume it rather than show
        // the older learner copy and make the new edits appear lost.
        $course = $this->stagedAuthoring->activeDraftFor($course) ?: $course;
        $course->load([
            'classifications',
            'teachers.photo',
            'level',
            'photo',
            'accessPlans',
            'pdfs',
            'modules' => fn ($modules) => $modules->with([
                'sections' => fn ($sections) => $sections->with('sectionable')->orderBy('order'),
            ])->orderBy('order'),
        ]);
        $sections = $course->modules
            ->flatMap(fn ($module) => $module->sections)
            ->values();
        $course->setRelation('sections', $sections);
        $reportCourse = $this->stagedAuthoring->canonicalFor($course);
        $reportCourse->loadCount('ratings')->loadAvg('ratings', 'rating');
        $catalogRatingSummary = [
            'count' => (int) $reportCourse->ratings_count,
            'average' => $reportCourse->ratings_count > 0
                ? round((float) $reportCourse->ratings_avg_rating, 1)
                : null,
        ];
        if ($administrator) {
            $reportCourse->loadCount('activeEnrollments');
        }
        $this->durations->attach($course);
        $managedDraft = $this->stagedAuthoring->isManagedDraft($course);
        $editorPlans = $this->accessPlans->plansForEditor($course);
        $course->setRelation('accessPlans', $editorPlans);

        $commercialReport = $administrator && $loadCommercialReport
            ? $this->paginatedCommercialReport($reportCourse, $commercialPage)
            : null;

        return [
            ...$this->formOptions($course),
            'course' => $course,
            'sections' => $sections,
            'publishingAudit' => $this->publishing->audit($course),
            'commercialReport' => $commercialReport,
            'canViewCommercialReport' => $administrator,
            'accessPlans' => $editorPlans,
            'planStats' => $administrator ? $this->reports->accessPlanStats($course) : collect(),
            'catalogVisibilityDefault' => $managedDraft
                ? (bool) $reportCourse->is_catalog_visible
                : (bool) $course->is_catalog_visible,
            'mainCourseDefault' => $managedDraft
                ? ($this->stagedAuthoring->explicitHeroSelection($course)
                    ?? (bool) $reportCourse->is_main_course)
                : (bool) $course->is_main_course,
            'hasPublishedRevision' => (int) ($reportCourse->last_published_authoring_version ?? 0) > 0
                || $reportCourse->published_at !== null,
            'canManageHero' => $canManageHero,
            'authoringGraph' => $this->outline->graph($course),
            'coursePdfs' => $course->pdfs
                ->map(fn ($pdf): array => $this->pdfPresenter->one($course, $pdf))
                ->values(),
            'coursePdfStoreUrl' => route('admin.courses.pdfs.store', $course),
            'coursePdfReorderUrl' => route('admin.courses.pdfs.reorder', $course),
            'coursePdfMaxOrder' => (int) ($course->pdfs->max('order') ?? 0),
            'activeStudentsCount' => $administrator
                ? (int) $reportCourse->active_enrollments_count
                : null,
            'catalogRatingSummary' => $catalogRatingSummary,
            'learningHealthSummary' => $administrator
                ? $this->learningHealth->forCourse($reportCourse)
                : null,
            'ratingSummary' => $administrator ? [
                ...$catalogRatingSummary,
                'removed_count' => CourseRating::onlyTrashed()
                    ->where('course_id', $reportCourse->id)
                    ->count(),
            ] : null,
            'previewPlans' => $course->accessPlans
                ->where('is_active', true)
                ->sortBy([['sort_order', 'asc'], ['id', 'asc']])
                ->map(fn ($plan): array => $this->accessPlans->publicPayload($plan))
                ->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function paginatedCommercialReport(Course $course, int $page): array
    {
        $report = $this->commercialReports->forCourse($course);
        $rows = collect($report['rows']);
        $perPage = 25;
        $lastPage = max(1, (int) ceil($rows->count() / $perPage));
        $page = min(max(1, $page), $lastPage);
        $report['student_rows'] = (new LengthAwarePaginator(
            $rows->forPage($page, $perPage)->values(),
            $rows->count(),
            $perPage,
            $page,
            [
                'path' => route('admin.courses.show', $course),
                'pageName' => 'commercial_page',
            ]
        ))->appends('tab', 'commercial-report')->fragment('commercial-report');
        unset($report['rows']);

        return $report;
    }

    /** @return array<string, mixed> */
    private function formOptions(Course $course): array
    {
        $existingAdminInstructorIds = $course->teachers
            ->filter(fn (User $teacher): bool => $teacher->role === 'admin')
            ->modelKeys();

        return [
            'enableEnglish' => (bool) (Setting::query()->value('english_translation') ?? false),
            'classifications' => Classification::query()->orderBy('home_order')->orderBy('id')->get(),
            'levels' => Level::ordered()->get(),
            'designSettings' => DesignSetting::getDefaultSettings(),
            'teachers' => User::query()
                ->where(function (Builder $teachers) use ($existingAdminInstructorIds): void {
                    $teachers->where('role', 'teacher');
                    if ($existingAdminInstructorIds !== []) {
                        $teachers->orWhereIn('id', $existingAdminInstructorIds);
                    }
                })
                ->with('photo')
                ->orderBy('name_ar')->orderBy('id')->get(),
            'paths' => Path::query()->orderBy('title_ar')->orderBy('id')->get(),
            'certificateTextTemplates' => $this->certificateTemplates->catalogue(),
        ];
    }

    private function applyState(Builder $query, string $state, bool $administrator): void
    {
        if ($state === 'active') {
            $query->where(fn (Builder $current) => $current
                ->where('is_coming_soon', true)
                ->orWhere('is_catalog_visible', true));
            return;
        }
        if (!$administrator || $state !== 'archived') {
            return;
        }

        $query->where(fn (Builder $archived) => $archived
            ->whereNotNull('courses.deleted_at')
            ->orWhere(fn (Builder $retired) => $retired
                ->whereNull('courses.deleted_at')
                ->where('is_coming_soon', false)
                ->where('is_catalog_visible', false)));
    }

    private function applySearch(Builder $query, string $raw): void
    {
        $raw = trim($raw);
        if ($raw === '') {
            return;
        }
        $literal = addcslashes($raw, '\\%_');
        $normalized = $this->searchNormalizer->normalize($raw);
        $tokens = array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $token): bool => mb_strlen($token) >= 2
        )));

        $query->where(function (Builder $search) use ($literal, $normalized, $tokens): void {
            $search->where('name_ar', 'like', "%{$literal}%")
                ->orWhere('name_en', 'like', "%{$literal}%")
                ->orWhere('description_ar', 'like', "%{$literal}%")
                ->orWhere('description_en', 'like', "%{$literal}%");
            if ($normalized === '') {
                return;
            }
            $search->orWhere('search_terms_normalized', 'like', '%'.addcslashes($normalized, '\\%_').'%');
            if ($tokens !== []) {
                $search->orWhere(function (Builder $allTokens) use ($tokens): void {
                    foreach ($tokens as $token) {
                        $allTokens->where(
                            'search_terms_normalized',
                            'like',
                            '%'.addcslashes($token, '\\%_').'%'
                        );
                    }
                });
            }
        });
    }
}
