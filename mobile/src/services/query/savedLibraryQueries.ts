import {
  infiniteQueryOptions,
  queryOptions,
  type InfiniteData,
  type QueryFunctionContext,
} from '@tanstack/react-query';
import {
  getSavedFolderOptions,
  type SavedFolderOption,
} from '../../components/VideoPlayer/courseLearning/savedFolderIndex';
import {assertAccountSessionBoundary} from '../../constants/helpers';
import type {SavedLesson, SavedLessonsPage} from '../api/savedLessons';
import {getSavedFolderLessonsPage, getSavedLessonsPage} from '../roknApi';
import {captureScopedBoundary} from './accountQueryScope';
import {accountQueryKeys} from './queryKeys';
import {restoreBeforeFailing} from './queryPersistence';

/** The list of every saved lesson, across folders. */
export const ALL_SAVED_LESSONS = 'all';

export type SavedLessonsData = InfiniteData<SavedLessonsPage, number>;
type SavedLessonsKey = ReturnType<
  ReturnType<typeof accountQueryKeys>['savedLessons']
>;

/**
 * One saved-lessons list (all lessons, or one folder) as an infinite query
 * (TanStack "Infinite Queries" guide): page one on mount and on every focus,
 * later pages on "عرض المزيد". The first page of all lessons is the only one
 * the device snapshot keeps (`persistedQueryPolicies`, 24 hours), as the
 * previous hand-written page cache did.
 */
export const savedLessonsQueryOptions = (
  scope: string | null,
  folderId: string,
) =>
  infiniteQueryOptions<
    SavedLessonsPage,
    Error,
    SavedLessonsData,
    SavedLessonsKey,
    number
  >({
    queryKey: accountQueryKeys(scope).savedLessons(folderId),
    queryFn: restoreBeforeFailing(
      async ({
        pageParam,
      }: QueryFunctionContext<SavedLessonsKey, number>) => {
        const boundary = await captureScopedBoundary(scope!);
        const page =
          folderId === ALL_SAVED_LESSONS
            ? await getSavedLessonsPage(pageParam)
            : await getSavedFolderLessonsPage(folderId, pageParam);
        assertAccountSessionBoundary(boundary);
        return page;
      },
    ),
    initialPageParam: 1,
    getNextPageParam: lastPage =>
      lastPage.hasMore ? lastPage.page + 1 : undefined,
    staleTime: 0,
    // The library reads on screen focus, not on every return to the app.
    refetchOnWindowFocus: false,
  });

/**
 * The learner's saved lists (name, count, cover), read through the player
 * sheet's account-scoped folder index and its offline copy. The full library
 * and ركني observe the same entry; confirmed save, remove and folder changes
 * invalidate it (`invalidateFolderList`). `requireFresh` is read when a fetch
 * starts: an explicit retry must reach the server, not clear a warning with
 * the offline index.
 */
export const savedFoldersQueryOptions = (
  scope: string | null,
  requireFresh?: () => boolean,
) =>
  queryOptions({
    queryKey: accountQueryKeys(scope).savedFolders(),
    queryFn: async (): Promise<SavedFolderOption[]> => {
      const boundary = await captureScopedBoundary(scope!);
      const folders = await getSavedFolderOptions(
        requireFresh?.() ? {requireFresh: true} : undefined,
      );
      assertAccountSessionBoundary(boundary);
      return folders;
    },
    staleTime: 0,
    refetchOnWindowFocus: false,
  });

export const savedRowKey = (item: Pick<SavedLesson, 'folderId' | 'id'>) =>
  `${item.folderId}:${item.id}`;

/** Pages merge by row (folder + lesson); a repeated row keeps its position. */
export const savedLessonRows = (
  data: SavedLessonsData | undefined,
): SavedLesson[] => {
  if (!data) return [];
  const seen = new Set<string>();
  return data.pages.flatMap(page =>
    page.lessons.filter(item => {
      const key = savedRowKey(item);
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    }),
  );
};

/** Rows dropped from every page; each page total follows. */
export const removeSavedRows = (
  data: SavedLessonsData,
  keep: (item: SavedLesson) => boolean,
): SavedLessonsData => {
  let removed = 0;
  const pages = data.pages.map(page => {
    const lessons = page.lessons.filter(keep);
    removed += page.lessons.length - lessons.length;
    return lessons.length === page.lessons.length ? page : {...page, lessons};
  });
  if (removed === 0) return data;
  return {
    ...data,
    pages: pages.map(page => ({
      ...page,
      total: Math.max(0, page.total - removed),
    })),
  };
};

/**
 * A rolled-back removal returns to its old position among the current rows
 * (a refresh may have changed them meanwhile), only if it is not back already.
 */
export const insertSavedRow = (
  data: SavedLessonsData,
  item: SavedLesson,
  index: number,
): SavedLessonsData => {
  if (savedLessonRows(data).some(row => savedRowKey(row) === savedRowKey(item)))
    return data;
  let offset = 0;
  let inserted = false;
  const pages = data.pages.map((page, pageIndex) => {
    const isLastPage = pageIndex === data.pages.length - 1;
    const position = index - offset;
    offset += page.lessons.length;
    if (inserted || (position > page.lessons.length && !isLastPage)) {
      return {...page, total: page.total + 1};
    }
    inserted = true;
    const lessons = [...page.lessons];
    lessons.splice(Math.min(Math.max(0, position), lessons.length), 0, item);
    return {...page, lessons, total: page.total + 1};
  });
  return {...data, pages};
};

/** A known folder count moves with an optimistic row removal or its rollback. */
export const adjustFolderCount = (
  folders: SavedFolderOption[],
  folderId: string,
  delta: number,
) =>
  folders.map(folder =>
    folder.id === folderId &&
    folder.lessonsCount !== undefined &&
    folder.lessonsCount + delta >= 0
      ? {...folder, lessonsCount: folder.lessonsCount + delta}
      : folder,
  );
