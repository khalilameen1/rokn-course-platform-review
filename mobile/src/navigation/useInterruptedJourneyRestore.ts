import {CommonActions} from '@react-navigation/native';
import React from 'react';
import {useSelector} from 'react-redux';

import {setNotificationNavigationReady} from '../services/pushNotifications';
import {sessionIdentityKey} from '../services/secureSession';
import type {RootState} from '../store/store';
import {
  acknowledgePendingLoginReturnTo,
  claimPendingLoginReturnTo,
  clearPendingLoginReturnTo,
  loginReturnResetState,
  safeLoginReturnToFromRoute,
  shouldPreserveVisibleJourneyAcrossSessionChange,
} from './authReturn';
import {
  acknowledgePendingCheckoutReturn,
  claimPendingCheckoutReturn,
} from './checkoutReturn';
import {parseRoknDestination} from './deepLinks';
import {navigationRef} from './RootNavigationHelper';
import {
  getInitialAppUrl,
  isRoknNavigationReady,
  resetRoknLinking,
} from './roknLinking';

const navigationDeadline = <T>(
  promise: Promise<T>,
  fallback: T,
  timeoutMs = 1_500,
) =>
  new Promise<T>(resolve => {
    let settled = false;
    const timer = setTimeout(() => {
      if (settled) return;
      settled = true;
      resolve(fallback);
    }, timeoutMs);
    promise.then(
      value => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(value);
      },
      () => {
        if (settled) return;
        settled = true;
        clearTimeout(timer);
        resolve(fallback);
      },
    );
  });

export const useInterruptedJourneyRestore = () => {
  const isLogin = useSelector((state: RootState) => state.auth.isLogin);
  const sessionData = useSelector((state: RootState) => state.auth.userData);
  const sessionKey = isLogin ? sessionIdentityKey(sessionData) : 'guest';
  const restoreFlightRef = React.useRef<{
    sessionKey: string;
    promise: Promise<boolean>;
  } | null>(null);
  const currentSessionKeyRef = React.useRef(sessionKey);
  currentSessionKeyRef.current = sessionKey;
  const previousSessionKeyRef = React.useRef(sessionKey);
  const passiveSessionReturnRef =
    React.useRef<ReturnType<typeof safeLoginReturnToFromRoute>>(undefined);

  if (previousSessionKeyRef.current !== sessionKey) {
    const previousSessionKey = previousSessionKeyRef.current;
    passiveSessionReturnRef.current =
      shouldPreserveVisibleJourneyAcrossSessionChange(
        previousSessionKey,
        sessionKey,
      ) && navigationRef.isReady()
        ? safeLoginReturnToFromRoute(navigationRef.getCurrentRoute())
        : undefined;
    previousSessionKeyRef.current = sessionKey;
  }

  React.useEffect(() => {
    resetRoknLinking();
    setNotificationNavigationReady(false);
    return () => {
      resetRoknLinking();
      setNotificationNavigationReady(false);
    };
  }, []);

  const restoreInterruptedJourney = React.useCallback(
    async (operationSessionKey: string) => {
      const initialUrl = await navigationDeadline(getInitialAppUrl(), null);
      if (currentSessionKeyRef.current !== operationSessionKey) return false;

      if (!isRoknNavigationReady() && parseRoknDestination(initialUrl)) {
        await clearPendingLoginReturnTo().catch(() => undefined);
        if (isLogin) {
          const staleCheckoutClaim = await navigationDeadline(
            claimPendingCheckoutReturn(),
            undefined,
          );
          if (
            currentSessionKeyRef.current === operationSessionKey &&
            staleCheckoutClaim
          ) {
            await acknowledgePendingCheckoutReturn(staleCheckoutClaim).catch(
              () => undefined,
            );
          }
        }
        return false;
      }

      const loginClaim = await navigationDeadline(
        claimPendingLoginReturnTo(),
        undefined,
      );
      if (currentSessionKeyRef.current !== operationSessionKey) return false;

      if (loginClaim && navigationRef.isReady()) {
        const returnTo = loginClaim.returnTo;
        navigationRef.dispatch(
          CommonActions.reset(
            isLogin
              ? loginReturnResetState(returnTo, 'authenticated')
              : {
                  index: 1,
                  routes: [
                    {name: 'Home' as const},
                    {name: 'Login' as const, params: {returnTo}},
                  ],
                },
          ),
        );
        if (isLogin) {
          await acknowledgePendingLoginReturnTo(loginClaim.receipt).catch(
            () => undefined,
          );
        }
        return true;
      }

      if (!isLogin || !navigationRef.isReady()) return false;
      const checkoutClaim = await navigationDeadline(
        claimPendingCheckoutReturn(),
        undefined,
      );
      if (
        currentSessionKeyRef.current !== operationSessionKey ||
        !checkoutClaim ||
        !navigationRef.isReady()
      ) {
        return false;
      }

      // During a slow secure-session restore the learner may already be
      // looking at the cold-start destination as a guest. That current
      // journey wins over an older checkout return, which is acknowledged so
      // it cannot reopen on the next process start.
      if (passiveSessionReturnRef.current) {
        await acknowledgePendingCheckoutReturn(checkoutClaim).catch(
          () => undefined,
        );
        return false;
      }

      const returnTo = checkoutClaim.returnTo;
      navigationRef.dispatch(
        CommonActions.reset({
          index: 1,
          routes: [
            {name: 'Home'},
            returnTo.name === 'CourseDetails' ||
            returnTo.name === 'Reels' ||
            returnTo.name === 'Profile'
              ? {name: returnTo.name, params: returnTo.params}
              : {name: returnTo.name},
          ],
        }),
      );
      await acknowledgePendingCheckoutReturn(checkoutClaim).catch(
        () => undefined,
      );
      return true;
    },
    [isLogin],
  );

  const run = React.useCallback(() => {
    const operationSessionKey = sessionKey;
    if (restoreFlightRef.current?.sessionKey === operationSessionKey) {
      return restoreFlightRef.current.promise;
    }
    const flight = restoreInterruptedJourney(operationSessionKey).finally(
      () => {
        if (restoreFlightRef.current?.promise === flight) {
          restoreFlightRef.current = null;
        }
      },
    );
    restoreFlightRef.current = {
      sessionKey: operationSessionKey,
      promise: flight,
    };
    return flight;
  }, [restoreInterruptedJourney, sessionKey]);

  React.useEffect(() => {
    if (!isLogin || !navigationRef.isReady()) return;
    const operationSessionKey = sessionKey;
    const passiveReturn = passiveSessionReturnRef.current;
    void run().then(restored => {
      if (currentSessionKeyRef.current !== operationSessionKey) return;
      if (
        !restored &&
        passiveReturn &&
        passiveSessionReturnRef.current === passiveReturn &&
        navigationRef.isReady()
      ) {
        navigationRef.dispatch(
          CommonActions.reset(
            loginReturnResetState(passiveReturn, 'authenticated'),
          ),
        );
      }
      // Remounting Stacks under the same NavigationContainer can rehydrate
      // its current Login route. A plain Google login has no durable return,
      // so explicitly leave Login after the session has been adopted.
      const currentRoute = navigationRef.isReady()
        ? navigationRef.getCurrentRoute()
        : undefined;
      if (!restored && !passiveReturn && currentRoute?.name === 'Login') {
        navigationRef.dispatch(
          CommonActions.reset(
            loginReturnResetState(
              (currentRoute.params as {returnTo?: unknown} | undefined)
                ?.returnTo,
              'authenticated',
            ),
          ),
        );
      }
      if (passiveSessionReturnRef.current === passiveReturn) {
        passiveSessionReturnRef.current = undefined;
      }
    });
  }, [isLogin, run, sessionKey]);

  return {run, sessionKey};
};
