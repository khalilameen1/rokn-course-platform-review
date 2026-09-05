import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Alert} from 'react-native';

const mockDispatch = jest.fn();
const mockRestore = jest.fn();
const mockResumeSocialAuth = jest.fn<Promise<unknown>, [string?]>(
  async () => null,
);
const mockLoadPendingSocialAuthAttempt = jest.fn<Promise<unknown>, []>(
  async () => null,
);
const mockResumeGuestMigration = jest.fn(async () => undefined);
const mockGetInitialUrl = jest.fn<Promise<string | null>, []>(async () => null);

let mockSecureSnapshot: {
  ready: boolean;
  session: unknown;
  epoch: number;
} = {ready: false, session: null, epoch: 0};

jest.mock('react-redux', () => ({
  useDispatch: () => mockDispatch,
}));

jest.mock('../src/services/secureSession', () => ({
  extractApiToken: (value: unknown) => {
    if (!value || typeof value !== 'object' || !('api_token' in value)) {
      return null;
    }
    return String((value as {api_token?: unknown}).api_token || '') || null;
  },
  loadSecureSession: jest.fn(async () => mockSecureSnapshot.session),
  loadPendingSocialAuthAttempt: () => mockLoadPendingSocialAuthAttempt(),
  peekSecureSession: () => mockSecureSnapshot,
  restoreSecureAuthState: () => mockRestore(),
}));

jest.mock('../src/services/socialAuth', () => ({
  resumePendingSocialAuth: (url?: string) => mockResumeSocialAuth(url),
}));

jest.mock('../src/services/guestAccountMigration', () => ({
  resumeCompleteGuestAccountMigration: () => mockResumeGuestMigration(),
}));

jest.mock('../src/navigation/roknLinking', () => ({
  getInitialAppUrl: () => mockGetInitialUrl(),
}));

import {useSessionBootstrap} from '../src/screens/appInitializer/useSessionBootstrap';

let latestBootstrap: ReturnType<typeof useSessionBootstrap> | null = null;

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason?: unknown) => void;
  const promise = new Promise<T>((next, fail) => {
    resolve = next;
    reject = fail;
  });
  return {promise, reject, resolve};
};

const Harness = () => {
  latestBootstrap = useSessionBootstrap();
  return null;
};

describe('session bootstrap ownership', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    latestBootstrap = null;
    mockSecureSnapshot = {ready: false, session: null, epoch: 0};
    mockLoadPendingSocialAuthAttempt.mockResolvedValue(null);
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('does not let a late restore failure log out a session committed meanwhile', async () => {
    const restoreFlight = deferred<{
      session: unknown;
      isAuthenticated: boolean;
    }>();
    mockRestore.mockReturnValue(restoreFlight.promise);
    let renderer: TestRenderer.ReactTestRenderer;

    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
    });
    await act(async () => {
      await jest.advanceTimersByTimeAsync(3_500);
    });

    const committedSession = {
      api_token: 'new-session-token',
      user: {id: 9, name: 'Rokn Learner'},
    };
    mockSecureSnapshot = {ready: true, session: committedSession, epoch: 1};
    await act(async () => {
      restoreFlight.reject(new Error('stale keychain read failed'));
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(mockDispatch).toHaveBeenCalledWith({
      type: 'auth/saveLoginData',
      payload: committedSession,
    });
    expect(mockDispatch).not.toHaveBeenCalledWith({type: 'auth/LogOut'});
    expect(mockResumeSocialAuth).not.toHaveBeenCalled();
    expect(mockResumeGuestMigration).toHaveBeenCalledTimes(1);

    await act(async () => {
      renderer!.unmount();
    });
  });

  it('keeps a cold-start callback actionable after a transient failure while the app stays active', async () => {
    const pendingAttempt = {
      provider: 'google',
      verifier: 'V'.repeat(64),
      challenge: 'C'.repeat(43),
      flow: 'browser',
      startedAt: '2026-09-05T10:00:00.000Z',
      callbackUrl: `rokn://auth?code=completion&attempt=${'C'.repeat(43)}`,
    };
    const session = {
      api_token: 'recovered-session-token',
      user: {id: 19, name: 'Recovered Learner'},
    };
    mockRestore.mockResolvedValue({session: null, isAuthenticated: false});
    mockGetInitialUrl.mockResolvedValue(pendingAttempt.callbackUrl);
    mockLoadPendingSocialAuthAttempt.mockResolvedValue(pendingAttempt);
    mockResumeSocialAuth
      .mockRejectedValueOnce(new Error('NETWORK_UNAVAILABLE'))
      .mockImplementationOnce(async () => {
        mockSecureSnapshot = {ready: true, session, epoch: 1};
        return session;
      });
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    let renderer: TestRenderer.ReactTestRenderer;

    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(alert).toHaveBeenCalledTimes(1);
    expect(alert).toHaveBeenCalledWith(
      'تعذّر تسجيل الدخول',
      'تحقق من الاتصال\nثم حاول مرة أخرى',
      expect.arrayContaining([
        expect.objectContaining({
          text: 'إعادة المحاولة',
          onPress: expect.any(Function),
        }),
      ]),
    );
    const retry = alert.mock.calls[0]?.[2]?.find(
      button => button.text === 'إعادة المحاولة',
    );

    await act(async () => {
      retry?.onPress?.();
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(mockResumeSocialAuth).toHaveBeenNthCalledWith(
      1,
      pendingAttempt.callbackUrl,
    );
    expect(mockResumeSocialAuth).toHaveBeenNthCalledWith(2, undefined);
    expect(mockDispatch).toHaveBeenCalledWith({
      type: 'auth/saveLoginData',
      payload: session,
    });
    expect(mockResumeGuestMigration).toHaveBeenCalledTimes(1);

    await act(async () => {
      retry?.onPress?.();
      await Promise.resolve();
    });
    expect(mockResumeSocialAuth).toHaveBeenCalledTimes(2);

    alert.mockRestore();
    await act(async () => {
      renderer!.unmount();
    });
  });

  it('drops an old failure when a newer attempt owns the pending journal', async () => {
    const pendingAttempt = {
      provider: 'google',
      verifier: 'V'.repeat(64),
      challenge: 'C'.repeat(43),
      flow: 'browser',
      startedAt: '2026-09-05T10:00:00.000Z',
      callbackUrl: `rokn://auth?code=completion&attempt=${'C'.repeat(43)}`,
    };
    const pendingRead = deferred<unknown>();
    mockRestore.mockResolvedValue({session: null, isAuthenticated: false});
    mockGetInitialUrl.mockResolvedValue(pendingAttempt.callbackUrl);
    mockLoadPendingSocialAuthAttempt
      .mockResolvedValueOnce(pendingAttempt)
      .mockReturnValueOnce(pendingRead.promise);
    mockResumeSocialAuth.mockRejectedValueOnce(
      new Error('NETWORK_UNAVAILABLE'),
    );
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    let renderer: TestRenderer.ReactTestRenderer;

    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(mockLoadPendingSocialAuthAttempt).toHaveBeenCalledTimes(2);

    const newerAttempt = {
      ...pendingAttempt,
      verifier: 'N'.repeat(64),
      challenge: 'D'.repeat(43),
      startedAt: '2026-09-05T10:01:00.000Z',
    };
    await act(async () => {
      pendingRead.resolve(newerAttempt);
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(alert).not.toHaveBeenCalled();
    alert.mockRestore();
    await act(async () => {
      renderer!.unmount();
    });
  });

  it('does not begin resume after unmount while the pending attempt is loading', async () => {
    const pendingRead = deferred<unknown>();
    mockRestore.mockResolvedValue({session: null, isAuthenticated: false});
    mockGetInitialUrl.mockResolvedValue(
      `rokn://auth?code=completion&attempt=${'C'.repeat(43)}`,
    );
    mockLoadPendingSocialAuthAttempt.mockReturnValueOnce(pendingRead.promise);
    let renderer: TestRenderer.ReactTestRenderer;

    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
      await Promise.resolve();
    });
    expect(mockLoadPendingSocialAuthAttempt).toHaveBeenCalledTimes(1);

    await act(async () => {
      renderer!.unmount();
    });
    await act(async () => {
      pendingRead.resolve({
        provider: 'google',
        verifier: 'V'.repeat(64),
        startedAt: '2026-09-05T10:00:00.000Z',
      });
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(mockResumeSocialAuth).not.toHaveBeenCalled();
  });

  it('deduplicates concurrent terminal observers and reports the attempt once', async () => {
    const pendingAttempt = {
      provider: 'facebook',
      verifier: 'V'.repeat(64),
      challenge: 'C'.repeat(43),
      flow: 'browser',
      startedAt: '2026-09-05T10:00:00.000Z',
      callbackUrl: `rokn://auth?attempt=${'C'.repeat(43)}`,
    };
    const completion = deferred<unknown>();
    mockRestore.mockResolvedValue({session: null, isAuthenticated: false});
    mockGetInitialUrl.mockResolvedValue(pendingAttempt.callbackUrl);
    mockLoadPendingSocialAuthAttempt
      .mockResolvedValueOnce(pendingAttempt)
      .mockResolvedValueOnce(null);
    mockResumeSocialAuth.mockReturnValueOnce(completion.promise);
    const alert = jest.spyOn(Alert, 'alert').mockImplementation(() => {});
    let renderer: TestRenderer.ReactTestRenderer;

    await act(async () => {
      renderer = TestRenderer.create(<Harness />);
      await Promise.resolve();
      await Promise.resolve();
    });
    await act(async () => {
      const first = latestBootstrap?.resumePendingAuthentication(
        pendingAttempt.callbackUrl,
      );
      const second = latestBootstrap?.resumePendingAuthentication(
        pendingAttempt.callbackUrl,
      );
      expect(first).toBe(second);
      expect(mockResumeSocialAuth).toHaveBeenCalledTimes(1);
      completion.reject(new Error('LOGIN_CODE_MISSING'));
      await Promise.resolve();
      await Promise.resolve();
      await Promise.resolve();
    });

    expect(alert).toHaveBeenCalledTimes(1);
    expect(alert.mock.calls[0]?.[2]).toEqual([{text: 'حسنًا'}]);
    alert.mockRestore();
    await act(async () => {
      renderer!.unmount();
    });
  });
});
