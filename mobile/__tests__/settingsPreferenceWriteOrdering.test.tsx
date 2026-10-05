import React from 'react';
import {
  Alert,
  AppState,
  type AppStateStatus,
  Linking,
  Text,
} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';
import {NavigationContext} from '@react-navigation/core';

let mockUser = {id: 1, api_token: 'token-a'};
let mockBoundary = {epoch: 1, scope: 'user-1'};
let mockAuthenticated = false;
let mockRenderPrimer = false;
let mockRenderChoice = false;
let mockFocused = true;
const mockNavigationListeners = new Map<string, Set<() => void>>();
const mockNavigation = {
  isFocused: () => mockFocused,
  addListener: (event: string, listener: () => void) => {
    const listeners = mockNavigationListeners.get(event) || new Set();
    listeners.add(listener);
    mockNavigationListeners.set(event, listeners);
    return () => listeners.delete(listener);
  },
};
const changeFocus = (focused: boolean) => {
  mockFocused = focused;
  for (const listener of
    mockNavigationListeners.get(focused ? 'focus' : 'blur') || [])
    listener();
};
const mockValues = new Map<string, unknown>();
const mockWrite = jest.fn();
const mockNotificationUpdate = jest.fn();
const mockPlaybackUpdate = jest.fn();
const mockProfile = jest.fn();
const mockPermission = jest.fn();
const mockRegistration = jest.fn();
const mockUnregister = jest.fn();
const mockCapture = jest.fn();
let mockUseNativePermissionHelper = false;
const mockOsPermissionRead = jest.fn();
const mockOsPermissionRequest = jest.fn();
const mockDirty = new Set<string>();
const mockQueue = jest.fn();

jest.mock('@react-navigation/native', () => ({
  // Run the installed navigation focus lifecycle, not a substitute owner hook.
  useFocusEffect: jest.requireActual('@react-navigation/core').useFocusEffect,
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: () => mockCapture(),
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
  removeItem: async (key: string) => {
    mockValues.delete(key);
    return true;
  },
}));
jest.mock('../src/services/smartReminders', () => ({
  areSmartRemindersSupported: () => true,
  getSmartRemindersEnabled: async (boundary: typeof mockBoundary) =>
    mockValues.get(`${boundary.scope}:reminder`) ?? false,
  getSmartReminderHour: async (boundary: typeof mockBoundary) =>
    mockValues.get(`${boundary.scope}:REMINDER_HOUR`) ?? 20,
  setSmartReminderHour: (hour: number, boundary: typeof mockBoundary) =>
    mockWrite(`${boundary.scope}:REMINDER_HOUR`, hour),
  setSmartRemindersEnabled: (value: boolean, boundary: typeof mockBoundary) =>
    mockWrite(`${boundary.scope}:reminder`, value),
  enableSmartReminders: (...args: unknown[]) => mockUseNativePermissionHelper
    ? jest.requireActual('../src/services/smartReminders').enableSmartReminders(...args)
    : mockPermission(),
  cancelLearningReminders: jest.fn(),
  REMINDER_ENABLED_KEY: 'reminder',
}));
jest.mock('expo-notifications', () => ({
  getPermissionsAsync: () => mockOsPermissionRead(),
  requestPermissionsAsync: () => mockOsPermissionRequest(),
}));
jest.mock('../src/services/roknApi', () => ({
  getProfile: (...args: unknown[]) => mockProfile(...args),
  updateNotificationStatus: (value: boolean, boundary: typeof mockBoundary) =>
    mockNotificationUpdate(value, boundary),
  updatePlaybackPreferences: (...args: unknown[]) =>
    mockPlaybackUpdate(...args),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  WATCH_HISTORY_ENABLED_KEY: 'watch-history',
}));
jest.mock('../src/services/pushDeviceRegistration', () => ({
  unregisterPushDevice: (...args: unknown[]) => mockUnregister(...args),
  registerPushDeviceIfEligible: (...args: unknown[]) => mockRegistration(...args),
}));
jest.mock('../src/screens/settings/settingsData', () => ({
  PENDING_WATCH_HISTORY_CLEAR_KEY: 'pending-clear',
}));
jest.mock('../src/screens/settings/usePrivacyPreferenceSync', () => ({
  usePrivacyPreferenceSync: () => ({dirtyKeys: mockDirty, queue: mockQueue}),
  readPendingPrivacyPreferences: async () => ({}),
  MARKETING_NOTIFICATIONS_KEY: 'marketing',
}));

jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 12, bottom: 8, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => true,
}));
jest.mock('react-native-svg', () => ({
  __esModule: true,
  default: 'Svg',
  Circle: 'Circle',
  Path: 'Path',
}));
jest.mock('../src/assets/SVG', () => ({MoreBellIcon: () => null}));
jest.mock('../src/components/ui/RoknCoin', () => ({RoknCoinStack: () => null}));

import {useSettingsPreferences} from '../src/screens/settings/useSettingsPreferences';
import NotificationPermissionPrimer from '../src/components/ui/NotificationPermissionPrimer';
import {SettingsChoiceModal} from '../src/components/settings/SettingsChoiceModal';
import {
  flushPlaybackPreferenceWrites,
  PENDING_PLAYBACK_PREFERENCES_KEY,
  savePlaybackPreferencePatch,
} from '../src/services/playbackPreferenceSync';

let preferences!: ReturnType<typeof useSettingsPreferences>;
const PreferencesHarness = () => {
  preferences = useSettingsPreferences({
    hasAuthenticatedAccount: mockAuthenticated,
    userData: mockUser,
  });
  return (
    <>
      {mockRenderChoice && (
        <SettingsChoiceModal
          bottomInset={8}
          choice={preferences.choiceModal}
          onClose={preferences.closeChoiceModal}
          onSelect={preferences.selectChoice}
          quality={preferences.quality}
          reminderHour={preferences.reminderHour}
        />
      )}
      {mockRenderPrimer && (
        <NotificationPermissionPrimer
          visible={preferences.notificationPrimer}
          onEnable={preferences.confirmNotifications}
          onClose={preferences.closeNotificationPrimer}
        />
      )}
    </>
  );
};
const Harness = () => (
  <NavigationContext.Provider
    value={mockNavigation as unknown as React.ComponentProps<
      typeof NavigationContext.Provider
    >['value']}>
    <PreferencesHarness />
  </NavigationContext.Provider>
);
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
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
// Fail the durable intent, not an unrelated cache mirror/native read. Quality
// now has an account journal while the other settings keep their own writers.
const intentSuffix = (setting: Setting) =>
  `:${
    setting.field === 'quality' ? PENDING_PLAYBACK_PREFERENCES_KEY : setting.key
  }`;
const intentWrites = (setting: Setting) =>
  mockWrite.mock.calls.filter(([key]) =>
    String(key).endsWith(intentSuffix(setting)),
  );
const configureIntentWrites = (
  setting: Setting,
  results: Array<Promise<boolean> | boolean>,
) => {
  mockWrite.mockImplementation(async (key: string, value: unknown) => {
    const result =
      key.endsWith(intentSuffix(setting)) && results.length
        ? await results.shift()
        : true;
    if (result) mockValues.set(key, value);
    return result;
  });
};
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
    mockRenderPrimer = false;
    mockRenderChoice = false;
    mockFocused = true;
    mockNavigationListeners.clear();
    mockPermission.mockReset().mockResolvedValue(true);
    mockUseNativePermissionHelper = false;
    mockOsPermissionRead.mockReset().mockResolvedValue({granted: false, canAskAgain: true});
    mockOsPermissionRequest.mockReset().mockResolvedValue({granted: true});
    mockRegistration.mockReset().mockResolvedValue(true);
    mockUnregister.mockReset().mockResolvedValue(undefined);
    mockCapture.mockReset().mockImplementation(async () => mockBoundary);
    mockDirty.clear();
    mockQueue.mockReset().mockResolvedValue(undefined);
    mockValues.clear();
    mockPlaybackUpdate.mockReset().mockRejectedValue(new Error('offline'));
    mockProfile.mockReset().mockResolvedValue({
      watchHistoryEnabled: true,
      marketingNotificationsEnabled: false,
      videoQualityPreference: 'auto',
      playbackSpeed: 1,
    });
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
      configureIntentWrites(setting, [first.promise, false]);
      await change(setting, setting.first);
      await change(setting, setting.second);
      expect(preferences[setting.field]).toBe(setting.second);
      expect(intentWrites(setting)).toHaveLength(1);
      await act(async () => first.resolve(false));
      expect(intentWrites(setting)).toHaveLength(2);
      expect(preferences[setting.field]).toBe(setting.initial);
    },
  );

  it.each(cases)(
    'keeps the accepted $field when its following write fails',
    async setting => {
      const first = deferred<boolean>();
      configureIntentWrites(setting, [first.promise, false]);
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
      configureIntentWrites(setting, [first.promise, true]);
      await change(setting, setting.first);
      await change(setting, setting.second);
      await act(async () => first.resolve(false));
      expect(preferences[setting.field]).toBe(setting.second);
      expect(Alert.alert).not.toHaveBeenCalled();
    },
  );

  it.each(cases)(
    'reports only the latest failed $field in the same focused visit',
    async setting => {
      const first = deferred<boolean>();
      configureIntentWrites(setting, [first.promise, false]);
      await change(setting, setting.first);
      await change(setting, setting.second);
      await act(async () => first.resolve(false));
      expect(preferences[setting.field]).toBe(setting.initial);
      expect(Alert.alert).toHaveBeenCalledTimes(1);
      expect(Alert.alert).toHaveBeenCalledWith(
        'لم يُحفظ التغيير',
        expect.any(String),
      );
    },
  );

  describe.each(['blur', 'blur and return', 'unmount'] as const)(
    'late failures after %s',
    departure => {
      it.each(cases)(
        'keep the rollback but retire the $field notice',
        async setting => {
          const write = deferred<boolean>();
          configureIntentWrites(setting, [write.promise]);
          await change(setting, setting.first);
          expect(intentWrites(setting)).toHaveLength(1);
          await act(async () => {
            if (departure === 'unmount') renderer.unmount();
            else changeFocus(false);
          });
          if (departure === 'blur and return')
            await act(async () => changeFocus(true));
          await act(async () => write.resolve(false));
          expect(Alert.alert).not.toHaveBeenCalled();
          if (departure !== 'unmount')
            expect(preferences[setting.field]).toBe(setting.initial);
        },
      );
    },
  );

  it.each(cases)(
    'does not silence a fresh $field failure when an old visit finishes',
    async setting => {
      const oldWrite = deferred<boolean>();
      configureIntentWrites(setting, [oldWrite.promise, false]);
      await change(setting, setting.first);
      await act(async () => changeFocus(false));
      await act(async () => changeFocus(true));
      await change(setting, setting.second);
      await act(async () => oldWrite.resolve(false));
      expect(preferences[setting.field]).toBe(setting.initial);
      expect(Alert.alert).toHaveBeenCalledTimes(1);
    },
  );

  it.each(cases)(
    'keeps an accepted $field write after leaving its settings visit',
    async setting => {
      const write = deferred<boolean>();
      configureIntentWrites(setting, [write.promise]);
      await change(setting, setting.first);
      await act(async () => changeFocus(false));
      await act(async () => write.resolve(true));
      await act(async () => changeFocus(true));
      expect(preferences[setting.field]).toBe(setting.first);
      expect(Alert.alert).not.toHaveBeenCalled();
    },
  );

  it.each(cases)(
    'does not roll back the replacement account’s $field or show its old error',
    async setting => {
      const oldWrite = deferred<boolean>();
      const newWrite = deferred<boolean>();
      configureIntentWrites(setting, [oldWrite.promise, newWrite.promise]);
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
      configureIntentWrites(setting, [false]);
      await change(setting, setting.second);
      expect(preferences[setting.field]).toBe(setting.first);
    },
  );

  describe('native settings choice presentation ownership', () => {
    const choiceCases = [
      {...cases[0], choice: 'quality', label: '٧٢٠ بكسل'},
      {...cases[1], choice: 'reminderTime', label: 'صباحًا · ١٠:٠٠'},
    ] as const;
    const open = async (setting: (typeof choiceCases)[number]) => {
      await act(async () => {
        if (setting.choice === 'quality') preferences.openQualityChoice();
        else preferences.openReminderChoice();
      });
    };
    // Select the actual accessible radio, not React Native's internal
    // memoized Pressable export identity, which is not the rendered row type.
    const rowPress = (label: string): (() => void) => renderer.root
      .findByProps({accessibilityLabel: label, accessibilityRole: 'radio'})
      .props.onPress;

    beforeEach(async () => {
      mockRenderChoice = true;
      await act(async () => renderer.update(<Harness />));
      mockWrite.mockClear();
      mockCapture.mockClear();
    });

    it('does not treat selection without an open modal as a quality change', async () => {
      const select = renderer.root.findByType(SettingsChoiceModal).props.onSelect;
      await act(async () => select('720p'));
      expect(preferences.quality).toBe('auto');
      expect(mockCapture).not.toHaveBeenCalled();
      expect(mockWrite).not.toHaveBeenCalled();
    });

    it.each(choiceCases)(
      'does not let an old $choice row or close action own a reopened modal',
      async setting => {
        await open(setting);
        const oldRow = rowPress(setting.label);
        const oldClose = renderer.root.findByType(SettingsChoiceModal).props.onClose;
        await act(async () => oldClose());
        await open(setting);
        await act(async () => {oldRow(); oldClose();});
        expect(preferences.choiceModal).toBe(setting.choice);
        expect(preferences[setting.field]).toBe(setting.initial);
        expect(intentWrites(setting)).toHaveLength(0);
        await act(async () => rowPress(setting.label)());
        expect(preferences.choiceModal).toBeNull();
        expect(preferences[setting.field]).toBe(setting.first);
        expect(intentWrites(setting)).toHaveLength(1);
        expect(Alert.alert).not.toHaveBeenCalled();
      },
    );

    it('does not interpret a retained quality row as the current reminder choice', async () => {
      await open(choiceCases[0]);
      const oldRow = rowPress(choiceCases[0].label);
      const oldClose = preferences.closeChoiceModal;
      await act(async () => oldClose());
      await open(choiceCases[1]);
      await act(async () => {oldRow(); oldClose();});
      expect(preferences.choiceModal).toBe('reminderTime');
      expect(preferences.quality).toBe('auto');
      expect(preferences.reminderHour).toBe(20);
      expect(mockWrite).not.toHaveBeenCalled();
      await act(async () => rowPress(choiceCases[1].label)());
      expect(mockValues.get('user-1:REMINDER_HOUR')).toBe(10);
      expect(intentWrites(choiceCases[0])).toHaveLength(0);
    });

    describe.each(['blur', 'blur and return', 'unmount', 'account switch'] as const)(
      'retained native rows after %s',
      departure => {
        it.each(choiceCases)('cannot start another $choice write', async setting => {
          await open(setting);
          const oldRow = rowPress(setting.label);
          const oldClose = preferences.closeChoiceModal;
          await act(async () => {
            if (departure === 'unmount') renderer.unmount();
            else if (departure === 'account switch') {
              mockUser = {id: 2, api_token: 'token-b'};
              mockBoundary = {epoch: 2, scope: 'user-2'};
              renderer.update(<Harness />);
            } else changeFocus(false);
          });
          if (departure === 'blur and return')
            await act(async () => changeFocus(true));
          mockWrite.mockClear();
          const captures = mockCapture.mock.calls.length;
          await act(async () => {oldRow(); oldClose();});
          expect(mockCapture).toHaveBeenCalledTimes(captures);
          expect(mockWrite).not.toHaveBeenCalled();
          expect(Alert.alert).not.toHaveBeenCalled();
          if (departure !== 'unmount') {
            expect(preferences.choiceModal).toBeNull();
            expect(preferences[setting.field]).toBe(setting.initial);
          }
        });
      },
    );

    it.each(choiceCases)(
      'does not acquire a replacement session for an already-open $choice',
      async setting => {
        await open(setting);
        const press = rowPress(setting.label);
        const captures = mockCapture.mock.calls.length;
        // The keychain session can change before Redux rerenders this surface.
        mockBoundary = {epoch: 2, scope: 'user-1'};
        await act(async () => press());
        expect(mockCapture).toHaveBeenCalledTimes(captures);
        expect(mockWrite).not.toHaveBeenCalled();
        expect(preferences[setting.field]).toBe(setting.initial);
        expect(Alert.alert).not.toHaveBeenCalled();
      },
    );

    it.each(choiceCases)(
      'consumes a double native row press for $choice only once',
      async setting => {
        await open(setting);
        const press = rowPress(setting.label);
        await act(async () => {press(); press();});
        expect(intentWrites(setting)).toHaveLength(1);
        expect(preferences[setting.field]).toBe(setting.first);
      },
    );

    it.each(choiceCases)(
      'does not let delayed opening-account preparation overwrite a newer $choice',
      async setting => {
        const captured = deferred<typeof mockBoundary>();
        mockCapture.mockReturnValueOnce(captured.promise);
        await open(setting);
        await act(async () => rowPress(setting.label)());
        expect(intentWrites(setting)).toHaveLength(0);
        await change(setting, setting.second);
        expect(intentWrites(setting)).toHaveLength(1);
        await act(async () => captured.resolve(mockBoundary));
        expect(intentWrites(setting)).toHaveLength(1);
        expect(preferences[setting.field]).toBe(setting.second);
        expect(Alert.alert).not.toHaveBeenCalled();
      },
    );

    it.each(choiceCases)(
      'keeps an admitted $choice selection when its same-account preparation settles after blur',
      async setting => {
        const boundary = mockBoundary;
        const captured = deferred<typeof mockBoundary>();
        mockCapture.mockReturnValueOnce(captured.promise);
        await open(setting);
        await act(async () => rowPress(setting.label)());
        await act(async () => changeFocus(false));
        await act(async () => captured.resolve(boundary));
        expect(intentWrites(setting)).toHaveLength(1);
        expect(preferences[setting.field]).toBe(setting.first);
        expect(Alert.alert).not.toHaveBeenCalled();
      },
    );

    describe.each(['SETTINGS_STORAGE_WRITE_FAILED', 'SESSION_STORAGE_UNAVAILABLE'])(
      'opening capture failure %s',
      message => {
        it.each(choiceCases)(
          'keeps $choice unchanged without a write and allows a fresh retry',
          async setting => {
            mockCapture.mockRejectedValueOnce(new Error(message));
            await open(setting);
            await act(async () => rowPress(setting.label)());
            expect(intentWrites(setting)).toHaveLength(0);
            expect(preferences[setting.field]).toBe(setting.initial);
            expect(Alert.alert).toHaveBeenCalledTimes(1);
            await open(setting);
            await act(async () => rowPress(setting.label)());
            expect(intentWrites(setting)).toHaveLength(1);
            expect(preferences[setting.field]).toBe(setting.first);
          },
        );
      },
    );
  });

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
      void preferences.confirmNotifications().catch(() => undefined);
    });
    await act(async () => disable.resolve(false));
    expect(mockNotificationUpdate).toHaveBeenCalledTimes(1);
    expect(mockNotificationUpdate).toHaveBeenCalledWith(true, mockBoundary);
    expect(mockWrite).toHaveBeenLastCalledWith('user-1:reminder', true);
    expect(preferences.notifications).toBe(true);
  });

  it.each(['blur and return', 'unmount', 'account switch'] as const)(
    'does not launch an OS permission request after %s during capture',
    async departure => {
      const captured = deferred<typeof mockBoundary>();
      const boundary = mockBoundary;
      mockCapture.mockReturnValueOnce(captured.promise);
      let activation!: Promise<unknown>;
      await act(async () => {
        activation = preferences.confirmNotifications().catch(error => error);
      });
      await act(async () => {
        if (departure === 'unmount') renderer.unmount();
        else if (departure === 'account switch') {
          mockBoundary = {epoch: 2, scope: 'user-2'};
          mockUser = {id: 2, api_token: 'token-b'};
          renderer.update(<Harness />);
        } else changeFocus(false);
      });
      if (departure === 'blur and return')
        await act(async () => changeFocus(true));
      await act(async () => {
        captured.resolve(boundary);
        await activation;
      });
      expect(mockPermission).not.toHaveBeenCalled();
      expect(mockRegistration).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
    },
  );

  it.each(['account switch', 'same-account replacement'] as const)(
    'does not save or register after %s during the native permission request',
    async departure => {
      const permission = deferred<boolean>();
      mockPermission.mockReturnValueOnce(permission.promise);
      mockWrite.mockClear();
      let activation!: Promise<unknown>;
      await act(async () => {
        activation = preferences.confirmNotifications().catch(error => error);
      });
      expect(mockPermission).toHaveBeenCalledTimes(1);
      await act(async () => {
        if (departure === 'account switch') {
          mockBoundary = {epoch: 2, scope: 'user-2'};
          mockUser = {id: 2, api_token: 'token-b'};
          renderer.update(<Harness />);
        } else mockBoundary = {epoch: 2, scope: 'user-1'};
      });
      await act(async () => {
        permission.resolve(true);
        await activation;
      });
      expect(mockWrite).not.toHaveBeenCalled();
      expect(mockNotificationUpdate).not.toHaveBeenCalled();
      expect(mockRegistration).not.toHaveBeenCalled();
    },
  );

  it('keeps the fixed account owner after the OS request even when the view closes', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    mockCapture.mockClear();
    const permission = deferred<boolean>();
    mockPermission.mockReturnValueOnce(permission.promise);
    let activation!: Promise<unknown>;
    await act(async () => {
      activation = preferences.confirmNotifications();
    });
    await act(async () => changeFocus(false));
    await act(async () => {permission.resolve(true); await activation;});
    expect(mockRegistration).toHaveBeenCalledWith({
      requestPermission: false,
      ownerBoundary: mockBoundary,
    });
    expect(mockCapture).toHaveBeenCalledTimes(1); // one owner for the entire activation
    expect(preferences.notifications).toBe(true);
  });

  it('does not let an older registration failure undo a newer accepted notification choice', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    const registration = deferred<boolean>();
    mockRegistration.mockReturnValueOnce(registration.promise);
    let firstActivation!: Promise<unknown>;
    await act(async () => {
      firstActivation = preferences.confirmNotifications().catch(error => error);
    });
    expect(mockRegistration).toHaveBeenCalledTimes(1);
    await act(async () => {await preferences.toggleNotifications(false);});
    expect(mockUnregister).toHaveBeenCalledWith(mockBoundary);
    await act(async () => {await preferences.confirmNotifications();});
    expect(mockRegistration).toHaveBeenCalledTimes(2);
    await act(async () => {registration.resolve(false); await firstActivation;});
    expect(preferences.notifications).toBe(true);
    expect(mockValues.get('user-1:reminder')).toBe(true);
    expect(mockNotificationUpdate.mock.calls.map(([value]) => value)).toEqual([
      true, false, true,
    ]);
  });

  it('does not let a delayed opt-out capture supersede a newer accepted opt-in', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    const captured = deferred<typeof mockBoundary>();
    mockCapture.mockReturnValueOnce(captured.promise);
    let optOut!: Promise<void>;
    await act(async () => {optOut = preferences.toggleNotifications(false);});
    await act(async () => {await preferences.confirmNotifications();});
    expect(preferences.notifications).toBe(true);
    await act(async () => {captured.resolve(mockBoundary); await optOut;});
    expect(mockNotificationUpdate.mock.calls.map(([value]) => value)).toEqual([true]);
    expect(mockUnregister).not.toHaveBeenCalled();
    expect(preferences.notifications).toBe(true);
  });

  it('retires preparation while the real SDK permission read waits without opening a late OS dialog', async () => {
    mockUseNativePermissionHelper = true;
    const permissionRead = deferred<{granted: boolean; canAskAgain: boolean}>();
    mockOsPermissionRead.mockReturnValueOnce(permissionRead.promise);
    let activation!: Promise<unknown>;
    await act(async () => {
      activation = preferences.confirmNotifications().catch(error => error);
    });
    expect(mockOsPermissionRead).toHaveBeenCalledTimes(1);
    await act(async () => changeFocus(false));
    await act(async () => changeFocus(true));
    await act(async () => {
      permissionRead.resolve({granted: false, canAskAgain: true});
      await activation;
    });
    expect(mockOsPermissionRequest).not.toHaveBeenCalled();
    expect(mockNotificationUpdate).not.toHaveBeenCalled();
    expect(mockRegistration).not.toHaveBeenCalled();
  });

  it('retains account settlement after the real native OS prompt launches and the view leaves', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    mockUseNativePermissionHelper = true;
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    const permissionRequest = deferred<{granted: boolean}>();
    mockOsPermissionRequest.mockReturnValueOnce(permissionRequest.promise);
    let activation!: Promise<unknown>;
    await act(async () => {activation = preferences.confirmNotifications();});
    expect(mockOsPermissionRequest).toHaveBeenCalledTimes(1);
    await act(async () => changeFocus(false));
    await act(async () => {permissionRequest.resolve({granted: true}); await activation;});
    expect(mockRegistration).toHaveBeenCalledWith({
      requestPermission: false, ownerBoundary: mockBoundary,
    });
    expect(preferences.notifications).toBe(true);
  });

  it('does not commit an older native grant after the learner has explicitly disabled notifications', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    mockUseNativePermissionHelper = true;
    await act(async () => {renderer = TestRenderer.create(<Harness />);});
    const permissionRequest = deferred<{granted: boolean}>();
    mockOsPermissionRequest.mockReturnValueOnce(permissionRequest.promise);
    let activation!: Promise<unknown>;
    await act(async () => {
      activation = preferences.confirmNotifications().catch(error => error);
    });
    expect(mockOsPermissionRequest).toHaveBeenCalledTimes(1);
    await act(async () => {await preferences.toggleNotifications(false);});
    await act(async () => {permissionRequest.resolve({granted: true}); await activation;});
    expect(mockNotificationUpdate.mock.calls.map(([value]) => value)).toEqual([false]);
    expect(mockRegistration).not.toHaveBeenCalled();
    expect(preferences.notifications).toBe(false);
  });

  it('hydrates the pending playback choice and preserves it over the old server profile', async () => {
    await act(async () => renderer.unmount());
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '480p', playbackSpeed: 1.5},
      mockBoundary,
    );
    await flushPlaybackPreferenceWrites(mockBoundary);
    mockAuthenticated = true;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    expect(preferences.quality).toBe('480p');
    expect(mockValues.get('user-1:VIDEO_QUALITY')).toBe('480p');
    expect(mockValues.get('user-1:VIDEO_PLAYBACK_SPEED')).toBe(1.5);
    expect(
      mockValues.get(`user-1:${PENDING_PLAYBACK_PREFERENCES_KEY}`),
    ).toBeDefined();
  });

  it('persists playback intent while an authenticated privacy write is waiting for the server', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    const privacyRequest = deferred<void>();
    mockQueue.mockReturnValueOnce(privacyRequest.promise);
    let privacyUpdate!: Promise<boolean>;
    await act(async () => {
      privacyUpdate = preferences.toggleWatchHistory(false);
    });
    try {
      expect(mockQueue).toHaveBeenCalledWith(
        {watchHistoryEnabled: false},
        mockBoundary,
      );
      await change(cases[0], '480p');
      await act(async () => {
        await savePlaybackPreferencePatch({playbackSpeed: 1.5}, mockBoundary);
      });
      expect(
        mockValues.get(`user-1:${PENDING_PLAYBACK_PREFERENCES_KEY}`),
      ).toEqual({
        revision: 2,
        patch: {videoQualityPreference: '480p', playbackSpeed: 1.5},
      });
      expect(preferences.watchHistory).toBe(false);
      expect(preferences.quality).toBe('480p');
    } finally {
      await act(async () => {
        privacyRequest.resolve(undefined);
        await privacyUpdate;
      });
    }
  });

  it('does not block playback on notification HTTP or let its failed enable undo a following disable', async () => {
    await act(async () => renderer.unmount());
    mockAuthenticated = true;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    const notificationRequest = deferred<boolean>();
    mockNotificationUpdate.mockReturnValueOnce(notificationRequest.promise);
    let enable!: Promise<unknown>;
    let disable!: Promise<void>;
    await act(async () => {
      enable = preferences.confirmNotifications().catch(error => error);
    });
    try {
      expect(mockNotificationUpdate).toHaveBeenCalledWith(true, mockBoundary);
      await act(async () => {
        disable = preferences.toggleNotifications(false);
        await savePlaybackPreferencePatch({playbackSpeed: 1.5}, mockBoundary);
      });
      expect(
        mockValues.get(`user-1:${PENDING_PLAYBACK_PREFERENCES_KEY}`),
      ).toEqual({
        revision: 1,
        patch: {playbackSpeed: 1.5},
      });
      expect(preferences.notifications).toBe(false);
      expect(mockNotificationUpdate).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => {
        notificationRequest.reject(new Error('offline'));
        await enable;
        await disable;
      });
    }
    expect(mockValues.get('user-1:reminder')).toBe(false);
    expect(mockNotificationUpdate.mock.calls.map(([value]) => value)).toEqual([
      true,
      false,
    ]);
    expect(preferences.notifications).toBe(false);
  });

  it.each(['storage', 'server', 'registration'] as const)(
    'shows primer retry rather than OS denial for %s failure after settings return',
    async failure => {
      const listeners = new Set<(state: AppStateStatus) => void>();
      jest
        .spyOn(AppState, 'addEventListener')
        .mockImplementation((_event, listener) => {
          listeners.add(listener);
          return {
            remove: () => {
              listeners.delete(listener);
            },
          };
        });
      // RN's preset already provides a jest.fn; restoreAllMocks does not
      // clear that native mock's call history between parameterized cases.
      jest.spyOn(Linking, 'openSettings').mockClear().mockResolvedValue(undefined);
      mockRenderPrimer = true;
      mockAuthenticated = true;
      await act(async () => renderer.update(<Harness />));
      await act(async () => preferences.toggleNotifications(true));
      const press = async (label: string) => {
        const button = renderer.root
          .findAllByProps({accessibilityRole: 'button'})
          .find(node =>
            node
              .findAllByType(Text)
              .some(text => text.props.children === label),
          )!;
        await act(async () => {
          button.props.onPress();
        });
      };
      mockPermission.mockResolvedValueOnce(false);
      await press('فعّل الإشعارات');
      expect(mockNotificationUpdate).not.toHaveBeenCalled();
      await press('فتح إعدادات الهاتف');
      if (failure === 'storage') mockWrite.mockResolvedValueOnce(false);
      if (failure === 'server')
        mockNotificationUpdate.mockRejectedValueOnce(new Error('offline'));
      if (failure === 'registration')
        mockRegistration.mockResolvedValueOnce(false);
      await act(async () => {
        for (const listener of Array.from(listeners)) listener('background');
        for (const listener of Array.from(listeners)) listener('active');
      });
      const strings = renderer.root
        .findAllByType(Text)
        .map(node => node.props.children);
      expect(strings).toContain('تعذّر تفعيل الإشعارات');
      expect(strings).not.toContain('اسمح بإشعارات ركن من إعدادات الهاتف');
      expect(preferences.notificationPrimer).toBe(true);
      expect(preferences.notifications).toBe(false);
      expect(Alert.alert).not.toHaveBeenCalled();
      await press('حاول مرة أخرى');
      expect(preferences.notificationPrimer).toBe(false);
      expect(preferences.notifications).toBe(true);
      expect(mockValues.get('user-1:reminder')).toBe(true);
      expect(mockNotificationUpdate).toHaveBeenLastCalledWith(
        true,
        mockBoundary,
      );
      expect(Linking.openSettings).toHaveBeenCalledTimes(1);
    },
  );
});
