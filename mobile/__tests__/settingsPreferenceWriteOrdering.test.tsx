import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockUser = {id: 1, api_token: 'token-a'};
let mockBoundary = {epoch: 1, scope: 'user-1'};
let mockAuthenticated = false;
const mockValues = new Map<string, unknown>();
const mockWrite = jest.fn();
const mockNotificationUpdate = jest.fn();
const mockDirty = new Set<string>();

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    require('react').useEffect(effect, [effect]);
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary !== mockBoundary)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${boundary.scope}:${key}`,
  sessionIdentityKey: (user: typeof mockUser) => `user-${user.id}`,
  extractApiToken: (user: typeof mockUser) => user.api_token,
  getItem: async (key: string) => mockValues.get(key) ?? null,
  saveItem: (key: string, value: unknown) => mockWrite(key, value),
  removeItem: async () => undefined,
}));
jest.mock('../src/services/smartReminders', () => ({
  getSmartRemindersEnabled: async (boundary: typeof mockBoundary) =>
    mockValues.get(`${boundary.scope}:reminder`) ?? false,
  getSmartReminderHour: async (boundary: typeof mockBoundary) =>
    mockValues.get(`${boundary.scope}:REMINDER_HOUR`) ?? 20,
  setSmartReminderHour: (hour: number, boundary: typeof mockBoundary) =>
    mockWrite(`${boundary.scope}:REMINDER_HOUR`, hour),
  setSmartRemindersEnabled: (value: boolean, boundary: typeof mockBoundary) =>
    mockWrite(`${boundary.scope}:reminder`, value),
  enableSmartReminders: async () => true,
  cancelLearningReminders: jest.fn(),
  REMINDER_ENABLED_KEY: 'reminder',
}));
jest.mock('../src/services/roknApi', () => ({
  getProfile: async () => ({
    watchHistoryEnabled: true,
    marketingNotificationsEnabled: false,
    videoQualityPreference: 'auto',
    playbackSpeed: 1,
  }),
  updateNotificationStatus: (value: boolean, boundary: typeof mockBoundary) =>
    mockNotificationUpdate(value, boundary),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  WATCH_HISTORY_ENABLED_KEY: 'watch-history',
}));
jest.mock('../src/services/pushNotifications', () => ({
  unregisterPushDevice: async () => undefined,
  registerPushDeviceIfEligible: async () => true,
}));
jest.mock('../src/screens/settings/settingsData', () => ({
  PENDING_WATCH_HISTORY_CLEAR_KEY: 'pending-clear',
}));
jest.mock('../src/screens/settings/usePrivacyPreferenceSync', () => ({
  usePrivacyPreferenceSync: () => ({dirtyKeys: mockDirty, queue: mockQueue}),
  readPendingPrivacyPreferences: async () => ({}),
  MARKETING_NOTIFICATIONS_KEY: 'marketing',
}));
const mockQueue = async () => undefined;

import {useSettingsPreferences} from '../src/screens/settings/useSettingsPreferences';

let preferences!: ReturnType<typeof useSettingsPreferences>;
const Harness = () => {
  preferences = useSettingsPreferences({
    hasAuthenticatedAccount: mockAuthenticated,
    userData: mockUser,
  });
  return null;
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(accept => {
    resolve = accept;
  });
  return {promise, resolve};
};
const cases = [
  {
    field: 'quality',
    key: 'VIDEO_QUALITY',
    initial: 'auto',
    first: '720p',
    second: '480p',
  },
  {
    field: 'reminderHour',
    key: 'REMINDER_HOUR',
    initial: 20,
    first: 10,
    second: 15,
  },
  {
    field: 'watchHistory',
    key: 'watch-history',
    initial: true,
    first: false,
    second: true,
  },
  {
    field: 'marketingNotifications',
    key: 'marketing',
    initial: false,
    first: true,
    second: false,
  },
] as const;
type Setting = (typeof cases)[number];
const change = async (setting: Setting, value: string | number | boolean) => {
  if (setting.field === 'quality' || setting.field === 'reminderHour') {
    await act(async () => {
      if (setting.field === 'quality') preferences.openQualityChoice();
      else preferences.openReminderChoice();
    });
    await act(async () => preferences.selectChoice(String(value)));
  } else {
    await act(async () => {
      if (setting.field === 'watchHistory')
        void preferences.toggleWatchHistory(Boolean(value));
      else void preferences.toggleMarketing(Boolean(value));
    });
  }
};

describe('settings optimistic changes retain the last saved value', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  beforeEach(async () => {
    mockUser = {id: 1, api_token: 'token-a'};
    mockBoundary = {epoch: 1, scope: 'user-1'};
    mockAuthenticated = false;
    mockDirty.clear();
    mockValues.clear();
    mockNotificationUpdate
      .mockReset()
      .mockImplementation(async (value: boolean) => value);
    mockWrite
      .mockReset()
      .mockImplementation(async (key: string, value: unknown) => {
        mockValues.set(key, value);
        return true;
      });
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
  });
  afterEach(async () => {
    await act(async () => renderer.unmount());
    jest.restoreAllMocks();
  });

  it.each(cases)(
    'does not restore an unsaved intermediate $field after both writes fail',
    async setting => {
      const first = deferred<boolean>();
      mockWrite
        .mockImplementationOnce(() => first.promise)
        .mockResolvedValueOnce(false);
      await change(setting, setting.first);
      await change(setting, setting.second);
      expect(preferences[setting.field]).toBe(setting.second);
      expect(mockWrite).toHaveBeenCalledTimes(1);
      await act(async () => first.resolve(false));
      expect(mockWrite).toHaveBeenCalledTimes(2);
      expect(preferences[setting.field]).toBe(setting.initial);
    },
  );

  it.each(cases)(
    'keeps the accepted $field when its following write fails',
    async setting => {
      const first = deferred<boolean>();
      mockWrite
        .mockImplementationOnce(() => first.promise)
        .mockResolvedValueOnce(false);
      await change(setting, setting.first);
      await change(setting, setting.second);
      await act(async () => first.resolve(true));
      expect(preferences[setting.field]).toBe(setting.first);
    },
  );

  it.each(cases)(
    'keeps the latest accepted $field when its earlier write fails',
    async setting => {
      const first = deferred<boolean>();
      mockWrite
        .mockImplementationOnce(() => first.promise)
        .mockResolvedValueOnce(true);
      await change(setting, setting.first);
      await change(setting, setting.second);
      await act(async () => first.resolve(false));
      expect(preferences[setting.field]).toBe(setting.second);
    },
  );

  it.each(cases)(
    'does not roll back the replacement account’s $field or show its old error',
    async setting => {
      const oldWrite = deferred<boolean>();
      const newWrite = deferred<boolean>();
      mockWrite
        .mockImplementationOnce(() => oldWrite.promise)
        .mockImplementationOnce(() => newWrite.promise);
      await change(setting, setting.first);
      mockUser = {id: 2, api_token: 'token-b'};
      mockBoundary = {epoch: 2, scope: 'user-2'};
      await act(async () => renderer.update(<Harness />));
      await change(setting, setting.second);
      await act(async () => oldWrite.resolve(false));
      expect(preferences[setting.field]).toBe(setting.second);
      expect(Alert.alert).not.toHaveBeenCalled();
      if (
        setting.field === 'watchHistory' ||
        setting.field === 'marketingNotifications'
      ) {
        expect(mockDirty.has(setting.key)).toBe(true);
      }
      await act(async () => newWrite.resolve(true));
      expect(preferences[setting.field]).toBe(setting.second);
    },
  );

  it.each(cases)(
    'uses the hydrated $field as its rollback value',
    async setting => {
      mockUser = {id: 2, api_token: 'token-b'};
      mockBoundary = {epoch: 2, scope: 'user-2'};
      mockValues.set(`user-2:${setting.key}`, setting.first);
      await act(async () => renderer.update(<Harness />));
      expect(preferences[setting.field]).toBe(setting.first);
      mockWrite.mockResolvedValueOnce(false);
      await change(setting, setting.second);
      expect(preferences[setting.field]).toBe(setting.first);
    },
  );

  it('restores the saved notification choice when a failed disable is followed by a rejected enable', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    mockValues.set('user-1:reminder', true);
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(preferences.notifications).toBe(true);
    const disable = deferred<boolean>();
    mockWrite.mockClear().mockImplementationOnce(() => disable.promise);
    mockNotificationUpdate.mockRejectedValue(new Error('offline'));
    await act(async () => {
      void preferences.toggleNotifications(false);
    });
    await act(async () => {
      void preferences.confirmNotifications();
    });
    await act(async () => disable.resolve(false));
    expect(mockNotificationUpdate).toHaveBeenCalledTimes(1);
    expect(mockNotificationUpdate).toHaveBeenCalledWith(true, mockBoundary);
    expect(mockWrite).toHaveBeenLastCalledWith('user-1:reminder', true);
    expect(preferences.notifications).toBe(true);
  });
});
