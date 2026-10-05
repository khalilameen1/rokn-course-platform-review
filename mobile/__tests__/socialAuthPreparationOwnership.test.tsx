import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import AsyncStorage from '@react-native-async-storage/async-storage';
import {Alert} from 'react-native';
import type {SocialAuthOptions} from '../src/services/socialAuthContract';

const mockDispatch = jest.fn();
const mockPreflight = jest.fn();
const mockSignIn = jest.fn();
const mockGuestScope = jest.fn();
const mockLoadAttempt = jest.fn();
const mockDeleteAttempt = jest.fn();
let mockSession: {api_token: string} | null = null;
const mockListeners = new Map<string, Set<() => void>>();
const mockEmit = (event: string) => {
  for (const listener of Array.from(mockListeners.get(event) ?? [])) listener();
};
const mockNavigation = {
  addListener: (event: string, listener: () => void) => {
    const listeners = mockListeners.get(event) ?? new Set();
    mockListeners.set(event, listeners);
    listeners.add(listener);
    return () => listeners.delete(listener);
  },
  navigate: jest.fn(() => mockEmit('blur')),
  reset: jest.fn(),
};
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => mockNavigation,
  useRoute: () => ({
    params: {returnTo: {name: 'CourseDetails', params: {courseId: '3'}}},
  }),
}));
jest.mock('react-redux', () => ({
  useDispatch: () => mockDispatch,
  useSelector: () => null,
}));
jest.mock('../src/constants/helpers', () => ({
  extractApiToken: (session: {api_token?: string} | null) =>
    session?.api_token ?? '',
  getCurrentAccountStorageScope: async () => 'guest-1',
  getCurrentGuestJourneyScope: () => mockGuestScope(),
}));
jest.mock('../src/store/reducers/auth', () => ({
  LogOut: () => ({type: 'logout'}),
  saveLoginData: (session: unknown) => ({type: 'login', payload: session}),
}));
jest.mock('../src/services/socialAuth', () => ({
  getSocialAuthMethods: async () => ({providers: ['google']}),
  signInWithSocialProvider: (...args: unknown[]) => mockSignIn(...args),
}));
jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: () => ({session: mockSession}),
  assertSecureSessionStorageAvailable: () => mockPreflight(),
  loadPendingSocialAuthAttempt: () => mockLoadAttempt(),
  deletePendingSocialAuthAttempt: (...args: unknown[]) =>
    mockDeleteAttempt(...args),
}));
jest.mock('../src/services/guestAccountMigration', () => ({
  stageGuestAccountMigration: async () => undefined,
  resumeCompleteGuestAccountMigration: async () => undefined,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('../src/components/auth/SocialAuthView', () => () => null);

import SocialAuthShell from '../src/components/auth/SocialAuthShell';
import SocialAuthView from '../src/components/auth/SocialAuthView';
import {
  claimPendingLoginReturnTo,
  savePendingLoginReturnTo,
} from '../src/navigation/authReturn';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((yes, no) => {
    resolve = yes;
    reject = no;
  });
  return {promise, resolve, reject};
};
const flush = async () => {
  for (let index = 0; index < 30; index += 1) await Promise.resolve();
};

describe('login preparation owns its presentation only until native open', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  let alert: jest.SpyInstance;
  const view = () => renderer!.root.findByType(SocialAuthView).props;
  const mount = async () =>
    act(async () => {
      renderer = TestRenderer.create(<SocialAuthShell />);
    });
  const start = async () =>
    act(async () => {
      view().onContinue('google');
      await flush();
    });

  beforeEach(async () => {
    jest.clearAllMocks();
    mockListeners.clear();
    mockSession = null;
    mockPreflight.mockResolvedValue(undefined);
    mockSignIn.mockReset();
    mockGuestScope.mockReset().mockResolvedValue('journey-1');
    mockLoadAttempt.mockReset().mockResolvedValue(null);
    mockDeleteAttempt.mockReset().mockResolvedValue(true);
    await AsyncStorage.clear();
    alert = jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });
  afterEach(() => {
    act(() => renderer?.unmount());
    renderer = undefined;
    alert.mockRestore();
  });

  it.each(['beforeRemove', 'blur', 'unmount'])(
    'does not launch after leaving during secure preflight via %s',
    async departure => {
      const preflight = deferred<void>();
      mockPreflight.mockReturnValueOnce(preflight.promise);
      await mount();
      await start();
      act(() =>
        departure === 'unmount' ? renderer!.unmount() : mockEmit(departure),
      );
      if (departure === 'unmount') renderer = undefined;
      await act(async () => {
        preflight.resolve(undefined);
        await flush();
      });
      expect(mockSignIn).not.toHaveBeenCalled();
      expect(await claimPendingLoginReturnTo()).toBeUndefined();
      expect(mockDispatch).not.toHaveBeenCalled();
      expect(alert).not.toHaveBeenCalled();
    },
  );

  it('retires preparation when opening terms and permits a new attempt after returning', async () => {
    const preflight = deferred<void>();
    mockPreflight.mockReturnValueOnce(preflight.promise);
    mockSignIn.mockRejectedValue(new Error('LOGIN_CANCELLED'));
    await mount();
    await start();
    act(() => view().onOpenTerms());
    await act(async () => {
      preflight.resolve(undefined);
      await flush();
      mockEmit('focus');
    });
    expect(mockSignIn).not.toHaveBeenCalled();
    expect(view().loading).toBeNull();
    await start();
    expect(mockSignIn).toHaveBeenCalledTimes(1);
  });

  it('a raw return write finishing after departure cannot erase a newer journey', async () => {
    const write = deferred<void>();
    const entered = deferred<void>();
    const originalSet = (
      AsyncStorage.setItem as jest.Mock
    ).getMockImplementation()!;
    (AsyncStorage.setItem as jest.Mock).mockImplementationOnce(
      async (key, raw) => {
        entered.resolve(undefined);
        await write.promise;
        return originalSet(key, raw);
      },
    );
    try {
      await mount();
      await start();
      await entered.promise;
      act(() => mockEmit('blur'));
      const newer = savePendingLoginReturnTo({name: 'Wallet'});
      await act(async () => {
        write.resolve(undefined);
        await newer;
        await flush();
      });
      expect(mockSignIn).not.toHaveBeenCalled();
      expect((await claimPendingLoginReturnTo())?.returnTo).toEqual({
        name: 'Wallet',
      });
    } finally {
      write.resolve(undefined);
      (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSet);
    }
  });

  it('does not expose a retired raw envelope while its writer cleanup is pending', async () => {
    const write = deferred<void>();
    const writeEntered = deferred<void>();
    const cleanup = deferred<void>();
    const cleanupEntered = deferred<void>();
    const originalSet = (
      AsyncStorage.setItem as jest.Mock
    ).getMockImplementation()!;
    const originalRemove = (
      AsyncStorage.removeItem as jest.Mock
    ).getMockImplementation()!;
    (AsyncStorage.setItem as jest.Mock).mockImplementationOnce(
      async (key, raw) => {
        await originalSet(key, raw);
        writeEntered.resolve(undefined);
        await write.promise;
      },
    );
    (AsyncStorage.removeItem as jest.Mock).mockImplementationOnce(async key => {
      cleanupEntered.resolve(undefined);
      await cleanup.promise;
      return originalRemove(key);
    });
    try {
      await mount();
      await start();
      await writeEntered.promise;
      act(() => mockEmit('blur'));
      let delivered = false;
      const claim = claimPendingLoginReturnTo().then(value => {
        delivered = true;
        return value;
      });
      await act(async () => {
        write.resolve(undefined);
        await cleanupEntered.promise;
        await flush();
      });
      expect(delivered).toBe(false);
      await act(async () => {
        cleanup.resolve(undefined);
        await flush();
      });
      expect(await claim).toBeUndefined();
      expect(mockSignIn).not.toHaveBeenCalled();
    } finally {
      write.resolve(undefined);
      cleanup.resolve(undefined);
      (AsyncStorage.setItem as jest.Mock).mockImplementation(originalSet);
      (AsyncStorage.removeItem as jest.Mock).mockImplementation(originalRemove);
    }
  });

  it.each([
    ['failed', 'blur'],
    ['failed', 'beforeRemove'],
    ['resuming', 'blur'],
    ['resuming', 'beforeRemove'],
    ['committed', 'blur'],
    ['committed', 'beforeRemove'],
  ])(
    'settles late optional return from a %s provider flight after %s',
    async (outcome, departure) => {
      jest.useFakeTimers();
      const scope = deferred<string>();
      mockGuestScope.mockReturnValueOnce(scope.promise);
      mockSignIn.mockImplementation(
        async (_provider, _methods, options: SocialAuthOptions) => {
          options.onProviderStarted?.();
          if (outcome === 'failed')
            throw new Error('LOGIN_BROWSER_UNAVAILABLE');
          if (outcome === 'resuming') throw new Error('LOGIN_RESUMING');
          mockSession = {api_token: 'committed-session'};
          return mockSession;
        },
      );
      try {
        await mount();
        await start();
        await act(async () => {
          await jest.advanceTimersByTimeAsync(601);
          await flush();
        });
        expect(mockSignIn).toHaveBeenCalledTimes(1);
        expect(view().loading).toBeNull();
        act(() => mockEmit(departure));
        await act(async () => {
          scope.resolve('journey-1');
          await flush();
        });
        const claim = await claimPendingLoginReturnTo();
        if (
          outcome === 'failed' ||
          (outcome === 'resuming' && departure === 'beforeRemove')
        )
          expect(claim).toBeUndefined();
        else expect(claim?.returnTo.name).toBe('CourseDetails');
      } finally {
        scope.resolve('journey-1');
        await act(async () => {
          await flush();
        });
        jest.useRealTimers();
      }
    },
  );

  it('explicit Back abandons a resuming handoff that was already durable', async () => {
    const pendingAttempt = {
      provider: 'google',
      purpose: 'login',
      verifier: 'owned-verifier',
      startedAt: new Date(Date.now() - 1000).toISOString(),
    };
    mockLoadAttempt.mockResolvedValue(pendingAttempt);
    mockSignIn.mockImplementation(
      async (_provider, _methods, options: SocialAuthOptions) => {
        options.onProviderStarted?.();
        throw new Error('LOGIN_RESUMING');
      },
    );
    await mount();
    await start();
    expect((await claimPendingLoginReturnTo())?.returnTo.name).toBe(
      'CourseDetails',
    );
    await act(async () => {
      mockEmit('beforeRemove');
      await flush();
    });
    expect(await claimPendingLoginReturnTo()).toBeUndefined();
    expect(mockDeleteAttempt).toHaveBeenCalledWith(pendingAttempt);
    expect(mockDispatch).not.toHaveBeenCalled();
    expect(alert).not.toHaveBeenCalled();
  });

  it('an accepted older return with delayed scopes cannot overwrite a newer destination', async () => {
    const scope = deferred<string>();
    mockGuestScope.mockReturnValueOnce(scope.promise);
    const older = savePendingLoginReturnTo(
      {name: 'Profile'},
      'login',
      () => true,
    );
    await savePendingLoginReturnTo({name: 'Wallet'});
    scope.resolve('journey-1');
    await older;
    expect((await claimPendingLoginReturnTo())?.returnTo).toEqual({
      name: 'Wallet',
    });
  });

  it('retiring during service preparation prevents its last native-open boundary', async () => {
    const preparation = deferred<void>();
    const opened = jest.fn();
    mockSignIn.mockImplementation(
      async (_provider, _methods, options: SocialAuthOptions) => {
        await preparation.promise;
        if (!options.canStart?.()) throw new Error('LOGIN_CANCELLED');
        options.onProviderStarted?.();
        opened();
      },
    );
    await mount();
    await start();
    expect(mockSignIn).toHaveBeenCalledTimes(1);
    act(() => mockEmit('beforeRemove'));
    await act(async () => {
      preparation.resolve(undefined);
      await flush();
    });
    expect(opened).not.toHaveBeenCalled();
    expect(await claimPendingLoginReturnTo()).toBeUndefined();
    expect(alert).not.toHaveBeenCalled();
  });

  it('still adopts an accepted credential after the old Login unmounts', async () => {
    const completion = deferred<{api_token: string}>();
    mockSignIn.mockImplementation(
      (_provider, _methods, options: SocialAuthOptions) => {
        options.onProviderStarted?.();
        return completion.promise;
      },
    );
    await mount();
    await start();
    act(() => {
      mockEmit('beforeRemove');
      renderer!.unmount();
    });
    renderer = undefined;
    await act(async () => {
      mockSession = {api_token: 'durably-committed-session'};
      completion.resolve(mockSession);
      await flush();
    });
    expect(mockDispatch).toHaveBeenCalledWith({
      type: 'login',
      payload: mockSession,
    });
    expect((await claimPendingLoginReturnTo())?.returnTo.name).toBe(
      'CourseDetails',
    );
  });

  it('an old provider cancellation acknowledges only its own return receipt', async () => {
    const completion = deferred<{api_token: string}>();
    mockSignIn.mockImplementation(
      (_provider, _methods, options: SocialAuthOptions) => {
        options.onProviderStarted?.();
        return completion.promise;
      },
    );
    await mount();
    await start();
    act(() => mockEmit('blur'));
    await savePendingLoginReturnTo({name: 'Wallet'});
    await act(async () => {
      completion.reject(new Error('LOGIN_CANCELLED'));
      await flush();
    });
    expect((await claimPendingLoginReturnTo())?.returnTo).toEqual({
      name: 'Wallet',
    });
    expect(alert).not.toHaveBeenCalled();
  });
});
