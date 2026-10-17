import React from 'react';
import {AppState} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockFlushAccountWrites = jest.fn(async () => undefined);
const mockFlushProductEvents = jest.fn(async () => undefined);
const mockFlushTelemetry = jest.fn(async () => undefined);
const mockRetryPositions = jest.fn(async () => undefined);
const mockStopConnectivity = jest.fn();
let mockOnRestored: (() => void) | null = null;

jest.mock('../src/services/playbackPreferenceConnectivity', () => ({
  subscribeToConnectivityRecovery: (callback: () => void) => {
    mockOnRestored = callback;
    return mockStopConnectivity;
  },
}));
jest.mock('../src/constants/helpers', () => ({
  extractUserProfile: () => ({id: 52}),
}));
jest.mock('../src/constants/distribution', () => ({
  CAN_START_NATIVE_CHECKOUT: false,
}));
jest.mock('../src/services/secureSession', () => ({
  abandonPendingSecureSessionRestore: jest.fn(),
  extractApiToken: () => 'authenticated',
  restoreSecureAuthState: async () => ({session: null, isAuthenticated: false}),
}));
jest.mock('../src/services/coinCheckout', () => ({
  reconcilePendingCoinCheckout: async () => null,
}));
jest.mock('../src/services/notificationPresentation', () => ({
  prepareNotificationChannels: async () => undefined,
}));
jest.mock('../src/services/pushDeviceRegistration', () => ({
  reconcilePushRegistration: async () => undefined,
  subscribeToPushTokenRefresh: () => () => undefined,
}));
jest.mock('../src/services/pushNotificationNavigation', () => ({
  flushPendingNotificationNavigation: async () => undefined,
  subscribeToPushResponses: () => () => undefined,
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  retryPendingPlaybackPositions: () => mockRetryPositions(),
  retryPendingProjectSubmissions: async () => undefined,
  retryPendingSectionCompletions: async () => undefined,
}));
jest.mock('../src/services/productAnalytics', () => ({
  flushProductEvents: () => mockFlushProductEvents(),
}));
jest.mock('../src/services/pendingAccountWrites', () => ({
  flushPendingAccountWrites: () => mockFlushAccountWrites(),
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  flushOperationalTelemetry: () => mockFlushTelemetry(),
}));
jest.mock('../src/services/portfolioMediaReplay', () => ({
  replayPendingPortfolioMediaUploads: async () => undefined,
}));
jest.mock('../src/services/sentryTelemetry', () => ({
  setSentryUserId: jest.fn(),
}));
jest.mock('../src/services/androidAuthSession', () => ({
  androidAuthSessionOwnsCallback: () => false,
}));
jest.mock('../src/services/guestAccountMigration', () => ({
  resumeCompleteGuestAccountMigration: async () => undefined,
}));

import {useAppRuntime} from '../src/screens/appInitializer/useAppRuntime';

describe('app runtime offline replay on restored connectivity', () => {
  it('flushes pending account writes and outboxes when the network returns while open', async () => {
    const subscription = jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation(() => ({remove: jest.fn()}));
    const Harness = () => {
      useAppRuntime({
        sessionReady: true,
        storedUser: {id: 52},
        refreshUpdateNotice: async () => undefined,
        adoptAuthenticatedSession: async () => true,
        resumePendingAuthentication: async () => undefined,
      });
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      expect(mockOnRestored).toEqual(expect.any(Function));
      const startupFlushes = mockFlushAccountWrites.mock.calls.length;
      mockFlushProductEvents.mockClear();
      mockFlushTelemetry.mockClear();

      await act(async () => {
        mockOnRestored?.();
      });

      expect(mockFlushAccountWrites).toHaveBeenCalledTimes(startupFlushes + 1);
      expect(mockFlushProductEvents).toHaveBeenCalledTimes(1);
      expect(mockFlushTelemetry).toHaveBeenCalledTimes(1);

      await act(async () => {
        renderer.unmount();
      });
      expect(mockStopConnectivity).toHaveBeenCalled();
    } finally {
      subscription.mockRestore();
    }
  });
});
