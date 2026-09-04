import {useCallback, useMemo, useRef} from 'react';
import type {Course} from '../../types/Course';
import {useCourseAccessOverlay} from './useCourseAccessOverlay';
import {usePublishedCourseCatalogue} from './usePublishedCourseCatalogue';

type Params = {
  active: boolean;
  appIsActive: boolean;
  identityKey: string;
  searchQuery: string;
};

const addAccess = (
  courses: Course[],
  accessByCourse: ReadonlyMap<string, {progress: number; started: boolean}>,
): Course[] =>
  courses.map(course => {
    const access = accessByCourse.get(String(course.id));
    return access
      ? {
          ...course,
          owned: true,
          progress: access.progress,
          started: access.started,
        }
      : course;
  });

/**
 * Home joins two independent reads for presentation only:
 * public discovery and account-owned access.
 */
export const useHomeCatalogue = ({
  active,
  appIsActive,
  identityKey,
  searchQuery,
}: Params) => {
  const publicCatalogue = usePublishedCourseCatalogue({
    active,
    appIsActive,
    searchQuery,
  });
  const access = useCourseAccessOverlay({
    active,
    appIsActive,
    identityKey,
  });
  const refreshFlightRef = useRef<Promise<void> | null>(null);
  const refreshPublicCatalogue = publicCatalogue.refresh;
  const refreshAccess = access.refresh;

  const accessByCourse = useMemo(
    () =>
      new Map(
        access.courses.map(course => [String(course.id), course] as const),
      ),
    [access.courses],
  );
  const catalogue = useMemo(
    () => addAccess(publicCatalogue.browseCourses, accessByCourse),
    [accessByCourse, publicCatalogue.browseCourses],
  );
  const remoteCourses = useMemo(
    () =>
      publicCatalogue.courses
        ? addAccess(publicCatalogue.courses, accessByCourse)
        : null,
    [accessByCourse, publicCatalogue.courses],
  );

  const refresh = useCallback(() => {
    if (refreshFlightRef.current) return refreshFlightRef.current;
    const flight = Promise.all([refreshPublicCatalogue(), refreshAccess()])
      .then(() => undefined)
      .finally(() => {
        if (refreshFlightRef.current === flight)
          refreshFlightRef.current = null;
      });
    refreshFlightRef.current = flight;
    return flight;
  }, [refreshAccess, refreshPublicCatalogue]);

  return {
    catalogue,
    error: publicCatalogue.error,
    handleScroll: publicCatalogue.handleScroll,
    loadMore: publicCatalogue.loadMore,
    loading: publicCatalogue.loading,
    loadingMore: publicCatalogue.loadingMore,
    loadMoreError: publicCatalogue.loadMoreError,
    loadedSearchQuery: publicCatalogue.loadedSearchQuery,
    refresh,
    remoteCourses,
    serverSession: access.session,
    staleNotice: publicCatalogue.staleNotice,
  };
};
