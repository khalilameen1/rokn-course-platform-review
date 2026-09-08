import React from 'react';
import {Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

let mockUser = {id: 1, api_token: 'token-a'};
let mockBoundary = {epoch: 1, scope: 'user-1'};
let mockFocused = true;
const mockCapture = jest.fn();
const mockDirty = new Set<string>();
const mockClearLocal = jest.fn();
const mockClearRemote = jest.fn();
const mockSave = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react');
    ReactModule.useEffect(
      () => (mockFocused ? effect() : undefined),
      [effect, mockFocused],
    );
  },
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
  getItem: async () => null,
  saveItem: (...args: unknown[]) => mockSave(...args),
  removeItem: async () => undefined,
}));
jest.mock('../src/services/smartReminders', () => ({
  getSmartRemindersEnabled: async () => false,
  getSmartReminderHour: async () => 20,
  REMINDER_ENABLED_KEY: 'reminder',
}));
jest.mock('../src/services/roknApi', () => ({
  clearWatchHistory: (...args: unknown[]) => mockClearRemote(...args),
  getProfile: async () => ({
    watchHistoryEnabled: true,
    videoQualityPreference: 'auto',
  }),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  clearLocalWatchHistory: (...args: unknown[]) => mockClearLocal(...args),
  WATCH_HISTORY_ENABLED_KEY: 'watch-history',
}));
jest.mock('../src/services/pushNotifications', () => ({}));
jest.mock('../src/screens/settings/settingsData', () => ({
  PENDING_WATCH_HISTORY_CLEAR_KEY: 'pending-clear',
}));
jest.mock('../src/screens/settings/usePrivacyPreferenceSync', () => ({
  usePrivacyPreferenceSync: () => ({
    dirtyKeys: mockDirty,
    queue: async () => undefined,
  }),
  readPendingPrivacyPreferences: async () => ({}),
  MARKETING_NOTIFICATIONS_KEY: 'marketing',
}));

import {useSettingsPreferences} from '../src/screens/settings/useSettingsPreferences';

let preferences!: ReturnType<typeof useSettingsPreferences>;
const Harness = () => {
  preferences = useSettingsPreferences({
    hasAuthenticatedAccount: true,
    userData: mockUser,
  });
  return null;
};

describe('watch-history destructive confirmation ownership', () => {
  beforeEach(() => {
    mockUser = {id: 1, api_token: 'token-a'};
    mockBoundary = {epoch: 1, scope: 'user-1'};
    mockFocused = true;
    mockCapture.mockReset().mockImplementation(async () => mockBoundary);
    mockSave.mockReset().mockResolvedValue(true);
    mockClearLocal.mockReset().mockResolvedValue(undefined);
    mockClearRemote.mockReset().mockResolvedValue(undefined);
  });

  it.each([
    'blur and return',
    'unmount',
    'account switch during capture',
    'blur during capture',
    'same-account session replacement',
  ])('does not erase history after %s', async exit => {
    let confirm: (() => void) | undefined;
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation((_title, _message, buttons) => {
        if (buttons)
          confirm = buttons.find(
            button => button.style === 'destructive',
          )?.onPress;
      });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    let resolveCapture!: (boundary: typeof mockBoundary) => void;
    const delayedCapture = exit.includes('during capture');
    if (delayedCapture) {
      mockCapture.mockReturnValueOnce(
        new Promise<typeof mockBoundary>(resolve => {
          resolveCapture = resolve;
        }),
      );
    }
    try {
      await act(async () => preferences.confirmClearWatchHistory());
      expect(confirm).toBeDefined();
      if (delayedCapture) {
        await act(async () => {
          void confirm!();
        });
      }
      if (exit.includes('account switch')) {
        mockUser = {id: 2, api_token: 'token-b'};
        mockBoundary = {epoch: 2, scope: 'user-2'};
        await act(async () => renderer.update(<Harness />));
      } else if (exit.includes('blur')) {
        await act(async () => {
          mockFocused = false;
          renderer.update(<Harness />);
        });
        if (exit === 'blur and return') {
          await act(async () => {
            mockFocused = true;
            renderer.update(<Harness />);
          });
        }
      } else if (exit === 'unmount') {
        await act(async () => renderer.unmount());
      } else {
        mockBoundary = {epoch: 2, scope: 'user-1'};
      }
      if (delayedCapture) await act(async () => resolveCapture(mockBoundary));
      else await act(async () => confirm!());
      expect(mockClearLocal).not.toHaveBeenCalled();
      expect(mockClearRemote).not.toHaveBeenCalled();
    } finally {
      alert.mockRestore();
      if (exit !== 'unmount') await act(async () => renderer.unmount());
    }
  });

  it.each(['online', 'offline'])(
    'retains one valid owner confirmation and existing %s delivery',
    async network => {
      if (network === 'offline')
        mockClearRemote.mockRejectedValue(new Error('offline'));
      let confirm: (() => void) | undefined;
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation((_title, _message, buttons) => {
          if (buttons)
            confirm = buttons.find(
              button => button.style === 'destructive',
            )?.onPress;
        });
      let renderer!: TestRenderer.ReactTestRenderer;
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      try {
        await act(async () => preferences.confirmClearWatchHistory());
        await act(async () => {
          void confirm!();
          void confirm!();
        });
        expect(mockClearLocal).toHaveBeenCalledTimes(1);
        expect(mockClearLocal).toHaveBeenCalledWith(mockBoundary);
        expect(mockClearRemote).toHaveBeenCalledTimes(1);
        expect(mockClearRemote).toHaveBeenCalledWith(mockBoundary);
        expect(alert).toHaveBeenCalledWith('تم مسح السجل', expect.any(String));
        if (network === 'offline') {
          expect(mockSave).toHaveBeenCalledWith('user-1:pending-clear', true);
        }
      } finally {
        alert.mockRestore();
        await act(async () => renderer.unmount());
      }
    },
  );

  it('does not clear the replacement account when the old account dialog is confirmed', async () => {
    let confirm: (() => void) | undefined;
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation((_title, _message, buttons) => {
        if (buttons)
          confirm = buttons.find(
            button => button.style === 'destructive',
          )?.onPress;
      });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
    });
    try {
      await act(async () => preferences.confirmClearWatchHistory());
      expect(confirm).toBeDefined();
      mockUser = {id: 2, api_token: 'token-b'};
      mockBoundary = {epoch: 2, scope: 'user-2'};
      await act(async () => renderer.update(<Harness />));
      await act(async () => confirm!());
      expect(mockClearLocal).not.toHaveBeenCalled();
      expect(mockClearRemote).not.toHaveBeenCalled();
    } finally {
      alert.mockRestore();
      await act(async () => renderer.unmount());
    }
  });
});
