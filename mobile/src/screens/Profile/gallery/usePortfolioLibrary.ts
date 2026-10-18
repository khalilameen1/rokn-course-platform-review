import {useFocusEffect} from '@react-navigation/native';
import {useQuery, useQueryClient} from '@tanstack/react-query';
import {useCallback, useMemo, useRef} from 'react';
import type {MutableRefObject, SetStateAction} from 'react';
import type {AccountSessionBoundary} from '../../../constants/helpers';
import {assertAccountSessionBoundary} from '../../../constants/helpers';
import {useAccountQueryScope} from '../../../services/query/accountQueryScope';
import {accountQueryKeys} from '../../../services/query/queryKeys';
import {restoreBeforeFailing} from '../../../services/query/queryPersistence';
import {
  getPortfolio,
  getPortfolioItem,
  hasSession,
} from '../../../services/roknApi';
import {
  isPortfolioAccountChangedError,
  fallbackPortfolioCover,
  portfolioProjectCoverUri,
  toPortfolioProject,
  type Project,
} from './portfolioModel';

type Params = {
  identityKey: string;
  captureBoundary: () => Promise<AccountSessionBoundary>;
  isLoadBlocked: () => boolean;
  mountedRef: MutableRefObject<boolean>;
};

const NO_PROJECTS: Project[] = [];

/**
 * The owner's project list as one account-scoped query (ARCHITECTURE.md M1):
 * read on mount and on every focus, foreground return and media replay
 * (`loadProjects`), kept on device by the `portfolio` snapshot policy with
 * `shareReady` (publication state and `is_live`) as the screen computed it.
 * The create, edit, media and delete hooks write acknowledged responses into
 * the same entry (`setProjects`, TanStack "Updates from Mutation Responses")
 * and cancel a list read that could land over them (`cancelLoad`); a media
 * delivery confirmed by the outbox invalidates it (`invalidatePortfolio`).
 */
export function usePortfolioLibrary({
  identityKey,
  captureBoundary,
  isLoadBlocked,
  mountedRef,
}: Params) {
  const client = useQueryClient();
  const {scope, failed: scopeFailed} = useAccountQueryScope(identityKey);
  const scopeRef = useRef(scope);
  scopeRef.current = scope;
  const keys = useMemo(() => accountQueryKeys(scope), [scope]);
  const portfolioKey = useMemo(() => keys.portfolio(), [keys]);
  const generationRef = useRef(0);
  const coverRefreshAttemptsRef = useRef(new Set<string>());
  const coverRefreshFlightsRef = useRef(new Set<string>());

  const session = useQuery({
    queryKey: keys.session(),
    queryFn: () => hasSession(),
    enabled: scope !== null,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const signedIn = session.data === true;

  const portfolio = useQuery({
    queryKey: portfolioKey,
    queryFn: restoreBeforeFailing(async (): Promise<Project[]> => {
      const boundary = await captureBoundary();
      if (boundary.scope !== scope) {
        throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
      }
      const items = await getPortfolio(boundary);
      assertAccountSessionBoundary(boundary);
      return items.map(toPortfolioProject);
    }),
    enabled: scope !== null && signedIn,
    // A device snapshot never counts as fresh: the mount read always asks
    // the server. Later reads happen when the gallery asks (focus,
    // foreground, replay, retry) or when a confirmed write invalidates the
    // list; never on a timer or on every return to the app.
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const projects = signedIn ? portfolio.data ?? NO_PROJECTS : NO_PROJECTS;
  // The latest stored list, synchronously after a write too.
  const projectsRef = useRef<Project[]>(projects);
  projectsRef.current = projects;

  /** A mutation starts: an older list response must not land over its edit. */
  const cancelLoad = useCallback(() => {
    generationRef.current += 1;
    const owner = scopeRef.current;
    if (owner === null) return;
    void client
      .cancelQueries({queryKey: accountQueryKeys(owner).portfolio(), exact: true})
      .catch(() => undefined);
  }, [client]);

  const refetchSession = session.refetch;
  const refetchPortfolio = portfolio.refetch;
  const loadProjects = useCallback(async () => {
    if (isLoadBlocked() || scopeRef.current === null) return;
    generationRef.current += 1;
    const result = await refetchSession({cancelRefetch: false});
    if (result.data !== true || !mountedRef.current) return;
    await refetchPortfolio();
  }, [isLoadBlocked, mountedRef, refetchPortfolio, refetchSession]);

  const setProjects = useCallback(
    (update: SetStateAction<Project[]>) => {
      const owner = scopeRef.current;
      if (owner === null) return;
      const queryKey = accountQueryKeys(owner).portfolio();
      client.setQueryData<Project[]>(queryKey, current =>
        typeof update === 'function' ? update(current ?? []) : update,
      );
      projectsRef.current = client.getQueryData<Project[]>(queryKey) ?? [];
    },
    [client],
  );

  const handleProjectCoverError = useCallback(
    (project: Project) => {
      const failedUri = portfolioProjectCoverUri(project);
      if (!failedUri) return;
      const current = projectsRef.current.find(item => item.id === project.id);
      if (!current || portfolioProjectCoverUri(current) !== failedUri) return;
      // Never leave a broken remote image in the grid while its short-lived
      // URL is renewed. The media record stays intact for the detail refresh.
      setProjects(items =>
        items.map(item =>
          item === current ? {...current, cover: fallbackPortfolioCover} : item,
        ),
      );
      // The stored entry (after structural sharing) is the receipt: a renewal
      // replaces exactly it, never a project edited meanwhile.
      const fallbackProject = projectsRef.current.find(
        item => item.id === project.id,
      );
      if (!fallbackProject) return;

      if (
        isLoadBlocked() ||
        coverRefreshAttemptsRef.current.has(project.id) ||
        coverRefreshFlightsRef.current.has(project.id)
      ) {
        return;
      }
      coverRefreshAttemptsRef.current.add(project.id);
      coverRefreshFlightsRef.current.add(project.id);
      const generation = generationRef.current;

      void captureBoundary()
        .then(boundary => getPortfolioItem(project.id, boundary))
        .then(item => {
          if (!mountedRef.current || generation !== generationRef.current)
            return;
          const next = toPortfolioProject(item);
          setProjects(items =>
            items.map(candidate =>
              candidate === fallbackProject ? next : candidate,
            ),
          );
        })
        .catch(error => {
          if (
            isPortfolioAccountChangedError(error) ||
            !mountedRef.current ||
            generation !== generationRef.current
          ) {
            return;
          }
          const status = Number(
            (error as {status?: unknown; response?: {status?: unknown}})
              ?.status ??
              (error as {response?: {status?: unknown}})?.response?.status ??
              0,
          );
          if (status === 404) {
            setProjects(items =>
              items.filter(candidate => candidate !== fallbackProject),
            );
          }
        })
        .finally(() => {
          coverRefreshFlightsRef.current.delete(project.id);
        });
    },
    [captureBoundary, isLoadBlocked, mountedRef, setProjects],
  );

  const handleProjectCoverLoad = useCallback((project: Project) => {
    const loadedUri = portfolioProjectCoverUri(project);
    if (!loadedUri) return;
    const current = projectsRef.current.find(item => item.id === project.id);
    if (current && portfolioProjectCoverUri(current) === loadedUri) {
      coverRefreshAttemptsRef.current.delete(project.id);
    }
  }, []);

  // TanStack React Native guide, "Refresh on Screen focus": the first focus
  // is the mount read; every later focus reads again. Leaving the screen
  // drops a read still in flight, as before.
  const focusedBeforeRef = useRef(false);
  const loadProjectsRef = useRef(loadProjects);
  loadProjectsRef.current = loadProjects;
  const cancelLoadRef = useRef(cancelLoad);
  cancelLoadRef.current = cancelLoad;
  useFocusEffect(
    useCallback(() => {
      if (focusedBeforeRef.current) void loadProjectsRef.current();
      focusedBeforeRef.current = true;
      return () => cancelLoadRef.current();
    }, []),
  );

  const serverSession = scope === null ? null : session.data ?? null;
  const loading =
    scope === null
      ? !scopeFailed
      : !signedIn
      ? session.data === undefined && !session.isError
      : portfolio.isFetching ||
        (portfolio.data === undefined && !portfolio.isError);
  let loadError = '';
  if (scopeFailed || (session.isError && !session.isFetching)) {
    loadError = 'تعذّر تحميل البورتفوليو\nأعد فتح الصفحة';
  } else if (
    signedIn &&
    portfolio.isError &&
    !portfolio.isFetching &&
    !isPortfolioAccountChangedError(portfolio.error)
  ) {
    loadError = 'تعذّر تحميل البورتفوليو\nمشروعاتك محفوظة';
  }

  return {
    cancelLoad,
    loadError,
    loading,
    loadProjects,
    handleProjectCoverError,
    handleProjectCoverLoad,
    projects,
    serverSession,
    setProjects,
  };
}
