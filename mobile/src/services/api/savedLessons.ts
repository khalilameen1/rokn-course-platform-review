import {publicRequest} from '../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
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

/**
 * One page of a saved-lessons list. The device copy of the first page of all
 * lessons lives in the TanStack Query snapshot (`persistedQueryPolicies`,
 * family `saved-lessons`), not here.
 */
export type SavedLessonsPage = {
  lessons: SavedLesson[];
  page: number;
  hasMore: boolean;
  total: number;
  /** The folder a folder page belongs to, as the server names it. */
  folderName?: string;
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  Boolean(value) && typeof value === 'object' && !Array.isArray(value);

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
    ...(folderId && isRecord(folder)
      ? {folderName: String(folder.name).trim()}
      : {}),
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
  assertAccountSessionBoundary(boundary);
  const raw = payload<unknown>(
    await publicRequest.get(
      folderId ? `saved-folders/${folderId}/lessons` : 'saved-lessons',
      {params: {page: safePage, per_page: safePerPage}},
    ),
  );
  assertAccountSessionBoundary(boundary);
  return mapSavedLessonsPage(raw, safePage, folderId);
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
    (typeof lesson.duration_seconds !== 'number' &&
      (typeof lesson.duration_seconds !== 'string' ||
        !/^\d+$/.test(lesson.duration_seconds))) ||
    !Number.isSafeInteger(durationSeconds) ||
    durationSeconds < 0 ||
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
    // The API uses numeric zero when media duration is not known yet. It is
    // optional display metadata, not a reason to reject all saved lessons.
    duration:
      durationSeconds > 0
        ? `${String(Math.floor(durationSeconds / 60)).padStart(
            2,
            '0',
          )}:${String(durationSeconds % 60).padStart(2, '0')}`
        : '',
    imageUrl: imageUrl || undefined,
  }));
};
