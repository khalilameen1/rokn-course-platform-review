<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Exceptions\PortfolioOperationException;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ApiResponseService;
use App\Services\PortfolioItemService;
use App\Services\PortfolioMediaMutationService;
use App\Services\PortfolioUploadAccessService;
use App\Services\SafeExternalUrl;
use App\Support\UnicodeText;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PortfolioController extends Controller
{
    public function __construct(
        private readonly PortfolioItemService $items,
        private readonly PortfolioMediaMutationService $mediaMutations,
        private readonly PortfolioUploadAccessService $uploadAccess,
        private readonly ApiResponseService $responses
    ) {}

    public function uploadAccess(): JsonResponse
    {
        return $this->responses->success(
            $this->uploadAccess->decision($this->user()),
            'تم التحقق من إمكانية إضافة أعمال'
        );
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
        // Clients that can render large histories opt into the page contract.
        $paginated = $request->filled('page') || $request->filled('per_page');
        $list = $this->items->list(
            $this->user(),
            $request->boolean('summary'),
            $paginated ? (int) ($validated['page'] ?? 1) : null,
            $paginated ? (int) ($validated['per_page'] ?? 30) : null
        );

        return $this->responses->success(
            $list['items'],
            'تم تحميل المشروعات',
            additional: $list['pagination'] !== null ? ['pagination' => $list['pagination']] : []
        );
    }

    /** Passed course projects that have not yet been added to this portfolio. */
    public function eligibleProjects(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        return $this->responses->success(
            $this->items->eligibleProjects(
                $this->user(),
                max(1, (int) $request->input('page', 1)),
                (int) ($validated['per_page'] ?? 20)
            ),
            'تم تحميل المشروعات المتاحة'
        );
    }

    /**
     * Store a new portfolio item.
     */
    public function store(Request $request): JsonResponse
    {
        $this->normalizePortfolioInput($request);
        if (! $request->filled('client_request_id')) {
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

        try {
            $created = $this->items->create($this->user(), $request->string('client_request_id')->toString(), [
                'title' => $request->input('title'),
                'description' => $request->filled('description') ? (string) $request->input('description') : null,
                'course_id' => $request->filled('course_id') ? $request->integer('course_id') : null,
                'source_project_id' => $request->filled('source_project_id')
                    ? $request->integer('source_project_id')
                    : null,
                'role' => $request->input('role'),
                'tools' => $request->input('tools'),
                'external_url' => $request->input('external_url'),
                'completed_at' => $request->input('completed_at'),
                'is_featured' => $request->boolean('is_featured'),
                'sort_order' => $request->integer('sort_order', 0),
                'expected_media_count' => $request->integer('expected_media_count', 0),
            ]);
        } catch (LockTimeoutException) {
            return $this->responses->error("جارٍ حفظ هذا المشروع\nحاول بعد قليل", 409);
        }

        return $this->responses->success(
            $created['item'],
            'تمت إضافة المشروع',
            additional: ['replayed' => $created['replayed']]
        );
    }

    /**
     * Show a single portfolio item.
     */
    public function show($id): JsonResponse
    {
        $item = $this->items->show($this->user(), $id);
        if (! $item) {
            return $this->responses->error('المشروع غير متاح', 404);
        }

        return $this->responses->success($item, 'تم تحميل المشروع');
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
            // Owner visibility on the public link; false hides the project
            // immediately without deleting it or waiting for review.
            'is_public' => 'nullable|boolean',
            'cover_media_id' => 'nullable|integer|min:1',
        ]);
        $presentation = [];
        if ($request->filled('is_public')) {
            $presentation['is_public'] = $request->boolean('is_public');
        }
        if ($request->exists('cover_media_id')) {
            $presentation['cover_media_id'] = $request->filled('cover_media_id')
                ? $request->integer('cover_media_id')
                : null;
        }

        return $this->responses->success(
            $this->items->update($this->user(), $id, $request->only([
                'title', 'description', 'role', 'tools', 'external_url', 'completed_at',
                'is_featured', 'sort_order',
            ]), $presentation),
            'تم تحديث المشروع'
        );
    }

    /**
     * Delete a portfolio item.
     */
    public function destroy($id): JsonResponse
    {
        $deleted = $this->mediaMutations->deleteItem($this->user(), (int) $id);

        return $this->responses->success(['already_deleted' => ! $deleted], 'تم حذف المشروع');
    }

    /** Publish an intentionally shortened upload after at least one file landed. */
    public function finalize($id): JsonResponse
    {
        try {
            $item = $this->items->finalize($this->user(), (int) $id);
        } catch (PortfolioOperationException $exception) {
            return $this->responses->error(match ($exception->reason) {
                PortfolioOperationException::INCOMPLETE_ITEM => 'أضف ملفًا واحدًا على الأقل قبل النشر',
                PortfolioOperationException::MEDIA_NOT_READY => "الملفات ما زالت قيد التجهيز\nحاول بعد قليل",
                default => 'هذا المشروع غير متاح الآن',
            }, 409);
        }

        return $this->responses->success($item, 'تم حفظ المشروع للمراجعة');
    }

    private function user(): User
    {
        /** @var User $user */
        $user = auth('api')->user();

        return $user;
    }

    private function normalizePortfolioInput(Request $request): void
    {
        // The app labels a project's tools as "skills"; both names write the
        // same project field. An explicit tools list wins.
        if (! $request->exists('tools') && $request->exists('skills')) {
            $request->merge(['tools' => $request->input('skills')]);
        }
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
        if (! $request->hasHeader('Idempotency-Key') || ! $request->has($field)) {
            return;
        }
        $header = strtolower(trim((string) $request->header('Idempotency-Key')));
        $body = strtolower(trim((string) $request->input($field)));
        if ($header === '' || $body === '' || ! hash_equals($header, $body)) {
            throw ValidationException::withMessages([
                $field => ['تغيّر معرّف الطلب أثناء التنفيذ'],
            ]);
        }
    }
}
