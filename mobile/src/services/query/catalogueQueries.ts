import {
  infiniteQueryOptions,
  type InfiniteData,
  type QueryFunctionContext,
  type QueryClient,
} from '@tanstack/react-query';
import type {Course} from '../../types/Course';
import {getPublishedCoursesPage} from '../roknApi';
import type {PublishedCoursesPage} from '../api/courseContractTypes';
import {publicQueryKeys} from './queryKeys';
import {restoreBeforeFailing} from './queryPersistence';

export type CataloguePageParam = {page: number; revision?: number};
export type CataloguePage = PublishedCoursesPage & {
  /** The normalized search this page answers ('' = browsing). */
  query: string;
};
export type CatalogueData = InfiniteData<CataloguePage>;

/** Previous foreground rule: a successful read stays current for 2 minutes. */
export const CATALOGUE_STALE_TIME_MS = 2 * 60 * 1000;
/** Matches the browse snapshot's device max age so it is never GC'd first. */
const BROWSE_GC_TIME_MS = 2 * 60 * 60 * 1000;

/**
 * Published discovery, one infinite query per normalized search (TanStack
 * "Infinite Queries" guide). Later pages carry the revision of the pages
 * already shown; the server answers a revision change with page one again
 * (`reset`), which `keepPagesFromLastReset` turns into a fresh list.
 */
type CatalogueKey = ReturnType<typeof publicQueryKeys.cataloguePages>;

export const catalogueQueryOptions = (search: string) =>
  infiniteQueryOptions<
    CataloguePage,
    Error,
    CatalogueData,
    CatalogueKey,
    CataloguePageParam
  >({
    queryKey: publicQueryKeys.cataloguePages(search),
    queryFn: restoreBeforeFailing(
      async ({
        pageParam,
        signal,
      }: QueryFunctionContext<
        CatalogueKey,
        CataloguePageParam
      >): Promise<CataloguePage> => ({
        ...(await getPublishedCoursesPage({
          page: pageParam.page,
          perPage: 30,
          revision: pageParam.page > 1 ? pageParam.revision : undefined,
          search,
          signal,
        })),
        query: search,
      }),
    ),
    initialPageParam: {page: 1} as CataloguePageParam,
    getNextPageParam: lastPage =>
      lastPage.hasMore
        ? {page: lastPage.page + 1, revision: lastPage.revision}
        : undefined,
    staleTime: CATALOGUE_STALE_TIME_MS,
    ...(search ? {} : {gcTime: BROWSE_GC_TIME_MS}),
    // A failed refresh keeps the list; foreground retries it immediately.
    refetchOnWindowFocus: query =>
      query.state.status === 'error' ? 'always' : true,
  });

/** After a `reset` page only that page and the ones after it are current. */
export const keepPagesFromLastReset = (data: CatalogueData): CatalogueData => {
  const lastReset = data.pages.reduce(
    (found, page, index) => (index > 0 && page.reset ? index : found),
    0,
  );
  if (lastReset === 0) return data;
  return {
    pages: data.pages
      .slice(lastReset)
      .map((page, index) => (index === 0 ? {...page, reset: false} : page)),
    pageParams: [
      {page: 1} satisfies CataloguePageParam,
      ...data.pageParams.slice(lastReset + 1),
    ],
  };
};

/** Pages merge by course id: a repeated course keeps its first position. */
export const catalogueCourses = (data: CatalogueData | undefined) => {
  if (!data) return null;
  const merged = new Map<string, Course>();
  keepPagesFromLastReset(data).pages.forEach(page =>
    page.courses.forEach(course => merged.set(course.id, course)),
  );
  return [...merged.values()];
};

/**
 * A course answered 404/410 by its details read. Remove it from every loaded
 * catalogue list (and therefore from the device snapshot). A list read that
 * was already in flight is restarted, so its older response cannot bring the
 * course back; other lists are marked stale and confirm on their next read.
 */
export const removeUnavailableCourse = async (
  client: QueryClient,
  courseId: string,
) => {
  const filters = {queryKey: publicQueryKeys.catalogue()};
  const inFlight = client
    .getQueryCache()
    .findAll({...filters, fetchStatus: 'fetching'})
    .map(query => query.queryKey);
  await client.cancelQueries(filters);
  client.setQueriesData<CatalogueData>(
    filters,
    data =>
      data && {
        ...data,
        pages: data.pages.map(page => ({
          ...page,
          courses: page.courses.filter(course => course.id !== courseId),
        })),
      },
  );
  await client.invalidateQueries({...filters, refetchType: 'none'});
  await Promise.all(
    inFlight.map(queryKey => client.refetchQueries({queryKey, exact: true})),
  );
};

/** Courses from the browse list already in memory or restored from device. */
export const knownCatalogueCourses = (client: QueryClient) =>
  catalogueCourses(
    client.getQueryData<CatalogueData>(publicQueryKeys.cataloguePages('')),
  ) ?? [];
