import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockDispatch = jest.fn();
const mockRestore = jest.fn();
const mockResumeSocialAuth = jest.fn(async () => null);
const mockResumeGuestMigration = jest.fn(async () => undefined);
const mockGetInitialUrl = jest.fn(async () => null);

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
  peekSecureSession: () => mockSecureSnapshot,
  restoreSecureAuthState: () => mockRestore(),
}));

jest.mock('../src/services/socialAuth', () => ({
  resumePendingSocialAuth: mockResumeSocialAuth,
}));

jest.mock('../src/services/guestAccountMigration', () => ({
  resumeCompleteGuestAccountMigration: () => mockResumeGuestMigration(),
}));

jest.mock('../src/navigation/roknLinking', () => ({
  getInitialAppUrl: () => mockGetInitialUrl(),
}));

import {useSessionBootstrap} from '../src/screens/appInitializer/useSessionBootstrap';

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
  useSessionBootstrap();
  return null;
};

describe('session bootstrap ownership', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockSecureSnapshot = {ready: false, session: null, epoch: 0};
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
});
