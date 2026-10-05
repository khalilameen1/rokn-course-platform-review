import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Alert} from 'react-native';
import {NavigationContext} from '@react-navigation/core';

const mockValues = new Map<string, unknown>();
const mockSave = jest.fn();
const mockRemove = jest.fn();
const mockPut = jest.fn();
const mockGet = jest.fn();
let mockBoundary = {scope: 'user-reminder', epoch: 0};

jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'USER_DATA'},
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: (owner: typeof mockBoundary) => {
    if (owner.scope !== mockBoundary.scope || owner.epoch !== mockBoundary.epoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, owner: typeof mockBoundary) =>
    `${key}:${owner.scope}`,
  extractApiToken: (value: {api_token?: string} | null) => value?.api_token ?? '',
  sessionIdentityKey: (value: {id?: number} | null) => `user-${value?.id ?? 'guest'}`,
  getItem: async (key: string) => mockValues.get(key) ?? null,
  saveItem: (...args: unknown[]) => mockSave(...args),
  removeItem: (...args: unknown[]) => mockRemove(...args),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    put: (...args: unknown[]) => mockPut(...args),
    get: (...args: unknown[]) => mockGet(...args),
  },
}));
jest.mock('../src/services/roknApi', () =>
  jest.requireActual('../src/services/api/accountProfile'));
jest.mock('@react-navigation/native', () => ({
  useFocusEffect: jest.requireActual('@react-navigation/core').useFocusEffect,
}));
jest.mock('../src/services/pushDeviceRegistration', () => ({
  registerPushDeviceIfEligible: async () => true,
  unregisterPushDevice: async () => undefined,
}));
jest.mock('../src/screens/settings/settingsData', () => ({
  PENDING_WATCH_HISTORY_CLEAR_KEY: '@rokn/pending-watch-history-clear/v1',
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () =>
  jest.requireActual('../src/components/VideoPlayer/courseLearning/persistence'));

import {
  flushPendingAccountWrites,
  flushPrivacyPreferenceWrites,
  PENDING_PRIVACY_PREFERENCES_KEY,
  PENDING_WATCH_HISTORY_CLEAR_KEY,
  queuePendingPrivacyPreferences,
  readPendingPrivacyPreferences,
} from '../src/services/pendingAccountWrites';
import {
  privacyPreferenceReadIsCurrent,
  privacyPreferenceVersions,
} from '../src/services/accountPreferenceWrites';
import {getProfile} from '../src/services/api/accountProfile';
import {REMINDER_HOUR_KEY, getSmartReminderHour} from '../src/services/smartReminders';
import {useSettingsPreferences} from '../src/screens/settings/useSettingsPreferences';

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((accept, fail) => {resolve = accept; reject = fail;});
  return {promise, resolve, reject};
};
const key = (base: string, owner = mockBoundary) => `${base}:${owner.scope}`;
const reminder = (hour: number) => ({
  learningReminderHour: hour, learningReminderTimezone: 'Asia/Kolkata',
});
const receipt = (body: Record<string, unknown>) => ({data: {data: {id: 7, ...body}}});
const settleScheduledWork = async () => {
  for (let tick = 0; tick < 60; tick += 1) await Promise.resolve();
};
const write = async (patch: Parameters<typeof queuePendingPrivacyPreferences>[0]) => {
  await queuePendingPrivacyPreferences(patch, mockBoundary);
  await flushPrivacyPreferenceWrites(mockBoundary);
};
let settings!: ReturnType<typeof useSettingsPreferences>;
const settingsUser = {id: 7, api_token: 'test-token'};
const SettingsHarness = () => {
  settings = useSettingsPreferences({hasAuthenticatedAccount: true, userData: settingsUser});
  return null;
};
const navigation = {isFocused: () => true, addListener: () => () => undefined};
const mountSettings = () => TestRenderer.create(React.createElement(
  NavigationContext.Provider,
  {value: navigation as unknown as React.ComponentProps<typeof NavigationContext.Provider>['value']},
  React.createElement(SettingsHarness),
));
const selectReminder = async (hour: number) => {
  await act(async () => {settings.openReminderChoice();});
  await act(async () => {settings.selectChoice(String(hour));});
};

// Real journal, account-native writer, reminder cache and HTTP payload adapter.
// Only native storage/session and network transport are seams. Not executed yet.
describe('account reminder preference admission and exact acknowledgement', () => {
  beforeEach(() => {
    mockBoundary = {scope: 'user-reminder', epoch: mockBoundary.epoch + 1};
    mockValues.clear();
    mockValues.set('USER_DATA', {id: 7, api_token: 'test-token'});
    mockSave.mockReset().mockImplementation(async (storageKey: string, value: unknown) => {
      mockValues.set(storageKey, value);
      return true;
    });
    mockRemove.mockReset().mockImplementation(async (storageKey: string) => {
      mockValues.delete(storageKey);
      return true;
    });
    mockPut.mockReset().mockRejectedValue(new Error('offline'));
    mockGet.mockReset();
  });

  it('retains offline intent and mirrors the selected hour through the actual reminder service', async () => {
    await write(reminder(10));
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual(reminder(10));
    expect(await getSmartReminderHour(mockBoundary)).toBe(10);
    expect(mockPut).toHaveBeenCalledWith('user/profile', {
      learning_reminder_hour: 10, learning_reminder_timezone: 'Asia/Kolkata',
    });
    expect(mockValues.get(key(PENDING_PRIVACY_PREFERENCES_KEY))).toEqual({
      revision: 1, patch: reminder(10),
    });
    expect(mockRemove).not.toHaveBeenCalled();
  });

  it('persists a second selection without waiting for a slow PUT and old ACK cannot delete it', async () => {
    const first = deferred<ReturnType<typeof receipt>>();
    const second = deferred<ReturnType<typeof receipt>>();
    mockPut.mockReturnValueOnce(first.promise).mockReturnValueOnce(second.promise);
    await queuePendingPrivacyPreferences(reminder(10), mockBoundary);
    const worker = flushPrivacyPreferenceWrites(mockBoundary);
    await settleScheduledWork();
    expect(mockPut).toHaveBeenCalledTimes(1);
    await queuePendingPrivacyPreferences(reminder(15), mockBoundary);
    expect(mockValues.get(key(PENDING_PRIVACY_PREFERENCES_KEY))).toEqual({
      revision: 2, patch: reminder(15),
    });
    expect(await getSmartReminderHour(mockBoundary)).toBe(15);
    first.resolve(receipt({learning_reminder_hour: 10, learning_reminder_timezone: 'Asia/Kolkata'}));
    await settleScheduledWork();
    expect(mockPut).toHaveBeenCalledTimes(2);
    expect(mockRemove).not.toHaveBeenCalled();
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual(reminder(15));
    second.resolve(receipt({learning_reminder_hour: 15, learning_reminder_timezone: 'Asia/Kolkata'}));
    await worker;
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
    expect(await getSmartReminderHour(mockBoundary)).toBe(15);
  });

  it('preserves plain v1 privacy intents while merging a new reminder pair', async () => {
    mockValues.set(key(PENDING_PRIVACY_PREFERENCES_KEY), {
      watchHistoryEnabled: false, marketingNotificationsEnabled: true,
    });
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({
      watchHistoryEnabled: false, marketingNotificationsEnabled: true,
    });
    await write(reminder(15));
    expect(mockValues.get(key(PENDING_PRIVACY_PREFERENCES_KEY))).toEqual({
      revision: 1, patch: {
        watchHistoryEnabled: false, marketingNotificationsEnabled: true, ...reminder(15),
      },
    });
    expect(mockValues.get(key('PREF_WATCH_HISTORY'))).toBe(false);
    expect(mockValues.get(key('PREF_MARKETING_NOTIFICATIONS'))).toBe(true);
  });

  it('replays legacy intent alone and keeps its fields when the server does not acknowledge them', async () => {
    mockValues.set(key(PENDING_PRIVACY_PREFERENCES_KEY), {watchHistoryEnabled: false});
    mockPut.mockResolvedValue(receipt({watch_history_enabled: true}));
    await flushPrivacyPreferenceWrites(mockBoundary);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({watchHistoryEnabled: false});
    mockPut.mockResolvedValue(receipt({watch_history_enabled: false}));
    await flushPrivacyPreferenceWrites(mockBoundary);
    expect(mockValues.get(key('PREF_WATCH_HISTORY'))).toBe(false);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
  });

  it('does not trust an old backend HTTP 200 that ignored the new reminder fields', async () => {
    mockPut.mockResolvedValue(receipt({watch_history_enabled: true}));
    await write(reminder(20));
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual(reminder(20));
    expect(mockRemove).not.toHaveBeenCalled();
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body));
    await flushPendingAccountWrites();
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
  });

  it('retains A to B to A as a newer command even if its value matches the old request', async () => {
    const first = deferred<ReturnType<typeof receipt>>();
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body))
      .mockReturnValueOnce(first.promise);
    await queuePendingPrivacyPreferences(reminder(10), mockBoundary);
    const worker = flushPrivacyPreferenceWrites(mockBoundary);
    await settleScheduledWork();
    await queuePendingPrivacyPreferences(reminder(15), mockBoundary);
    await queuePendingPrivacyPreferences(reminder(10), mockBoundary);
    expect(mockValues.get(key(PENDING_PRIVACY_PREFERENCES_KEY))).toEqual({revision: 3, patch: reminder(10)});
    first.resolve(receipt({learning_reminder_hour: 10, learning_reminder_timezone: 'Asia/Kolkata'}));
    await worker;
    expect(mockPut).toHaveBeenCalledTimes(2);
  });

  it('keeps its journal after an ACK when the native cache cannot be repaired', async () => {
    const normalSave = mockSave.getMockImplementation()!;
    mockSave.mockImplementation(async (storageKey: string, value: unknown) =>
      storageKey === key(REMINDER_HOUR_KEY) ? false : normalSave(storageKey, value));
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body));
    await write(reminder(15));
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual(reminder(15));
    expect(mockRemove).not.toHaveBeenCalled();
    mockSave.mockImplementation(normalSave);
    await flushPrivacyPreferenceWrites(mockBoundary);
    expect(await getSmartReminderHour(mockBoundary)).toBe(15);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
  });

  it('reports failed journal persistence without sending or mirroring a new value', async () => {
    mockValues.set(key(REMINDER_HOUR_KEY), 20);
    mockSave.mockResolvedValue(false);
    await expect(queuePendingPrivacyPreferences(reminder(10), mockBoundary))
      .rejects.toThrow('DURABLE_ACCOUNT_WRITE_UNAVAILABLE');
    expect(mockPut).not.toHaveBeenCalled();
    expect(await getSmartReminderHour(mockBoundary)).toBe(20);
  });

  it('does not spin on failed native deletion and settles on the next lifecycle retry', async () => {
    const normalRemove = mockRemove.getMockImplementation()!;
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body));
    mockRemove.mockResolvedValue(false);
    await queuePendingPrivacyPreferences(reminder(15), mockBoundary);
    await flushPrivacyPreferenceWrites(mockBoundary);
    await settleScheduledWork();
    // Joining/afterCurrent may make one follow-up probe, never an unbounded loop.
    expect(mockPut.mock.calls.length).toBeLessThanOrEqual(2);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual(reminder(15));
    mockRemove.mockImplementation(normalRemove);
    await flushPrivacyPreferenceWrites(mockBoundary);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
  });

  it('retires an old-account ACK without deleting either account newer work', async () => {
    const first = deferred<ReturnType<typeof receipt>>();
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body))
      .mockReturnValueOnce(first.promise);
    const oldOwner = mockBoundary;
    await queuePendingPrivacyPreferences(reminder(10), oldOwner);
    const oldResult = flushPrivacyPreferenceWrites(oldOwner).catch((error: Error) => error.message);
    await settleScheduledWork();
    mockBoundary = {scope: 'user-reminder-other', epoch: mockBoundary.epoch + 1};
    await write(reminder(15));
    first.resolve(receipt({learning_reminder_hour: 10, learning_reminder_timezone: 'Asia/Kolkata'}));
    expect(await oldResult).toBe('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockValues.get(key(PENDING_PRIVACY_PREFERENCES_KEY, oldOwner))).toBeDefined();
    expect(await getSmartReminderHour(mockBoundary)).toBe(15);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
  });

  it('serializes HTTP across a same-account epoch replacement while accepting the new local choice', async () => {
    const first = deferred<ReturnType<typeof receipt>>();
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body))
      .mockReturnValueOnce(first.promise);
    const oldOwner = mockBoundary;
    await queuePendingPrivacyPreferences(reminder(10), oldOwner);
    const oldResult = flushPrivacyPreferenceWrites(oldOwner).catch(() => undefined);
    await settleScheduledWork();
    mockBoundary = {...oldOwner, epoch: oldOwner.epoch + 1};
    await queuePendingPrivacyPreferences(reminder(15), mockBoundary);
    const nextWorker = flushPrivacyPreferenceWrites(mockBoundary);
    expect(mockPut).toHaveBeenCalledTimes(1);
    expect(await getSmartReminderHour(mockBoundary)).toBe(15);
    first.resolve(receipt({learning_reminder_hour: 10, learning_reminder_timezone: 'Asia/Kolkata'}));
    await oldResult;
    await nextWorker;
    expect(mockPut).toHaveBeenCalledTimes(2);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
  });

  it('invalidates a profile read even after the newer choice has already been acknowledged', async () => {
    const oldGet = deferred<ReturnType<typeof receipt>>();
    const versions = privacyPreferenceVersions(mockBoundary);
    mockGet.mockReturnValue(oldGet.promise);
    const profileRead = getProfile(mockBoundary);
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body));
    await write(reminder(15));
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
    oldGet.resolve(receipt({learning_reminder_hour: 20, learning_reminder_timezone: 'Africa/Cairo'}));
    expect((await profileRead).learningReminderHour).toBe(20);
    expect(privacyPreferenceReadIsCurrent(mockBoundary, versions, 'reminder')).toBe(false);
    expect(await getSmartReminderHour(mockBoundary)).toBe(15);
  });

  it('rejects a half-pair without damaging a pending command', async () => {
    await write(reminder(10));
    await expect(queuePendingPrivacyPreferences({learningReminderHour: 15}, mockBoundary))
      .rejects.toThrow('ACCOUNT_PREFERENCE_INVALID');
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual(reminder(10));
  });

  it('leaves the separately owned watch-history-clear record intact during reminder ACK', async () => {
    mockValues.set(key(PENDING_WATCH_HISTORY_CLEAR_KEY), true);
    mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body));
    await write(reminder(15));
    expect(mockValues.get(key(PENDING_WATCH_HISTORY_CLEAR_KEY))).toBe(true);
    expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
  });

  describe('real settings hook and privacy journal binding', () => {
    let renderer: TestRenderer.ReactTestRenderer | undefined;
    beforeEach(() => {
      jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
      mockGet.mockResolvedValue(receipt({learning_reminder_hour: 20, learning_reminder_timezone: 'Africa/Cairo'}));
    });
    afterEach(async () => {
      await act(async () => {renderer?.unmount();});
      renderer = undefined;
      jest.restoreAllMocks();
    });

    it('accepts both modal choices while the first HTTP write remains unresolved', async () => {
      const first = deferred<ReturnType<typeof receipt>>();
      mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body))
        .mockReturnValueOnce(first.promise);
      await act(async () => {renderer = mountSettings();});
      await selectReminder(10);
      await act(settleScheduledWork);
      expect(mockPut).toHaveBeenCalledTimes(1);
      await selectReminder(15);
      expect(settings.reminderHour).toBe(15);
      expect((await readPendingPrivacyPreferences(undefined, mockBoundary)).learningReminderHour).toBe(15);
      expect(await getSmartReminderHour(mockBoundary)).toBe(15);
      const worker = flushPrivacyPreferenceWrites(mockBoundary);
      await act(async () => {
        first.resolve(receipt({learning_reminder_hour: 10,
          learning_reminder_timezone: mockPut.mock.calls[0][1].learning_reminder_timezone}));
        await worker;
      });
      expect(mockPut).toHaveBeenCalledTimes(2);
      expect(settings.reminderHour).toBe(15);
      expect(Alert.alert).not.toHaveBeenCalled();
    });

    it('does not apply a GET started before an edit from another visit even after that edit ACKs', async () => {
      const oldGet = deferred<ReturnType<typeof receipt>>();
      mockGet.mockReturnValue(oldGet.promise);
      await act(async () => {renderer = mountSettings();});
      expect(mockGet).toHaveBeenCalledTimes(1);
      mockPut.mockImplementation(async (_path: string, body: Record<string, unknown>) => receipt(body));
      await act(async () => {await write(reminder(15));});
      expect(await readPendingPrivacyPreferences(undefined, mockBoundary)).toEqual({});
      await act(async () => {oldGet.resolve(receipt({learning_reminder_hour: 20}));});
      expect(await getSmartReminderHour(mockBoundary)).toBe(15);
      // This retained view has not received a new local selection. The stale
      // GET must not replace durable truth; a new visit reads it on hydration.
      await act(async () => {renderer?.unmount();});
      mockGet.mockRejectedValue(new Error('offline'));
      await act(async () => {renderer = mountSettings();});
      expect(settings.reminderHour).toBe(15);
    });

    it('hydrates the newer durable value when a previous visit native write overlaps its initial reads', async () => {
      const save = deferred<boolean>();
      const normalSave = mockSave.getMockImplementation()!;
      mockSave.mockImplementationOnce(async (storageKey: string, value: unknown) => {
        await save.promise;
        return normalSave(storageKey, value);
      });
      const previousVisitWrite = queuePendingPrivacyPreferences(reminder(15), mockBoundary);
      await settleScheduledWork();
      mockGet.mockRejectedValue(new Error('offline'));
      await act(async () => {renderer = mountSettings();});
      await act(async () => {save.resolve(true); await previousVisitWrite;});
      await act(settleScheduledWork);
      expect(settings.reminderHour).toBe(15);
      expect(await getSmartReminderHour(mockBoundary)).toBe(15);
    });

    it('rolls a failed later choice back to the last durable accepted hour', async () => {
      await act(async () => {renderer = mountSettings();});
      await selectReminder(10);
      const normalSave = mockSave.getMockImplementation()!;
      mockSave.mockImplementation(async (storageKey: string, value: unknown) =>
        storageKey === key(PENDING_PRIVACY_PREFERENCES_KEY) ? false : normalSave(storageKey, value));
      await selectReminder(15);
      await act(settleScheduledWork);
      expect(settings.reminderHour).toBe(10);
      expect((await readPendingPrivacyPreferences(undefined, mockBoundary)).learningReminderHour).toBe(10);
      expect(Alert.alert).toHaveBeenCalledWith('لم يُحفظ التغيير', 'تعذّر حفظ وقت التذكير\nحاول مرة أخرى');
    });
  });
});
