import {useCallback, useRef} from 'react';

import {
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';

/**
 * Owns the account for the lifetime of one mounted gallery. Token refreshes may
 * advance the session epoch, but a different storage scope must remount the
 * gallery before it can read, render or mutate that account's portfolio.
 */
export const usePortfolioOwnerBoundary = () => {
  const boundaryRef = useRef<AccountSessionBoundary | null>(null);

  const captureBoundary = useCallback(async () => {
    const boundary = await captureAccountSessionBoundary();
    const previous = boundaryRef.current;
    if (previous && previous.scope !== boundary.scope) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
    boundaryRef.current = boundary;
    return boundary;
  }, []);

  return {boundaryRef, captureBoundary};
};
