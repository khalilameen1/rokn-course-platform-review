const mockGetItem = jest.fn(async (_key: string): Promise<any> => null);
const mockSaveItem = jest.fn(async (_key: string, _value: unknown) => true);
const mockRemoveItem = jest.fn(async (_key: string) => true);
const mockPost = jest.fn(async (_url: string, _body: unknown) => ({data: {}}));
const mockDelete = jest.fn(async (_url: string, _config: unknown) => ({
  data: {},
}));
const mockGet = jest.fn(async (_url: string) => ({data: {}}));
const mockGetPermissions = jest.fn();
const mockRequestPermissions = jest.fn();
const mockGetDeviceToken = jest.fn();
const mockNavigate = jest.fn();
const mockDeleteNativeToken = jest.fn(async () => true);
const mockFirebaseRegister = jest.fn(async (_messaging?: unknown) => undefined);
const mockFirebaseGetToken = jest.fn(
  async (_messaging?: unknown) => 'ios-fcm-token',
);
const mockFirebaseDeleteToken = jest.fn(
  async (_messaging?: unknown) => undefined,
);
const mockFirebaseOnTokenRefresh = jest.fn(
  (_messaging?: unknown, _listener?: (token: string) => void) => jest.fn(),
);
const mockOpenUrl = jest.fn(async (_url?: unknown) => true);
let mockAccountScope = 'account-1';
let mockSessionSnapshot: {ready: boolean; session: unknown} = {
  ready: true,
  session: {api_token: 'session-token'},
};

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  Linking: {openURL: (url: unknown) => mockOpenUrl(url)},
  NativeModules: {
    RoknPushTokens: {deleteToken: () => mockDeleteNativeToken()},
  },
}));

jest.mock('expo-notifications', () => ({
  AndroidImportance: {DEFAULT: 3},
  SchedulableTriggerInputTypes: {DATE: 'date'},
  cancelScheduledNotificationAsync: jest.fn(async () => undefined),
  getAllScheduledNotificationsAsync: jest.fn(async () => []),
  scheduleNotificationAsync: jest.fn(async () => 'scheduled-notification'),
  setNotificationHandler: jest.fn(),
  setNotificationChannelAsync: jest.fn(async () => null),
  getPermissionsAsync: () => mockGetPermissions(),
  requestPermissionsAsync: () => mockRequestPermissions(),
  getDevicePushTokenAsync: () => mockGetDeviceToken(),
  addPushTokenListener: jest.fn(() => ({remove: jest.fn()})),
  addNotificationResponseReceivedListener: jest.fn(() => ({
    remove: jest.fn(),
  })),
  getLastNotificationResponseAsync: jest.fn(async () => null),
  clearLastNotificationResponseAsync: jest.fn(async () => undefined),
  dismissAllNotificationsAsync: jest.fn(async () => undefined),
  setBadgeCountAsync: jest.fn(async () => true),
}));

jest.mock('@react-native-firebase/messaging', () => ({
  getMessaging: jest.fn(() => ({kind: 'messaging'})),
  registerDeviceForRemoteMessages: (...args: unknown[]) =>
    mockFirebaseRegister(...args),
  getToken: (...args: unknown[]) => mockFirebaseGetToken(...args),
  deleteToken: (...args: unknown[]) => mockFirebaseDeleteToken(...args),
  onTokenRefresh: (...args: unknown[]) => mockFirebaseOnTokenRefresh(...args),
}));

jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'USER_DATA'},
  accountScopedStorageKey: jest.fn(async (key: string) => `${key}:account-1`),
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 0,
    scope: mockAccountScope,
  })),
  extractApiToken: (session: any) => session?.api_token || null,
  getCurrentAccountStorageScope: jest.fn(async () => mockAccountScope),
  getItem: (key: string) => mockGetItem(key),
  saveItem: (key: string, value: unknown) => mockSaveItem(key, value),
  removeItem: (key: string) => mockRemoveItem(key),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (url: string) => mockGet(url),
    post: (url: string, body: unknown) => mockPost(url, body),
    delete: (url: string, config: unknown) => mockDelete(url, config),
  },
}));

jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: () => mockSessionSnapshot,
}));

jest.mock('../src/navigation/RootNavigationHelper', () => ({
  navigate: (...args: unknown[]) => mockNavigate(...args),
  openRoknDestination: (destination: {
    name: string;
    params?: Record<string, unknown>;
  }) => {
    mockNavigate(destination.name, destination.params);
    return true;
  },
}));

import {clearAccountPushState} from '../src/services/pushAccountCleanup';
import {
  flushPendingNotificationNavigation,
  openNotificationLink,
  setNotificationNavigationReady,
  subscribeToPushResponses,
} from '../src/services/pushNotificationNavigation';
import {
  registerPushDeviceIfEligible,
  unregisterPushDevice,
} from '../src/services/pushDeviceRegistration';
import {
  invalidateLocalPushDeviceRegistration,
  PUSH_TOKEN_INVALIDATION_PENDING_KEY,
  retryPendingNativePushTokenInvalidation,
} from '../src/services/pushDeviceState';

describe('push notification opt-in', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockAccountScope = 'account-1';
    (jest.requireMock('react-native').Platform as {OS: string}).OS = 'android';
    mockGetPermissions.mockResolvedValue({
      granted: true,
      status: 'granted',
      canAskAgain: true,
    });
    mockRequestPermissions.mockResolvedValue({
      granted: true,
      status: 'granted',
      canAskAgain: true,
    });
    mockGetDeviceToken.mockResolvedValue({type: 'fcm', data: 'fcm-token'});
  });

  it('never prompts or registers a guest', async () => {
    mockGetItem.mockResolvedValue(null);

    await expect(
      registerPushDeviceIfEligible({requestPermission: true}),
    ).resolves.toBe(false);
    expect(mockRequestPermissions).not.toHaveBeenCalled();
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('registers the raw FCM token only after auth and stored opt-in', async () => {
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.startsWith('PREF_NOTIFICATIONS')) return true;
      return null;
    });

    await expect(registerPushDeviceIfEligible()).resolves.toBe(true);
    expect(mockPost).toHaveBeenCalledWith('user/device-token', {
      device_id: '11111111-1111-4111-8111-111111111111',
      device_token: 'fcm-token',
      device_type: 'android',
      device_os: 'android',
    });
  });

  it('registers an iOS FCM token instead of sending a raw APNs token', async () => {
    (jest.requireMock('react-native').Platform as {OS: string}).OS = 'ios';
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.startsWith('PREF_NOTIFICATIONS')) return true;
      return null;
    });

    await expect(registerPushDeviceIfEligible()).resolves.toBe(true);
    expect(mockFirebaseRegister).toHaveBeenCalledTimes(1);
    expect(mockFirebaseGetToken).toHaveBeenCalledTimes(1);
    expect(mockGetDeviceToken).not.toHaveBeenCalled();
    expect(mockPost).toHaveBeenCalledWith('user/device-token', {
      device_id: '11111111-1111-4111-8111-111111111111',
      device_token: 'ios-fcm-token',
      device_type: 'ios',
      device_os: 'ios',
    });
  });

  it('deletes the iOS FCM token when the account binding is removed', async () => {
    (jest.requireMock('react-native').Platform as {OS: string}).OS = 'ios';
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.includes('@rokn/push-device-token')) return 'ios-fcm-token';
      return null;
    });

    await expect(unregisterPushDevice()).resolves.toBe(true);
    expect(mockFirebaseDeleteToken).toHaveBeenCalledTimes(1);
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
  });

  it('removes this installation token when notifications are disabled', async () => {
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.includes('@rokn/push-device-token')) return 'fcm-token';
      return null;
    });

    await expect(unregisterPushDevice()).resolves.toBe(true);
    expect(mockDelete).toHaveBeenCalledWith('user/device-token', {
      data: {device_token: 'fcm-token'},
    });
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(1);
    expect(mockRemoveItem).toHaveBeenCalledWith(
      '@rokn/push-device-token/v1:account-1',
    );
  });

  it('invalidates the native token even when server revoke is offline', async () => {
    mockGetItem.mockImplementation(async (key: string) => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.includes('@rokn/push-device-token')) return 'fcm-token';
      return null;
    });
    mockDelete.mockRejectedValueOnce(new Error('offline'));

    await expect(unregisterPushDevice()).resolves.toBe(false);
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(1);
    expect(mockRemoveItem).toHaveBeenCalledWith(
      '@rokn/push-device-token/v1:account-1',
    );
  });

  it('retries a failed native deletion after restart without storing identity', async () => {
    mockDeleteNativeToken.mockRejectedValueOnce(new Error('firebase offline'));
    await expect(invalidateLocalPushDeviceRegistration()).resolves.toBe(false);
    expect(mockSaveItem).toHaveBeenCalledWith(
      PUSH_TOKEN_INVALIDATION_PENDING_KEY,
      true,
    );

    mockGetItem.mockImplementation(async (key: string) =>
      key === PUSH_TOKEN_INVALIDATION_PENDING_KEY ? true : null,
    );
    await expect(retryPendingNativePushTokenInvalidation()).resolves.toBe(true);
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(2);
    expect(mockRemoveItem).toHaveBeenCalledWith(
      PUSH_TOKEN_INVALIDATION_PENDING_KEY,
    );
  });

  it('fails closed when the native token module is missing', async () => {
    const reactNative = jest.requireMock('react-native') as {
      NativeModules: {RoknPushTokens?: unknown};
    };
    const module = reactNative.NativeModules.RoknPushTokens;
    delete reactNative.NativeModules.RoknPushTokens;

    await expect(invalidateLocalPushDeviceRegistration()).resolves.toBe(false);
    expect(mockSaveItem).toHaveBeenCalledWith(
      PUSH_TOKEN_INVALIDATION_PENDING_KEY,
      true,
    );
    reactNative.NativeModules.RoknPushTokens = module;
  });

  it('opens a local reminder destination inside the app', async () => {
    await openNotificationLink({
      notification: {
        request: {
          content: {
            data: {rokn_reminder_id: 'rokn-local-42', link: '/course/42'},
          },
        },
      },
    } as any);

    expect(mockNavigate).toHaveBeenCalledWith('CourseDetails', {
      courseId: '42',
    });
  });

  it('derives a continue-course destination from structured push data', async () => {
    await openNotificationLink({
      notification: {
        request: {
          content: {
            data: {
              rokn_reminder_id: 'rokn-local-72',
              notification_type: 'enrolled_stalled',
              course_id: '72',
            },
          },
        },
      },
    } as any);

    expect(mockNavigate).toHaveBeenCalledWith('Reels', {
      courseId: '72',
    });
  });

  it('opens the owned inbox instead of trusting an id-less remote payload', async () => {
    await openNotificationLink({
      notification: {
        request: {
          content: {data: {link: 'https://attacker.example/login'}},
        },
      },
    } as any);

    expect(mockNavigate).toHaveBeenCalledWith('Notifications');
    expect(mockOpenUrl).not.toHaveBeenCalled();
  });

  it('uses the fetched delivery destination for an id-backed push', async () => {
    mockGetItem.mockImplementation(async (key: string) =>
      key === 'USER_DATA' ? {api_token: 'session-token'} : null,
    );
    mockGet.mockResolvedValue({
      data: {
        data: {
          id: 9,
          notification_type: 'new_course',
          title_ar: 'كورس جديد',
          message_ar: 'شاهد التفاصيل',
          action_label_ar: 'افتح الكورس',
          link: '/course/42',
          is_read: false,
          created_at: '2026-09-04T10:00:00+03:00',
        },
      },
    });

    await openNotificationLink({
      actionIdentifier: 'default',
      notification: {
        request: {
          identifier: 'notification-9',
          content: {data: {notification_id: '9', course_id: '77'}},
        },
      },
    } as any);

    expect(mockGet).toHaveBeenCalledWith('notifications/9');
    expect(mockNavigate).toHaveBeenCalledWith('CourseDetails', {
      courseId: '42',
    });
  });

  it('does not deduplicate a notification tap across two accounts', async () => {
    const response = {
      actionIdentifier: 'default',
      notification: {
        request: {
          identifier: 'same-native-id',
          content: {
            data: {rokn_reminder_id: 'rokn-local-42', link: '/course/42'},
          },
        },
      },
    } as any;

    await openNotificationLink(response);
    mockAccountScope = 'account-2';
    await openNotificationLink(response);

    expect(mockNavigate).toHaveBeenCalledTimes(2);
  });
});

describe('notification tap navigation ownership', () => {
  const response = (id: number) =>
    ({
      actionIdentifier: 'default',
      notification: {
        request: {
          identifier: `owned-${id}`,
          content: {data: {notification_id: String(id)}},
        },
      },
    } as any);
  const delivery = (id: number) => ({
    data: {
      data: {
        id,
        notification_type: 'new_course',
        title_ar: 'كورس جديد',
        message_ar: 'شاهد التفاصيل',
        action_label_ar: 'افتح الكورس',
        link: `/course/${id}`,
        is_read: false,
        created_at: '2026-09-04T10:00:00+03:00',
      },
    },
  });
  const drain = async () => {
    for (let i = 0; i < 30; i += 1) await Promise.resolve();
  };
  beforeEach(() => {
    jest.clearAllMocks();
    mockGet.mockReset();
    mockAccountScope = 'account-1';
    mockSessionSnapshot = {ready: true, session: {api_token: 'session-token'}};
    mockGetItem.mockResolvedValue(null);
    mockSaveItem.mockResolvedValue(true);
    mockRemoveItem.mockResolvedValue(true);
    mockNavigate.mockReturnValue(true);
    setNotificationNavigationReady(true);
  });

  it.each(['resolve', 'reject'] as const)(
    'does not let an older %s replace the most recent tap',
    async outcome => {
      const latestId = outcome === 'resolve' ? 102 : 107;
      let resolve!: (value: ReturnType<typeof delivery>) => void;
      let reject!: (error: Error) => void;
      mockGet.mockImplementationOnce(
        () =>
          new Promise((yes, no) => {
            resolve = yes;
            reject = no;
          }),
      );
      mockGet.mockResolvedValueOnce(delivery(latestId));
      const old = openNotificationLink(
        response(outcome === 'resolve' ? 101 : 103),
      );
      await drain();
      await openNotificationLink(response(latestId));
      expect(mockNavigate).toHaveBeenLastCalledWith('CourseDetails', {
        courseId: String(latestId),
      });
      if (outcome === 'resolve') resolve(delivery(101));
      else reject(new Error('offline'));
      await old;
      expect(mockNavigate).toHaveBeenCalledTimes(1);
      expect(mockPost.mock.calls.map(call => call[0])).toEqual([
        `notifications/${latestId}/mark-read`,
      ]);
    },
  );

  it('shares a pending read for repeated taps on the same notification', async () => {
    let finish!: (value: ReturnType<typeof delivery>) => void;
    mockGet.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finish = resolve;
        }),
    );
    const first = openNotificationLink(response(104));
    const second = openNotificationLink(response(104));
    await drain();
    expect(mockGet).toHaveBeenCalledTimes(1);
    finish(delivery(104));
    await Promise.all([first, second]);
    expect(mockNavigate).toHaveBeenCalledTimes(1);
  });

  it('retries a durable cold-start identity after its first read cannot open navigation', async () => {
    await clearAccountPushState();
    jest.clearAllMocks();
    mockGetItem.mockImplementation(async key =>
      key.includes('push-open-pending') ? '113' : null,
    );
    mockGet.mockRejectedValueOnce(new Error('offline'));
    mockNavigate.mockReturnValueOnce(false);
    expect(await flushPendingNotificationNavigation()).toBe(false);
    expect(mockRemoveItem).not.toHaveBeenCalled();
    mockGet.mockResolvedValueOnce(delivery(113));
    expect(await flushPendingNotificationNavigation()).toBe(true);
    expect(mockGet.mock.calls).toEqual([
      ['notifications/113'],
      ['notifications/113'],
    ]);
    expect(mockNavigate).toHaveBeenLastCalledWith('CourseDetails', {
      courseId: '113',
    });
    expect(mockRemoveItem).toHaveBeenCalledWith(
      expect.stringContaining('push-open-pending'),
    );
  });

  it('treats a return to the first notification as a new choice after another tap', async () => {
    let finishOld!: (value: ReturnType<typeof delivery>) => void;
    mockGet.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finishOld = resolve;
        }),
    );
    mockGet.mockResolvedValueOnce(delivery(109));
    mockGet.mockResolvedValueOnce(delivery(108));
    const old = openNotificationLink(response(108));
    await drain();
    await openNotificationLink(response(109));
    await openNotificationLink(response(108));
    finishOld(delivery(108));
    await old;
    expect(mockNavigate.mock.calls).toEqual([
      ['CourseDetails', {courseId: '109'}],
      ['CourseDetails', {courseId: '108'}],
    ]);
  });

  it('does not reclaim an old durable marker after logout while its storage read is pending', async () => {
    await clearAccountPushState();
    let finishRead!: (value: string) => void;
    mockGetItem.mockImplementation(async key => {
      if (!key.includes('push-open-pending')) return null;
      return new Promise<string>(resolve => {
        finishRead = resolve;
      });
    });
    mockGet.mockRejectedValueOnce({status: 404});
    const oldRestore = flushPendingNotificationNavigation();
    await drain();
    expect(finishRead).toBeDefined();
    await clearAccountPushState();
    mockAccountScope = 'account-2';
    jest.clearAllMocks();
    finishRead('114');
    expect(await oldRestore).toBe(false);
    expect(mockGet).not.toHaveBeenCalled();
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockRemoveItem).not.toHaveBeenCalled();
  });

  it('does not open an old account response when the account changes during its read', async () => {
    let finish!: (value: ReturnType<typeof delivery>) => void;
    mockGet.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finish = resolve;
        }),
    );
    const flight = openNotificationLink(response(110));
    await drain();
    mockAccountScope = 'account-2';
    finish(delivery(110));
    await flight;
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('discards a delayed native startup response after logout resets navigation ownership', async () => {
    await clearAccountPushState();
    const notifications = jest.requireMock('expo-notifications');
    let finishInitial!: (value: ReturnType<typeof response>) => void;
    notifications.getLastNotificationResponseAsync.mockImplementationOnce(
      () =>
        new Promise((resolve: typeof finishInitial) => {
          finishInitial = resolve;
        }),
    );
    mockGet.mockRejectedValueOnce({status: 404});
    const unsubscribe = subscribeToPushResponses();
    await drain();
    await clearAccountPushState();
    mockAccountScope = 'account-2';
    jest.clearAllMocks();
    finishInitial(response(115));
    await drain();
    expect(mockGet).not.toHaveBeenCalled();
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(mockSaveItem).not.toHaveBeenCalled();
    unsubscribe();
  });

  it('does not let a delayed native startup response supersede a fresh notification tap', async () => {
    const notifications = jest.requireMock('expo-notifications');
    let finishInitial!: (value: ReturnType<typeof response>) => void;
    notifications.getLastNotificationResponseAsync.mockImplementationOnce(
      () =>
        new Promise((resolve: typeof finishInitial) => {
          finishInitial = resolve;
        }),
    );
    mockGet.mockResolvedValueOnce(delivery(112));
    const unsubscribe = subscribeToPushResponses();
    const listener =
      notifications.addNotificationResponseReceivedListener.mock.calls.at(
        -1,
      )[0];
    listener(response(112));
    await drain();
    finishInitial(response(111));
    await drain();
    expect(mockGet.mock.calls).toEqual([['notifications/112']]);
    expect(mockNavigate).toHaveBeenCalledTimes(1);
    unsubscribe();
  });

  it('keeps the latest cold-start tap while an older marker write is delayed', async () => {
    const notifications = jest.requireMock('expo-notifications');
    setNotificationNavigationReady(false);
    let finishWrite!: (value: boolean) => void;
    mockSaveItem.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finishWrite = resolve;
        }),
    );
    const unsubscribe = subscribeToPushResponses();
    const listener =
      notifications.addNotificationResponseReceivedListener.mock.calls.at(
        -1,
      )[0];
    listener(response(105));
    await drain();
    listener(response(106));
    await drain();
    finishWrite(true);
    await drain();
    mockGet.mockResolvedValueOnce(delivery(106));
    setNotificationNavigationReady(true);
    await flushPendingNotificationNavigation();
    expect(mockGet).toHaveBeenCalledWith('notifications/106');
    expect(mockGet).not.toHaveBeenCalledWith('notifications/105');
    expect(mockNavigate).toHaveBeenCalledTimes(1);
    unsubscribe();
  });

  it('invalidates both owners immediately while old registration and inbox requests are in flight', async () => {
    mockGetItem.mockImplementation(async key => {
      if (key === 'USER_DATA') return {api_token: 'session-token'};
      if (key.startsWith('PREF_NOTIFICATIONS')) return true;
      return null;
    });
    let finishRegistration!: (value: {data: {}}) => void;
    let finishInbox!: (value: ReturnType<typeof delivery>) => void;
    let registrationStarted!: () => void;
    let inboxStarted!: () => void;
    const registrationReady = new Promise<void>(resolve => {
      registrationStarted = resolve;
    });
    const inboxReady = new Promise<void>(resolve => {
      inboxStarted = resolve;
    });
    mockPost.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finishRegistration = resolve;
          registrationStarted();
        }),
    );
    mockGet.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finishInbox = resolve;
          inboxStarted();
        }),
    );
    const registration = registerPushDeviceIfEligible();
    const tap = openNotificationLink(response(201));
    await Promise.all([registrationReady, inboxReady]);

    const cleanup = clearAccountPushState();
    finishInbox(delivery(201));
    await expect(tap).resolves.toBe(false);
    const notifications = jest.requireMock('expo-notifications');
    expect(notifications.dismissAllNotificationsAsync).toHaveBeenCalledTimes(1);
    expect(notifications.setBadgeCountAsync).toHaveBeenCalledWith(0);
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
    expect(mockNavigate).not.toHaveBeenCalled();

    finishRegistration({data: {}});
    await expect(registration).resolves.toBe(false);
    await expect(cleanup).resolves.toBe(true);
    expect(mockDeleteNativeToken).toHaveBeenCalledTimes(1);
    expect(mockSaveItem).not.toHaveBeenCalledWith(
      expect.stringContaining('push-device-token'),
      expect.anything(),
    );
    expect(mockDelete).toHaveBeenCalledWith('user/device-token', {
      data: {device_token: 'fcm-token'},
      headers: {Authorization: 'Bearer session-token'},
      skipPersistedSessionInvalidation: true,
    });
  });

  it('clears private notification presentation even when token retirement cannot be made durable', async () => {
    mockDeleteNativeToken.mockResolvedValueOnce(false);
    mockSaveItem.mockResolvedValueOnce(false);
    await expect(clearAccountPushState()).rejects.toThrow(
      'PUSH_INVALIDATION_NOT_DURABLE',
    );
    const notifications = jest.requireMock('expo-notifications');
    expect(
      notifications.clearLastNotificationResponseAsync,
    ).toHaveBeenCalledTimes(1);
    expect(notifications.dismissAllNotificationsAsync).toHaveBeenCalledTimes(1);
    expect(notifications.setBadgeCountAsync).toHaveBeenCalledWith(0);
  });

  it('does not let an expired account teardown touch the current account', async () => {
    const helpers = jest.requireMock('../src/constants/helpers');
    helpers.assertAccountSessionBoundary.mockImplementationOnce(() => {
      throw new Error('ACCOUNT_CHANGED');
    });
    await expect(
      clearAccountPushState({scope: 'account-previous', epoch: 1}),
    ).rejects.toThrow('ACCOUNT_CHANGED');
    expect(mockDeleteNativeToken).not.toHaveBeenCalled();
    expect(mockRemoveItem).not.toHaveBeenCalled();
    expect(
      jest.requireMock('expo-notifications').dismissAllNotificationsAsync,
    ).not.toHaveBeenCalled();
  });

  it('keeps a cold-start tap pending while secure storage has not resolved the session', async () => {
    await clearAccountPushState();
    jest.clearAllMocks();
    mockSessionSnapshot = {ready: false, session: null};
    const notifications = jest.requireMock('expo-notifications');
    notifications.getLastNotificationResponseAsync.mockResolvedValueOnce(
      response(202),
    );
    const unsubscribe = subscribeToPushResponses();
    await drain();
    expect(mockGet).not.toHaveBeenCalled();
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(
      notifications.clearLastNotificationResponseAsync,
    ).not.toHaveBeenCalled();

    mockSessionSnapshot = {ready: true, session: {api_token: 'session-token'}};
    mockGet.mockResolvedValueOnce(delivery(202));
    await expect(flushPendingNotificationNavigation()).resolves.toBe(true);
    expect(mockNavigate).toHaveBeenCalledWith('CourseDetails', {
      courseId: '202',
    });
    expect(
      notifications.clearLastNotificationResponseAsync,
    ).toHaveBeenCalledTimes(1);
    unsubscribe();
  });

  it('discards the pending remote tap when bootstrap confirms a guest', async () => {
    await clearAccountPushState();
    jest.clearAllMocks();
    mockSessionSnapshot = {ready: false, session: null};
    const notifications = jest.requireMock('expo-notifications');
    notifications.getLastNotificationResponseAsync.mockResolvedValueOnce(
      response(203),
    );
    const unsubscribe = subscribeToPushResponses();
    await drain();
    mockSessionSnapshot = {ready: true, session: null};
    await expect(flushPendingNotificationNavigation()).resolves.toBe(true);
    expect(mockGet).not.toHaveBeenCalled();
    expect(mockNavigate).not.toHaveBeenCalled();
    expect(
      notifications.clearLastNotificationResponseAsync,
    ).toHaveBeenCalledTimes(1);
    unsubscribe();
  });

  it('retires a durable tap only after an older marker write has finished', async () => {
    await clearAccountPushState();
    jest.clearAllMocks();
    setNotificationNavigationReady(false);
    let finishWrite!: (saved: boolean) => void;
    let writeStarted!: () => void;
    const started = new Promise<void>(resolve => {
      writeStarted = resolve;
    });
    mockSaveItem.mockImplementationOnce(
      () =>
        new Promise(resolve => {
          finishWrite = resolve;
          writeStarted();
        }),
    );
    const notifications = jest.requireMock('expo-notifications');
    const unsubscribe = subscribeToPushResponses();
    const listener =
      notifications.addNotificationResponseReceivedListener.mock.calls.at(
        -1,
      )[0];
    listener(response(204));
    await started;
    const cleanup = clearAccountPushState();
    try {
      await drain();
      expect(mockRemoveItem).not.toHaveBeenCalledWith(
        expect.stringContaining('push-open-pending'),
      );
    } finally {
      finishWrite(true);
      await cleanup;
      unsubscribe();
    }
    expect(mockRemoveItem).toHaveBeenCalledWith(
      expect.stringContaining('push-open-pending'),
    );
    expect(mockGet).not.toHaveBeenCalled();
    expect(mockNavigate).not.toHaveBeenCalled();
  });
});
