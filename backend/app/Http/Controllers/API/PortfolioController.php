<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortfolioOperationException;
use App\Http\Controllers\Controller;
use App\Http\Resources\PortfolioItemResource;
use App\Models\Course;
use App\Models\PortfolioItem;
use App\Models\Project;
use App\Models\ProjectSubmission;
use App\Models\User;
use App\Services\CourseChatAccessService;
use App\Services\CourseAccessPlanService;
use App\Services\CourseRevisionLearnerReadService;
use App\Services\CourseStagedAuthoringService;
use App\Services\PortfolioMediaMutationService;
use App\Services\SafeExternalUrl;
use App\Support\ProjectSubmissionEvaluationSnapshot;
use App\Support\UnicodeText;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PortfolioController extends Controller
{
    public function __construct(
        private CourseChatAccessService $courseAccess,
        private CourseAccessPlanService $accessPlans,
        private PortfolioMediaMutationService $mediaMutations,
        private CourseRevisionLearnerReadService $revisionReads,
        private CourseStagedAuthoringService $stagedAuthoring
    ) {
    }

    /**
     * List user's portfolio items.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'summary' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);
        $user = auth('api')->user();
        $summary = $request->boolean('summary');
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
        if ($request->filled('page') || $request->filled('per_page')) {
            $paginator = $query->paginate(
                (int) ($validated['per_page'] ?? 30),
                ['*'],
                'page',
                (int) ($validated['page'] ?? 1)
            );
            $items = collect($paginator->items());
            $pagination = [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ];
        } else {
            // Keep the established mobile response intact. Clients that can
            // render large histories opt into the page contract above.
            $items = $query->get();
        }

        $payload = [
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المشروعات',
            'data' => PortfolioItemResource::collection($items),
        ];
        if ($pagination !== null) {
            $payload['pagination'] = $pagination;
        }

        return response()->json($payload);
    }

    /** Passed course projects that have not yet been added to this portfolio. */
    public function eligibleProjects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth('api')->user();
        $usedProjectIds = $user->portfolioItems()
            ->whereNotNull('source_project_id')
            ->pluck('source_project_id');
        $usedCurrentProjectIds = collect($this->stagedAuthoring->currentLearnerEntityMap(
            Project::class,
            $usedProjectIds
        ))->values()->unique()->values();

        $submissionProjectIds = ProjectSubmission::query()
            ->where('user_id', $user->id)
            ->where('review_status', ProjectSubmission::STATUS_PASSED)
            ->distinct()
            ->pluck('project_id');
        $candidateProjectIds = collect($this->stagedAuthoring->currentLearnerEntityMap(
            Project::class,
            $submissionProjectIds
        ))->values()->unique()->values();

        $projects = Project::query()
            ->whereIn('id', $candidateProjectIds)
            ->whereHas('section.course', fn ($courses) => $courses
                ->where('is_coming_soon', false))
            ->when($usedCurrentProjectIds->isNotEmpty(), fn ($query) =>
                $query->whereNotIn('id', $usedCurrentProjectIds)
            )
            ->with([
                'section.course:id,name_ar,name_en,image',
                'section.module:id,course_id,title,title_ar,title_en,order',
            ])->get()->keyBy('id');
        $eligible = $this->revisionReads
            ->projectSubmissions((int) $user->id, $projects->keys())
            ->filter(fn (ProjectSubmission $row): bool =>
                $row->reviewOutcome()['passed'] && $this->submissionAllowsPortfolio($row)
            )
            ->map(function (ProjectSubmission $row, int $currentId) use ($projects) {
                $row->setRelation('project', $projects->get($currentId));
                $row->project_id = $currentId;
                return $row;
            })->sortByDesc('updated_at')->values();
        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = max(1, (int) $request->input('page', 1));
        $evaluations = new LengthAwarePaginator(
            $eligible->forPage($page, $perPage)->values(),
            $eligible->count(),
            $perPage,
            $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المشروعات المتاحة',
            'data' => [
                'items' => collect($evaluations->items())->map(function (ProjectSubmission $submission) {
                    $project = $submission->project;
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
                })->values(),
                'pagination' => [
                    'current_page' => $evaluations->currentPage(),
                    'last_page' => $evaluations->lastPage(),
                    'per_page' => $evaluations->perPage(),
                    'total' => $evaluations->total(),
                ],
            ],
        ]);
    }

    /**
     * Store a new portfolio item.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizePortfolioInput($request);
        if (!$request->filled('client_request_id')) {
            $candidate = trim((string) $request->header('Idempotency-Key'));
            $request->merge([
                'client_request_id' => $candidate !== '' ? $candidate : (string) Str::uuid(),
            ]);
        }
        $this->assertIdempotencyHeaderMatches($request, 'client_request_id');
        $request->validate([
            'client_request_id' => 'required|uuid',
            'title' => 'nullable|required_without:source_project_id|string|max:255',
            'description' => 'nullable|string',
            'course_id' => 'nullable|integer|exists:courses,id',
            'source_project_id' => 'nullable|integer|exists:projects,id',
            'role' => 'nullable|string|max:255',
            'tools' => 'nullable|array|max:20',
            'tools.*' => 'string|max:80',
            'external_url' => ['nullable', 'string', 'max:2000', SafeExternalUrl::validationRule()],
            'completed_at' => 'nullable|date',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000',
            'expected_media_count' => 'nullable|integer|min:0|max:12',
            // Item creation is metadata-only. Images use /media and videos
            // use the resumable direct-upload contract; silently accepting an
            // inline file here would create an empty item on a modern client.
            'files' => 'prohibited',
            'file_types' => 'prohibited',
        ]);

        $user = auth('api')->user();
        $courseId = $request->filled('course_id') ? $request->integer('course_id') : null;
        $requestedSourceProjectId = $request->filled('source_project_id')
            ? $request->integer('source_project_id')
            : null;
        $currentSourceProjectId = $requestedSourceProjectId
            ? $this->currentProjectId($requestedSourceProjectId)
            : null;
        $sourceProject = null;
        $equivalentSourceProjectIds = $currentSourceProjectId
            ? $this->logicalProjectIds($currentSourceProjectId)
            : [];

        $requestFingerprint = hash('sha256', json_encode([
            'title' => trim((string) $request->input('title')),
            'description' => trim((string) $request->input('description')),
            'course_id' => $courseId,
            'source_project_id' => $request->filled('source_project_id')
                ? $request->integer('source_project_id')
                : null,
            'role' => trim((string) $request->input('role')),
            'tools' => array_values((array) $request->input('tools', [])),
            'external_url' => trim((string) $request->input('external_url')),
            'completed_at' => $request->input('completed_at'),
            'is_featured' => $request->boolean('is_featured'),
            'sort_order' => $request->integer('sort_order', 0),
            'expected_media_count' => $request->integer(
                'expected_media_count',
                0
            ),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $clientRequestId = $request->string('client_request_id')->toString();
        try {
            return Cache::lock(
                'portfolio-item-create:' . $user->id . ':' . strtolower($clientRequestId),
                30
            )->block(10, function () use (
                $user,
                $request,
                $requestFingerprint,
                $courseId,
                $requestedSourceProjectId,
                $currentSourceProjectId,
                $sourceProject,
                $equivalentSourceProjectIds
            ): JsonResponse {
                $existingRequest = $user->portfolioItems()
                    ->with(['mediaFiles', 'course'])
                    ->where('client_request_id', $request->string('client_request_id')->toString())
                    ->first();
                if ($existingRequest) {
                    abort_unless(
                        hash_equals((string) $existingRequest->request_fingerprint, $requestFingerprint),
                        409
                    );
                    return $this->createdItemResponse($existingRequest, true);
                }

                if ($currentSourceProjectId) {
                    if ($user->portfolioItems()
                        ->whereIn('source_project_id', $equivalentSourceProjectIds)
                        ->exists()) {
                        throw ValidationException::withMessages([
                            'source_project_id' => ['This project is already in your portfolio.'],
                        ]);
                    }

                    $sourceProject = $this->currentPublishedProject($currentSourceProjectId);
                    $sourceSubmission = $this->revisionReads->projectSubmissions(
                        (int) $user->id,
                        [$currentSourceProjectId]
                    )->get($currentSourceProjectId);
                    if (
                        !$sourceSubmission
                        || !$sourceSubmission->reviewOutcome()['passed']
                        || !$this->submissionAllowsPortfolio($sourceSubmission)
                        || !$sourceProject->section
                    ) {
                        throw ValidationException::withMessages([
                            'source_project_id' => ['Only a passed project can be added to the portfolio.'],
                        ]);
                    }

                    $projectCourseId = (int) $sourceProject->section->course_id;
                    if ($courseId && $courseId !== $projectCourseId) {
                        throw ValidationException::withMessages([
                            'course_id' => ['The course does not match the selected project.'],
                        ]);
                    }
                    $courseId = $projectCourseId;
                } elseif ($courseId && !$this->courseAccess->hasLearningAccess(
                    (int) $user->id,
                    $courseId
                )) {
                    throw ValidationException::withMessages([
                        'course_id' => ['Only one of your courses can be linked to this portfolio item.'],
                    ]);
                }

                $itemTitle = trim((string) $request->input('title'));
                if ($itemTitle === '') {
                    $itemTitle = trim((string) ($sourceProject?->section?->title ?: 'مشروع تطبيقي'));
                }
                $itemDescription = $request->filled('description')
                    ? (string) $request->input('description')
                    : (string) ($sourceProject?->requirements_text ?? '');

                $item = DB::transaction(function () use (
                    $user,
                    $request,
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
                    abort_if(!$lockedUser, 404);

                    $existing = $lockedUser->portfolioItems()
                        ->with(['mediaFiles', 'course'])
                        ->where('client_request_id', $request->string('client_request_id')->toString())
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
                            ->whereKey($lockedSourceProject->section->course_id)
                            ->lockForUpdate()
                            ->firstOrFail();
                        $lockedCurrentSourceProjectId = $this->currentProjectId($requestedSourceProjectId);
                        $lockedSourceProject = $this->currentPublishedProject($lockedCurrentSourceProjectId);
                        abort_unless(
                            (int) $lockedSourceProject->section->course_id === (int) $lockedCourse->id,
                            409
                        );
                        $sourceSubmission = $this->revisionReads->projectSubmissions(
                            (int) $lockedUser->id,
                            [$lockedCurrentSourceProjectId]
                        )->get($lockedCurrentSourceProjectId);
                        if (
                            !$sourceSubmission
                            || !$sourceSubmission->reviewOutcome()['passed']
                            || !$this->submissionAllowsPortfolio($sourceSubmission)
                            || !$lockedSourceProject->section
                        ) {
                            throw ValidationException::withMessages([
                                'source_project_id' => ['Only a passed project can be added to the portfolio.'],
                            ]);
                        }
                        $projectCourseId = (int) $lockedSourceProject->section->course_id;
                        if ($lockedCourseId && $lockedCourseId !== $projectCourseId) {
                            throw ValidationException::withMessages([
                                'course_id' => ['The course does not match the selected project.'],
                            ]);
                        }
                        $lockedCourseId = $projectCourseId;
                    }

                    return $lockedUser->portfolioItems()->create([
                        'client_request_id' => $request->string('client_request_id')->toString(),
                        'request_fingerprint' => $requestFingerprint,
                        'title' => $itemTitle,
                        'description' => $itemDescription,
                        'course_id' => $lockedCourseId,
                        'source_project_id' => $lockedCurrentSourceProjectId,
                        'slug' => $this->portfolioItemSlug($itemTitle),
                        'role' => $request->input('role'),
                        'tools' => $request->input('tools'),
                        'external_url' => $request->input('external_url'),
                        'completed_at' => $request->input('completed_at'),
                        'is_featured' => $request->boolean('is_featured'),
                        'sort_order' => $request->integer('sort_order', 0),
                        'expected_media_count' => $request->integer('expected_media_count', 0),
                        'is_public' => false,
                    ]);
                }, 3);

                $item->load(['mediaFiles', 'course']);

                return $this->createdItemResponse($item, false);
            });
        } catch (LockTimeoutException) {
            return $this->error("جارٍ حفظ هذا المشروع\nحاول بعد قليل", 409);
        }
    }

    private function createdItemResponse(PortfolioItem $item, bool $replayed): JsonResponse
    {
        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تمت إضافة المشروع',
            'data' => new PortfolioItemResource($item),
            'replayed' => $replayed,
        ]);
    }

    /**
     * Show a single portfolio item.
     */
    public function show($id): JsonResponse
    {
        $user = auth('api')->user();
        $item = $user->portfolioItems()
            ->available()
            ->withCount('mediaFiles')
            ->with(['mediaFiles', 'course'])
            ->find($id);

        if (!$item) {
            return $this->error('المشروع غير متاح', 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المشروع',
            'data' => new PortfolioItemResource($item),
        ]);
    }

    /**
     * Update a portfolio item.
     */
    public function update(Request $request, $id): JsonResponse
    {
        $this->normalizePortfolioInput($request);
        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'role' => 'nullable|string|max:255',
            'tools' => 'nullable|array|max:20',
            'tools.*' => 'string|max:80',
            'external_url' => ['nullable', 'string', 'max:2000', SafeExternalUrl::validationRule()],
            'completed_at' => 'nullable|date',
            'is_featured' => 'nullable|boolean',
            'sort_order' => 'nullable|integer|min:0|max:10000',
        ]);

        $user = auth('api')->user();
        $item = DB::transaction(function () use ($user, $id, $request): PortfolioItem {
            $lockedUser = User::query()
                ->whereKey($user->id)
                ->lockForUpdate()
                ->first();
            abort_if(!$lockedUser, 404);
            $item = $lockedUser->portfolioItems()->lockForUpdate()->find($id);
            abort_if(!$item, 404);
            abort_if($item->deletion_started_at !== null, 409);

            $item->update($request->only([
                'title', 'description', 'role', 'tools', 'external_url', 'completed_at',
                'is_featured', 'sort_order',
            ]) + ($request->filled('title')
                ? ['slug' => $this->portfolioItemSlug(
                    $request->input('title'),
                    (string) $item->slug
                )]
                : []));

            return $item->fresh(['mediaFiles', 'course']);
        }, 3);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحديث المشروع',
            'data' => new PortfolioItemResource($item),
        ]);
    }

    /**
     * Delete a portfolio item.
     */
    public function destroy($id): JsonResponse
    {
        $user = auth('api')->user();
        $deleted = $this->mediaMutations->deleteItem($user, (int) $id);

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم حذف المشروع',
            'data' => ['already_deleted' => !$deleted],
        ]);
    }

    /** Publish an intentionally shortened upload after at least one file landed. */
    public function finalize($id): JsonResponse
    {
        $user = auth('api')->user();
        try {
            $item = $this->mediaMutations->finalize($user, (int) $id);
        } catch (PortfolioOperationException $exception) {
            return $this->portfolioOperationError($exception);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم نشر المشروع',
            'data' => new PortfolioItemResource($item),
        ]);
    }

    private function normalizePortfolioInput(Request $request): void
    {
        foreach (['title', 'role'] as $field) {
            if ($request->has($field) && is_string($request->input($field))) {
                $request->merge([$field => UnicodeText::clean($request->input($field), false)]);
            }
        }
        if ($request->has('description') && is_string($request->input('description'))) {
            $request->merge([
                'description' => UnicodeText::clean($request->input('description')),
            ]);
        }
        if (is_array($request->input('tools'))) {
            $request->merge([
                'tools' => array_map(
                    static fn ($tool) => is_string($tool)
                        ? UnicodeText::clean($tool, false)
                        : $tool,
                    $request->input('tools')
                ),
            ]);
        }
    }

    private function assertIdempotencyHeaderMatches(Request $request, string $field): void
    {
        if (!$request->hasHeader('Idempotency-Key') || !$request->has($field)) {
            return;
        }
        $header = strtolower(trim((string) $request->header('Idempotency-Key')));
        $body = strtolower(trim((string) $request->input($field)));
        if ($header === '' || $body === '' || !hash_equals($header, $body)) {
            throw ValidationException::withMessages([
                $field => ['تغيّر معرّف الطلب أثناء التنفيذ'],
            ]);
        }
    }

    private function portfolioItemSlug(mixed $title, string $fallback = ''): string
    {
        $slug = Str::slug(UnicodeText::clean($title, false));
        if ($slug !== '') return $slug;
        if ($fallback !== '') return $fallback;

        return 'item-' . Str::lower((string) Str::uuid());
    }

    /** @return list<int> */
    private function logicalProjectIds(int $projectId): array
    {
        $currentProjectId = $this->stagedAuthoring->currentLearnerEntityMap(
            Project::class,
            [$projectId]
        )[$projectId] ?? $projectId;

        return $this->stagedAuthoring->equivalentEntityIds(
            Project::class,
            (int) $currentProjectId
        );
    }

    private function currentProjectId(int $projectId): int
    {
        return (int) ($this->stagedAuthoring->currentLearnerEntityMap(
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
            if ($project) return $project;
        }

        throw (new ModelNotFoundException())->setModel(Project::class, [$projectId]);
    }

    private function submissionAllowsPortfolio(ProjectSubmission $submission): bool
    {
        $snapshot = ProjectSubmissionEvaluationSnapshot::fromSubmission($submission);
        $terms = $snapshot ? data_get($snapshot, 'access.terms') : null;
        if (!is_array($terms)) return false;

        return (bool) ($this->accessPlans->publicPayloadFromTerms($terms)['project_output_enabled'] ?? false);
    }

    private function error(string $message, int $httpStatus): JsonResponse
    {
        return response()->json([
            'status' => $httpStatus,
            'success' => false,
            'message' => $message,
            'data' => null,
        ], $httpStatus);
    }

    private function portfolioOperationError(PortfolioOperationException $exception): JsonResponse
    {
        [$status, $message] = match ($exception->reason) {
            PortfolioOperationException::INCOMPLETE_ITEM => [409, 'أضف ملفًا واحدًا على الأقل قبل النشر'],
            PortfolioOperationException::MEDIA_NOT_READY => [409, "الملفات ما زالت قيد التجهيز\nحاول بعد قليل"],
            default => [409, 'هذا المشروع غير متاح الآن'],
        };

        return $this->error($message, $status);
    }
}
