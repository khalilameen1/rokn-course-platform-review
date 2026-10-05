import {useCallback, useRef, useState} from 'react';
import {Alert} from 'react-native';
import type {AccountSessionBoundary} from '../../../constants/helpers';
import {pausePortfolioMediaDelivery} from '../../../services/portfolioMediaDelivery';
import {isPortfolioAccountChangedError} from './portfolioModel';

/** Presentation owner for one explicitly started/resumed project's transfer. */
export const usePortfolioUploadSession = (
  mountedRef: React.MutableRefObject<boolean>,
) => {
  const ownerRef = useRef<{
    projectId: string;
    boundary: AccountSessionBoundary;
  } | null>(null);
  const pauseFlightRef = useRef<Promise<boolean> | null>(null);
  const [canPause, setCanPause] = useState(false);
  const [pausing, setPausing] = useState(false);
  const begin = useCallback(
    (projectId: string, boundary: AccountSessionBoundary) => {
      ownerRef.current = {projectId, boundary};
      pauseFlightRef.current = null;
      if (mountedRef.current) setCanPause(true);
    },
    [mountedRef],
  );
  const pause = useCallback(() => {
    if (pauseFlightRef.current) return pauseFlightRef.current;
    const owner = ownerRef.current;
    if (!owner) return Promise.resolve(false);
    if (mountedRef.current) setPausing(true);
    const flight = pausePortfolioMediaDelivery(owner.projectId, owner.boundary)
      .catch(error => {
        if (
          mountedRef.current &&
          ownerRef.current === owner &&
          !isPortfolioAccountChangedError(error)
        ) {
          Alert.alert('تعذّر إيقاف الرفع', 'الرفع مستمر\nحاول مرة أخرى');
        }
        return false;
      })
      .then(paused => {
        if (ownerRef.current === owner && mountedRef.current) {
          setPausing(false);
          if (paused) setCanPause(false);
        }
        if (!paused && pauseFlightRef.current === flight)
          pauseFlightRef.current = null;
        return paused;
      });
    pauseFlightRef.current = flight;
    return flight;
  }, [mountedRef]);
  const end = useCallback(async () => {
    const owner = ownerRef.current;
    const paused = await (pauseFlightRef.current ?? Promise.resolve(false));
    if (ownerRef.current === owner) {
      ownerRef.current = null;
      pauseFlightRef.current = null;
      if (mountedRef.current) {
        setCanPause(false);
        setPausing(false);
      }
    }
    return paused;
  }, [mountedRef]);
  return {begin, end, pause, canPause, pausing};
};
