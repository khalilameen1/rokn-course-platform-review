import React from 'react';
import {AccessibilityInfo, AppState} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import type {Notification} from '../src/services/notificationMapper';

const mockPage = jest.fn();
const mockMarkAll = jest.fn();
const mockMarkRead = jest.fn();
const mockSaveCache = jest.fn();
let mockBoundary = {scope: 'account-a', epoch: 1};

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    jest.requireActual('react').useEffect(effect, [effect]);
  },
}));
jest.mock('../src/services/roknApi', () => ({
  getNotificationsPage: (...args: unknown[]) => mockPage(...args),
  markAllNotificationsRead: (...args: unknown[]) => mockMarkAll(...args),
  markNotificationRead: (...args: unknown[]) => mockMarkRead(...args),
  getCachedPublishedCourses: async () => [],
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
}));
jest.mock('../src/screens/notifications/cache', () => ({
  notificationCacheKey: async (boundary: {scope: string}) => boundary.scope,
  readCachedNotifications: async () => [],
  saveCachedNotifications: (...args: unknown[]) => mockSaveCache(...args),
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  openRoknDestination: jest.fn(),
}));

import {useNotificationsInbox} from '../src/screens/notifications/useNotificationsInbox';

const notification = (id: string, read = false): Notification => ({
  id,
  type: 'admin_message',
  kind: 'account_update',
  title: `رسالة ${id}`,
  description: 'رسالة من الإدارة',
  actionLabel: '',
  createdAt: '2026-09-08T08:00:00.000Z',
  tone: 'learning',
  read,
});
const page = (notifications: Notification[]) => ({
  notifications,
  page: 1,
  hasMore: false,
  nextCursor: null,
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

describe('notification read acknowledgement ownership', () => {
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
  const readState = () => inbox.source.map(({id, read}) => ({id, read}));

  beforeEach(() => {
    jest.resetAllMocks();
    mockBoundary = {scope: 'account-a', epoch: 1};
    mockPage.mockResolvedValue(page([notification('1')]));
    mockSaveCache.mockResolvedValue(true);
    mockMarkAll.mockResolvedValue(undefined);
    mockMarkRead.mockResolvedValue(undefined);
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
  });

  it('keeps a new message unread after an older mark-all acknowledgement and later refresh', async () => {
    const acknowledgement = deferred<void>();
    mockMarkAll.mockReturnValue(acknowledgement.promise);
    await mount();
    let marking!: Promise<void>;
    await act(async () => {
      marking = inbox.markAllRead();
    });

    // The mark-all SQL has committed for message 1. Message 2 is delivered
    // afterwards, then the learner refreshes before that POST's ACK arrives.
    mockPage.mockResolvedValue(
      page([notification('2'), notification('1', true)]),
    );
    await act(async () => inbox.refreshNotifications());
    expect(readState()).toEqual([
      {id: '2', read: false},
      {id: '1', read: true},
    ]);

    await act(async () => {
      acknowledgement.resolve();
      await marking;
    });
    expect(readState()).toEqual([
      {id: '2', read: false},
      {id: '1', read: true},
    ]);
    expect(inbox.hasUnread).toBe(true);
    expect(mockSaveCache.mock.calls.at(-1)?.[1]).toEqual([
      notification('2'),
      notification('1', true),
    ]);

    await act(async () => inbox.refreshNotifications());
    expect(readState()[0]).toEqual({id: '2', read: false});
    expect(inbox.hasUnread).toBe(true);
    expect(mockMarkAll).toHaveBeenCalledTimes(1);
  });

  it('preserves authoritative read state for another message included by the server', async () => {
    const acknowledgement = deferred<void>();
    mockMarkAll.mockReturnValue(acknowledgement.promise);
    await mount();
    let marking!: Promise<void>;
    await act(async () => {
      marking = inbox.markAllRead();
    });

    // This delivery preceded the server's mark-all SQL, so the server included
    // it even though it was not in the list when the learner tapped.
    mockPage.mockResolvedValue(
      page([notification('2', true), notification('1', true)]),
    );
    await act(async () => inbox.refreshNotifications());
    await act(async () => {
      acknowledgement.resolve();
      await marking;
    });
    expect(readState()).toEqual([
      {id: '2', read: true},
      {id: '1', read: true},
    ]);
    expect(inbox.hasUnread).toBe(false);
  });

  it('does not let a pre-ACK list response undo the read of the original message', async () => {
    const acknowledgement = deferred<void>();
    const oldRead = deferred<ReturnType<typeof page>>();
    mockMarkAll.mockReturnValue(acknowledgement.promise);
    await mount();
    let marking!: Promise<void>;
    let refreshing!: Promise<void>;
    await act(async () => {
      marking = inbox.markAllRead();
      mockPage.mockReturnValueOnce(oldRead.promise);
      refreshing = inbox.refreshNotifications();
    });

    await act(async () => {
      acknowledgement.resolve();
      await marking;
    });
    expect(readState()).toEqual([{id: '1', read: true}]);
    await act(async () => {
      oldRead.resolve(page([notification('2'), notification('1')]));
      await refreshing;
    });
    expect(readState()).toEqual([
      {id: '2', read: false},
      {id: '1', read: true},
    ]);
    expect(inbox.hasUnread).toBe(true);
  });

  it('deduplicates pending taps and leaves failed requests unread and retryable', async () => {
    const acknowledgement = deferred<void>();
    mockMarkAll.mockReturnValueOnce(acknowledgement.promise);
    await mount();
    let marking!: Promise<void>;
    await act(async () => {
      marking = inbox.markAllRead();
      await inbox.markAllRead();
      await inbox.markAllRead();
    });
    expect(mockMarkAll).toHaveBeenCalledTimes(1);
    expect(readState()).toEqual([{id: '1', read: false}]);

    await act(async () => {
      acknowledgement.reject(new Error('temporary network failure'));
      await marking;
    });
    expect(mockPage).toHaveBeenCalledTimes(2);
    expect(readState()).toEqual([{id: '1', read: false}]);
    expect(inbox.hasUnread).toBe(true);
    expect(mockSaveCache.mock.calls.every(([, items]) => !items[0].read)).toBe(
      true,
    );
    await act(async () => inbox.refreshNotifications());
    expect(readState()).toEqual([{id: '1', read: false}]);

    await act(async () => inbox.markAllRead());
    expect(mockMarkAll).toHaveBeenCalledTimes(2);
    expect(readState()).toEqual([{id: '1', read: true}]);
    expect(inbox.hasUnread).toBe(false);
  });

  it.each(['account-a', 'account-b'])(
    'does not apply an old ACK or release the replacement session flight (%s)',
    async scope => {
      const oldAcknowledgement = deferred<void>();
      const newAcknowledgement = deferred<void>();
      mockMarkAll
        .mockReturnValueOnce(oldAcknowledgement.promise)
        .mockReturnValueOnce(newAcknowledgement.promise);
      await mount();
      let oldMarking!: Promise<void>;
      await act(async () => {
        oldMarking = inbox.markAllRead();
      });

      // A same-account relogin remounts the signed-in screens. A different
      // account can also be detected by the current screen's refresh.
      if (scope === 'account-a') {
        await act(async () => renderer!.unmount());
        renderer = undefined;
        mockBoundary = {scope, epoch: 2};
        await mount();
      } else {
        mockBoundary = {scope, epoch: 2};
        await act(async () => inbox.refreshNotifications());
      }
      let newMarking!: Promise<void>;
      await act(async () => {
        newMarking = inbox.markAllRead();
      });
      const cacheWrites = mockSaveCache.mock.calls.length;
      await act(async () => {
        oldAcknowledgement.resolve();
        await oldMarking;
        await inbox.markAllRead();
      });
      expect(mockSaveCache).toHaveBeenCalledTimes(cacheWrites);
      expect(mockMarkAll).toHaveBeenCalledTimes(2);
      expect(readState()).toEqual([{id: '1', read: false}]);
      await act(async () => {
        newAcknowledgement.resolve();
        await newMarking;
      });
      expect(readState()).toEqual([{id: '1', read: true}]);
      expect(mockSaveCache.mock.calls.at(-1)?.[2]).toEqual(mockBoundary);
    },
  );

  it('does not save or refresh after a pending mark-all outlives the inbox', async () => {
    const acknowledgement = deferred<void>();
    mockMarkAll.mockReturnValue(acknowledgement.promise);
    await mount();
    let marking!: Promise<void>;
    await act(async () => {
      marking = inbox.markAllRead();
    });
    await act(async () => renderer!.unmount());
    renderer = undefined;
    const cacheWrites = mockSaveCache.mock.calls.length;
    await act(async () => {
      acknowledgement.resolve();
      await marking;
    });
    expect(mockSaveCache).toHaveBeenCalledTimes(cacheWrites);
    expect(mockPage).toHaveBeenCalledTimes(1);
  });
});
