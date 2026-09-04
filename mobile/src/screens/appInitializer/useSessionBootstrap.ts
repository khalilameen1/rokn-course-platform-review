import {useCallback, useEffect, useRef, useState} from 'react';
import {useDispatch} from 'react-redux';
import type {AppDispatch} from '../../store/store';
import {LogOut, saveLoginData} from '../../store/reducers/auth';
import {
  extractApiToken,
  loadSecureSession,
  peekSecureSession,
  restoreSecureAuthState,
} from '../../services/secureSession';
import {resumePendingSocialAuth} from '../../services/socialAuth';
import {resumeCompleteGuestAccountMigration} from '../../services/guestAccountMigration';
import {getInitialAppUrl} from '../../navigation/roknLinking';

type DeadlineResult<T> =
  | {status: 'fulfilled'; value: T}
  | {status: 'rejected'}
  | {status: 'timeout'};

const settleByDeadline = <T>(
  promise: Promise<T>,
  timeoutMs: number,
): Promise<DeadlineResult<T>> =>
  new Promise(resolve => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      resolve({status: 'timeout'});
    }, timeoutMs);
    promise.then(
      value => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve({status: 'fulfilled', value});
      },
      () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve({status: 'rejected'});
      },
    );
  });

export const useSessionBootstrap = () => {
  const dispatch = useDispatch<AppDispatch>();
  const [ready, setReady] = useState(false);
  const mounted = useRef(true);

  useEffect(() => {
    mounted.current = true;
    return () => {
      mounted.current = false;
    };
  }, []);

  const adopt = useCallback(
    async (candidate: unknown) => {
      const expectedToken = extractApiToken(candidate);
      if (!expectedToken) return false;
      const snapshot = peekSecureSession();
      const current = snapshot.ready
        ? snapshot.session
        : await loadSecureSession().catch(() => null);
      if (!mounted.current || extractApiToken(current) !== expectedToken) {
        return false;
      }
      dispatch(saveLoginData(current));
      return true;
    },
    [dispatch],
  );

  useEffect(() => {
    let active = true;

    const adoptPending = async (session: unknown) => {
      if (!active || !session) return;
      if ((await adopt(session)) && active) {
        void resumeCompleteGuestAccountMigration().catch(() => undefined);
      }
    };

    const resumePending = async (initialUrl?: string | null) => {
      const session = await resumePendingSocialAuth(initialUrl).catch(
        () => null,
      );
      await adoptPending(session);
    };

    const resumePendingAfterGuestRestore = async (
      initialUrlFlight: Promise<string | null>,
    ) => {
      const initialUrl = await settleByDeadline(initialUrlFlight, 1_000);
      if (!active) return;
      if (initialUrl.status === 'fulfilled') {
        void resumePending(initialUrl.value);
        return;
      }
      if (initialUrl.status === 'timeout') {
        void initialUrlFlight
          .then(url => (active ? resumePending(url) : undefined))
          .catch(() => undefined);
        return;
      }
      void resumePending();
    };

    const settleAsGuest = async (initialUrlFlight: Promise<string | null>) => {
      // Navigation is live while native storage restores. A learner can finish
      // OAuth before an older keychain read rejects. That late failure must
      // not dispatch LogOut over the newly committed session.
      const committed = peekSecureSession();
      if (
        committed.ready &&
        extractApiToken(committed.session) &&
        (await adopt(committed.session))
      ) {
        void resumeCompleteGuestAccountMigration().catch(() => undefined);
        return;
      }
      if (!active) return;
      dispatch(LogOut());
      void resumePendingAfterGuestRestore(initialUrlFlight);
    };

    const applyRestore = async (
      restored: Awaited<ReturnType<typeof restoreSecureAuthState>>,
      initialUrlFlight: Promise<string | null>,
    ) => {
      if (!active) return;
      if (restored.isAuthenticated) {
        await adoptPending(restored.session);
        return;
      }
      await settleAsGuest(initialUrlFlight);
    };

    void (async () => {
      const restoreFlight = restoreSecureAuthState();
      const initialUrlFlight = getInitialAppUrl();
      const quickRestore = await settleByDeadline(restoreFlight, 3_500);
      if (!active) return;

      if (quickRestore.status === 'fulfilled') {
        await applyRestore(quickRestore.value, initialUrlFlight);
        if (active) setReady(true);
        return;
      }
      if (quickRestore.status === 'rejected') {
        await settleAsGuest(initialUrlFlight);
        if (active) setReady(true);
        return;
      }
      // Home is already mounted for the guest, so a slow keychain must not
      // create a splash gate. Account-owned reconciliation, however, remains
      // paused until the restore really resolves; a deadline is not a session.
      void restoreFlight
        .then(restored => applyRestore(restored, initialUrlFlight))
        .catch(() => settleAsGuest(initialUrlFlight))
        .finally(() => {
          if (active) setReady(true);
        });
    })();

    return () => {
      active = false;
    };
  }, [adopt, dispatch]);

  return {sessionReady: ready, adoptAuthenticatedSession: adopt};
};
