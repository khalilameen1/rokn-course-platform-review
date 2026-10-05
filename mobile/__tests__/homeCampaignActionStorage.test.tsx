import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockRead = jest.fn();
const mockSave = jest.fn();
const mockReceiptKey = jest.fn();
const mockReceipts = new Map<string, unknown>();
const mockTrack = jest.fn();
const mockOpenCourse = jest.fn(() => true);
let mockBoundary = {scope: 'learner-a', epoch: 1};
jest.mock('../src/services/roknApi', () => ({
  claimDailyReward: async () => ({}),
  markNotificationRead: (...args: unknown[]) => mockRead(...args),
}));
jest.mock('../src/services/api/notifications', () => ({
  getNotificationsPage: async () => ({
    notifications: [
      {
        id: '71',
        kind: 'course_recommendation',
        read: false,
        title: 'كورس جديد',
        description: 'تابع التعلم',
        actionLabel: 'افتح الكورس',
        courseId: '3',
        link: 'rokn://course/3',
        homeCourse: {
          id: '3',
          title: 'الكورس المنشور',
          imageUrl: 'https://cdn.example/course.jpg',
        },
      },
    ],
    page: 1,
    hasMore: false,
    nextCursor: null,
  }),
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: (...args: unknown[]) => mockReceiptKey(...args),
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  getItem: async (key: string) => mockReceipts.get(key) ?? null,
  saveItem: (...args: unknown[]) => mockSave(...args),
}));
jest.mock('../src/services/api/engagement', () => ({
  getEngagementMessage: async () => null,
  getNextEngagementMessage: async () => null,
}));
jest.mock('../src/services/pendingWelcomeBonus', () => ({
  clearPendingWelcomeBonus: async () => undefined,
  getPendingWelcomeBonus: async () => null,
}));
jest.mock('../src/services/productAnalytics', () => ({
  trackProductEvent: (...args: unknown[]) => mockTrack(...args),
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));

import {useHomeEngagement} from '../src/screens/home/useHomeEngagement';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('Home campaign CTA is not owned by optional read receipts', () => {
  let engagement!: ReturnType<typeof useHomeEngagement>;
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const Harness = () => {
    engagement = useHomeEngagement({
      active: true,
      identityKey: mockBoundary.scope,
      loading: false,
      navigation: {} as never,
      openCourse: mockOpenCourse,
      serverSession: true,
    });
    return null;
  };
  const mount = async (expectCampaign = true) => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    if (expectCampaign) expect(engagement.campaign?.courseId).toBe('3');
    else expect(engagement.campaign).toBeNull();
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'learner-a', epoch: 1};
    mockReceipts.clear();
    mockReceiptKey
      .mockReset()
      .mockImplementation(
        async (key: string, boundary: typeof mockBoundary) =>
          `${key}:${boundary.scope}`,
      );
    mockRead.mockReset().mockResolvedValue(undefined);
    mockSave
      .mockReset()
      .mockImplementation(async (key: string, value: unknown) => {
        mockReceipts.set(key, value);
        return true;
      });
    mockOpenCourse.mockReturnValue(true);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  });

  it('opens the chosen course and acknowledges the inbox without waiting for a stalled seen write', async () => {
    const storage = deferred<boolean>();
    mockSave.mockReturnValueOnce(storage.promise);
    await mount();
    let action!: Promise<void>;
    await act(async () => {
      action = engagement.dismissCampaign(true);
    });
    try {
      expect(mockRead).toHaveBeenCalledWith('71', {
        scope: 'learner-a',
        epoch: 1,
      });
      expect(mockSave).toHaveBeenCalledWith(
        '@rokn/home-receipt/campaign/71:learner-a',
        true,
      );
      expect(engagement.campaign).toBeNull();
      expect(mockOpenCourse).toHaveBeenCalledWith({id: '3'});
      expect(mockOpenCourse).toHaveBeenCalledTimes(1);
    } finally {
      storage.resolve(true);
      await act(async () => action);
    }
    expect(mockOpenCourse).toHaveBeenCalledTimes(1);
  });

  it('does not require a pending read ACK to open a course', async () => {
    const read = deferred<void>();
    mockRead.mockReturnValueOnce(read.promise);
    await mount();
    let action!: Promise<void>;
    await act(async () => {
      action = engagement.dismissCampaign(true);
    });
    try {
      expect(mockOpenCourse).toHaveBeenCalledTimes(1);
      expect(mockSave).toHaveBeenCalledWith(
        '@rokn/home-receipt/campaign/71:learner-a',
        true,
      );
      expect(mockReceipts.get('@rokn/home-receipt/campaign/71:learner-a')).toBe(
        true,
      );
    } finally {
      read.resolve();
      await act(async () => action);
    }
    expect(mockOpenCourse).toHaveBeenCalledTimes(1);
  });

  it.each(['read', 'storage'])(
    'opens the course when optional %s receipt fails',
    async stage => {
      if (stage === 'read')
        mockRead.mockRejectedValueOnce(new Error('offline'));
      else mockSave.mockRejectedValueOnce(new Error('native failure'));
      await mount();
      await act(async () => engagement.dismissCampaign(true));
      expect(mockOpenCourse).toHaveBeenCalledTimes(1);
      expect(engagement.campaign).toBeNull();
      expect(mockTrack).toHaveBeenCalledTimes(2);
      expect(mockRead).toHaveBeenCalledTimes(1);
      expect(mockSave).toHaveBeenCalledTimes(1);
      if (stage === 'read') {
        expect(
          mockReceipts.get('@rokn/home-receipt/campaign/71:learner-a'),
        ).toBe(true);
      }
    },
  );

  it('does not repeat an offline-dismissed campaign in a later launch with the same unread server row', async () => {
    mockRead.mockRejectedValue(new Error('offline'));
    await mount();
    await act(async () => engagement.dismissCampaign(false));
    expect(mockReceipts.get('@rokn/home-receipt/campaign/71:learner-a')).toBe(
      true,
    );
    await act(async () => renderer!.unmount());
    renderer = undefined;
    await mount(false);
    expect(mockRead).toHaveBeenCalledTimes(1);
    expect(mockSave).toHaveBeenCalledTimes(1);
    expect(mockOpenCourse).not.toHaveBeenCalled();
  });

  it('records the dismissal even if the course cannot be opened', async () => {
    mockRead.mockRejectedValueOnce(new Error('offline'));
    mockOpenCourse.mockReturnValue(false);
    await mount();
    await act(async () => engagement.dismissCampaign(true));
    expect(mockReceipts.get('@rokn/home-receipt/campaign/71:learner-a')).toBe(
      true,
    );
    expect(mockTrack).not.toHaveBeenCalled();
  });

  it('does not make the server ACK depend on a storage helper returning false', async () => {
    mockSave.mockResolvedValueOnce(false);
    await mount();
    await act(async () => engagement.dismissCampaign(true));
    expect(mockRead).toHaveBeenCalledWith('71', {scope: 'learner-a', epoch: 1});
    expect(mockOpenCourse).toHaveBeenCalledTimes(1);
    expect(mockReceipts.has('@rokn/home-receipt/campaign/71:learner-a')).toBe(
      false,
    );
  });

  it('does not promise durable suppression if both local persistence and the read fail', async () => {
    mockSave.mockResolvedValue(false);
    mockRead.mockRejectedValue(new Error('offline'));
    await mount();
    await act(async () => engagement.dismissCampaign(false));
    expect(mockReceipts.size).toBe(0);
    await act(async () => renderer!.unmount());
    renderer = undefined;
    await mount();
    expect(mockOpenCourse).not.toHaveBeenCalled();
  });

  it('opens the course and starts the read even if resolving the local key fails', async () => {
    await mount();
    mockReceiptKey.mockRejectedValueOnce(new Error('storage unavailable'));
    await act(async () => engagement.dismissCampaign(true));
    expect(mockSave).not.toHaveBeenCalled();
    expect(mockRead).toHaveBeenCalledTimes(1);
    expect(mockOpenCourse).toHaveBeenCalledTimes(1);
  });

  it('dismisses without opening and persists one receipt despite a repeated retained callback', async () => {
    await mount();
    const dismiss = engagement.dismissCampaign;
    await act(async () => {
      await dismiss(false);
      await dismiss(true);
    });
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(mockTrack).not.toHaveBeenCalled();
    expect(mockRead).toHaveBeenCalledTimes(1);
    expect(mockSave).toHaveBeenCalledTimes(1);
    expect(engagement.campaign).toBeNull();
  });

  it('does not consume or open a replacement account campaign from an old callback', async () => {
    await mount();
    const oldAction = engagement.dismissCampaign;
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await act(async () => renderer!.update(<Harness />));
    expect(engagement.campaign).toBeNull();
    await act(async () => oldAction(true));
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(mockRead).not.toHaveBeenCalled();
    expect(engagement.campaign).toBeNull();
    await act(async () => engagement.dismissCampaign(true));
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(mockRead).not.toHaveBeenCalled();
  });

  it('rejects a presented campaign whose secure-session epoch changed before the press', async () => {
    await mount();
    mockBoundary = {...mockBoundary, epoch: 2};
    await expect(engagement.dismissCampaign(true)).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(mockRead).not.toHaveBeenCalled();
  });

  it('a delayed prior-account read cannot trigger another save or navigate in the replacement account', async () => {
    const read = deferred<void>();
    mockRead.mockReturnValueOnce(read.promise);
    await mount();
    await act(async () => engagement.dismissCampaign(false));
    expect(mockSave).toHaveBeenCalledWith(
      '@rokn/home-receipt/campaign/71:learner-a',
      true,
    );
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await act(async () => renderer!.update(<Harness />));
    await act(async () => read.resolve());
    expect(mockSave).toHaveBeenCalledTimes(1);
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(engagement.campaign).toBeNull();
  });

  it('retires a delayed local key before writing when the account changes', async () => {
    const key = deferred<string>();
    await mount();
    mockReceiptKey.mockReturnValueOnce(key.promise);
    await act(async () => engagement.dismissCampaign(false));
    expect(mockRead).toHaveBeenCalledWith('71', {scope: 'learner-a', epoch: 1});
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await act(async () => renderer!.update(<Harness />));
    await act(async () =>
      key.resolve('@rokn/home-receipt/campaign/71:learner-a'),
    );
    expect(mockSave).not.toHaveBeenCalled();
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(engagement.campaign).toBeNull();
  });

  it('a started disk write can only finish under its captured account key', async () => {
    const storage = deferred<boolean>();
    await mount();
    mockSave.mockImplementationOnce(async (key: string, value: unknown) => {
      await storage.promise;
      mockReceipts.set(key, value);
      return true;
    });
    await act(async () => engagement.dismissCampaign(false));
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await act(async () => renderer!.update(<Harness />));
    await act(async () => storage.resolve(true));
    expect(mockReceipts.get('@rokn/home-receipt/campaign/71:learner-a')).toBe(
      true,
    );
    expect(mockReceipts.has('@rokn/home-receipt/campaign/71:learner-b')).toBe(
      false,
    );
    expect(mockRead).toHaveBeenCalledTimes(1);
    expect(mockOpenCourse).not.toHaveBeenCalled();
  });

  it('retires the presentation on unmount and tracks no refused navigation', async () => {
    await mount();
    const oldAction = engagement.dismissCampaign;
    await act(async () => renderer!.unmount());
    renderer = undefined;
    await oldAction(true);
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(mockRead).not.toHaveBeenCalled();
    await mount();
    mockOpenCourse.mockReturnValue(false);
    await act(async () => engagement.dismissCampaign(true));
    expect(mockOpenCourse).toHaveBeenCalledTimes(1);
    expect(mockTrack).not.toHaveBeenCalled();
  });
});
