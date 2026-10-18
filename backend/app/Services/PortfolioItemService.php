<?php

declare(strict_types=1);

namespace App\Services;

use App\Exceptions\PortfolioOperationException;
use App\Http\Resources\PortfolioItemResource;
use App\Models\Course;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\UnicodeText;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * A learner's own portfolio items: listing, the passed projects that can
 * become items, and item metadata writes. Media bytes, finalization and
 * deletion stay with PortfolioMediaAuthoringService and
 * PortfolioMediaMutationService; publication stays with
 * PortfolioModerationService. Every item answer carries which items are on
 * the live public portfolio.
 */
final readonly class PortfolioItemService
{
    public function __construct(
        private CourseEntitlementService $courseAccess,
        private CourseAccessPlanService $accessPlans,
        private PortfolioMediaMutationService $mediaMutations,
        private CourseRevisionLearnerReadService $revisionReads,
        private CourseRevisionResolver $revisionResolver,
        private PortfolioUploadAccessService $uploadAccess,
        private PortfolioReviewReadService $reviews
    ) {}

    /**
     * The owner's items, newest featured first. Without a page request the
     * established unpaginated mobile response is kept.
     *
     * @return array{items: AnonymousResourceCollection, pagination: ?array{current_page:int,last_page:int,per_page:int,total:int}}
     */
    public function list(User $user, bool $summary, ?int $page, ?int $perPage): array
    {
        $query = $user->portfolioItems()
            ->available()
            ->withCount('mediaFiles')
            ->with([
                'mediaFiles' => function ($media) use ($summary): void {
                    if ($summary) {
                        // The mobile gallery needs a cover only. Excluding video
                        // rows here also prevents PortfolioMediaResource from
                        // performing one Bunny inspection for every saved video.
                        $media->where('file_type', 'image')->limit(1);
                    }
                },
                'course',
            ])
            ->orderByDesc('is_featured')
            ->orderBy('sort_order')
            ->latest('id');
        $pagination = null;
        if ($page !== null || $perPage !== null) {
            $paginator = $query->paginate($perPage ?? 30, ['*'], 'page', $page ?? 1);
            $items = collect($paginator->items());
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        } else {
            $items = $query->get();
        }

        $liveItemIds = $this->liveItemIds($user);
        $resources = PortfolioItemResource::collection($items);
        $resources->collection->each(
            fn (PortfolioItemResource $resource) => $resource->withLiveItemIds($liveItemIds)
        );

        return ['items' => $resources, 'pagination' => $pagination];
    }

    /**
     * Passed course projects that have not yet been added to this portfolio.
     *
     * @return array{items: list<array<string, mixed>>, pagination: array<string, int>}
     */
    public function eligibleProjects(User $user, int $page, int $perPage): array
    {
        $usedProjectIds = $user->portfolioItems()
            ->whereNotNull('source_project_id')
            ->pluck('source_project_id');
        $usedCurrentProjectIds = collect($this->revisionResolver->currentLearnerEntityMap(
            Project::class,
            $usedProjectIds
        ))->values()->unique()->values();

        $submissionProjectIds = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->distinct()
            ->pluck('project_id');
        $candidateProjectIds = collect($this->revisionResolver->currentLearnerEntityMap(
            Project::class,
            $submissionProjectIds
        ))->values()->unique()->values();

        $projects = Project::query()
            ->whereIn('id', $candidateProjectIds)
            ->whereHas('section.course', fn ($courses) => $courses
                ->where('is_coming_soon', false))
            ->when($usedCurrentProjectIds->isNotEmpty(), fn ($query) => $query->whereNotIn('id', $usedCurrentProjectIds))
            ->with([
                'section.course:id,name_ar,name_en,image',
                'section.module:id,course_id,title,title_ar,title_en,order',
            ])->get()->keyBy('id');
        $eligible = $this->revisionReads
            ->projectSubmissions((int) $user->id, $projects->keys())
            ->filter(fn (ProjectSubmission $row): bool => $row->reviewOutcome()['passed'] && $this->submissionAllowsPortfolio($row))
            ->map(function (ProjectSubmission $row, int $currentId) use ($projects) {
                $row->setRelation('project', $projects->get($currentId));
                $row->project_id = $currentId;

                return $row;
            })->sortByDesc('updated_at')->values();
        $evaluations = new LengthAwarePaginator(
            $eligible->forPage($page, $perPage)->values(),
            $eligible->count(),
            $perPage,
            $page
        );

        return [
            'items' => collect($evaluations->items())->map(function (ProjectSubmission $submission) {
                $project = $submission->getRelation('project');
                $section = $project?->section;
                $outcome = $submission->reviewOutcome();

                return [
                    'project_id' => $project?->id,
                    'course_section_id' => $section?->id,
                    'title' => $section?->title,
                    'requirements' => $project?->requirements_text,
                    'course' => $section?->course ? [
                        'id' => $section->course->id,
                        'title' => $section->course->name_ar,
                        'title_en' => $section->course->name_en,
                        'image' => $section->course->image,
                    ] : null,
                    'module' => $section?->module ? [
                        'id' => $section->module->id,
                        'title' => $section->module->title,
                        'order' => $section->module->order,
                    ] : null,
                    'score' => $outcome['assessment_type'] === 'human_review'
                        ? $outcome['score']
                        : null,
                    'assessment_type' => $outcome['assessment_type'],
                    'skill_verified' => $outcome['skill_verified'],
                    'passed_at' => $outcome['reviewed_at'] ?? $submission->updated_at,
                ];
            })->values()->all(),
            'pagination' => [
                'current_page' => $evaluations->currentPage(),
                'last_page' => $evaluations->lastPage(),
                'per_page' => $evaluations->perPage(),
                'total' => $evaluations->total(),
            ],
        ];
    }

    /**
     * Creates one metadata-only item. The client request id makes a retry
     * after a lost response return the same item (replayed); reusing it for
     * different content is a 409. Lock order: the learner, then the source
     * project's course, then the item.
     *
     * @param  array{title:mixed, description:?string, course_id:?int, source_project_id:?int, role:mixed, tools:mixed, external_url:mixed, completed_at:mixed, is_featured:bool, sort_order:int, expected_media_count:int}  $input
     * @return array{item: PortfolioItemResource, replayed: bool}
     *
     * @throws LockTimeoutException while the same request is still being saved
     * @throws ValidationException when the source project or course cannot be linked
     */
    public function create(User $user, string $clientRequestId, array $input): array
    {
        $courseId = $input['course_id'];
        $requestedSourceProjectId = $input['source_project_id'];
        $currentSourceProjectId = $requestedSourceProjectId
            ? $this->currentProjectId($requestedSourceProjectId)
            : null;
        $equivalentSourceProjectIds = $currentSourceProjectId
            ? $this->logicalProjectIds($currentSourceProjectId)
            : [];
        $requestFingerprint = $this->requestFingerprint($input);

        return Cache::lock(
            'portfolio-item-create:'.$user->id.':'.strtolower($clientRequestId),
            30
        )->block(10, function () use (
            $user,
            $clientRequestId,
            $input,
            $requestFingerprint,
            $courseId,
            $requestedSourceProjectId,
            $currentSourceProjectId,
            $equivalentSourceProjectIds
        ): array {
            $existingRequest = $user->portfolioItems()
                ->with(['mediaFiles', 'course'])
                ->where('client_request_id', $clientRequestId)
                ->first();
            if ($existingRequest) {
                abort_unless(
                    hash_equals((string) $existingRequest->request_fingerprint, $requestFingerprint),
                    409
                );

                return ['item' => $this->resource($existingRequest), 'replayed' => true];
            }

            $this->uploadAccess->assertAllowed($user);

            $sourceProject = null;
            $sourceSection = null;
            if ($currentSourceProjectId) {
                if ($user->portfolioItems()
                    ->whereIn('source_project_id', $equivalentSourceProjectIds)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'source_project_id' => ['This project is already in your portfolio.'],
                    ]);
                }

                $sourceProject = $this->currentPublishedProject($currentSourceProjectId);
                $sourceSection = $sourceProject->getRelation('section');
                $sourceSubmission = $this->revisionReads->projectSubmissions(
                    (int) $user->id,
                    [$currentSourceProjectId]
                )->get($currentSourceProjectId);
                if (
                    ! $sourceSubmission
                    || ! $sourceSubmission->reviewOutcome()['passed']
                    || ! $this->submissionAllowsPortfolio($sourceSubmission)
                    || ! $sourceSection
                ) {
                    throw ValidationException::withMessages([
                        'source_project_id' => ['Only a passed project can be added to the portfolio.'],
                    ]);
                }

                $projectCourseId = (int) $sourceSection->course_id;
                if ($courseId && $courseId !== $projectCourseId) {
                    throw ValidationException::withMessages([
                        'course_id' => ['The course does not match the selected project.'],
                    ]);
                }
                $courseId = $projectCourseId;
            } elseif ($courseId && ! $this->courseAccess->hasLearningAccess(
                (int) $user->id,
                $courseId
            )) {
                throw ValidationException::withMessages([
                    'course_id' => ['Only one of your courses can be linked to this portfolio item.'],
                ]);
            }

            $itemTitle = trim((string) $input['title']);
            if ($itemTitle === '') {
                $itemTitle = trim((string) ($sourceSection?->title ?: 'مشروع تطبيقي'));
            }
            $itemDescription = $input['description'] ?? (string) ($sourceProject->requirements_text ?? '');

            $item = DB::transaction(function () use (
                $user,
                $clientRequestId,
                $input,
                $courseId,
                $itemTitle,
                $itemDescription,
                $requestFingerprint,
                $requestedSourceProjectId
            ): PortfolioItem {
                $lockedUser = User::query()
                    ->whereKey($user->id)
                    ->lockForUpdate()
                    ->first();
                abort_if(! $lockedUser, 404);

                $existing = $lockedUser->portfolioItems()
                    ->with(['mediaFiles', 'course'])
                    ->where('client_request_id', $clientRequestId)
                    ->first();
                if ($existing) {
                    abort_unless(
                        hash_equals((string) $existing->request_fingerprint, $requestFingerprint),
                        409
                    );

                    return $existing;
                }

                $lockedCurrentSourceProjectId = $requestedSourceProjectId
                    ? $this->currentProjectId($requestedSourceProjectId)
                    : null;
                $currentEquivalentSourceProjectIds = $lockedCurrentSourceProjectId
                    ? $this->logicalProjectIds($lockedCurrentSourceProjectId)
                    : [];
                if ($currentEquivalentSourceProjectIds !== [] && $lockedUser->portfolioItems()
                    ->whereIn('source_project_id', $currentEquivalentSourceProjectIds)->exists()) {
                    throw ValidationException::withMessages([
                        'source_project_id' => ['This project is already in your portfolio.'],
                    ]);
                }

                $lockedCourseId = $courseId;
                if ($lockedCurrentSourceProjectId) {
                    $lockedSourceProject = $this->currentPublishedProject($lockedCurrentSourceProjectId);
                    $lockedCourse = Course::query()
                        ->whereKey($lockedSourceProject->getRelation('section')->course_id)
                        ->lockForUpdate()
                        ->firstOrFail();
                    $lockedCurrentSourceProjectId = $this->currentProjectId($requestedSourceProjectId);
                    $lockedSection = $this->currentPublishedProject($lockedCurrentSourceProjectId)->getRelation('section');
                    abort_unless(
                        (int) $lockedSection->course_id === (int) $lockedCourse->id,
                        409
                    );
                    $sourceSubmission = $this->revisionReads->projectSubmissions(
                        (int) $lockedUser->id,
                        [$lockedCurrentSourceProjectId]
                    )->get($lockedCurrentSourceProjectId);
                    if (
                        ! $sourceSubmission
                        || ! $sourceSubmission->reviewOutcome()['passed']
                        || ! $this->submissionAllowsPortfolio($sourceSubmission)
                        || ! $lockedSection
                    ) {
                        throw ValidationException::withMessages([
                            'source_project_id' => ['Only a passed project can be added to the portfolio.'],
                        ]);
                    }
                    $projectCourseId = (int) $lockedSection->course_id;
                    if ($lockedCourseId && $lockedCourseId !== $projectCourseId) {
                        throw ValidationException::withMessages([
                            'course_id' => ['The course does not match the selected project.'],
                        ]);
                    }
                    $lockedCourseId = $projectCourseId;
                }

                return $lockedUser->portfolioItems()->create([
                    'client_request_id' => $clientRequestId,
                    'request_fingerprint' => $requestFingerprint,
                    'title' => $itemTitle,
                    'description' => $itemDescription,
                    'course_id' => $lockedCourseId,
                    'source_project_id' => $lockedCurrentSourceProjectId,
                    'slug' => $this->portfolioItemSlug($itemTitle),
                    'role' => $input['role'],
                    'tools' => $input['tools'],
                    'external_url' => $input['external_url'],
                    'completed_at' => $input['completed_at'],
                    'is_featured' => $input['is_featured'],
                    'sort_order' => $input['sort_order'],
                    'expected_media_count' => $input['expected_media_count'],
                    'is_public' => false,
                ]);
            }, 3);

            $item->load(['mediaFiles', 'course']);

            return ['item' => $this->resource($item), 'replayed' => false];
        });
    }

    public function show(User $user, int|string $id): ?PortfolioItemResource
    {
        $item = $user->portfolioItems()
            ->available()
            ->withCount('mediaFiles')
            ->with(['mediaFiles', 'course'])
            ->find($id);

        return $item ? $this->resource($item) : null;
    }

    /**
     * Owner edits. is_public=false hides the project from the public link
     * at once, without deleting it or waiting for review.
     *
     * @param  array<string, mixed>  $changes  Only the editable fields the request carried.
     * @param  array{is_public?: bool, cover_media_id?: ?int}  $presentation  Only the presentation fields the request carried.
     */
    public function update(User $user, int|string $id, array $changes, array $presentation): PortfolioItemResource
    {
        $item = DB::transaction(function () use ($user, $id, $changes, $presentation): PortfolioItem {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();
            abort_if(! $lockedUser, 404);
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($id);
            abort_if(! $item, 404);
            abort_if($item->deletion_started_at !== null, 409);

            $attributes = [];
            if (array_key_exists('is_public', $presentation)) {
                $attributes['hidden_at'] = $presentation['is_public'] ? null : ($item->hidden_at ?? now());
            }
            if (array_key_exists('cover_media_id', $presentation)) {
                $coverId = $presentation['cover_media_id'];
                if ($coverId !== null && ! $item->mediaFiles()->available()
                    ->where('file_type', 'image')->whereKey($coverId)->exists()) {
                    throw ValidationException::withMessages([
                        'cover_media_id' => ['اختر صورة الغلاف من صور هذا المشروع'],
                    ]);
                }
                $attributes['cover_media_id'] = $coverId;
            }
            $item->update($attributes + $changes + (filled($changes['title'] ?? null)
                ? ['slug' => $this->portfolioItemSlug($changes['title'], (string) $item->slug)]
                : []));

            return $item->fresh(['mediaFiles', 'course']);
        }, 3);

        return $this->resource($item);
    }

    /**
     * Publishes an intentionally shortened upload after at least one file landed.
     *
     * @throws PortfolioOperationException when the item has no file yet or its media is still processing
     */
    public function finalize(User $user, int $id): PortfolioItemResource
    {
        return $this->resource($this->mediaMutations->finalize($user, $id));
    }

    private function resource(PortfolioItem $item): PortfolioItemResource
    {
        $owner = $item->relationLoaded('user') ? $item->getRelation('user') : User::query()->find($item->user_id);

        return (new PortfolioItemResource($item))->withLiveItemIds($owner ? $this->liveItemIds($owner) : []);
    }

    /** @return list<int> */
    private function liveItemIds(User $owner): array
    {
        $live = $this->reviews->livePublication($owner);

        return array_map(static fn (array $project): int => (int) $project['item']->id, $live['projects'] ?? []);
    }

    /** @param array<string, mixed> $input */
    private function requestFingerprint(array $input): string
    {
        return hash('sha256', json_encode([
            'title' => trim((string) $input['title']),
            'description' => trim((string) $input['description']),
            'course_id' => $input['course_id'],
            'source_project_id' => $input['source_project_id'],
            'role' => trim((string) $input['role']),
            'tools' => array_values((array) ($input['tools'] ?? [])),
            'external_url' => trim((string) $input['external_url']),
            'completed_at' => $input['completed_at'],
            'is_featured' => $input['is_featured'],
            'sort_order' => $input['sort_order'],
            'expected_media_count' => $input['expected_media_count'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function portfolioItemSlug(mixed $title, string $fallback = ''): string
    {
        $slug = Str::slug(UnicodeText::clean($title, false));
        if ($slug !== '') {
            return $slug;
        }
        if ($fallback !== '') {
            return $fallback;
        }

        return 'item-'.Str::lower((string) Str::uuid());
    }

    /** @return list<int> */
    private function logicalProjectIds(int $projectId): array
    {
        $currentProjectId = $this->revisionResolver->currentLearnerEntityMap(
            Project::class,
            [$projectId]
        )[$projectId] ?? $projectId;

        return $this->revisionResolver->equivalentEntityIds(
            Project::class,
            (int) $currentProjectId
        );
    }

    private function currentProjectId(int $projectId): int
    {
        return (int) ($this->revisionResolver->currentLearnerEntityMap(
            Project::class,
            [$projectId]
        )[$projectId] ?? $projectId);
    }

    private function currentPublishedProject(int $projectId): Project
    {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $projectId = $this->currentProjectId($projectId);
            $project = Project::query()
                ->whereKey($projectId)
                ->whereHas('section.course', fn ($courses) => $courses
                    ->where('is_coming_soon', false))
                ->with('section')
                ->first();
            if ($project) {
                return $project;
            }
        }

        throw (new ModelNotFoundException)->setModel(Project::class, [$projectId]);
    }

    private function submissionAllowsPortfolio(ProjectSubmission $submission): bool
    {
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = $snapshot ? data_get($snapshot, 'access.terms') : null;
        if (! is_array($terms)) {
            return false;
        }

        return (bool) ($this->accessPlans->publicPayloadFromTerms($terms)['project_output_enabled'] ?? false);
    }
}
