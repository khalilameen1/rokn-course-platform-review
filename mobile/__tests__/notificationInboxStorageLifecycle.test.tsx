import React from 'react';
import {AccessibilityInfo, AppState} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {Notification} from '../src/services/notificationMapper';

const mockPage = jest.fn();
const mockCourses = jest.fn();
const mockGetItem = jest.fn();
const mockSaveItem = jest.fn();
let mockBoundary = {scope: 'account-a', epoch: 1};

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    jest.requireActual('react').useEffect(effect, [effect]);
  },
}));
jest.mock('../src/services/roknApi', () => ({
  getNotificationsPage: (...args: unknown[]) => mockPage(...args),
  getCachedPublishedCourses: (...args: unknown[]) => mockCourses(...args),
  markNotificationRead: jest.fn(),
  markAllNotificationsRead: jest.fn(),
  hasSession: async () => true,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: {scope: string; epoch: number}) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  accountScopedStorageKey: async (base: string, boundary: {scope: string}) =>
    `${base}:${boundary.scope}`,
  getItem: (...args: unknown[]) => mockGetItem(...args),
  saveItem: (...args: unknown[]) => mockSaveItem(...args),
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  openRoknDestination: jest.fn(),
}));

import {useNotificationsInbox} from '../src/screens/notifications/useNotificationsInbox';

const notification = (id: string): Notification => ({
  id,
  type: 'admin_message',
  kind: 'account_update',
  title: `رسالة ${id}`,
  description: 'رسالة من الإدارة',
  actionLabel: '',
  createdAt: '2026-09-09T08:00:00.000Z',
  tone: 'learning',
  read: false,
});
const page = (id: string) => ({
  notifications: [notification(id)],
  page: 1,
  hasMore: false,
  nextCursor: null,
});
const cache = (id: string) => ({
  version: 2,
  savedAt: Date.now(),
  items: [notification(id)],
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: unknown) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};

// The inbox and its cache parser/write queue are real. Only native storage,
// session ownership, navigation and HTTP/catalogue seams are fixtures here.
describe('notification inbox optional storage lifecycle', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let inbox!: ReturnType<typeof useNotificationsInbox>;
  const Harness = () => {
    inbox = useNotificationsInbox();
    return null;
  };
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  };
  const waitForOptionalCache = async () => {
    await act(async () => {
      await jest.advanceTimersByTimeAsync(800);
    });
  };

  beforeEach(() => {
    jest.useFakeTimers();
    jest.resetAllMocks();
    mockBoundary = {scope: 'account-a', epoch: 1};
    mockGetItem.mockResolvedValue(null);
    mockSaveItem.mockResolvedValue(true);
    mockCourses.mockResolvedValue([]);
    mockPage.mockResolvedValue(page('2'));
    jest
      .spyOn(AccessibilityInfo, 'isScreenReaderEnabled')
      .mockResolvedValue(false);
    jest
      .spyOn(AccessibilityInfo, 'addEventListener')
      .mockReturnValue({remove: jest.fn()} as unknown as ReturnType<
        typeof AccessibilityInfo.addEventListener
      >);
    jest
      .spyOn(AppState, 'addEventListener')
      .mockReturnValue({remove: jest.fn()});
    AppState.currentState = 'active';
  });
  afterEach(async () => {
    if (renderer) await act(async () => renderer!.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
    jest.useRealTimers();
  });

  it('starts the server read despite stalled native cache and ignores its late stale result', async () => {
    const storage = deferred<ReturnType<typeof cache>>();
    mockGetItem.mockReturnValueOnce(storage.promise);
    await mount();
    expect(mockPage).toHaveBeenCalledTimes(1);
    await waitForOptionalCache();
    expect(inbox.loading).toBe(false);
    expect(inbox.source.map(item => item.id)).toEqual(['2']);

    await act(async () => storage.resolve(cache('1')));
    expect(inbox.source.map(item => item.id)).toEqual(['2']);
    expect(mockPage).toHaveBeenCalledTimes(1);
    expect(mockSaveItem.mock.calls.at(-1)?.[1].items).toEqual([
      notification('2'),
    ]);
  });

  it('shows received notifications when optional cached course images stall', async () => {
    const courses = deferred<[]>();
    mockCourses.mockReturnValueOnce(courses.promise);
    await mount();
    expect(mockPage).toHaveBeenCalledTimes(1);
    await waitForOptionalCache();
    expect(inbox.loading).toBe(false);
    expect(inbox.source.map(item => item.id)).toEqual(['2']);
    expect(inbox.notificationError).toBe('');
    await act(async () => courses.resolve([]));
  });

  it('does not treat a cache read failure as failure of the server request', async () => {
    mockGetItem.mockRejectedValueOnce(new Error('native read failed'));
    await mount();
    expect(mockPage).toHaveBeenCalledTimes(1);
    expect(inbox.source.map(item => item.id)).toEqual(['2']);
    expect(inbox.notificationError).toBe('');
    expect(inbox.loading).toBe(false);
  });

  it('keeps the cached inbox on network failure and can explicitly refresh it', async () => {
    mockGetItem.mockResolvedValueOnce(cache('1'));
    mockPage.mockRejectedValueOnce(new Error('network failed'));
    await mount();
    expect(inbox.source.map(item => item.id)).toEqual(['1']);
    expect(inbox.notificationError).not.toBe('');
    expect(inbox.loading).toBe(false);
    expect(mockSaveItem).not.toHaveBeenCalled();

    await act(async () => inbox.refreshNotifications());
    expect(inbox.source.map(item => item.id)).toEqual(['2']);
    expect(inbox.notificationError).toBe('');
  });

  it('does not let an older account cache or server result overwrite a new inbox', async () => {
    const storage = deferred<ReturnType<typeof cache>>();
    const oldRead = deferred<ReturnType<typeof page>>();
    mockGetItem.mockReturnValueOnce(storage.promise);
    mockPage.mockReturnValueOnce(oldRead.promise);
    await mount();
    expect(mockPage).toHaveBeenCalledTimes(1);

    mockBoundary = {scope: 'account-b', epoch: 2};
    mockPage.mockResolvedValue(page('9'));
    await act(async () => inbox.refreshNotifications());
    expect(inbox.source.map(item => item.id)).toEqual(['9']);
    await act(async () => {
      storage.resolve(cache('1'));
      oldRead.resolve(page('2'));
    });
    await waitForOptionalCache();
    expect(inbox.source.map(item => item.id)).toEqual(['9']);
    expect(
      mockSaveItem.mock.calls.every(([key]) => key.endsWith('account-b')),
    ).toBe(true);
  });

  it('aborts the in-flight server read on unmount while native cache is pending', async () => {
    const storage = deferred<ReturnType<typeof cache>>();
    const request = deferred<ReturnType<typeof page>>();
    mockGetItem.mockReturnValueOnce(storage.promise);
    mockPage.mockReturnValueOnce(request.promise);
    await mount();
    expect(mockPage).toHaveBeenCalledTimes(1);
    const signal = mockPage.mock.calls[0][0].signal as AbortSignal;
    await act(async () => renderer!.unmount());
    renderer = undefined;
    expect(signal.aborted).toBe(true);
    await act(async () => {
      storage.resolve(cache('1'));
      request.resolve(page('2'));
    });
    await waitForOptionalCache();
    expect(mockSaveItem).not.toHaveBeenCalled();
    expect(mockPage).toHaveBeenCalledTimes(1);
  });
});
