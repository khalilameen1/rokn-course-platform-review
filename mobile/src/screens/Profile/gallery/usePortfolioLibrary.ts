import {useCallback, useRef, useState} from 'react';
import {useFocusEffect} from '@react-navigation/native';
import type {MutableRefObject} from 'react';
import {
  getCachedPortfolio,
  getPortfolio,
  hasSession,
} from '../../../services/roknApi';
import type {AccountSessionBoundary} from '../../../constants/helpers';
import {assertAccountSessionBoundary} from '../../../constants/helpers';
import {settleWithin} from '../../../utils/settleWithin';
import {
  isPortfolioAccountChangedError,
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
    projects,
    serverSession,
    setProjects,
  };
}
