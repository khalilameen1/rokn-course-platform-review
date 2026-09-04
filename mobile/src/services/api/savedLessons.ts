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

export const getSavedLessonsPage = async (
  page = 1,
  perPage = 20,
): Promise<SavedLessonsPage> => {
  const safePage = Math.max(1, Math.floor(page));
  const safePerPage = Math.min(50, Math.max(1, Math.floor(perPage)));
  const boundary = await captureAccountSessionBoundary();
  const capturedCacheKey = await accountScopedStorageKey(CACHE_KEY, boundary);

  try {
    const raw = payload<unknown>(
      await publicRequest.get('saved-lessons', {
        params: {page: safePage, per_page: safePerPage},
      }),
    );
    assertAccountSessionBoundary(boundary);
    if (!isRecord(raw) || !Array.isArray(raw.lessons)) {
      throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
    }

    const data = raw as SavedLessonsPayloadDto;
    const sourceLessons = data.lessons as SavedLessonDto[];
    if (sourceLessons.some(invalidSavedLesson)) {
      throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
    }

    const lessons = sourceLessons.flatMap(mapSavedLesson);
    const pagination = data.pagination;
    if (
      !isRecord(pagination) ||
      !Number.isSafeInteger(Number(pagination.current_page)) ||
      Number(pagination.current_page) < 1 ||
      !Number.isSafeInteger(Number(pagination.last_page)) ||
      Number(pagination.last_page) < Number(pagination.current_page)
    ) {
      throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
    }

    const currentPage = Number(pagination.current_page);
    const lastPage = Number(pagination.last_page);
    if (currentPage !== safePage) {
      throw new Error('SAVED_LESSONS_CONTRACT_INVALID');
    }
    if (currentPage === 1) {
      assertAccountSessionBoundary(boundary);
      void AsyncStorage.setItem(
        capturedCacheKey,
        JSON.stringify({
          version: 2,
          savedAt: serverNowMs(),
          lessons,
        }),
      ).catch(() => undefined);
    }
    assertAccountSessionBoundary(boundary);
    return {
      lessons,
      page: currentPage,
      hasMore: currentPage < lastPage,
      total: Math.max(0, Number(pagination.total ?? lessons.length) || 0),
      fromCache: false,
    };
  } catch (error) {
    assertAccountSessionBoundary(boundary);
    const cached =
      safePage === 1
        ? await settleWithin(readCache(capturedCacheKey), null)
        : null;
    if (!cached) throw error;
    assertAccountSessionBoundary(boundary);
    return {
      lessons: cached,
      page: 1,
      hasMore: false,
      total: cached.length,
      fromCache: true,
    };
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
  const capturedCacheKey = await accountScopedStorageKey(CACHE_KEY, boundary);
  assertAccountSessionBoundary(boundary);
  const cached = await readCache(capturedCacheKey);
  if (!cached) return;
  assertAccountSessionBoundary(boundary);
  await AsyncStorage.setItem(
    capturedCacheKey,
    JSON.stringify({
      version: 2,
      savedAt: serverNowMs(),
      lessons: cached.filter(keep),
    }),
  );
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
