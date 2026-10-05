import {useCallback, useEffect, useRef, useState} from 'react';
import type {ScrollView} from 'react-native';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';

type GuestHandoff = {
  scope: string;
  offset?: number;
  query?: string;
  createdAt: number;
};

type RestorePosition = {identityKey: string; offset: number};

let guestHandoff: GuestHandoff | null = null;

const scrollKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey('@rokn/home-scroll/v1', boundary);

type HomeScrollMemoryInput = {
  active: boolean;
  identityKey: string;
  loading: boolean;
  searchQuery: string;
  setSearchQuery: (query: string) => void;
};

export const useHomeScrollMemory = ({
  active,
  identityKey,
  loading,
  searchQuery,
  setSearchQuery,
}: HomeScrollMemoryInput) => {
  const scrollRef = useRef<ScrollView | null>(null);
  const saveTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const restoreTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const boundaryRef = useRef<AccountSessionBoundary | null>(null);
  const boundaryFlightRef = useRef<Promise<AccountSessionBoundary> | null>(
    null,
  );
  const boundaryGenerationRef = useRef(0);
  const latestOffsetRef = useRef<number | null>(null);
  const userMovedRef = useRef(false);
  const writeTailRef = useRef<Promise<void>>(Promise.resolve());
  const searchQueryRef = useRef(searchQuery);
  const [restorePosition, setRestorePosition] =
    useState<RestorePosition | null>(null);
  const presentationRef = useRef({active, identityKey, loading, searchQuery});
  presentationRef.current = {active, identityKey, loading, searchQuery};
  searchQueryRef.current = searchQuery;

  useEffect(() => {
    boundaryGenerationRef.current += 1;
    boundaryRef.current = null;
    boundaryFlightRef.current = null;
    userMovedRef.current = false;
    latestOffsetRef.current = null;
    setRestorePosition(null);
    if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
    saveTimerRef.current = null;
  }, [identityKey]);

  const boundary = useCallback(() => {
    if (boundaryRef.current) return Promise.resolve(boundaryRef.current);
    if (boundaryFlightRef.current) return boundaryFlightRef.current;

    const generation = boundaryGenerationRef.current;
    const flight = captureAccountSessionBoundary()
      .then(owner => {
        if (generation !== boundaryGenerationRef.current) {
          throw new Error('HOME_SCROLL_OWNER_CHANGED');
        }
        boundaryRef.current = owner;
        return owner;
      })
      .finally(() => {
        if (boundaryFlightRef.current === flight) {
          boundaryFlightRef.current = null;
        }
      });
    boundaryFlightRef.current = flight;
    return flight;
  }, []);

  const persist = useCallback(() => {
    const offset = latestOffsetRef.current;
    if (offset === null || !Number.isFinite(offset)) return;

    void boundary()
      .then(owner => {
        const write = writeTailRef.current
          .catch(() => undefined)
          .then(async () => {
            assertAccountSessionBoundary(owner);
            await saveItem(await scrollKey(owner), offset);
            assertAccountSessionBoundary(owner);
          });
        writeTailRef.current = write.catch(() => undefined);
        return write;
      })
      .catch(() => undefined);
  }, [boundary]);

  useEffect(() => {
    return () => {
      if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
      const owner = boundaryRef.current;
      const offset = latestOffsetRef.current;
      const query = searchQueryRef.current.trim();
      if (
        owner?.scope.startsWith('guest-') &&
        ((offset !== null && Number.isFinite(offset)) || query)
      ) {
        guestHandoff = {
          scope: owner.scope,
          ...(offset !== null && Number.isFinite(offset)
            ? {offset: Math.max(0, offset)}
            : {}),
          ...(query ? {query: query.slice(0, 240)} : {}),
          createdAt: Date.now(),
        };
      }
      persist();
    };
  }, [persist]);

  useEffect(() => {
    let current = true;
    void boundary()
      .then(async owner => {
        let offset = await getItem<number>(await scrollKey(owner));
        assertAccountSessionBoundary(owner);
        if (!current) return;
        const handoff = guestHandoff;
        const canAdopt = Boolean(
          owner.scope.startsWith('user-') &&
            handoff?.scope.startsWith('guest-') &&
            handoff.scope !== owner.scope &&
            Date.now() - handoff.createdAt < 5 * 60 * 1000,
        );
        if (
          canAdopt &&
          typeof handoff?.offset === 'number' &&
          Number.isFinite(handoff.offset)
        ) {
          offset = handoff.offset;
          await saveItem(await scrollKey(owner), offset);
          assertAccountSessionBoundary(owner);
        }
        if (!current) return;
        if (canAdopt && handoff?.query) setSearchQuery(handoff.query);
        if (owner.scope.startsWith('user-')) guestHandoff = null;
        if (
          !current ||
          userMovedRef.current ||
          typeof offset !== 'number' ||
          !Number.isFinite(offset)
        ) {
          return;
        }
        const normalized = Math.max(0, offset);
        latestOffsetRef.current = normalized;
        setRestorePosition({identityKey, offset: normalized});
      })
      .catch(() => undefined);
    return () => {
      current = false;
    };
  }, [boundary, identityKey, setSearchQuery]);

  useEffect(() => {
    if (active) return;
    if (saveTimerRef.current) {
      clearTimeout(saveTimerRef.current);
      saveTimerRef.current = null;
    }
    persist();
  }, [active, persist]);

  useEffect(() => {
    if (
      !active ||
      loading ||
      searchQuery.trim() ||
      userMovedRef.current ||
      !restorePosition ||
      restorePosition.identityKey !== identityKey ||
      !scrollRef.current
    ) {
      return undefined;
    }
    const scrollView = scrollRef.current;
    const timer = setTimeout(() => {
      if (restoreTimerRef.current !== timer) return;
      restoreTimerRef.current = null;
      const presentation = presentationRef.current;
      // A delayed restore is optional. Current interaction, search, screen or
      // account takes precedence even before effect cleanup has run.
      if (
        !presentation.active ||
        presentation.loading ||
        presentation.identityKey !== restorePosition.identityKey ||
        presentation.searchQuery.trim() ||
        userMovedRef.current ||
        scrollRef.current !== scrollView
      )
        return;
      scrollView.scrollTo({y: restorePosition.offset, animated: false});
      setRestorePosition(null);
    }, 80);
    restoreTimerRef.current = timer;
    return () => {
      clearTimeout(timer);
      if (restoreTimerRef.current === timer) restoreTimerRef.current = null;
    };
  }, [active, identityKey, loading, restorePosition, searchQuery]);

  const bind = useCallback((scrollView: ScrollView | null) => {
    scrollRef.current = scrollView;
  }, []);

  const record = useCallback(
    (offset: number) => {
      if (searchQueryRef.current.trim()) return;
      latestOffsetRef.current = Math.max(0, offset);
      if (saveTimerRef.current) clearTimeout(saveTimerRef.current);
      saveTimerRef.current = setTimeout(() => {
        saveTimerRef.current = null;
        persist();
      }, 600);
    },
    [persist],
  );

  const markUserMoved = useCallback(() => {
    userMovedRef.current = true;
    if (restoreTimerRef.current) clearTimeout(restoreTimerRef.current);
    restoreTimerRef.current = null;
    setRestorePosition(null);
  }, []);

  return {bind, markUserMoved, record};
};
