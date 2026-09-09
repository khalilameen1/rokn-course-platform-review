import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockRead = jest.fn();
const mockSave = jest.fn();
const mockTrack = jest.fn();
const mockOpenCourse = jest.fn(() => true);
let mockBoundary = {scope: 'learner-a', epoch: 1};
jest.mock('../src/services/roknApi', () => ({
  claimDailyReward: async () => ({}),
  getNotifications: async () => [
    {
      id: '71',
      kind: 'course_recommendation',
      read: false,
      title: 'كورس جديد',
      description: 'تابع التعلم',
      actionLabel: 'افتح الكورس',
      courseId: '3',
      link: 'rokn://course/3',
    },
  ],
  markNotificationRead: (...args: unknown[]) => mockRead(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  getItem: async () => null,
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
const courses = [{id: '3', published: true, owned: false}];

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
      remoteCourses: courses as never,
      serverSession: true,
    });
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(engagement.campaign?.courseId).toBe('3');
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockBoundary = {scope: 'learner-a', epoch: 1};
    mockRead.mockReset().mockResolvedValue(undefined);
    mockSave.mockReset().mockResolvedValue(true);
    mockOpenCourse.mockReturnValue(true);
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
  });

  it('opens the chosen course after the read ACK without waiting for a stalled seen write', async () => {
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
      expect(mockSave).not.toHaveBeenCalled();
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
      if (stage === 'read') expect(mockSave).not.toHaveBeenCalled();
    },
  );

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
    expect(engagement.campaign?.id).toBe('71');
    await act(async () => oldAction(true));
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(mockRead).not.toHaveBeenCalled();
    expect(engagement.campaign?.id).toBe('71');
    await act(async () => engagement.dismissCampaign(true));
    expect(mockOpenCourse).toHaveBeenCalledTimes(1);
    expect(mockRead).toHaveBeenCalledWith('71', {scope: 'learner-b', epoch: 2});
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

  it('does not persist a prior account receipt or navigate after its delayed read finishes', async () => {
    const read = deferred<void>();
    mockRead.mockReturnValueOnce(read.promise);
    await mount();
    await act(async () => engagement.dismissCampaign(false));
    mockBoundary = {scope: 'learner-b', epoch: 2};
    await act(async () => renderer!.update(<Harness />));
    await act(async () => read.resolve());
    expect(mockSave).not.toHaveBeenCalled();
    expect(mockOpenCourse).not.toHaveBeenCalled();
    expect(engagement.campaign?.id).toBe('71');
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
