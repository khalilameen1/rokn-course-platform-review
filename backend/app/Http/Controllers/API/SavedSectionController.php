<?php

declare(strict_types=1);

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Http\Resources\SavedFolderResource;
use App\Http\Resources\SavedLessonResource;
use App\Services\SavedLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

final class SavedSectionController extends Controller
{
    public function __construct(private readonly SavedLibraryService $savedLibrary) {}

    /**
     * Return a de-duplicated, paginated view of every lesson the current user
     * saved, together with all of its folder memberships.
     */
    public function getSavedLessons(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        $user = auth('api')->user();
        $lessons = $this->savedLibrary->savedLessons(
            $user,
            isset($validated['per_page']) ? (int) $validated['per_page'] : 20
        );

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المحفوظات',
            'data' => [
                'lessons' => SavedLessonResource::collection($lessons->getCollection()),
                'pagination' => [
                    'current_page' => $lessons->currentPage(),
                    'last_page' => $lessons->lastPage(),
                    'per_page' => $lessons->perPage(),
                    'total' => $lessons->total(),
                ],
            ],
        ]);
    }

    /**
     * Get all saved folders for the authenticated user.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFolders(): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $folders = $this->savedLibrary->folders($user);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل المجلدات',
                'data' => SavedFolderResource::collection($folders)
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر تحميل المجلدات',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Create a new saved folder.
     *
     * @param Request $request
     * @return \Illuminate\Http\JsonResponse
     */
    public function createFolder(Request $request): JsonResponse
    {
        try {
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
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'راجع اسم المجلد',
                    'data' => null,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth('api')->user();
            $result = $this->savedLibrary->createFolder(
                $user,
                (string) $request->input('name'),
                $requestId
            );
            $folder = $result['folder'];
            $created = $result['created'];
            $requestConflict = $result['request_conflict'];
            $limitReached = $result['limit_reached'];

            if ($requestConflict) {
                return response()->json([
                    'status' => 409,
                    'success' => false,
                    'message' => "تغيّر المجلد أثناء الحفظ\nأعد المحاولة",
                    'data' => null,
                ], 409);
            }

            if ($limitReached) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'وصلت إلى الحد المتاح من المجلدات',
                    'data' => null,
                ], 422);
            }

            return response()->json([
                'status' => $created ? 201 : 200,
                'success' => true,
                'message' => $created ? 'تم إنشاء المجلد' : 'المجلد موجود بالفعل',
                'data' => new SavedFolderResource($folder),
            ], $created ? 201 : 200);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر إنشاء المجلد',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Get a single saved folder with its lessons.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function getFolder($id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $result = $this->savedLibrary->folder($user, (int) $id);

            if ($result === null) {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المجلد غير متاح',
                    'data' => null,
                ], 404);
            }
            $folder = $result['folder'];
            $lessons = $result['lessons'];

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تم تحميل المجلد',
                'data' => [
                    'id' => (int)$folder->id,
                    'name' => (string)$folder->name,
                    'created_at' => $folder->created_at?->toIso8601String(),
                    'updated_at' => $folder->updated_at?->toIso8601String(),
                    'lessons' => SavedLessonResource::collection($lessons),
                    'lessons_count' => (int) $folder->lessons_count,
                    'lessons_has_more' => (int) $folder->lessons_count > $lessons->count(),
                    'lessons_endpoint' => "/api/v1/saved-folders/{$folder->id}/lessons",
                ]
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر تحميل المجلد',
                'data' => null,
            ], 500);
        }
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

        $user = auth('api')->user();
        $result = $this->savedLibrary->folderLessons(
            $user,
            (int) $id,
            $validated['per_page'] ?? 20
        );

        if ($result === null) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'المجلد غير متاح',
                'data' => null,
            ], 404);
        }
        $folder = $result['folder'];
        $lessons = $result['lessons'];

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل المقاطع المحفوظة',
            'data' => [
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
            ],
        ]);
    }

    /** Return every list with membership state for the reel save sheet. */
    public function getLessonFolders($lessonId): JsonResponse
    {
        $user = auth('api')->user();
        $result = $this->savedLibrary->lessonFolders($user, (int) $lessonId);

        if ($result === null) {
            return response()->json([
                'status' => 404,
                'success' => false,
                'message' => 'المقطع غير متاح',
                'data' => null,
            ], 404);
        }

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل مجلدات المقطع',
            'data' => [
                'lesson_id' => $result['lesson_id'],
                'is_saved' => $result['is_saved'],
                'folders' => $result['folders'],
            ],
        ]);
    }

    /** Resolve bookmark state for a whole reel feed without one request per lesson. */
    public function getSavedLessonState(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'lesson_ids' => 'required|array|min:1|max:200',
            'lesson_ids.*' => 'required|integer|min:1',
        ]);
        $user = auth('api')->user();
        $savedLessonIds = $this->savedLibrary->savedLessonIds(
            $user,
            $validated['lesson_ids']
        );

        return response()->json([
            'status' => 200,
            'success' => true,
            'message' => 'تم تحميل حالة الحفظ',
            'data' => ['saved_lesson_ids' => $savedLessonIds],
        ]);
    }

    /**
     * Delete a saved folder.
     *
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function deleteFolder($id): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $deleted = $this->savedLibrary->deleteFolder($user, (int) $id);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => $deleted ? 'تم حذف المجلد' : 'المجلد محذوف بالفعل',
                'data' => ['already_deleted' => !$deleted],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر حذف المجلد',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Save a lesson to a folder.
     *
     * @param Request $request
     * @param int $id
     * @return \Illuminate\Http\JsonResponse
     */
    public function saveLesson(Request $request, $id): JsonResponse
    {
        try {
            $validator = Validator::make($request->all(), [
                'lesson_id' => 'required|exists:lessons,id',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'status' => 422,
                    'success' => false,
                    'message' => 'راجع بيانات الحفظ',
                    'data' => null,
                    'errors' => $validator->errors(),
                ], 422);
            }

            $user = auth('api')->user();
            $result = $this->savedLibrary->save(
                $user,
                (int) $id,
                $request->integer('lesson_id')
            );

            if ($result['status'] === 'lesson_unavailable') {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المقطع غير متاح',
                    'data' => null,
                ], 404);
            }
            if ($result['status'] === 'forbidden') {
                return response()->json([
                    'status' => 403,
                    'success' => false,
                    'message' => 'افتح الكورس أولًا لحفظ هذا المقطع',
                    'data' => null,
                ], 403);
            }
            if ($result['status'] === 'folder_unavailable') {
                return response()->json([
                    'status' => 404,
                    'success' => false,
                    'message' => 'المجلد غير متاح',
                    'data' => null,
                ], 404);
            }
            $lesson = $result['lesson'];
            $folder = $result['folder'];
            $inserted = $result['inserted'];

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => $inserted ? 'تم حفظ المقطع' : 'المقطع محفوظ بالفعل',
                'data' => [
                    'lesson_id' => (int) $lesson->id,
                    'folder_id' => (int) $folder->id,
                    'folder_name' => (string) $folder->name,
                    'is_saved' => true,
                    'already_saved' => !$inserted,
                ],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّر حفظ المقطع',
                'data' => null,
            ], 500);
        }
    }

    /**
     * Remove a lesson from a folder.
     *
     * @param int $id
     * @param int $lessonId
     * @return \Illuminate\Http\JsonResponse
     */
    public function removeLesson($id, $lessonId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $result = $this->savedLibrary->remove($user, (int) $id, (int) $lessonId);

            if ($result === null) {
                return response()->json([
                    'status' => 200,
                    'success' => true,
                    'message' => 'تمت إزالة المقطع',
                    'data' => ['already_removed' => true],
                ]);
            }

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تمت إزالة المقطع',
                'data' => ['already_removed' => $result === 0],
            ]);
        } catch (\Exception $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذّرت إزالة المقطع',
                'data' => null,
            ], 500);
        }
    }

    /** Remove a saved lesson from every folder owned by the current user. */
    public function removeLessonEverywhere($lessonId): JsonResponse
    {
        try {
            $user = auth('api')->user();
            $removed = $this->savedLibrary->removeEverywhere($user, (int) $lessonId);

            return response()->json([
                'status' => 200,
                'success' => true,
                'message' => 'تمت إزالة المقطع من المحفوظات',
                'data' => ['removed_memberships' => $removed],
            ]);
        } catch (\Throwable $e) {
            $this->rethrowExpectedRequestException($e);
            report($e);
            return response()->json([
                'status' => 500,
                'success' => false,
                'message' => 'تعذر تحديث المحفوظات',
                'data' => null,
            ], 500);
        }
    }

}
