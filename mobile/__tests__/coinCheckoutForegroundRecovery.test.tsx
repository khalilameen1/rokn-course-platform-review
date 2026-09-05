import React from 'react';
import {AppState, type AppStateStatus} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockRecoverAttempts = jest.fn();
const mockBoundary = {scope: 'foreground-learner', epoch: 1};
const mockNavigation = {setParams: jest.fn(), dispatch: jest.fn()};

jest.mock('@react-navigation/native', () => ({
  useIsFocused: () => true,
  useNavigation: () => mockNavigation,
  useRoute: () => ({params: {}}),
}));

jest.mock('../src/constants/helpers', () => ({
  extractUserProfile: () => ({id: 52}),
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: jest.fn(),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_EXTERNAL_CHECKOUT: true,
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/services/secureSession', () => ({
  extractApiToken: () => 'authenticated',
}));
jest.mock('../src/services/coinCheckoutAttemptStore', () => ({
  coinCheckoutOwnerKey: async () => 'foreground-owner',
}));
jest.mock('../src/services/coinCheckoutRecovery', () => ({
  reconcilePendingCoinCheckoutAttempts: (...args: unknown[]) =>
    mockRecoverAttempts(...args),
}));
jest.mock('../src/services/coinCheckoutHttp', () => ({}));
jest.mock('../src/services/coinCheckoutProvider', () => ({}));
jest.mock('../src/services/productFeatures', () => ({}));
jest.mock('../src/navigation/checkoutReturn', () => ({}));
jest.mock('../src/services/pushNotifications', () => ({
  prepareNotificationChannels: async () => undefined,
  reconcilePushRegistration: async () => undefined,
  flushPendingNotificationNavigation: async () => undefined,
  subscribeToPushResponses: () => () => undefined,
  subscribeToPushTokenRefresh: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  retryPendingPlaybackPositions: async () => undefined,
  retryPendingProjectSubmissions: async () => undefined,
  retryPendingSectionCompletions: async () => undefined,
}));
jest.mock('../src/services/productAnalytics', () => ({
  flushProductEvents: async () => undefined,
}));
jest.mock('../src/services/pendingAccountWrites', () => ({
  flushPendingAccountWrites: async () => undefined,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  flushOperationalTelemetry: async () => undefined,
}));
jest.mock('../src/services/portfolioMediaReplay', () => ({
  replayPendingPortfolioMediaUploads: async () => undefined,
}));
jest.mock('../src/services/sentryTelemetry', () => ({
  setSentryUserId: jest.fn(),
}));
jest.mock('../src/services/androidAuthSession', () => ({}));
jest.mock('../src/services/guestAccountMigration', () => ({}));

import {useAppRuntime} from '../src/screens/appInitializer/useAppRuntime';
import {useWalletCheckout} from '../src/screens/Wallet/useWalletCheckout';
import {runCoinCheckoutSingleFlight} from '../src/services/coinCheckoutCoordinator';
import type {CoinCheckoutResult} from '../src/services/coinCheckoutTypes';

const runtimeInput = {
  sessionReady: true,
  storedUser: {id: 52},
  refreshUpdateNotice: async () => undefined,
  adoptAuthenticatedSession: async () => true,
  resumePendingAuthentication: async () => undefined,
};

describe('foreground checkout handoff to runtime recovery', () => {
  it('polls a pending browser return until settled and asks the production wallet subscriber to refresh once', async () => {
    jest.useFakeTimers();
    mockRecoverAttempts.mockReset().mockResolvedValue(null);
    mockNavigation.setParams.mockClear();
    mockNavigation.dispatch.mockClear();
    let appStateChanged!: (state: AppStateStatus) => void;
    const subscription = jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((_event, listener) => {
        appStateChanged = listener;
        return {remove: jest.fn()};
      });
    const previousAppState = AppState.currentState;
    AppState.currentState = 'active';
    const refreshAfterCurrent = jest.fn(async () => undefined);
    const walletData = {
      identityKey: 'foreground-learner',
      invalidatePackages: jest.fn(async () => undefined),
      ownsBoundary: (boundary: unknown) => boundary === mockBoundary,
      refreshAfterCurrent,
    };
    const Harness = () => {
      useAppRuntime(runtimeInput);
      useWalletCheckout(walletData);
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      expect(mockRecoverAttempts).toHaveBeenCalledTimes(1);
      mockRecoverAttempts.mockClear();

      let finishCheckout!: (result: CoinCheckoutResult) => void;
      const providerCheckout = jest.fn(
        () =>
          new Promise<CoinCheckoutResult>(resolve => {
            finishCheckout = resolve;
          }),
      );
      const checkout = runCoinCheckoutSingleFlight(
        'foreground-owner:1',
        'course-52',
        providerCheckout,
      );
      await act(async () => {
        AppState.currentState = 'background';
        appStateChanged('background');
        AppState.currentState = 'active';
        appStateChanged('active');
      });
      expect(mockRecoverAttempts).not.toHaveBeenCalled();
      await act(async () => {
        finishCheckout({
          success: false,
          pending: true,
          cancelled: false,
          coinsAdded: 0,
          orderRef: 'PKG-FOREGROUND-HANDOFF',
        });
        await checkout;
      });
      expect(refreshAfterCurrent).not.toHaveBeenCalled();
      mockRecoverAttempts.mockResolvedValueOnce({
        success: true,
        pending: false,
        cancelled: false,
        coinsAdded: 600,
        orderRef: 'PKG-FOREGROUND-HANDOFF',
      });
      await act(async () => {
        jest.advanceTimersByTime(4_000);
      });
      expect(mockRecoverAttempts).toHaveBeenCalledTimes(1);
      expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
      await act(async () => {
        jest.advanceTimersByTime(60_000);
      });
      expect(mockRecoverAttempts).toHaveBeenCalledTimes(1);
      expect(providerCheckout).toHaveBeenCalledTimes(1);
      expect(refreshAfterCurrent).toHaveBeenCalledTimes(1);
      expect(mockNavigation.dispatch).not.toHaveBeenCalled();
      expect(mockNavigation.setParams).not.toHaveBeenCalled();
    } finally {
      if (renderer) await act(async () => renderer.unmount());
      subscription.mockRestore();
      AppState.currentState = previousAppState;
      jest.useRealTimers();
    }
  });
});
