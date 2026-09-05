import {useCallback, useRef, useState} from 'react';
import {useFocusEffect} from '@react-navigation/native';
import type {MutableRefObject} from 'react';
import {
  getCachedPortfolio,
  getPortfolio,
  getPortfolioItem,
  hasSession,
} from '../../../services/roknApi';
import type {AccountSessionBoundary} from '../../../constants/helpers';
import {assertAccountSessionBoundary} from '../../../constants/helpers';
import {settleWithin} from '../../../utils/settleWithin';
import {
  isPortfolioAccountChangedError,
  fallbackPortfolioCover,
  portfolioProjectCoverUri,
  toPortfolioProject,
  type Project,
} from './portfolioModel';

type Params = {
  captureBoundary: () => Promise<AccountSessionBoundary>;
  isLoadBlocked: () => boolean;
  mountedRef: MutableRefObject<boolean>;
};

export function usePortfolioLibrary({
  captureBoundary,
  isLoadBlocked,
  mountedRef,
}: Params) {
  const [projects, setProjects] = useState<Project[]>([]);
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [loading, setLoading] = useState(true);
  const [loadError, setLoadError] = useState('');
  const generationRef = useRef(0);
  const coverRefreshAttemptsRef = useRef(new Set<string>());
  const coverRefreshFlightsRef = useRef(new Set<string>());
  const projectsRef = useRef<Project[]>(projects);
  projectsRef.current = projects;

  const cancelLoad = useCallback(() => {
    generationRef.current += 1;
    if (mountedRef.current) setLoading(false);
  }, [mountedRef]);

  const loadProjects = useCallback(async () => {
    if (isLoadBlocked()) return;
    const generation = ++generationRef.current;
    const isCurrent = () =>
      mountedRef.current && generation === generationRef.current;
    setLoading(true);
    let boundary: AccountSessionBoundary;
    let sessionAvailable = false;
    try {
      boundary = await captureBoundary();
      sessionAvailable = await hasSession();
      assertAccountSessionBoundary(boundary);
    } catch (error: unknown) {
      if (isPortfolioAccountChangedError(error)) return;
      if (isCurrent()) {
        setLoadError('تعذّر تحميل البورتفوليو\nأعد فتح الصفحة');
        setLoading(false);
      }
      return;
    }
    if (!isCurrent()) return;
    setServerSession(sessionAvailable);
    if (!sessionAvailable) {
      setProjects([]);
      setLoadError('');
      setLoading(false);
      return;
    }

    try {
      const remoteItems = getPortfolio(boundary).then(
        value => ({ok: true as const, value}),
        error => ({ok: false as const, error}),
      );
      const cached = await settleWithin(getCachedPortfolio(boundary), []);
      if (!isCurrent()) return;
      if (cached.length && projectsRef.current.length === 0) {
        setProjects(cached.map(toPortfolioProject));
        setLoading(false);
      }
      const remoteResult = await remoteItems;
      if (!remoteResult.ok) throw remoteResult.error;
      if (!isCurrent()) return;
      setProjects(remoteResult.value.map(toPortfolioProject));
      setLoadError('');
    } catch (error: unknown) {
      if (isPortfolioAccountChangedError(error)) return;
      if (isCurrent()) {
        setLoadError('تعذّر تحميل البورتفوليو\nمشروعاتك محفوظة');
      }
    } finally {
      if (isCurrent()) setLoading(false);
    }
  }, [captureBoundary, isLoadBlocked, mountedRef]);

  const handleProjectCoverError = useCallback(
    (project: Project) => {
      const failedUri = portfolioProjectCoverUri(project);
      if (!failedUri) return;
      const current = projectsRef.current.find(item => item.id === project.id);
      if (!current || portfolioProjectCoverUri(current) !== failedUri) return;
      const fallbackProject = {...current, cover: fallbackPortfolioCover};

      // Never leave a broken remote image in the grid while its short-lived
      // URL is renewed. The media record stays intact for the detail refresh.
      setProjects(items =>
        items.map(item => (item === current ? fallbackProject : item)),
      );

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
    [captureBoundary, isLoadBlocked, mountedRef],
  );

  const handleProjectCoverLoad = useCallback((project: Project) => {
    const loadedUri = portfolioProjectCoverUri(project);
    if (!loadedUri) return;
    const current = projectsRef.current.find(item => item.id === project.id);
    if (current && portfolioProjectCoverUri(current) === loadedUri) {
      coverRefreshAttemptsRef.current.delete(project.id);
    }
  }, []);

  useFocusEffect(
    useCallback(() => {
      void loadProjects();
      return cancelLoad;
    }, [cancelLoad, loadProjects]),
  );

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
