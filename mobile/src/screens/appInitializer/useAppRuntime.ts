import {useEffect} from 'react';
import {AppState, Linking, Platform} from 'react-native';
import {extractUserProfile} from '../../constants/helpers';
import {
  abandonPendingSecureSessionRestore,
  extractApiToken,
  restoreSecureAuthState,
} from '../../services/secureSession';
import {
  flushPendingNotificationNavigation,
  prepareNotificationChannels,
  reconcilePushRegistration,
  subscribeToPushResponses,
  subscribeToPushTokenRefresh,
} from '../../services/pushNotifications';
import {
  retryPendingPlaybackPositions,
  retryPendingProjectSubmissions,
  retryPendingSectionCompletions,
} from '../../components/VideoPlayer/courseLearningApi';
import {CAN_START_NATIVE_CHECKOUT} from '../../constants/distribution';
import {reconcilePendingCoinCheckout} from '../../services/coinCheckout';
import {flushProductEvents} from '../../services/productAnalytics';
import {flushPendingAccountWrites} from '../../services/pendingAccountWrites';
import {flushOperationalTelemetry} from '../../services/operationalTelemetry';
import {replayPendingPortfolioMediaUploads} from '../../services/portfolioMediaReplay';
import {networkFailureKind} from '../../services/networkExperience';
import {setSentryUserId} from '../../services/sentryTelemetry';
import {androidAuthSessionOwnsCallback} from '../../services/androidAuthSession';
import {resumeCompleteGuestAccountMigration} from '../../services/guestAccountMigration';

type RuntimeInput = {
  sessionReady: boolean;
  storedUser: unknown;
  refreshUpdateNotice: (force?: boolean) => Promise<void>;
  adoptAuthenticatedSession: (session: unknown) => Promise<boolean>;
  resumePendingAuthentication: (url?: string | null) => Promise<void>;
};

export const useAppRuntime = ({
  sessionReady,
  storedUser,
  refreshUpdateNotice,
  adoptAuthenticatedSession,
  resumePendingAuthentication,
}: RuntimeInput) => {
  const hasSession = Boolean(extractApiToken(storedUser));

  useEffect(() => {
    void prepareNotificationChannels().catch(() => undefined);
  }, []);

  useEffect(() => {
    if (!sessionReady) return undefined;
    const unsubscribeResponses = subscribeToPushResponses();
    const unsubscribeTokenRefresh = subscribeToPushTokenRefresh();
    return () => {
      unsubscribeResponses();
      unsubscribeTokenRefresh();
    };
  }, [sessionReady]);

  useEffect(() => {
    if (!sessionReady) return;
    void refreshUpdateNotice(true);
  }, [refreshUpdateNotice, sessionReady]);

  useEffect(() => {
    if (!sessionReady) return;
    void reconcilePushRegistration();
    void flushPendingNotificationNavigation().catch(() => undefined);
    void flushPendingAccountWrites().catch(() => undefined);
  }, [sessionReady, storedUser]);

  useEffect(() => {
    const profile = hasSession ? extractUserProfile(storedUser) : null;
    setSentryUserId(profile?.id ?? profile?.user_id ?? null);
  }, [hasSession, storedUser]);

  useEffect(() => {
    if (!sessionReady || !hasSession) return;
    void replayPendingPortfolioMediaUploads().catch(() => undefined);
  }, [hasSession, sessionReady]);

  useEffect(() => {
    if (!sessionReady) return undefined;
    let active = true;
    let learningFlight: Promise<unknown> | null = null;
    let sessionFlight: Promise<void> | null = null;
    let storeFlight: Promise<void> | null = null;
    let storeTimer: ReturnType<typeof setTimeout> | null = null;
    let storeAttempt = 0;
    let lastLearningAt = 0;

    const reconcileLearning = (force = false) => {
      const now = Date.now();
      if (learningFlight || (!force && now - lastLearningAt < 60_000)) return;
      lastLearningAt = now;
      learningFlight = Promise.allSettled([
        retryPendingProjectSubmissions(),
        retryPendingPlaybackPositions().then(retryPendingSectionCompletions),
      ]).finally(() => {
        learningFlight = null;
      });
    };

    const clearStoreTimer = () => {
      if (storeTimer) clearTimeout(storeTimer);
      storeTimer = null;
    };

    const reconcileStore = () => {
      if (!hasSession || storeFlight) return;
      const external = reconcilePendingCoinCheckout();
      const native = CAN_START_NATIVE_CHECKOUT
        ? import('../../services/nativeStoreBilling').then(store =>
            store.reconcileNativeStorePurchases(),
          )
        : Promise.resolve(null);
      storeFlight = Promise.allSettled([external, native])
        .then(results => {
          if (!active) return;
          const pending = results.some(
            result =>
              result.status === 'fulfilled' && Boolean(result.value?.pending),
          );
          const retryableFailure = results.some(
            result =>
              result.status === 'rejected' &&
              [
                'offline',
                'timeout',
                'rate_limited',
                'maintenance',
                'server',
              ].includes(networkFailureKind(result.reason)),
          );
          clearStoreTimer();
          if (
            (!pending && !retryableFailure) ||
            AppState.currentState !== 'active'
          ) {
            storeAttempt = 0;
            return;
          }
          const delays = [4_000, 10_000, 20_000, 40_000, 60_000];
          if (storeAttempt >= delays.length) return;
          storeTimer = setTimeout(() => {
            storeTimer = null;
            reconcileStore();
          }, delays[storeAttempt++]);
        })
        .finally(() => {
          storeFlight = null;
        });
    };

    const restoreAfterUnlock = () => {
      if (hasSession || sessionFlight) return;
      abandonPendingSecureSessionRestore();
      sessionFlight = (async () => {
        const result = await restoreSecureAuthState();
        if (result.isAuthenticated) {
          if (
            active &&
            (await adoptAuthenticatedSession(result.session)) &&
            active
          ) {
            void resumeCompleteGuestAccountMigration().catch(() => undefined);
          }
          return;
        }
        // A durable account always wins over an abandoned provider attempt.
        // Resume OAuth only after the secure store has confirmed this device
        // is still a guest, so a stale callback cannot switch an account.
        await resumePendingAuthentication();
      })()
        .catch(() => undefined)
        .finally(() => {
          sessionFlight = null;
        });
    };

    const authLinkSubscription =
      Platform.OS === 'android' && !hasSession
        ? Linking.addEventListener('url', ({url}) => {
            if (androidAuthSessionOwnsCallback(url)) return;
            void resumePendingAuthentication(url);
          })
        : null;

    reconcileLearning(true);
    reconcileStore();
    const appStateSubscription = AppState.addEventListener('change', state => {
      if (state !== 'active') {
        clearStoreTimer();
        return;
      }
      reconcileLearning();
      reconcileStore();
      if (hasSession) {
        void replayPendingPortfolioMediaUploads().catch(() => undefined);
      }
      void reconcilePushRegistration();
      void flushProductEvents().catch(() => undefined);
      void flushOperationalTelemetry().catch(() => undefined);
      void refreshUpdateNotice();
      void flushPendingNotificationNavigation().catch(() => undefined);
      void flushPendingAccountWrites().catch(() => undefined);
      restoreAfterUnlock();
    });

    return () => {
      active = false;
      clearStoreTimer();
      authLinkSubscription?.remove();
      appStateSubscription.remove();
    };
  }, [
    adoptAuthenticatedSession,
    hasSession,
    refreshUpdateNotice,
    resumePendingAuthentication,
    sessionReady,
  ]);
};
