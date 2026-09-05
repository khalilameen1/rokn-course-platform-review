import {useCallback, useEffect, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {useDispatch} from 'react-redux';
import type {AppDispatch} from '../../store/store';
import {LogOut, saveLoginData} from '../../store/reducers/auth';
import {
  extractApiToken,
  loadPendingSocialAuthAttempt,
  loadSecureSession,
  peekSecureSession,
  restoreSecureAuthState,
  type PendingSocialAuthAttempt,
} from '../../services/secureSession';
import {resumePendingSocialAuth} from '../../services/socialAuth';
import {
  socialAuthFailureCode,
  socialAuthMessage,
} from '../../services/socialAuthErrors';
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

const pendingAttemptOwner = (attempt: PendingSocialAuthAttempt | null) =>
  attempt
    ? [
        attempt.provider,
        attempt.verifier,
        attempt.startedAt,
        attempt.purpose ?? 'login',
      ].join(':')
    : '';

export const useSessionBootstrap = () => {
  const dispatch = useDispatch<AppDispatch>();
  const [ready, setReady] = useState(false);
  const mounted = useRef(true);
  const resumeFlight = useRef<Promise<void> | null>(null);
  const resumeUrl = useRef<string | null | undefined>(undefined);
  const resumeRef = useRef<(url?: string | null) => Promise<void>>(async () => {});
  const failureNotice = useRef('');

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

  const adoptPending = useCallback(
    async (session: unknown) => {
      if (!session) return;
      if (await adopt(session)) {
        void resumeCompleteGuestAccountMigration().catch(() => undefined);
      }
    },
    [adopt],
  );

  const resumePendingAuthentication = useCallback(
    (callbackUrl?: string | null): Promise<void> => {
      if (!mounted.current) return Promise.resolve();
      const currentFlight = resumeFlight.current;
      if (currentFlight) {
        if (callbackUrl && callbackUrl !== resumeUrl.current) {
          return currentFlight.then(() => resumeRef.current(callbackUrl));
        }
        return currentFlight;
      }

      const operation = (async () => {
        const ownerSession = peekSecureSession();
        if (ownerSession.ready && extractApiToken(ownerSession.session)) return;
        const ownerAttempt = await loadPendingSocialAuthAttempt().catch(
          () => null,
        );
        const ownerAttemptKey = pendingAttemptOwner(ownerAttempt);
        const stillOwnsGuestSession = () => {
          const current = peekSecureSession();
          return (
            mounted.current &&
            current.epoch === ownerSession.epoch &&
            !extractApiToken(current.session)
          );
        };
        if (!stillOwnsGuestSession()) return;

        try {
          const session = await resumePendingSocialAuth(callbackUrl);
          if (session) {
            failureNotice.current = '';
            await adoptPending(session);
          }
        } catch (error) {
          if (!mounted.current) return;
          const code = socialAuthFailureCode(error);
          const message = socialAuthMessage(code);
          if (!message) return;

          const pending = await loadPendingSocialAuthAttempt().catch(() => null);
          const pendingKey = pendingAttemptOwner(pending);
          if (
            !mounted.current ||
            !stillOwnsGuestSession() ||
            (pendingKey && pendingKey !== ownerAttemptKey)
          ) {
            return;
          }
          const noticeKey = pending
            ? `${pendingKey}:${code}`
            : `terminal:${ownerAttemptKey}:${code}`;
          if (!mounted.current || failureNotice.current === noticeKey) return;
          failureNotice.current = noticeKey;

          const canRetry = Boolean(
            pending?.callbackUrl ||
              pending?.nativeToken ||
              pending?.completedSession,
          );
          Alert.alert(
            'تعذّر تسجيل الدخول',
            message,
            canRetry
              ? [
                  {text: 'إلغاء', style: 'cancel'},
                  {
                    text: 'إعادة المحاولة',
                    onPress: () => {
                      void (async () => {
                        if (!mounted.current || !stillOwnsGuestSession()) return;
                        const currentAttempt =
                          await loadPendingSocialAuthAttempt().catch(() => null);
                        if (
                          !mounted.current ||
                          !stillOwnsGuestSession() ||
                          pendingAttemptOwner(currentAttempt) !== pendingKey
                        ) {
                          return;
                        }
                        failureNotice.current = '';
                        await resumeRef.current();
                      })().catch(() => undefined);
                    },
                  },
                ]
              : [{text: 'حسنًا'}],
          );
        }
      })();

      let tracked: Promise<void>;
      tracked = operation.finally(() => {
        if (resumeFlight.current === tracked) {
          resumeFlight.current = null;
          resumeUrl.current = undefined;
        }
      });
      resumeFlight.current = tracked;
      resumeUrl.current = callbackUrl;
      return tracked;
    },
    [adoptPending],
  );
  resumeRef.current = resumePendingAuthentication;

  useEffect(() => {
    let active = true;

    const resumePendingAfterGuestRestore = async (
      initialUrlFlight: Promise<string | null>,
    ) => {
      const initialUrl = await settleByDeadline(initialUrlFlight, 1_000);
      if (!active) return;
      if (initialUrl.status === 'fulfilled') {
        void resumePendingAuthentication(initialUrl.value);
        return;
      }
      if (initialUrl.status === 'timeout') {
        void initialUrlFlight
          .then(url =>
            active ? resumePendingAuthentication(url) : undefined,
          )
          .catch(() => undefined);
        return;
      }
      void resumePendingAuthentication();
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
  }, [adopt, adoptPending, dispatch, resumePendingAuthentication]);

  return {
    sessionReady: ready,
    adoptAuthenticatedSession: adopt,
    resumePendingAuthentication,
  };
};
