import {
  keepPreviousData,
  useInfiniteQuery,
  useQueryClient,
  type InfiniteQueryObserverResult,
} from '@tanstack/react-query';
import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {type NativeScrollEvent, type NativeSyntheticEvent} from 'react-native';
import {normalizeText} from '../../utils/searchText';
import {
  friendlyNetworkMessage,
  transientReadFailureAllowsCache,
} from '../../services/networkExperience';
import {
  catalogueCourses,
  catalogueQueryOptions,
  keepPagesFromLastReset,
  type CatalogueData,
} from '../../services/query/catalogueQueries';
import {isRestoredSnapshot} from '../../services/query/queryClient';
import {publicQueryKeys} from '../../services/query/queryKeys';

type Params = {
  active: boolean;
  appIsActive: boolean;
  searchQuery: string;
};

const CACHE_NOTICE = 'نعرض النسخة المحفوظة\nسنحدّثها عند عودة الاتصال';
const OFFLINE_NOTICE = 'نعرض النسخة المحفوظة\nأعد المحاولة عند عودة الاتصال';
const LOAD_MORE_ERROR = 'تعذّر تحميل المزيد\nحاول مرة أخرى';
const SEARCH_DEBOUNCE_MS = 350;
/** A key no normalized search can produce (searches are trimmed). */
const IDLE_SEARCH = ' ';
const RETRY_INTERVAL_MS = 20_000;
const RETRY_ATTEMPTS = 3;
/** The browse snapshot's device age limit; older copies are not "saved". */
const BROWSE_SNAPSHOT_MAX_AGE_MS = 2 * 60 * 60 * 1000;

type CatalogueResult = InfiniteQueryObserverResult<CatalogueData, Error>;

/** A next-page failure keeps the list; only a list read can fail the list. */
const listError = (result: CatalogueResult) =>
  result.isError && !result.isFetchNextPageError && !result.isFetching
    ? result.error
    : null;

/** Published course discovery. This state is public and account-neutral. */
export const usePublishedCourseCatalogue = ({
  active,
  appIsActive,
  searchQuery,
}: Params) => {
  const client = useQueryClient();
  const currentQuery = normalizeText(searchQuery);
  const [requestedQuery, setRequestedQuery] = useState(currentQuery);
  const [manualRefreshing, setManualRefreshing] = useState(false);
  // Home opened straight into a search reads the browse list only once the
  // learner actually returns to browsing, as before.
  const [browseWanted, setBrowseWanted] = useState(currentQuery === '');
  useEffect(() => {
    if (currentQuery === '') setBrowseWanted(true);
  }, [currentQuery]);
  const refreshFlightRef = useRef<Promise<void> | null>(null);

  // TanStack "Disable queries on out of focus screens": Home keeps its data
  // while another screen is on top, and resubscribing refetches stale lists.
  const browse = useInfiniteQuery({
    ...catalogueQueryOptions(''),
    enabled: browseWanted || currentQuery === '',
    subscribed: active,
  });
  // Browsing has no search observer: two observers with different options
  // on the browse entry would keep re-applying their options to it.
  const search = useInfiniteQuery({
    ...catalogueQueryOptions(requestedQuery || IDLE_SEARCH),
    enabled: requestedQuery !== '',
    subscribed: active,
    placeholderData: keepPreviousData,
  });

  // Typing waits 350 ms before asking the server. Clearing the field, or
  // returning to a query whose result is already cached, applies at once and
  // abandons the unfinished request for the other query.
  useEffect(() => {
    if (currentQuery === requestedQuery) return;
    const cached =
      currentQuery === '' ||
      client.getQueryData(publicQueryKeys.cataloguePages(currentQuery)) !==
        undefined;
    if (cached) {
      setRequestedQuery(currentQuery);
      return;
    }
    const timer = setTimeout(
      () => setRequestedQuery(currentQuery),
      SEARCH_DEBOUNCE_MS,
    );
    return () => clearTimeout(timer);
  }, [client, currentQuery, requestedQuery]);

  // The result owner for the typed query: the observer once it follows that
  // query, otherwise that query's cached result (returning to an earlier
  // search shows it on the very first render, before the observer switches).
  const observerFollowsInput = requestedQuery === currentQuery;
  const currentSearchData = !currentQuery
    ? undefined
    : observerFollowsInput
    ? search.isPlaceholderData
      ? undefined
      : search.data
    : client.getQueryData<CatalogueData>(
        publicQueryKeys.cataloguePages(currentQuery),
      );

  const browseCoursesList = useMemo(
    () => catalogueCourses(browse.data),
    [browse.data],
  );
  const currentSearchCourses = useMemo(
    () => catalogueCourses(currentSearchData),
    [currentSearchData],
  );
  const previousSearchCourses = useMemo(
    () => catalogueCourses(search.data),
    [search.data],
  );

  const current = currentQuery ? search : browse;
  const hasCurrentResult = currentQuery
    ? currentSearchData !== undefined
    : browse.data !== undefined;
  const currentListError = currentQuery
    ? observerFollowsInput
      ? listError(search)
      : null
    : listError(browse);
  const currentSearchQueryLoaded = hasCurrentResult
    ? currentQuery
    : search.data?.pages[0]?.query ?? '';

  const browseQuery = client
    .getQueryCache()
    .find({queryKey: publicQueryKeys.cataloguePages(''), exact: true});
  // The saved-copy notice stays while a retry runs, until a read succeeds.
  const browseFailure =
    browse.isError && !browse.isFetchNextPageError ? browse.error : null;
  let staleNotice = '';
  if (browse.data !== undefined) {
    if (browseFailure) {
      const savedCopyUsable =
        transientReadFailureAllowsCache(browseFailure) &&
        Date.now() - browse.dataUpdatedAt <= BROWSE_SNAPSHOT_MAX_AGE_MS;
      staleNotice = savedCopyUsable ? CACHE_NOTICE : OFFLINE_NOTICE;
    } else if (isRestoredSnapshot(browseQuery)) {
      staleNotice = CACHE_NOTICE;
    }
  }

  const error = currentListError
    ? currentQuery
      ? friendlyNetworkMessage(currentListError, 'نتائج البحث')
      : browse.data === undefined
      ? friendlyNetworkMessage(currentListError, 'الكورسات')
      : ''
    : '';

  // Pull to refresh on a search shows its skeleton again; browsing keeps
  // the visible list while the refresh runs, exactly as before.
  const visibleLoading =
    !error &&
    (currentQuery
      ? !hasCurrentResult ||
        (manualRefreshing && observerFollowsInput && search.isFetching)
      : browse.data === undefined);
  const searchResultsReady =
    Boolean(currentQuery) && hasCurrentResult && !visibleLoading && !error;

  const refreshCurrent = useCallback(async () => {
    const target = currentQuery ? search : browse;
    if (currentQuery && requestedQuery !== currentQuery) {
      setRequestedQuery(currentQuery);
      return;
    }
    await target.refetch();
  }, [browse, currentQuery, requestedQuery, search]);

  const refresh = useCallback(() => {
    if (refreshFlightRef.current) return refreshFlightRef.current;
    setManualRefreshing(true);
    const flight = refreshCurrent()
      .catch(() => undefined)
      .finally(() => {
        if (refreshFlightRef.current === flight) {
          refreshFlightRef.current = null;
          setManualRefreshing(false);
        }
      });
    refreshFlightRef.current = flight;
    return flight;
  }, [refreshCurrent]);

  // Foreground return refetches stale lists through focusManager; a list
  // showing an error or a saved copy also retries (up to 3 times, 20 s apart).
  const retryNeeded = Boolean(error || (!currentQuery && staleNotice));
  const isFetchingRef = useRef(current.isFetching);
  isFetchingRef.current = current.isFetching;
  const refetchRef = useRef(current.refetch);
  refetchRef.current = current.refetch;
  useEffect(() => {
    if (!active || !appIsActive || !retryNeeded) return;
    let attempts = 0;
    const timer = setInterval(() => {
      if (isFetchingRef.current) return;
      if (attempts >= RETRY_ATTEMPTS) return clearInterval(timer);
      attempts += 1;
      void refetchRef.current().catch(() => undefined);
    }, RETRY_INTERVAL_MS);
    return () => clearInterval(timer);
  }, [active, appIsActive, retryNeeded]);

  // Returning to Home while it shows an error or a saved copy asks again even
  // when the data is not yet stale.
  const wasActiveRef = useRef(active);
  useEffect(() => {
    const returned = active && !wasActiveRef.current;
    wasActiveRef.current = active;
    if (returned && retryNeeded && !isFetchingRef.current) {
      void refetchRef.current().catch(() => undefined);
    }
  }, [active, retryNeeded]);

  const loadMore = useCallback(
    (manualRetry = false) => {
      if (
        visibleLoading ||
        (currentQuery !== '' && !observerFollowsInput) ||
        current.isFetching ||
        !hasCurrentResult ||
        error ||
        !current.hasNextPage ||
        (!manualRetry && current.isFetchNextPageError)
      ) {
        return;
      }
      const key = publicQueryKeys.cataloguePages(currentQuery);
      void current.fetchNextPage({cancelRefetch: false}).then(result => {
        // A revision change answers with page one: that page starts over.
        const data = result.data;
        const restarted = data && keepPagesFromLastReset(data);
        if (data && restarted !== data) client.setQueryData(key, restarted);
      });
    },
    [
      client,
      current,
      currentQuery,
      error,
      hasCurrentResult,
      observerFollowsInput,
      visibleLoading,
    ],
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

  // While a search is pending, the previously loaded list stays the result
  // owner, as before; the search view itself shows its skeleton.
  const courses = currentQuery
    ? currentSearchCourses ?? previousSearchCourses ?? browseCoursesList
    : browseCoursesList;
  const followsCurrent = !currentQuery || observerFollowsInput;

  return {
    browseCourses: currentQuery ? courses ?? [] : browseCoursesList ?? [],
    courses,
    error,
    handleScroll,
    hasMore: followsCurrent && hasCurrentResult && Boolean(current.hasNextPage),
    loadMore: retryLoadMore,
    loading: visibleLoading,
    loadingMore:
      followsCurrent && hasCurrentResult && current.isFetchingNextPage,
    loadMoreError:
      followsCurrent &&
      hasCurrentResult &&
      current.isFetchNextPageError &&
      !current.isFetching
        ? LOAD_MORE_ERROR
        : '',
    loadedSearchQuery: currentQuery ? currentSearchQueryLoaded : '',
    refresh,
    searchResultsReady,
    staleNotice: currentQuery ? '' : staleNotice,
  };
};
