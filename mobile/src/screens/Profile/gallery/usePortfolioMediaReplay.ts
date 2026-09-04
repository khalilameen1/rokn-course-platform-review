import {useCallback, useEffect, useRef} from 'react';

import {replayPendingPortfolioMediaUploads} from '../../../services/portfolioMediaReplay';

type Options = {
  appActive: boolean;
  loadProjects: () => Promise<void>;
  mountedRef: React.MutableRefObject<boolean>;
  onUploadsSettled?: (projectIds: string[]) => void | Promise<void>;
  refreshOpenProject: (force?: boolean) => Promise<void>;
  selectedRef: React.MutableRefObject<{source: string} | null>;
  serverSession: boolean | null;
};

/** Coalesces startup, foreground and project-open replay into one refresh. */
export const usePortfolioMediaReplay = ({
  appActive,
  loadProjects,
  mountedRef,
  onUploadsSettled,
  refreshOpenProject,
  selectedRef,
  serverSession,
}: Options) => {
  const refreshFlightRef = useRef<Promise<void> | null>(null);
  const completionRevisionRef = useRef(0);

  const replayPortfolioMedia = useCallback(() => {
    if (refreshFlightRef.current) return refreshFlightRef.current;
    const flight = (async () => {
      const result = await replayPendingPortfolioMediaUploads();
      if (
        !mountedRef.current ||
        result.completionRevision <= completionRevisionRef.current
      ) {
        return;
      }
      completionRevisionRef.current = result.completionRevision;
      await onUploadsSettled?.(result.completedProjectIds);
      if (!mountedRef.current) return;
      await loadProjects();
      if (selectedRef.current?.source === 'remote') {
        await refreshOpenProject(true);
      }
    })().finally(() => {
      if (refreshFlightRef.current === flight) refreshFlightRef.current = null;
    });
    refreshFlightRef.current = flight;
    return flight;
  }, [
    loadProjects,
    mountedRef,
    onUploadsSettled,
    refreshOpenProject,
    selectedRef,
  ]);

  useEffect(() => {
    if (!appActive || serverSession !== true) return;
    void replayPortfolioMedia().catch(() => undefined);
  }, [appActive, replayPortfolioMedia, serverSession]);

  return replayPortfolioMedia;
};
