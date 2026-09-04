import {useCallback, useEffect, useRef, useState} from 'react';
import {
  checkAppUpdatePolicy,
  dismissAppUpdate,
  shouldPresentAppUpdate,
} from '../../services/appVersionCheck';
import type {AppUpdateNotice} from '../../services/appVersionPolicy';

const monotonicNow = () => {
  const value = globalThis.performance?.now?.();
  return Number.isFinite(value) ? Number(value) : Date.now();
};

export const useAppUpdateNotice = () => {
  const [notice, setNotice] = useState<AppUpdateNotice | null>(null);
  const lastCheckAt = useRef(Number.NEGATIVE_INFINITY);
  const checkFlight = useRef<Promise<void> | null>(null);
  const mounted = useRef(true);

  useEffect(
    () => () => {
      mounted.current = false;
    },
    [],
  );

  const refresh = useCallback((force = false) => {
    if (!force && monotonicNow() - lastCheckAt.current < 15 * 60 * 1000) {
      return checkFlight.current || Promise.resolve();
    }
    if (checkFlight.current) return checkFlight.current;

    lastCheckAt.current = monotonicNow();
    const flight = (async () => {
      const result = await checkAppUpdatePolicy();
      if (!mounted.current || !result.authoritative) return;
      setNotice(
        result.notice && (await shouldPresentAppUpdate(result.notice))
          ? result.notice
          : null,
      );
    })().finally(() => {
      if (checkFlight.current === flight) checkFlight.current = null;
    });
    checkFlight.current = flight;
    return flight;
  }, []);

  const dismiss = useCallback(() => {
    const current = notice;
    setNotice(null);
    if (current) void dismissAppUpdate(current);
  }, [notice]);

  return {notice, refresh, dismiss};
};
