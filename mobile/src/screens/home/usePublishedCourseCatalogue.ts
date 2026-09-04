import {useCallback, useEffect, useRef, useState} from 'react';
import {type NativeScrollEvent, type NativeSyntheticEvent} from 'react-native';
import type {Course} from '../../types/Course';
import {normalizeText} from '../../utils/searchText';
import {settleWithin} from '../../utils/settleWithin';
import {
  getCachedPublishedCourses,
  getPublishedCoursesPage,
  subscribeToUnavailableCourses,
} from '../../services/roknApi';
import {
  friendlyNetworkMessage,
  networkFailureKind,
} from '../../services/networkExperience';

type LoadOptions = {
  append?: boolean;
  blocking?: boolean;
  page?: number;
  query?: string;
};

type Params = {
  active: boolean;
  appIsActive: boolean;
  searchQuery: string;
};

const CACHE_NOTICE = 'نعرض النسخة المحفوظة\nسنحدّثها عند عودة الاتصال';
const OFFLINE_NOTICE = 'نعرض النسخة المحفوظة\nأعد المحاولة عند عودة الاتصال';

/** Published course discovery. This state is public and account-neutral. */
export const usePublishedCourseCatalogue = ({
  active,
  appIsActive,
  searchQuery,
}: Params) => {
  const [courses, setCourses] = useState<Course[] | null>(null);
  const [error, setError] = useState('');
  const [staleNotice, setStaleNotice] = useState('');
  const [loading, setLoading] = useState(true);
  const [loadingMore, setLoadingMore] = useState(false);
  const [loadMoreError, setLoadMoreError] = useState('');
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);

  const controllerRef = useRef<AbortController | null>(null);
  const requestIdRef = useRef(0);
  const refreshFlightRef = useRef<Promise<void> | null>(null);
  const loadingMoreRef = useRef(false);
  const browseCoursesRef = useRef<Course[]>([]);
  const catalogueRevisionRef = useRef<number | undefined>(undefined);
  const loadedQueryRef = useRef('');
  const requestedQueryRef = useRef('');
  const activeQueryRef = useRef(normalizeText(searchQuery));
  const lastAttemptAtRef = useRef(0);
  const lastSuccessAtRef = useRef(0);
  const wasActiveRef = useRef(active);
  const wasAppActiveRef = useRef(appIsActive);
  activeQueryRef.current = normalizeText(searchQuery);

  const load = useCallback(
    async ({
      append = false,
      blocking = true,
      page: requestedPage = 1,
      query = '',
    }: LoadOptions = {}) => {
      if (append && loadingMoreRef.current) return;

      controllerRef.current?.abort();
      const controller = new AbortController();
      controllerRef.current = controller;
      const requestId = ++requestIdRef.current;
      const normalizedQuery = normalizeText(query);
      requestedQueryRef.current = normalizedQuery;
      lastAttemptAtRef.current = Date.now();
      loadingMoreRef.current = append;

      if (!append) setLoadMoreError('');
      if (append) {
        setLoadingMore(true);
        setLoadMoreError('');
      } else if (blocking) {
        setLoading(
          normalizedQuery !== '' || browseCoursesRef.current.length === 0,
        );
        setError('');
        if (browseCoursesRef.current.length === 0) setCourses(null);
      }

      try {
        const result = await getPublishedCoursesPage({
          page: requestedPage,
          perPage: 30,
          revision: append ? catalogueRevisionRef.current : undefined,
          search: query,
          signal: controller.signal,
        });
        if (requestId !== requestIdRef.current) return;

        setCourses(current => {
          if (!append || result.reset || !current) {
            if (!normalizedQuery) browseCoursesRef.current = result.courses;
            return result.courses;
          }

          const merged = new Map(current.map(course => [course.id, course]));
          result.courses.forEach(course => merged.set(course.id, course));
          const next = [...merged.values()];
          if (!normalizedQuery) browseCoursesRef.current = next;
          return next;
        });
        catalogueRevisionRef.current = result.revision;
        loadedQueryRef.current = normalizedQuery;
        setPage(result.page);
        setHasMore(result.hasMore);
        setError('');
        setLoadMoreError('');
        if (result.fromCache) {
          setStaleNotice(CACHE_NOTICE);
        } else {
          lastSuccessAtRef.current = Date.now();
          setStaleNotice('');
        }
      } catch (requestError) {
        if (networkFailureKind(requestError) === 'cancelled') return;
        if (requestId !== requestIdRef.current) return;

        if (append) {
          setLoadMoreError('تعذّر تحميل المزيد\nحاول مرة أخرى');
          return;
        }

        const hasBrowseSnapshot = browseCoursesRef.current.length > 0;
        const isSearch = normalizedQuery !== '';
        setHasMore(false);
        setPage(1);
        if (hasBrowseSnapshot && !isSearch) setStaleNotice(OFFLINE_NOTICE);
        setError(
          hasBrowseSnapshot && !isSearch
            ? ''
            : friendlyNetworkMessage(
                requestError,
                isSearch ? 'نتائج البحث' : 'الكورسات',
              ),
        );
      } finally {
        if (requestId === requestIdRef.current) {
          if (controllerRef.current === controller)
            controllerRef.current = null;
          loadingMoreRef.current = false;
          setLoading(false);
          setLoadingMore(false);
        }
      }
    },
    [],
  );

  useEffect(() => {
    let mounted = true;

    void load({query: activeQueryRef.current});
    void settleWithin(getCachedPublishedCourses(), []).then(cached => {
      if (
        !mounted ||
        !cached.length ||
        activeQueryRef.current ||
        lastSuccessAtRef.current ||
        browseCoursesRef.current.length
      ) {
        return;
      }
      browseCoursesRef.current = cached;
      setCourses(cached);
      setError('');
      setStaleNotice(CACHE_NOTICE);
      setLoading(false);
    });

    return () => {
      mounted = false;
      controllerRef.current?.abort();
      controllerRef.current = null;
      requestIdRef.current += 1;
      loadingMoreRef.current = false;
    };
  }, [load]);

  useEffect(
    () =>
      subscribeToUnavailableCourses(courseId => {
        browseCoursesRef.current = browseCoursesRef.current.filter(
          course => course.id !== courseId,
        );
        setCourses(current =>
          current ? current.filter(course => course.id !== courseId) : current,
        );
      }),
    [],
  );

  useEffect(() => {
    const returnedToScreen = active && !wasActiveRef.current;
    const returnedToApp = appIsActive && !wasAppActiveRef.current;
    wasActiveRef.current = active;
    wasAppActiveRef.current = appIsActive;

    if (!active || !appIsActive || (!returnedToScreen && !returnedToApp))
      return;
    const now = Date.now();
    if (!returnedToScreen && now - lastAttemptAtRef.current < 3_000) return;
    if (
      !returnedToScreen &&
      !error &&
      !staleNotice &&
      now - lastSuccessAtRef.current < 2 * 60 * 1000
    ) {
      return;
    }
    void load({
      blocking: browseCoursesRef.current.length === 0,
      query: activeQueryRef.current,
    });
  }, [active, appIsActive, error, load, staleNotice]);

  useEffect(() => {
    if (!active || !appIsActive || (!error && !staleNotice)) return;
    let attempts = 0;
    const timer = setInterval(() => {
      if (controllerRef.current) return;
      if (attempts >= 3) return clearInterval(timer);
      attempts += 1;
      void load({
        blocking: browseCoursesRef.current.length === 0,
        query: activeQueryRef.current,
      });
    }, 20_000);
    return () => clearInterval(timer);
  }, [active, appIsActive, error, load, staleNotice]);

  useEffect(() => {
    const query = normalizeText(searchQuery);
    if (query === loadedQueryRef.current) return;
    if (controllerRef.current && query === requestedQueryRef.current) return;

    controllerRef.current?.abort();
    controllerRef.current = null;
    requestIdRef.current += 1;
    loadingMoreRef.current = false;
    catalogueRevisionRef.current = undefined;
    setLoading(Boolean(query) || browseCoursesRef.current.length === 0);
    setLoadMoreError('');
    const timer = setTimeout(() => void load({query}), 350);
    return () => clearTimeout(timer);
  }, [load, searchQuery]);

  const refresh = useCallback(() => {
    if (refreshFlightRef.current) return refreshFlightRef.current;
    const flight = load({query: normalizeText(searchQuery)}).finally(() => {
      if (refreshFlightRef.current === flight) refreshFlightRef.current = null;
    });
    refreshFlightRef.current = flight;
    return flight;
  }, [load, searchQuery]);

  const loadMore = useCallback(
    (manualRetry = false) => {
      if (
        loading ||
        loadingMore ||
        controllerRef.current ||
        !hasMore ||
        (!manualRetry && loadMoreError)
      ) {
        return;
      }
      void load({
        append: true,
        blocking: false,
        page: page + 1,
        query: loadedQueryRef.current,
      });
    },
    [hasMore, load, loading, loadingMore, loadMoreError, page],
  );

  const handleScroll = useCallback(
    (event: NativeSyntheticEvent<NativeScrollEvent>) => {
      const {contentOffset, contentSize, layoutMeasurement} = event.nativeEvent;
      if (
        contentOffset.y + layoutMeasurement.height >=
        contentSize.height - 320
      ) {
        loadMore();
      }
    },
    [loadMore],
  );

  const retryLoadMore = useCallback(() => loadMore(true), [loadMore]);

  const browseCourses =
    !normalizeText(searchQuery) && loadedQueryRef.current
      ? browseCoursesRef.current
      : courses ?? [];

  return {
    browseCourses,
    courses,
    error,
    handleScroll,
    loadMore: retryLoadMore,
    loading,
    loadingMore,
    loadMoreError,
    loadedSearchQuery: loadedQueryRef.current,
    refresh,
    staleNotice,
  };
};
