import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../constants/api';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {isServerTimestampFresh, serverNowMs} from '../../utils/serverClock';
import {settleWithin} from '../../utils/settleWithin';
import {isApiRecord, payload} from './common';

type SavedFolderDto = {id?: unknown; name?: unknown};
type SavedLessonDto = {
  id?: unknown;
  folder_memberships?: unknown;
  course?: {id?: unknown; title?: unknown; image?: unknown};
  title?: unknown;
  duration_seconds?: unknown;
  image?: unknown;
};

type SavedLessonsPayloadDto = {
  folder?: unknown;
  lessons?: unknown;
  pagination?: {
    current_page?: unknown;
    last_page?: unknown;
    total?: unknown;
  };
};

export type SavedLesson = {
  id: string;
  folderId: string;
  folderName: string;
  courseId: string;
  title: string;
  courseTitle: string;
  duration: string;
  imageUrl?: string;
};

export type SavedLessonsPage = {
  lessons: SavedLesson[];
  page: number;
  hasMore: boolean;
  total: number;
  fromCache: boolean;
};

const CACHE_KEY = '@rokn/saved-lessons/v2';
const CACHE_TTL_MS = 24 * 60 * 60 * 1000;
const libraryRevisions = new Map<string, number>();
const cacheWrites = new Map<string, Promise<void>>();
const ownerKey = (boundary: AccountSessionBoundary) =>
  `${boundary.scope}:${boundary.epoch}`;
const libraryRevision = (boundary: AccountSessionBoundary) =>
  libraryRevisions.get(ownerKey(boundary)) ?? 0;

// Replacements and acknowledged removals share the existing cache's write
// order. A removal must read inside this queue, not prepare an older snapshot.
const updateCache = (
  boundary: AccountSessionBoundary,
  update: (key: string) => Promise<SavedLesson[] | null>,
): Promise<void> => {
  const scope = boundary.scope;
  const write = (cacheWrites.get(scope) ?? Promise.resolve()).then(async () => {
    const key = await accountScopedStorageKey(CACHE_KEY, boundary);
    assertAccountSessionBoundary(boundary);
    const lessons = await update(key);
    assertAccountSessionBoundary(boundary);
    if (lessons === null) return;
    await AsyncStorage.setItem(
      key,
      JSON.stringify({version: 2, savedAt: serverNowMs(), lessons}),
    );
  });
  const settled = write.catch(() => undefined);
  cacheWrites.set(scope, settled);
  void settled.then(() => {
    if (cacheWrites.get(scope) === settled) cacheWrites.delete(scope);
  });
  return write;
};
const isRecord = (value: unknown): value is Record<string, unknown> =>
  Boolean(value) && typeof value === 'object' && !Array.isArray(value);

const cacheKey = (capturedKey?: string) =>
  capturedKey
    ? Promise.resolve(capturedKey)
    : accountScopedStorageKey(CACHE_KEY);

const readCache = async (
  capturedKey?: string,
): Promise<SavedLesson[] | null> => {
  try {
    const raw = await AsyncStorage.getItem(await cacheKey(capturedKey));
    if (!raw) return null;
    const cached = JSON.parse(raw) as {
      version?: unknown;
      savedAt?: unknown;
      lessons?: unknown;
    };
    if (
      cached.version !== 2 ||
      !isServerTimestampFresh(Number(cached.savedAt), CACHE_TTL_MS) ||
      !Array.isArray(cached.lessons) ||
      cached.lessons.some(
        lesson =>
          !isRecord(lesson) ||
          typeof lesson.id !== 'string' ||
          !lesson.id ||
          typeof lesson.folderId !== 'string' ||
          !lesson.folderId ||
          typeof lesson.courseId !== 'string' ||
          !lesson.courseId ||
          typeof lesson.title !== 'string' ||
          typeof lesson.courseTitle !== 'string' ||
          typeof lesson.duration !== 'string',
      )
    ) {
      return null;
    }
    return cached.lessons as SavedLesson[];
  } catch {
    return null;
  }
};

const mapSavedLessonsPage = (
  raw: unknown,
  requestedPage: number,
  folderId?: string,
): SavedLessonsPage => {
  if (!isRecord(raw) || !Array.isArray(raw.lessons)) {
    throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
  }
  const data = raw as SavedLessonsPayloadDto;
  const folder = data.folder;
  if (
    folderId &&
    (!isRecord(folder) ||
      String(folder.id) !== folderId ||
      typeof folder.name !== 'string' ||
      !folder.name.trim())
  ) {
    throw new Error('SAVED_FOLDER_LESSONS_CONTRACT_INVALID');
  }
  // Folder responses omit savedFolders: the authenticated outer folder is
  // their membership. The all-lessons contract still requires every membership.
  const sourceLessons = (data.lessons as SavedLessonDto[]).map(lesson =>
    folderId && isApiRecord(lesson)
      ? {...lesson, folder_memberships: [folder]}
      : lesson,
  );
  if (sourceLessons.some(invalidSavedLesson)) {
    throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
  }
  const lessons = sourceLessons.flatMap(mapSavedLesson);
  const pagination = data.pagination;
  // A deletion can shrink Laravel's last_page below the requested page. An
  // empty response then exhausts pagination instead of creating a retry loop.
  if (
    !isRecord(pagination) ||
    !Number.isSafeInteger(Number(pagination.current_page)) ||
    Number(pagination.current_page) !== requestedPage ||
    !Number.isSafeInteger(Number(pagination.last_page)) ||
    Number(pagination.last_page) < 1 ||
    (Number(pagination.last_page) < requestedPage && lessons.length > 0)
  ) {
    throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
  }
  return {
    lessons,
    page: requestedPage,
    hasMore: requestedPage < Number(pagination.last_page),
    total: Math.max(0, Number(pagination.total ?? lessons.length) || 0),
    fromCache: false,
  };
};

export const getSavedFolderLessonsPage = async (
  folderId: string,
  page = 1,
  perPage = 20,
): Promise<SavedLessonsPage> => {
  const normalizedFolderId = folderId.trim();
  if (!/^\d+$/.test(normalizedFolderId)) {
    throw new Error('INVALID_SAVED_FOLDER_ROUTE');
  }
  const boundary = await captureAccountSessionBoundary();
  // A global first-page cache is not a complete page of this folder.
  return readSavedLessonsPage(boundary, page, perPage, normalizedFolderId);
};

export const getSavedLessonsPage = async (
  page = 1,
  perPage = 20,
): Promise<SavedLessonsPage> => {
  const boundary = await captureAccountSessionBoundary();
  return readSavedLessonsPage(boundary, page, perPage);
};

const readSavedLessonsPage = async (
  boundary: AccountSessionBoundary,
  page: number,
  perPage: number,
  folderId?: string,
): Promise<SavedLessonsPage> => {
  const safePage = Math.max(1, Math.floor(page));
  const safePerPage = Math.min(50, Math.max(1, Math.floor(perPage)));
  const capturedCacheKey = folderId
    ? null
    : await accountScopedStorageKey(CACHE_KEY, boundary);

  for (;;) {
    assertAccountSessionBoundary(boundary);
    const revision = libraryRevision(boundary);
    const isCurrent = () => libraryRevision(boundary) === revision;
    try {
      const raw = payload<unknown>(
        await publicRequest.get(
          folderId ? `saved-folders/${folderId}/lessons` : 'saved-lessons',
          {params: {page: safePage, per_page: safePerPage}},
        ),
      );
      assertAccountSessionBoundary(boundary);
      // Only a confirmed mutation overtaking this read warrants another GET.
      // Keep the original account and page; never replay the mutation.
      if (!isCurrent()) continue;
      const result = mapSavedLessonsPage(raw, safePage, folderId);
      if (capturedCacheKey && result.page === 1) {
        void updateCache(boundary, async () =>
          isCurrent() ? result.lessons : null,
        ).catch(() => undefined);
      }
      return result;
    } catch (error) {
      assertAccountSessionBoundary(boundary);
      if (!isCurrent()) continue;
      const cached =
        capturedCacheKey && safePage === 1
          ? await settleWithin(
              (async () => {
                await cacheWrites.get(boundary.scope);
                return readCache(capturedCacheKey);
              })(),
              null,
            )
          : null;
      assertAccountSessionBoundary(boundary);
      if (!isCurrent()) continue;
      if (!cached) throw error;
      return {
        lessons: cached,
        page: 1,
        hasMore: false,
        total: cached.length,
        fromCache: true,
      };
    }
  }
};

const invalidSavedLesson = (lesson: SavedLessonDto): boolean => {
  if (!isApiRecord(lesson)) return true;
  const memberships = Array.isArray(lesson.folder_memberships)
    ? (lesson.folder_memberships as SavedFolderDto[])
    : [];
  const durationSeconds = Number(lesson.duration_seconds);
  return (
    !/^\d+$/.test(String(lesson.id ?? '').trim()) ||
    !isRecord(lesson.course) ||
    !/^\d+$/.test(String(lesson.course?.id ?? '').trim()) ||
    !String(lesson.title || '').trim() ||
    !String(lesson.course?.title || '').trim() ||
    !Number.isSafeInteger(durationSeconds) ||
    durationSeconds < 1 ||
    !Array.isArray(lesson.folder_memberships) ||
    memberships.length === 0 ||
    memberships.some(
      folder =>
        !isApiRecord(folder) ||
        !/^\d+$/.test(String(folder.id ?? '').trim()) ||
        !String(folder.name || '').trim(),
    )
  );
};

const mapSavedLesson = (lesson: SavedLessonDto): SavedLesson[] => {
  const durationSeconds = Math.floor(Number(lesson.duration_seconds));
  const imageUrl = String(lesson.image || lesson.course?.image || '').trim();
  return (lesson.folder_memberships as SavedFolderDto[]).map(folder => ({
    id: String(lesson.id),
    folderId: String(folder.id),
    folderName: String(folder.name).trim(),
    courseId: String(lesson.course?.id).trim(),
    title: String(lesson.title).trim(),
    courseTitle: String(lesson.course?.title).trim(),
    duration: `${String(Math.floor(durationSeconds / 60)).padStart(
      2,
      '0',
    )}:${String(durationSeconds % 60).padStart(2, '0')}`,
    imageUrl: imageUrl || undefined,
  }));
};

export const getSavedLessons = async (): Promise<SavedLesson[]> =>
  (await getSavedLessonsPage()).lessons;

const filterCache = async (
  keep: (lesson: SavedLesson) => boolean,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  libraryRevisions.set(ownerKey(boundary), libraryRevision(boundary) + 1);
  await updateCache(boundary, async key => {
    const cached = await readCache(key);
    return cached ? cached.filter(keep) : null;
  });
  assertAccountSessionBoundary(boundary);
};

export const removeSavedLessonFromCache = (
  folderId: string,
  lessonId: string,
  ownerBoundary?: AccountSessionBoundary,
) =>
  filterCache(
    lesson => lesson.folderId !== folderId || lesson.id !== lessonId,
    ownerBoundary,
  );

export const removeSavedFolderFromCache = (
  folderId: string,
  ownerBoundary?: AccountSessionBoundary,
) => filterCache(lesson => lesson.folderId !== folderId, ownerBoundary);

export const removeSavedLessonEverywhereFromCache = (
  lessonId: string,
  ownerBoundary?: AccountSessionBoundary,
) => filterCache(lesson => lesson.id !== lessonId, ownerBoundary);

export const deleteSavedLesson = async (folderId: string, lessonId: string) => {
  const normalizedFolderId = String(folderId).trim();
  const normalizedLessonId = String(lessonId).trim();
  if (!/^\d+$/.test(normalizedFolderId) || !/^\d+$/.test(normalizedLessonId)) {
    throw new Error('INVALID_SAVED_LESSON_ROUTE');
  }
  const boundary = await captureAccountSessionBoundary();
  const response = await publicRequest.delete(
    `saved-folders/${normalizedFolderId}/lessons/${normalizedLessonId}`,
  );
  assertAccountSessionBoundary(boundary);
  await removeSavedLessonFromCache(
    normalizedFolderId,
    normalizedLessonId,
    boundary,
  ).catch(() => undefined);
  assertAccountSessionBoundary(boundary);
  return response;
};
