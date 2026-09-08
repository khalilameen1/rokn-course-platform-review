import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import {NativeModules, Platform} from 'react-native';
import {
  assertSecureSessionStorageAvailable,
  deleteSecureSession,
  deleteSecureSessionIfToken,
  clearSecureSessionStorage,
  loadSecureSession,
  peekSecureSession,
  resetSecureSessionForTests,
  restoreSecureAuthState,
  savePendingSocialAuthAttempt,
  sanitizeSessionForStorage,
  saveSecureSession,
  loadPendingSocialAuthAttempt,
  replacePendingSocialAuthAttempt,
  deletePendingSocialAuthAttempt,
  secureStoreOptionsForPlatform,
  sessionIdentityKey,
} from '../src/services/secureSession';

jest.mock('../src/services/smartReminders', () => ({
  cancelLearningReminders: jest.fn(),
  setSmartRemindersEnabled: jest.fn(async () => undefined),
}));
jest.mock('../src/services/pushNotifications', () => ({
  getCurrentPushDeviceToken: jest.fn(async () => null),
  clearCurrentPushDeviceRegistration: jest.fn(async () => undefined),
}));
jest.mock('../src/services/deviceSessions', () => ({
  revokeCurrentDeviceSession: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  clearCurrentAccountLearningFiles: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/fileCache', () => ({
  clearTransientChatCache: jest.fn(async () => undefined),
}));

const secureValues = new Map<string, string>();
const secureGet = SecureStore.getItemAsync as jest.MockedFunction<
  typeof SecureStore.getItemAsync
>;
const secureSet = SecureStore.setItemAsync as jest.MockedFunction<
  typeof SecureStore.setItemAsync
>;
const secureDelete = SecureStore.deleteItemAsync as jest.MockedFunction<
  typeof SecureStore.deleteItemAsync
>;
const secureIsAvailable = SecureStore.isAvailableAsync as jest.MockedFunction<
  typeof SecureStore.isAvailableAsync
>;

describe('secure mobile session persistence', () => {
  beforeEach(async () => {
    jest.restoreAllMocks();
    jest.clearAllMocks();
    secureValues.clear();
    resetSecureSessionForTests();
    await AsyncStorage.clear();
    secureGet.mockImplementation(async key => secureValues.get(key) ?? null);
    secureIsAvailable.mockResolvedValue(true);
    secureSet.mockImplementation(async (key, value) => {
      secureValues.set(key, value);
    });
    secureDelete.mockImplementation(async key => {
      secureValues.delete(key);
    });
  });

  it('passes iOS keychain accessibility only to the iOS native module', () => {
    expect(secureStoreOptionsForPlatform('android')).toEqual({});
    expect(secureStoreOptionsForPlatform('ios')).toEqual({
      keychainAccessible: SecureStore.WHEN_UNLOCKED_THIS_DEVICE_ONLY,
    });
  });

  it('derives non-secret distinct owners when authenticated profiles have no id', () => {
    const first = sessionIdentityKey({api_token: 'first-private-bearer'});
    const second = sessionIdentityKey({api_token: 'second-private-bearer'});

    expect(first).toMatch(/^session-[0-9a-f]{32}$/u);
    expect(second).toMatch(/^session-[0-9a-f]{32}$/u);
    expect(first).not.toBe(second);
    expect(first).not.toContain('first-private-bearer');
    expect(sessionIdentityKey(null)).toBe('guest');
  });

  it('keeps the same account owner across bearer replacement when it has an id', () => {
    const first = sessionIdentityKey({
      api_token: 'old-token',
      user: {id: 41},
    });
    const replacement = sessionIdentityKey({
      api_token: 'new-token',
      user: {id: 41},
    });

    expect(first).toBe(replacement);
    expect(first).toMatch(/^account-[0-9a-f]{32}$/u);
  });

  it('proves secure storage can round-trip before opening a social provider', async () => {
    await expect(
      assertSecureSessionStorageAvailable(),
    ).resolves.toBeUndefined();
    expect(secureSet).toHaveBeenCalledWith(
      'rokn.auth.storage-probe.v1',
      expect.stringMatching(/^rokn-\d+$/),
      expect.any(Object),
    );
    expect(secureValues.has('rokn.auth.storage-probe.v1')).toBe(false);
  });

  it('does not let an old social result replace or delete a newer attempt', async () => {
    const oldAttempt = {
      provider: 'google' as const,
      verifier: 'A'.repeat(64),
      challenge: 'B'.repeat(43),
      flow: 'browser' as const,
      startedAt: '2026-09-02T08:00:00.000Z',
      purpose: 'login' as const,
    };
    const newerAttempt = {
      ...oldAttempt,
      verifier: 'C'.repeat(64),
      challenge: 'D'.repeat(43),
      startedAt: '2026-09-02T08:01:00.000Z',
    };
    await savePendingSocialAuthAttempt(oldAttempt);
    await savePendingSocialAuthAttempt(newerAttempt);

    await expect(
      replacePendingSocialAuthAttempt(oldAttempt, {
        ...oldAttempt,
        callbackUrl: 'rokn://auth?code=old',
      }),
    ).resolves.toBe(false);
    await expect(deletePendingSocialAuthAttempt(oldAttempt)).resolves.toBe(
      false,
    );
    await expect(loadPendingSocialAuthAttempt()).resolves.toEqual(newerAttempt);
  });

  it('uses the Rokn Android Keystore bridge on Android releases', async () => {
    const originalPlatform = Platform.OS;
    const androidValues = new Map<string, string>();
    const nativeModule = {
      setItem: jest.fn(async (key: string, value: string) => {
        androidValues.set(key, value);
      }),
      getItem: jest.fn(async (key: string) => androidValues.get(key) ?? null),
      deleteItem: jest.fn(async (key: string) => {
        androidValues.delete(key);
      }),
    };
    Object.defineProperty(Platform, 'OS', {
      value: 'android',
      configurable: true,
    });
    NativeModules.RoknSecureSession = nativeModule;
    resetSecureSessionForTests();

    try {
      await expect(
        assertSecureSessionStorageAvailable(),
      ).resolves.toBeUndefined();
      expect(nativeModule.setItem).toHaveBeenCalledWith(
        'rokn.auth.storage-probe.v1',
        expect.stringMatching(/^rokn-\d+$/),
      );
      expect(nativeModule.deleteItem).toHaveBeenCalledWith(
        'rokn.auth.storage-probe.v1',
      );
      expect(secureSet).not.toHaveBeenCalled();
    } finally {
      delete NativeModules.RoknSecureSession;
      Object.defineProperty(Platform, 'OS', {
        value: originalPlatform,
        configurable: true,
      });
      resetSecureSessionForTests();
    }
  });

  it('fails before OAuth when the native secure store cannot persist', async () => {
    secureSet.mockRejectedValueOnce(new Error('native write failed'));
    await expect(assertSecureSessionStorageAvailable()).rejects.toThrow(
      'SESSION_STORAGE_UNAVAILABLE',
    );
  });

  it('stores only the API token securely and keeps a sanitized profile', async () => {
    const session = {
      api_token: 'api-secret',
      access_token: 'social-secret',
      oauthToken: 'oauth-secret',
      jwt: 'signed-jwt',
      user: {
        id: 7,
        name: 'Student',
        password: 'never-store-this',
        provider_token: 'nested-provider-secret',
        clientSecret: 'nested-client-secret',
        token_balance: 120,
      },
    };

    await saveSecureSession(session);

    expect(secureValues.get('rokn.auth.api-token.v2')).toBe('api-secret');
    expect(await AsyncStorage.getItem('USER_DATA')).toBe(
      JSON.stringify({user: {id: 7, name: 'Student', token_balance: 120}}),
    );
    expect(sanitizeSessionForStorage(session)).toEqual({
      user: {id: 7, name: 'Student', token_balance: 120},
    });
  });

  it('removes the secure session on logout', async () => {
    await saveSecureSession({api_token: 'api-secret', user: {id: 7}});

    await expect(deleteSecureSession()).resolves.toBe(true);

    expect(secureValues.has('rokn.auth.api-token.v2')).toBe(false);
    expect(await AsyncStorage.getItem('USER_DATA')).toBeNull();
  });

  it.each([
    'rokn.auth.api-token.v2',
    'rokn.auth.session-binding.v1',
    'rokn.auth.pending-social.v1',
    'USER_DATA',
  ])('keeps logout retryable after partial deletion of %s fails', async key => {
    const session = {api_token: 'retry-token', user: {id: 7}};
    await saveSecureSession(session);
    secureValues.set('rokn.auth.pending-social.v1', 'completed-auth-journal');
    const originalEpoch = peekSecureSession().epoch;
    if (key === 'USER_DATA') {
      jest
        .spyOn(AsyncStorage, 'removeItem')
        .mockRejectedValueOnce(new Error('profile removal failed'));
    } else {
      secureDelete.mockImplementation(async deletingKey => {
        if (deletingKey === key) throw new Error('native deletion failed');
        secureValues.delete(deletingKey);
      });
    }

    await expect(deleteSecureSessionIfToken('retry-token')).rejects.toThrow(
      'SESSION_STORAGE_UNAVAILABLE_DELETE',
    );
    expect(await loadSecureSession()).toEqual(session);
    expect(peekSecureSession().epoch).toBeGreaterThan(originalEpoch);
    expect(
      key === 'USER_DATA'
        ? await AsyncStorage.getItem(key)
        : secureValues.get(key),
    ).toBeTruthy();
    // Partial removal is not proof that the account can be restored on cold
    // start, but it is not a completed local logout either.
    secureDelete.mockImplementation(async deletingKey => {
      secureValues.delete(deletingKey);
    });
    await expect(deleteSecureSessionIfToken('retry-token')).resolves.toBe(true);
    expect(await loadSecureSession()).toBeNull();
    expect(secureValues.size).toBe(0);
    expect(await AsyncStorage.getItem('USER_DATA')).toBeNull();
  });

  it('preserves the direct deletion boolean and permits retry after failure', async () => {
    await saveSecureSession({api_token: 'direct-token', user: {id: 7}});
    secureDelete.mockRejectedValueOnce(new Error('native deletion failed'));

    await expect(deleteSecureSession()).resolves.toBe(false);
    expect(peekSecureSession().session).toMatchObject({
      api_token: 'direct-token',
    });
    await expect(deleteSecureSession()).resolves.toBe(true);
    expect(peekSecureSession().session).toBeNull();
  });

  it('attempts all deletion keys even when a native bridge throws synchronously', async () => {
    await saveSecureSession({api_token: 'old-token', user: {id: 7}});
    secureValues.set('rokn.auth.pending-social.v1', 'journal');
    secureDelete.mockImplementation(key => {
      if (key === 'rokn.auth.api-token.v2')
        throw new Error('bridge unavailable');
      secureValues.delete(key);
      return Promise.resolve();
    });

    await expect(deleteSecureSessionIfToken('old-token')).rejects.toThrow(
      'SESSION_STORAGE_UNAVAILABLE_DELETE',
    );
    expect(secureValues.has('rokn.auth.session-binding.v1')).toBe(false);
    expect(secureValues.has('rokn.auth.pending-social.v1')).toBe(false);
    expect(await AsyncStorage.getItem('USER_DATA')).toBeNull();
  });

  it('does not let an older native restore resurrect a successfully deleted session', async () => {
    await saveSecureSession({api_token: 'old-token', user: {id: 7}});
    resetSecureSessionForTests();
    let releaseRead!: () => void;
    const readGate = new Promise<void>(resolve => {
      releaseRead = resolve;
    });
    let readStarted!: () => void;
    const started = new Promise<void>(resolve => {
      readStarted = resolve;
    });
    secureGet.mockImplementation(async key => {
      const captured = secureValues.get(key) ?? null;
      if (key === 'rokn.auth.api-token.v2') {
        readStarted();
        await readGate;
      }
      return captured;
    });
    const oldRestore = loadSecureSession();
    await started;
    await expect(deleteSecureSession()).resolves.toBe(true);
    releaseRead();

    await expect(oldRestore).resolves.toBeNull();
    expect(peekSecureSession().session).toBeNull();
  });

  it.each([7, 8])(
    'waits for a delayed native sibling before account %s can replace the session',
    async nextOwner => {
      await saveSecureSession({api_token: 'old-token', user: {id: 7}});
      let releaseBinding!: () => void;
      const bindingGate = new Promise<void>(resolve => {
        releaseBinding = resolve;
      });
      let bindingStarted!: () => void;
      const started = new Promise<void>(resolve => {
        bindingStarted = resolve;
      });
      secureDelete.mockImplementation(async key => {
        if (key === 'rokn.auth.api-token.v2')
          throw new Error('token delete failed');
        if (key === 'rokn.auth.session-binding.v1') {
          bindingStarted();
          await bindingGate;
        }
        secureValues.delete(key);
      });
      const logout = deleteSecureSessionIfToken('old-token').catch(
        error => error,
      );
      await started;
      secureSet.mockClear();
      const replacement = saveSecureSession({
        api_token: 'new-token',
        user: {id: nextOwner},
      });
      await Promise.resolve();
      await Promise.resolve();
      expect(secureSet).not.toHaveBeenCalled();

      releaseBinding();
      expect(await logout).toEqual(
        new Error('SESSION_STORAGE_UNAVAILABLE_DELETE'),
      );
      await replacement;
      const deletes = secureDelete.mock.calls.length;
      await expect(deleteSecureSessionIfToken('old-token')).resolves.toBe(
        false,
      );
      expect(secureDelete).toHaveBeenCalledTimes(deletes);
      resetSecureSessionForTests();
      await expect(loadSecureSession()).resolves.toEqual({
        api_token: 'new-token',
        user: {id: nextOwner},
      });
    },
  );

  it('keeps a failed full clear queued until pending journal deletion settles', async () => {
    await saveSecureSession({api_token: 'old-token', user: {id: 7}});
    await AsyncStorage.setItem('unrelated-preference', 'keep');
    let releaseJournal!: () => void;
    const journalGate = new Promise<void>(resolve => {
      releaseJournal = resolve;
    });
    let journalStarted!: () => void;
    const started = new Promise<void>(resolve => {
      journalStarted = resolve;
    });
    secureDelete.mockImplementation(async key => {
      if (key === 'rokn.auth.api-token.v2')
        throw new Error('token delete failed');
      if (key === 'rokn.auth.pending-social.v1') {
        journalStarted();
        await journalGate;
      }
      secureValues.delete(key);
    });
    const clearing = clearSecureSessionStorage().catch(error => error);
    await started;
    secureSet.mockClear();
    const replacement = saveSecureSession({
      api_token: 'new-token',
      user: {id: 7},
    });
    await Promise.resolve();
    await Promise.resolve();
    expect(secureSet).not.toHaveBeenCalled();

    releaseJournal();
    expect(await clearing).toEqual(
      new Error('SESSION_STORAGE_UNAVAILABLE_DELETE'),
    );
    await replacement;
    expect(await AsyncStorage.getItem('unrelated-preference')).toBe('keep');
    expect(peekSecureSession().session).toMatchObject({api_token: 'new-token'});
  });

  it('hydrates the keychain once and serves later request interceptors from memory', async () => {
    await saveSecureSession({api_token: 'cached-token', user: {id: 15}});
    resetSecureSessionForTests();
    secureGet.mockClear();

    const first = await restoreSecureAuthState();
    const readsAfterHydration = secureGet.mock.calls.length;
    const second = await restoreSecureAuthState();

    expect(first.session).toEqual(second.session);
    expect(readsAfterHydration).toBeGreaterThan(0);
    expect(secureGet).toHaveBeenCalledTimes(readsAfterHydration);
  });

  it('rejects a bearer and profile mixed by process death during account replacement', async () => {
    await saveSecureSession({api_token: 'account-a-token', user: {id: 71}});
    // Account replacement writes the new secure bearer before the public
    // profile, then commits their binding last. Reproduce a process death in
    // that gap: the previous profile must never inherit the new account token.
    secureValues.set('rokn.auth.api-token.v2', 'account-b-token');
    resetSecureSessionForTests();

    await expect(restoreSecureAuthState()).resolves.toEqual({
      session: null,
      isAuthenticated: false,
    });
    expect(await AsyncStorage.getItem('USER_DATA')).toBeNull();
    expect(secureValues.has('rokn.auth.api-token.v2')).toBe(false);
    expect(secureValues.has('rokn.auth.session-binding.v1')).toBe(false);
  });

  it('lets public journeys inspect bootstrap without starting a keychain read', async () => {
    expect(peekSecureSession()).toEqual({
      ready: false,
      session: null,
      epoch: 0,
    });
    expect(secureGet).not.toHaveBeenCalled();

    await saveSecureSession({api_token: 'ready-token', user: {id: 21}});

    expect(peekSecureSession()).toEqual({
      ready: true,
      session: expect.objectContaining({api_token: 'ready-token'}),
      epoch: 1,
    });
  });
});
