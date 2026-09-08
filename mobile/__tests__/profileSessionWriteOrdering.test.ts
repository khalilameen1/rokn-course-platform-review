import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../src/constants/helpers';
import {
  deleteSecureSession,
  loadSecureSession,
  peekSecureSession,
  resetSecureSessionForTests,
  saveSecureSession,
  updateSecureSessionForOwner,
} from '../src/services/secureSession';
import {settleWithin} from '../src/utils/settleWithin';

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
const writeProfile = (
  AsyncStorage.setItem as jest.Mock
).getMockImplementation()!;
const initialSession = {
  api_token: 'profile-ordering-token-7',
  user: {id: 7, name: 'Original'},
};
const renamed = (current: unknown, name: string) => {
  const session = current as typeof initialSession;
  return {...session, user: {...session.user, name}};
};
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('profile caller deadlines preserve the real secure-session queue', () => {
  beforeEach(async () => {
    jest.restoreAllMocks();
    jest.clearAllMocks();
    resetSecureSessionForTests();
    secureValues.clear();
    (AsyncStorage.setItem as jest.Mock).mockImplementation(writeProfile);
    await AsyncStorage.clear();
    secureIsAvailable.mockResolvedValue(true);
    secureGet.mockImplementation(async key => secureValues.get(key) ?? null);
    secureSet.mockImplementation(async (key, value) => {
      secureValues.set(key, value);
    });
    secureDelete.mockImplementation(async key => {
      secureValues.delete(key);
    });
    await saveSecureSession(initialSession);
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
    (AsyncStorage.setItem as jest.Mock).mockImplementation(writeProfile);
    resetSecureSessionForTests();
  });

  it.each(['profile', 'binding'] as const)(
    'keeps a newer profile behind the pending native %s write after the caller returns',
    async phase => {
      const gate = deferred();
      const started = deferred();
      if (phase === 'profile') {
        jest
          .spyOn(AsyncStorage, 'setItem')
          .mockImplementation(async (key, value) => {
            if (
              key === 'USER_DATA' &&
              JSON.parse(value).user.name === 'Accepted'
            ) {
              started.resolve();
              await gate.promise;
            }
            await writeProfile(key, value);
          });
      } else {
        secureSet.mockImplementationOnce(async (key, value) => {
          expect(key).toBe('rokn.auth.session-binding.v1');
          started.resolve();
          await gate.promise;
          secureValues.set(key, value);
        });
      }
      const first = updateSecureSessionForOwner('7', current =>
        renamed(current, 'Accepted'),
      );
      await started.promise;
      const caller = settleWithin(
        first.then(() => true),
        false,
      );
      await jest.advanceTimersByTimeAsync(750);
      expect(await caller).toBe(false);

      const nextUpdate = jest.fn((current: unknown) =>
        renamed(current, 'Newer'),
      );
      const next = updateSecureSessionForOwner('7', nextUpdate);
      await jest.advanceTimersByTimeAsync(1);
      expect(nextUpdate).not.toHaveBeenCalled();
      expect(peekSecureSession().session).toEqual(initialSession);

      gate.resolve();
      await first;
      await next;
      expect(nextUpdate).toHaveBeenCalledWith(
        renamed(initialSession, 'Accepted'),
      );
      expect(JSON.parse((await AsyncStorage.getItem('USER_DATA'))!)).toEqual({
        user: {id: 7, name: 'Newer'},
      });
      resetSecureSessionForTests();
      await expect(loadSecureSession()).resolves.toEqual(
        renamed(initialSession, 'Newer'),
      );
    },
  );

  it.each([7, 8])(
    'waits for raw persistence before login as %s and rejects the old boundary at queued execution',
    async nextOwner => {
      const originalBoundary = await captureAccountSessionBoundary();
      const gate = deferred();
      const started = deferred();
      jest
        .spyOn(AsyncStorage, 'setItem')
        .mockImplementation(async (key, value) => {
          if (
            key === 'USER_DATA' &&
            JSON.parse(value).user.name === 'Accepted'
          ) {
            started.resolve();
            await gate.promise;
          }
          await writeProfile(key, value);
        });
      const first = updateSecureSessionForOwner('7', current =>
        renamed(current, 'Accepted'),
      );
      await started.promise;
      const caller = settleWithin(
        first.then(() => true),
        false,
      );
      await jest.advanceTimersByTimeAsync(750);
      expect(await caller).toBe(false);

      secureSet.mockClear();
      secureDelete.mockClear();
      const logout = deleteSecureSession();
      const newSession = {
        api_token: 'profile-ordering-replacement-token',
        user: {id: nextOwner, name: 'New login profile'},
      };
      const login = saveSecureSession(newSession);
      const applyOldProfile = jest.fn((current: unknown) =>
        renamed(current, 'Too late'),
      );
      const oldUpdate = jest.fn((current: unknown) => {
        // The caller must check its original visit inside the serialized update:
        // an account id alone also matches a replacement login for the same user.
        assertAccountSessionBoundary(originalBoundary);
        return applyOldProfile(current);
      });
      const stale = updateSecureSessionForOwner('7', oldUpdate).catch(
        error => error,
      );
      await jest.advanceTimersByTimeAsync(1);
      expect(secureDelete).not.toHaveBeenCalled();
      expect(secureSet).not.toHaveBeenCalled();
      expect(oldUpdate).not.toHaveBeenCalled();

      gate.resolve();
      await first;
      await expect(logout).resolves.toBe(true);
      await login;
      expect(await stale).toEqual(
        new Error(
          nextOwner === 7
            ? 'ACCOUNT_CHANGED_DURING_REQUEST'
            : 'ACCOUNT_CHANGED_DURING_SESSION_UPDATE',
        ),
      );
      expect(oldUpdate).toHaveBeenCalledTimes(nextOwner === 7 ? 1 : 0);
      expect(applyOldProfile).not.toHaveBeenCalled();
      const currentBoundary = await captureAccountSessionBoundary();
      expect(currentBoundary.epoch).toBeGreaterThan(originalBoundary.epoch);
      if (nextOwner === 7)
        expect(currentBoundary.scope).toBe(originalBoundary.scope);
      expect(peekSecureSession().session).toEqual(newSession);
      resetSecureSessionForTests();
      await expect(loadSecureSession()).resolves.toEqual(newSession);
    },
  );
});
