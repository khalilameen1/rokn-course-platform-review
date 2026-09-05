import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockClaimDailyReward = jest.fn();
let mockServerNowMs = Date.UTC(2026, 8, 4, 20, 30);

jest.mock('../src/services/roknApi', () => ({
  claimDailyReward: (...args: unknown[]) => mockClaimDailyReward(...args),
  getNotifications: jest.fn(async () => []),
  markNotificationRead: jest.fn(async () => undefined),
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(async (key: string) => key),
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'account-a',
  })),
  getItem: jest.fn(async () => null),
  saveItem: jest.fn(async () => undefined),
}));

jest.mock('../src/services/api/engagement', () => ({
  getEngagementMessage: jest.fn(async () => null),
  getNextEngagementMessage: jest.fn(async () => null),
}));

jest.mock('../src/services/pendingWelcomeBonus', () => ({
  clearPendingWelcomeBonus: jest.fn(async () => undefined),
  getPendingWelcomeBonus: jest.fn(async () => null),
}));

jest.mock('../src/utils/serverClock', () => ({
  serverNowMs: () => mockServerNowMs,
}));

jest.mock('../src/navigation/deepLinks', () => ({
  parseRoknDestination: jest.fn(() => null),
}));

jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: jest.fn(),
}));

jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));

import {useHomeEngagement} from '../src/screens/home/useHomeEngagement';

const flush = async () => {
  await Promise.resolve();
  await Promise.resolve();
  await Promise.resolve();
};

describe('home daily reward recovery', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockServerNowMs = Date.UTC(2026, 8, 4, 20, 30);
  });

  const Harness = ({active}: {active: boolean}) => {
    useHomeEngagement({
      active,
      identityKey: 'account-a',
      loading: true,
      navigation: {} as never,
      openCourse: () => false,
      remoteCourses: [],
      serverSession: true,
    });
    return null;
  };

  it('retries a failed daily claim when the account returns to foreground', async () => {
    mockClaimDailyReward
      .mockRejectedValueOnce(new Error('lost response'))
      .mockResolvedValueOnce({awarded: 20, balance: 120, rewardBalance: 20});

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness active />);
      await flush();
    });
    expect(mockClaimDailyReward).toHaveBeenCalledTimes(1);

    await act(async () => {
      renderer.update(<Harness active={false} />);
      await flush();
    });
    await act(async () => {
      renderer.update(<Harness active />);
      await flush();
    });

    expect(mockClaimDailyReward).toHaveBeenCalledTimes(2);
    await act(async () => renderer.unmount());
  });

  it('uses the Cairo calendar boundary for the next daily claim', async () => {
    mockClaimDailyReward.mockResolvedValue({
      awarded: 20,
      balance: 120,
      rewardBalance: 20,
    });

    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness active />);
      await flush();
    });
    expect(mockClaimDailyReward).toHaveBeenCalledTimes(1);

    await act(async () => {
      renderer.update(<Harness active={false} />);
      await flush();
    });
    await act(async () => {
      mockServerNowMs += 60 * 60 * 1000;
      renderer.update(<Harness active />);
      await flush();
    });

    expect(mockClaimDailyReward).toHaveBeenCalledTimes(2);
    await act(async () => renderer.unmount());
  });
});
