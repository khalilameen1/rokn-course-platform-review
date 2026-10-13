import {useCallback, useLayoutEffect, useMemo, useRef} from 'react';

export type PreparedMutationFlight = {
  phase: 'preparing' | 'dispatched';
  ownsVisit: () => boolean;
};

/** A departed visit cannot start a mutation on its next appearance. This lease
 * owns preparation only; dispatched work keeps its account/domain owner. */
export function usePreparationOwner(scope: string, available: boolean) {
  const visit = useMemo(() => ({scope, available}), [scope, available]);
  const current = useRef(visit);
  const mounted = useRef(true);
  useLayoutEffect(() => {
    current.current = visit;
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, [visit]);
  return useCallback(
    () => mounted.current && current.current === visit && visit.available,
    [visit],
  );
}
