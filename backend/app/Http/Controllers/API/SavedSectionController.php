<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavedFolderResource;
use App\Http\Resources\SavedLessonResource;
use App\Services\ApiResponseService;
use App\Services\SavedLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class SavedSectionController extends Controller
{
    public function __construct(
        private readonly SavedLibraryService $savedLibrary,
        private readonly ApiResponseService $responses
    ) {}

    /**
     * Return a de-duplicated, paginated view of every lesson the current user
     * saved, together with all of its folder memberships.
     */
    public function getSavedLessons(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $lessons = $this->savedLibrary->savedLessons(
            auth('api')->user(),
            isset($validated['per_page']) ? (int) $validated['per_page'] : 20
        );

        return $this->responses->success([
            'lessons' => SavedLessonResource::collection($lessons->getCollection()),
            'pagination' => [
                'current_page' => $lessons->currentPage(),
                'last_page' => $lessons->lastPage(),
                'per_page' => $lessons->perPage(),
                'total' => $lessons->total(),
            ],
        ], 'تم تحميل المحفوظات');
    }

    /**
     * Get all saved folders for the authenticated user.
     */
    public function getFolders(): JsonResponse
    {
        return $this->responses->success(
            SavedFolderResource::collection($this->savedLibrary->folders(auth('api')->user())),
            'تم تحميل المجلدات'
        );
    }

    /**
     * Create a new saved folder.
     */
    public function createFolder(Request $request): JsonResponse
    {
        $requestId = $request->input('client_request_id')
            ?: $request->header('Idempotency-Key')
            ?: (string) Str::uuid();
        $input = $request->all();
        $input['client_request_id'] = $requestId;
        $validator = Validator::make($input, [
            'name' => 'required|string|max:60',
            'client_request_id' => 'required|uuid',
        ]);

        if ($validator->fails()) {
            return $this->responses->error('راجع اسم المجلد', 422, null, [
                'errors' => $validator->errors(),
            ]);
        }

        $result = $this->savedLibrary->createFolder(
            auth('api')->user(),
            (string) $request->input('name'),
            $requestId
        );

        if ($result['request_conflict']) {
            return $this->responses->error("تغيّر المجلد أثناء الحفظ\nأعد المحاولة", 409);
        }

        if ($result['limit_reached']) {
            return $this->responses->error('وصلت إلى الحد المتاح من المجلدات', 422);
        }

        $created = $result['created'];

        return $this->responses->success(
            new SavedFolderResource($result['folder']),
            $created ? 'تم إنشاء المجلد' : 'المجلد موجود بالفعل',
            $created ? 201 : 200
        );
    }

    /**
     * Get a single saved folder with its lessons.
     *
     * @param  int  $id
     */
    public function getFolder($id): JsonResponse
    {
        $result = $this->savedLibrary->folder(auth('api')->user(), (int) $id);

        if ($result === null) {
            return $this->responses->error('المجلد غير متاح', 404);
        }
        $folder = $result['folder'];
        $lessons = $result['lessons'];

        return $this->responses->success([
            'id' => (int) $folder->id,
            'name' => (string) $folder->name,
            'created_at' => $folder->created_at?->toIso8601String(),
            'updated_at' => $folder->updated_at?->toIso8601String(),
            'lessons' => SavedLessonResource::collection($lessons),
            'lessons_count' => (int) $folder->lessons_count,
            'lessons_has_more' => (int) $folder->lessons_count > $lessons->count(),
            'lessons_endpoint' => "/api/v1/saved-folders/{$folder->id}/lessons",
        ], 'تم تحميل المجلد');
    }

    /**
     * Paginated folder contents for large libraries. The original getFolder
     * response remains unchanged for older app versions.
     */
    public function getFolderLessons(Request $request, $id): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $result = $this->savedLibrary->folderLessons(
            auth('api')->user(),
            (int) $id,
            isset($validated['per_page']) ? (int) $validated['per_page'] : 20
        );

        if ($result === null) {
            return $this->responses->error('المجلد غير متاح', 404);
        }
        $folder = $result['folder'];
        $lessons = $result['lessons'];

        return $this->responses->success([
            'folder' => [
                'id' => (int) $folder->id,
                'name' => (string) $folder->name,
            ],
            'lessons' => SavedLessonResource::collection($lessons->getCollection()),
            'pagination' => [
                'current_page' => $lessons->currentPage(),
                'last_page' => $lessons->lastPage(),
                'per_page' => $lessons->perPage(),
                'total' => $lessons->total(),
            ],
        ], 'تم تحميل المقاطع المحفوظة');
    }

    /** Return every list with membership state for the reel save sheet. */
    public function getLessonFolders($lessonId): JsonResponse
    {
        $result = $this->savedLibrary->lessonFolders(auth('api')->user(), (int) $lessonId);

        if ($result === null) {
            return $this->responses->error('المقطع غير متاح', 404);
        }

        return $this->responses->success([
            'lesson_id' => $result['lesson_id'],
            'is_saved' => $result['is_saved'],
            'folders' => $result['folders'],
        ], 'تم تحميل مجلدات المقطع');
    }

    /** Resolve bookmark state for a whole reel feed without one request per lesson. */
    public function getSavedLessonState(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lesson_ids' => 'required|array|min:1|max:200',
            'lesson_ids.*' => 'required|integer|min:1',
        ]);

        return $this->responses->success([
            'saved_lesson_ids' => $this->savedLibrary->savedLessonIds(
                auth('api')->user(),
                $validated['lesson_ids']
            ),
        ], 'تم تحميل حالة الحفظ');
    }

    /**
     * Delete a saved folder.
     *
     * @param  int  $id
     */
    public function deleteFolder($id): JsonResponse
    {
        $deleted = $this->savedLibrary->deleteFolder(auth('api')->user(), (int) $id);

        return $this->responses->success(
            ['already_deleted' => ! $deleted],
            $deleted ? 'تم حذف المجلد' : 'المجلد محذوف بالفعل'
        );
    }

    /**
     * Save a lesson to a folder.
     *
     * @param  int  $id
     */
    public function saveLesson(Request $request, $id): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'lesson_id' => 'required|exists:lessons,id',
        ]);

        if ($validator->fails()) {
            return $this->responses->error('راجع بيانات الحفظ', 422, null, [
                'errors' => $validator->errors(),
            ]);
        }

        $result = $this->savedLibrary->save(
            auth('api')->user(),
            (int) $id,
            $request->integer('lesson_id')
        );

        return match ($result['status']) {
            'lesson_unavailable' => $this->responses->error('المقطع غير متاح', 404),
            'forbidden' => $this->responses->error('افتح الكورس أولًا لحفظ هذا المقطع', 403),
            'folder_unavailable' => $this->responses->error('المجلد غير متاح', 404),
            default => $this->responses->success([
                // A visible historical reel may resolve to a published clone.
                // Acknowledge this command's identity, as the state endpoint does.
                'lesson_id' => $request->integer('lesson_id'),
                'folder_id' => (int) $result['folder']->id,
                'folder_name' => (string) $result['folder']->name,
                'is_saved' => true,
                'already_saved' => ! $result['inserted'],
            ], $result['inserted'] ? 'تم حفظ المقطع' : 'المقطع محفوظ بالفعل'),
        };
    }

    /**
     * Remove a lesson from a folder.
     *
     * @param  int  $id
     * @param  int  $lessonId
     */
    public function removeLesson($id, $lessonId): JsonResponse
    {
        $result = $this->savedLibrary->remove(auth('api')->user(), (int) $id, (int) $lessonId);

        return $this->responses->success(
            $result === null
                ? ['already_removed' => true]
                : [
                    'already_removed' => $result['removed'] === 0,
                    'still_saved' => $result['still_saved'],
                ],
            'تمت إزالة المقطع'
        );
    }

    /** Remove a saved lesson from every folder owned by the current user. */
    public function removeLessonEverywhere($lessonId): JsonResponse
    {
        return $this->responses->success(
            ['removed_memberships' => $this->savedLibrary->removeEverywhere(auth('api')->user(), (int) $lessonId)],
            'تمت إزالة المقطع من المحفوظات'
        );
    }
}
