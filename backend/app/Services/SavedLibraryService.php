<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Lesson;
use App\Models\SavedFolder;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SavedLibraryService
{
    public const MAX_FOLDERS_PER_USER = 100;
    public const FOLDER_PREVIEW_LIMIT = 100;
    private const REVISION_MATERIALIZATION_BATCH = 250;

    public function __construct(
        private readonly CourseChatAccessService $courseAccess,
        private readonly CourseStagedAuthoringService $revisions
    ) {}

    public function savedLessons(User $user, int $perPage): LengthAwarePaginator
    {
        $this->materializeSavedLessons((int) $user->id);
        $latestSaves = DB::table('saved_folder_lessons as saved_memberships')
            ->join('saved_folders as owned_folders', 'owned_folders.id', '=', 'saved_memberships.saved_folder_id')
            ->where('owned_folders.user_id', $user->id)
            ->groupBy('saved_memberships.lesson_id')
            ->selectRaw('saved_memberships.lesson_id, MAX(saved_memberships.created_at) as saved_at');

        return Lesson::query()
            ->publishedLearningGraph()
            ->joinSub($latestSaves, 'user_saves', function ($join) {
                $join->on('user_saves.lesson_id', '=', 'lessons.id');
            })
            ->select('lessons.*', 'user_saves.saved_at')
            ->with(array_merge($this->lessonRelations(), [
                'savedFolders' => fn ($query) => $query
                    ->where('saved_folders.user_id', $user->id)
                    ->orderBy('saved_folders.name'),
            ]))
            ->orderByDesc('user_saves.saved_at')
            ->orderByDesc('lessons.id')
            ->paginate($perPage);
    }

    public function folders(User $user): Collection
    {
        $this->materializeSavedLessons((int) $user->id);
        $relations = $this->lessonRelations();

        return SavedFolder::query()
            ->where('user_id', $user->id)
            ->with(['lessons' => function ($query) use ($relations) {
                $query->publishedLearningGraph()
                    ->with($relations)
                    ->orderByPivot('created_at')
                    ->orderByPivot('id')
                    ->limit(1);
            }])
            ->withCount(['lessons' => fn ($query) => $query->publishedLearningGraph()])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::MAX_FOLDERS_PER_USER)
            ->get();
    }

    /** @return array{folder:?SavedFolder,created:bool,request_conflict:bool,limit_reached:bool} */
    public function createFolder(User $user, string $inputName, string $requestId): array
    {
        $name = SavedFolder::cleanName($inputName);
        $normalizedName = SavedFolder::normalizeName($name);

        [$folder, $created, $requestConflict, $limitReached] = DB::transaction(
            function () use ($user, $name, $normalizedName, $requestId): array {
                User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

                $byRequest = SavedFolder::query()
                    ->where('user_id', $user->id)
                    ->where('client_request_id', $requestId)
                    ->first();
                if ($byRequest) {
                    return [
                        $byRequest,
                        false,
                        (string) $byRequest->normalized_name !== $normalizedName,
                        false,
                    ];
                }

                $existing = SavedFolder::query()
                    ->where('user_id', $user->id)
                    ->where('normalized_name', $normalizedName)
                    ->first();
                if ($existing) {
                    return [$existing, false, false, false];
                }

                if (SavedFolder::query()->where('user_id', $user->id)->count() >= self::MAX_FOLDERS_PER_USER) {
                    return [null, false, false, true];
                }

                return [SavedFolder::create([
                    'user_id' => $user->id,
                    'name' => $name,
                    'normalized_name' => $normalizedName,
                    'client_request_id' => $requestId,
                ]), true, false, false];
            }
        );

        return [
            'folder' => $folder,
            'created' => $created,
            'request_conflict' => $requestConflict,
            'limit_reached' => $limitReached,
        ];
    }

    /** @return array{folder:SavedFolder,lessons:Collection}|null */
    public function folder(User $user, int $folderId): ?array
    {
        $this->materializeSavedLessons((int) $user->id, [$folderId]);
        $folder = SavedFolder::query()
            ->whereKey($folderId)
            ->where('user_id', $user->id)
            ->withCount(['lessons' => fn ($query) => $query->publishedLearningGraph()])
            ->first();
        if (!$folder) {
            return null;
        }

        $lessons = $folder->lessons()
            ->publishedLearningGraph()
            ->with($this->lessonRelations())
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('id', 'desc')
            ->limit(self::FOLDER_PREVIEW_LIMIT)
            ->get();

        return ['folder' => $folder, 'lessons' => $lessons];
    }

    /** @return array{folder:SavedFolder,lessons:LengthAwarePaginator}|null */
    public function folderLessons(User $user, int $folderId, int $perPage): ?array
    {
        $this->materializeSavedLessons((int) $user->id, [$folderId]);
        $folder = SavedFolder::query()
            ->whereKey($folderId)
            ->where('user_id', $user->id)
            ->first();
        if (!$folder) {
            return null;
        }

        $lessons = $folder->lessons()
            ->publishedLearningGraph()
            ->with($this->lessonRelations())
            ->orderByPivot('created_at', 'desc')
            ->orderByPivot('id', 'desc')
            ->paginate($perPage);

        return ['folder' => $folder, 'lessons' => $lessons];
    }

    /** @return array{lesson_id:int,is_saved:bool,folders:Collection}|null */
    public function lessonFolders(User $user, int $lessonId): ?array
    {
        $this->materializeSavedLessons((int) $user->id);
        $currentLessonId = $this->currentLessonId($lessonId);
        $lesson = Lesson::query()->publishedLearningGraph()->find($currentLessonId);
        if (!$lesson) {
            return null;
        }

        $aliases = $this->revisions->equivalentEntityIds(Lesson::class, (int) $lesson->id);
        $savedFolderIds = DB::table('saved_folder_lessons')
            ->join('saved_folders', 'saved_folders.id', '=', 'saved_folder_lessons.saved_folder_id')
            ->where('saved_folders.user_id', $user->id)
            ->whereIn('saved_folder_lessons.lesson_id', $aliases)
            ->pluck('saved_folders.id')
            ->map(fn ($folderId) => (int) $folderId)
            ->flip();

        $folders = SavedFolder::query()
            ->where('user_id', $user->id)
            ->withCount(['lessons' => fn ($query) => $query->publishedLearningGraph()])
            ->latest('updated_at')
            ->latest('id')
            ->limit(self::MAX_FOLDERS_PER_USER)
            ->get()
            ->map(fn (SavedFolder $folder): array => [
                'id' => (int) $folder->id,
                'name' => (string) $folder->name,
                'lessons_count' => (int) $folder->lessons_count,
                'contains_lesson' => isset($savedFolderIds[$folder->id]),
                'updated_at' => $folder->updated_at,
            ]);

        return [
            'lesson_id' => (int) $lesson->id,
            'is_saved' => $savedFolderIds->isNotEmpty(),
            'folders' => $folders,
        ];
    }

    /** @param list<int> $lessonIds */
    public function savedLessonIds(User $user, array $lessonIds): Collection
    {
        $inputIds = collect($lessonIds)->map(static fn ($id) => (int) $id)->unique()->values();
        $currentByInput = $this->revisions->currentLearnerEntityMap(Lesson::class, $inputIds);
        $aliases = $this->revisions->equivalentEntityMap(
            Lesson::class,
            collect($currentByInput)->values()->unique()
        );
        // Collection::flatMap reindexes numeric keys. Lesson identifiers are
        // numeric, so using it here could report the saved state of a different
        // reel. Build the lookup explicitly and keep the database IDs intact.
        $aliasToCurrent = collect();
        foreach ($aliases as $currentId => $ids) {
            foreach ($ids as $id) {
                $aliasToCurrent->put((int) $id, (int) $currentId);
            }
        }
        $savedCurrentIds = DB::table('saved_folder_lessons')
            ->join('saved_folders', 'saved_folders.id', '=', 'saved_folder_lessons.saved_folder_id')
            ->where('saved_folders.user_id', $user->id)
            ->whereIn('saved_folder_lessons.lesson_id', $aliasToCurrent->keys())
            ->distinct()
            ->orderBy('saved_folder_lessons.lesson_id')
            ->pluck('saved_folder_lessons.lesson_id')
            ->map(fn ($id) => (int) $aliasToCurrent->get((int) $id))
            ->unique()
            ->flip();

        return $inputIds
            ->filter(fn (int $inputId): bool => $savedCurrentIds->has(
                (int) ($currentByInput[$inputId] ?? $inputId)
            ))
            ->values();
    }

    /** @return array{status:string,lesson?:Lesson,folder?:SavedFolder,inserted?:bool} */
    public function save(User $user, int $folderId, int $lessonId): array
    {
        $currentLessonId = $this->currentLessonId($lessonId);
        $lesson = Lesson::with(['course', 'courseSection'])->find($currentLessonId);
        if (!$lesson) {
            return ['status' => 'lesson_unavailable'];
        }

        $courseId = (int) $lesson->list_id;
        $course = $lesson->course;
        $section = $lesson->courseSection;
        if (
            !$course
            || !$section
            || !$course->isPublishedForLearning()
            || (int) $section->course_id !== $courseId
            || $section->getSectionType() !== 'lesson'
            || (int) $section->sectionable_id !== (int) $lesson->id
        ) {
            return ['status' => 'lesson_unavailable'];
        }

        $hasAccess = $courseId > 0
            && $this->courseAccess->hasLearningAccess((int) $user->id, $courseId);
        if (!$hasAccess && !(bool) $lesson->is_opened) {
            return ['status' => 'forbidden'];
        }

        $saved = DB::transaction(function () use ($folderId, $user, $lesson): ?array {
            $folder = SavedFolder::query()
                ->whereKey($folderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (!$folder) {
                return null;
            }

            $aliases = $this->revisions->equivalentEntityIds(Lesson::class, (int) $lesson->id);
            $alreadySaved = DB::table('saved_folder_lessons')
                ->where('saved_folder_id', $folder->id)
                ->whereIn('lesson_id', $aliases)
                ->exists();
            $inserted = DB::table('saved_folder_lessons')->insertOrIgnore([
                'saved_folder_id' => (int) $folder->id,
                'lesson_id' => (int) $lesson->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            if (!DB::table('saved_folder_lessons')
                ->where('saved_folder_id', $folder->id)
                ->where('lesson_id', $lesson->id)
                ->exists()) {
                throw new \RuntimeException('Saved lesson membership was not persisted.');
            }

            DB::table('saved_folder_lessons')
                ->where('saved_folder_id', $folder->id)
                ->whereIn('lesson_id', array_diff($aliases, [(int) $lesson->id]))
                ->delete();

            return [
                'folder' => $folder,
                'inserted' => !$alreadySaved && (bool) $inserted,
            ];
        }, 3);
        if ($saved === null) {
            return ['status' => 'folder_unavailable'];
        }

        return [
            'status' => 'saved',
            'lesson' => $lesson,
            'folder' => $saved['folder'],
            'inserted' => $saved['inserted'],
        ];
    }

    public function deleteFolder(User $user, int $folderId): bool
    {
        return (bool) SavedFolder::query()
            ->whereKey($folderId)
            ->where('user_id', $user->id)
            ->delete();
    }

    public function remove(User $user, int $folderId, int $lessonId): ?int
    {
        $aliases = $this->aliases($lessonId);

        return DB::transaction(function () use ($user, $folderId, $aliases): ?int {
            $folder = SavedFolder::query()
                ->whereKey($folderId)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();
            if (!$folder) {
                return null;
            }

            return DB::table('saved_folder_lessons')
                ->where('saved_folder_id', $folder->id)
                ->whereIn('lesson_id', $aliases)
                ->delete();
        }, 3);
    }

    public function removeEverywhere(User $user, int $lessonId): int
    {
        $aliases = $this->aliases($lessonId);

        return DB::transaction(function () use ($user, $aliases): int {
            $folders = SavedFolder::query()
                ->where('user_id', $user->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->pluck('id');
            if ($folders->isEmpty()) {
                return 0;
            }

            return DB::table('saved_folder_lessons')
                ->whereIn('saved_folder_id', $folders)
                ->whereIn('lesson_id', $aliases)
                ->delete();
        }, 3);
    }

    /** @return array<int,string> */
    private function lessonRelations(): array
    {
        return [
            'course',
            'mediaState',
        ];
    }

    private function currentLessonId(int $lessonId): int
    {
        return (int) (
            $this->revisions->currentLearnerEntityMap(Lesson::class, [$lessonId])[$lessonId]
            ?? $lessonId
        );
    }

    /** @return list<int> */
    private function aliases(int $lessonId): array
    {
        return $this->revisions->equivalentEntityIds(
            Lesson::class,
            $this->currentLessonId($lessonId)
        );
    }

    /** Lazily canonicalize this user's bookmarks outside the publish lock. */
    private function materializeSavedLessons(int $userId, ?array $folderIds = null): void
    {
        $scope = DB::table('saved_folder_lessons as memberships')
            ->join('saved_folders as folders', 'folders.id', '=', 'memberships.saved_folder_id')
            ->where('folders.user_id', $userId)
            ->when($folderIds !== null, fn ($query) => $query->whereIn('folders.id', $folderIds));
        $lessonIds = (clone $scope)
            ->distinct()
            ->pluck('memberships.lesson_id')
            ->map(static fn ($id): int => (int) $id);
        if ($lessonIds->isEmpty()) {
            return;
        }

        $current = $this->revisions->currentLearnerEntityMap(Lesson::class, $lessonIds);
        $staleLessonIds = $lessonIds->filter(
            static fn (int $lessonId): bool => (int) ($current[$lessonId] ?? $lessonId) !== $lessonId
        );
        if ($staleLessonIds->isEmpty()) {
            return;
        }

        $published = Lesson::query()
            ->publishedLearningGraph()
            ->whereIn('id', $staleLessonIds->map(
                static fn (int $lessonId): int => (int) $current[$lessonId]
            )->unique())
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->flip();
        $replaceableLessonIds = $staleLessonIds->filter(
            static fn (int $lessonId): bool => $published->has((int) $current[$lessonId])
        );
        if ($replaceableLessonIds->isEmpty()) {
            return;
        }

        while (true) {
            $candidates = (clone $scope)
                ->whereIn('memberships.lesson_id', $replaceableLessonIds)
                ->orderBy('memberships.id')
                ->limit(self::REVISION_MATERIALIZATION_BATCH)
                ->get([
                    'memberships.id',
                    'memberships.saved_folder_id',
                    'memberships.lesson_id',
                    'memberships.created_at',
                    'memberships.updated_at',
                ]);
            if ($candidates->isEmpty()) {
                return;
            }

            DB::transaction(function () use ($userId, $candidates, $current, $published): void {
                $lockedFolderIds = SavedFolder::query()
                    ->where('user_id', $userId)
                    ->whereIn('id', $candidates->pluck('saved_folder_id'))
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->pluck('id');
                if ($lockedFolderIds->isEmpty()) {
                    return;
                }

                // Re-read after the folder lock. A concurrent explicit removal
                // must win rather than being recreated from the earlier snapshot.
                $rows = DB::table('saved_folder_lessons')
                    ->whereIn('id', $candidates->pluck('id'))
                    ->whereIn('saved_folder_id', $lockedFolderIds)
                    ->get(['id', 'saved_folder_id', 'lesson_id', 'created_at', 'updated_at']);
                $replacements = [];
                $obsoleteRowIds = [];
                foreach ($rows as $row) {
                    $target = (int) ($current[(int) $row->lesson_id] ?? $row->lesson_id);
                    if ($target === (int) $row->lesson_id || !$published->has($target)) {
                        continue;
                    }

                    $replacements[] = [
                        'saved_folder_id' => (int) $row->saved_folder_id,
                        'lesson_id' => $target,
                        'created_at' => $row->created_at,
                        'updated_at' => $row->updated_at,
                    ];
                    $obsoleteRowIds[] = (int) $row->id;
                }
                if ($replacements === []) {
                    return;
                }

                DB::table('saved_folder_lessons')->insertOrIgnore($replacements);
                DB::table('saved_folder_lessons')->whereIn('id', $obsoleteRowIds)->delete();
            }, 3);
        }
    }
}
