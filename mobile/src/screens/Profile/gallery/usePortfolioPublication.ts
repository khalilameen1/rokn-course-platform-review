import {useCallback, useEffect, useRef} from 'react';

import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  finalizePortfolioItem,
  getPortfolioItem,
  type PortfolioItem,
} from '../../../services/roknApi';
import {errorStatus} from '../../../utils/errorPayload';
import {portfolioPublicationDisposition} from '../portfolioState';
import {
  isPortfolioAccountChangedError,
  toPortfolioProject,
} from './portfolioModel';

type Options = {
  commit: (item: PortfolioItem, detailGeneration?: number) => void;
  mountedRef: React.MutableRefObject<boolean>;
};

export type PortfolioPublicationResult =
  | 'published'
  | 'processing'
  | 'incomplete';

const RETRY_DELAYS_MS = [3_000, 7_000, 15_000, 30_000];

/**
 * Owns the short gap between an accepted upload and Bunny making that media
 * playable. Upload state never doubles as publication state: the server is
 * re-read before each retry and only a successful finalize exposes sharing.
 */
export const usePortfolioPublication = ({commit, mountedRef}: Options) => {
  const flightsRef = useRef(new Map<string, symbol>());
  const waitsRef = useRef(
    new Map<ReturnType<typeof setTimeout>, (active: boolean) => void>(),
  );

  useEffect(
    () => () => {
      flightsRef.current.clear();
      for (const [timer, resolve] of waitsRef.current) {
        clearTimeout(timer);
        resolve(false);
      }
      waitsRef.current.clear();
    },
    [],
  );

  const wait = useCallback(
    (milliseconds: number) =>
      new Promise<boolean>(resolve => {
        const timer = setTimeout(() => {
          waitsRef.current.delete(timer);
          resolve(mountedRef.current);
        }, milliseconds);
        waitsRef.current.set(timer, resolve);
      }),
    [mountedRef],
  );

  const attempt = useCallback(
    async (
      projectId: string,
      boundary: AccountSessionBoundary,
      detailGeneration?: number,
    ): Promise<PortfolioPublicationResult> => {
      try {
        const published = await finalizePortfolioItem(projectId, boundary);
        assertAccountSessionBoundary(boundary);
        commit(published, detailGeneration);
        return 'published';
      } catch (error: unknown) {
        if (isPortfolioAccountChangedError(error)) throw error;
        if (errorStatus(error) !== 409) throw error;

        const current = await getPortfolioItem(projectId, boundary);
        assertAccountSessionBoundary(boundary);
        commit(current, detailGeneration);
        return portfolioPublicationDisposition(toPortfolioProject(current)) ===
          'retry'
          ? 'processing'
          : 'incomplete';
      }
    },
    [commit],
  );

  const continueInBackground = useCallback(
    (
      projectId: string,
      boundary: AccountSessionBoundary,
      detailGeneration?: number,
    ) => {
      if (flightsRef.current.has(projectId)) return;
      const flight = Symbol(projectId);
      flightsRef.current.set(projectId, flight);

      void (async () => {
        for (const delay of RETRY_DELAYS_MS) {
          if (!(await wait(delay))) return;
          if (flightsRef.current.get(projectId) !== flight) return;
          try {
            const result = await attempt(projectId, boundary, detailGeneration);
            if (result !== 'processing') return;
          } catch (error: unknown) {
            if (isPortfolioAccountChangedError(error)) return;
            // Foreground replay or the explicit completion action owns later
            // recovery; an offline phone must not enter a request loop.
            return;
          }
        }
      })().finally(() => {
        if (flightsRef.current.get(projectId) === flight) {
          flightsRef.current.delete(projectId);
        }
      });
    },
    [attempt, wait],
  );

  const finalizeAfterUpload = useCallback(
    async (
      projectId: string,
      boundary: AccountSessionBoundary,
      detailGeneration?: number,
    ) => {
      // A processing item already owns a bounded reconciliation flight. A
      // remount/library refresh may rediscover it, but must not create a
      // second finalize loop for the same item.
      if (flightsRef.current.has(projectId)) return 'processing' as const;
      const result = await attempt(projectId, boundary, detailGeneration);
      if (result === 'processing') {
        continueInBackground(projectId, boundary, detailGeneration);
      }
      return result;
    },
    [attempt, continueInBackground],
  );

  return {finalizeAfterUpload};
};
