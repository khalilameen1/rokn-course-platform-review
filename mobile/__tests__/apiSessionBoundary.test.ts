import {AxiosHeaders} from 'axios';

const mockGetItem = jest.fn();
const mockPeekSession = jest.fn();
const mockSecureRandomUuid = jest.fn(
  () => '11111111-1111-4111-8111-111111111111',
);

jest.mock('react-native', () => ({Platform: {OS: 'android'}}));
jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'USER_DATA'},
  extractApiToken: (value: unknown) =>
    typeof value === 'object' && value !== null && 'api_token' in value
      ? String((value as {api_token: unknown}).api_token)
      : null,
  getItem: (...args: unknown[]) => mockGetItem(...args),
  removeItem: jest.fn(),
  rotateGuestStorageScope: jest.fn(),
}));
jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: (...args: unknown[]) => mockPeekSession(...args),
  loadSecureSession: (...args: unknown[]) => mockGetItem(...args),
  deleteSecureSessionIfToken: jest.fn(),
}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  getLoginReturnToSnapshot: jest.fn(),
  navigate: jest.fn(),
}));
jest.mock('../src/navigation/authReturn', () => ({
  savePendingLoginReturnTo: jest.fn(),
}));
jest.mock('../src/store/store', () => ({store: {dispatch: jest.fn()}}));
jest.mock('../src/store/reducers/auth', () => ({LogOut: jest.fn()}));
jest.mock('../src/services/smartReminders', () => ({
  cancelLearningReminders: jest.fn(),
  setSmartRemindersEnabled: jest.fn(),
}));
jest.mock('../src/services/pushDeviceState', () => ({
  invalidateLocalPushDeviceRegistration: jest.fn(),
}));
jest.mock('../src/utils/serverClock', () => ({observeServerTime: jest.fn()}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => mockSecureRandomUuid(),
}));
jest.mock('../src/services/installationIdentity', () => ({
  getInstallationId: jest.fn(async () => null),
}));

import {
  DEFAULT_READ_RECOVERY_BUDGET_MS,
  onFulfilledRequest,
  onRejectedResponse,
  publicRequest,
  responseConfig,
  type RoknRequestConfig,
} from '../src/constants/api';

describe('public request session boundary', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetItem.mockResolvedValue(null);
    mockPeekSession.mockReturnValue({ready: false, session: null, epoch: 1});
    mockSecureRandomUuid.mockReturnValue(
      '11111111-1111-4111-8111-111111111111',
    );
  });

  it('does not wait for secure session storage on a public request', async () => {
    const config = {
      method: 'get',
      url: 'auth-methods',
      headers: new AxiosHeaders(),
      skipAuthorization: true,
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(mockGetItem).not.toHaveBeenCalled();
    expect(config.headers.has('Authorization')).toBe(false);
  });

  it('lets the server assign tracing identity when native UUID generation is unavailable', async () => {
    mockSecureRandomUuid.mockImplementationOnce(() => {
      throw new Error('SECURE_RANDOM_UUID_UNAVAILABLE');
    });
    const config = {
      method: 'get',
      url: 'courses/list',
      headers: new AxiosHeaders(),
      optionalAuthorization: true,
    } as Parameters<typeof responseConfig>[0];

    await expect(responseConfig(config)).resolves.toBe(config);
    expect(config.headers.has('X-Request-Id')).toBe(false);
  });

  it('gives ordinary reads one bounded logical deadline across retries', async () => {
    const now = jest.spyOn(Date, 'now').mockReturnValue(1_000_000);
    const config = {
      method: 'get',
      url: 'wallet',
      headers: new AxiosHeaders(),
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(
      (config as typeof config & {roknNetworkRetryDeadlineAt: number})
        .roknNetworkRetryDeadlineAt,
    ).toBe(1_012_000);
    expect(config.timeout).toBe(DEFAULT_READ_RECOVERY_BUDGET_MS);
    now.mockRestore();
  });

  it('preserves a shorter screen-owned read deadline', async () => {
    const now = jest.spyOn(Date, 'now').mockReturnValue(1_000_000);
    const config = {
      method: 'get',
      url: 'courses/list',
      headers: new AxiosHeaders(),
      timeout: 15_000,
      roknNetworkRetryDeadlineAt: 1_002_500,
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(
      (config as typeof config & {roknNetworkRetryDeadlineAt: number})
        .roknNetworkRetryDeadlineAt,
    ).toBe(1_002_500);
    expect(config.timeout).toBe(2_500);
    now.mockRestore();
  });

  it('uses only the ready memory snapshot for optional catalogue auth', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'memory-token'},
    });
    const config = {
      method: 'get',
      url: 'courses/list',
      headers: new AxiosHeaders(),
      optionalAuthorization: true,
    } as Parameters<typeof responseConfig>[0];

    await responseConfig(config);

    expect(mockGetItem).not.toHaveBeenCalledWith('USER_DATA');
    expect(config.headers.get('Authorization')).toBe('Bearer memory-token');
  });

  it('does not read or clear a session for a bearer-less public 401', async () => {
    const response = {status: 401, data: {code: 'gateway_unauthorized'}};

    await expect(
      onRejectedResponse({
        response,
        config: {method: 'get', headers: new AxiosHeaders()},
      }),
    ).rejects.toBe(response);
    expect(mockGetItem).not.toHaveBeenCalledWith('USER_DATA');
  });

  it.each([
    [
      'guest to user',
      {ready: false, session: null, epoch: 10},
      {ready: true, session: {api_token: 'user-one'}, epoch: 11},
    ],
    [
      'user to user',
      {ready: true, session: {api_token: 'user-one'}, epoch: 20},
      {ready: true, session: {api_token: 'user-two'}, epoch: 21},
    ],
  ])(
    'rejects a %s response captured before the session epoch changed',
    async (_label, before, after) => {
      mockPeekSession.mockReturnValue(before);
      const config = {
        method: 'get',
        url: 'courses/list',
        headers: new AxiosHeaders(),
        optionalAuthorization: true,
      } as Parameters<typeof responseConfig>[0];
      await responseConfig(config);

      mockPeekSession.mockReturnValue(after);
      await expect(
        onFulfilledRequest({
          config,
          data: {},
          headers: {},
        } as never),
      ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    },
  );

  it('keeps a public guest response when slow storage settles to the same guest', async () => {
    mockPeekSession.mockReturnValue({ready: false, session: null, epoch: 10});
    const config = {
      method: 'get',
      url: 'auth-methods',
      headers: new AxiosHeaders(),
      skipAuthorization: true,
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);

    mockPeekSession.mockReturnValue({ready: true, session: null, epoch: 11});
    await expect(
      onFulfilledRequest({config, data: {}, headers: {}} as never),
    ).resolves.toBeDefined();
  });

  it('keeps an account-neutral catalogue response when login commits in flight', async () => {
    mockPeekSession.mockReturnValue({ready: false, session: null, epoch: 10});
    const config = {
      method: 'get',
      url: 'courses/list',
      headers: new AxiosHeaders(),
      skipAuthorization: true,
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);

    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'new-session'},
      epoch: 11,
    });
    await expect(
      onFulfilledRequest({config, data: {}, headers: {}} as never),
    ).resolves.toBeDefined();
    expect(config.headers.has('Authorization')).toBe(false);
  });

  it('does not resend a read-only network retry after the account epoch changes', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-one'},
      epoch: 30,
    });
    mockGetItem.mockResolvedValue({api_token: 'user-one'});
    const config = {
      method: 'get',
      url: 'profile',
      headers: new AxiosHeaders(),
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);
    expect(config.headers.get('Authorization')).toBe('Bearer user-one');

    (
      config as typeof config & {roknNetworkRetryCount: number}
    ).roknNetworkRetryCount = 1;
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-two'},
      epoch: 31,
    });
    mockGetItem.mockResolvedValue({api_token: 'user-two'});

    await expect(responseConfig(config)).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(config.headers.get('Authorization')).toBe('Bearer user-one');
  });

  it('keeps a same-owner retry on its captured bearer without rereading auth', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-one'},
      epoch: 40,
    });
    mockGetItem.mockResolvedValue({api_token: 'user-one'});
    const config = {
      method: 'get',
      url: 'profile',
      headers: new AxiosHeaders(),
    } as Parameters<typeof responseConfig>[0];
    await responseConfig(config);
    mockGetItem.mockClear();

    (
      config as typeof config & {roknNetworkRetryCount: number}
    ).roknNetworkRetryCount = 1;
    await responseConfig(config);

    expect(config.headers.get('Authorization')).toBe('Bearer user-one');
    // The in-memory secure-session snapshot owns this process. A retry must
    // not perform another native auth read that could stall or replace it.
    expect(
      mockGetItem.mock.calls.filter(([key]) => key === 'USER_DATA'),
    ).toHaveLength(0);
  });

  it('rejects an account write when the account changes before Axios intercepts it', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-one'},
      epoch: 50,
    });
    const adapter = jest.fn(async config => ({
      config,
      data: {ok: true},
      headers: {},
      status: 200,
      statusText: 'OK',
    }));

    const pending = publicRequest.post(
      'claim-coins',
      {task: 'daily'},
      {adapter},
    );
    // Axios request interceptors run in a later microtask. The API boundary
    // must already own user-one before this synchronous account replacement.
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-two'},
      epoch: 51,
    });

    await expect(pending).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(adapter).not.toHaveBeenCalled();
  });

  it('dispatches a same-owner write with the bearer captured at invocation', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-one'},
      epoch: 60,
    });
    const adapter = jest.fn(async config => ({
      config,
      data: {ok: true},
      headers: {},
      status: 200,
      statusText: 'OK',
    }));

    await publicRequest.put('user/profile', {name: 'Rokn'}, {adapter});

    expect(adapter).toHaveBeenCalledTimes(1);
    expect(adapter.mock.calls[0][0].headers.get('Authorization')).toBe(
      'Bearer user-one',
    );
  });

  it('does not defer a write until an unknown cold-start session appears', async () => {
    mockPeekSession.mockReturnValue({ready: false, session: null, epoch: 70});
    const adapter = jest.fn();

    await expect(
      publicRequest.delete('saved-folders/12', {adapter}),
    ).rejects.toThrow('SESSION_NOT_READY_FOR_ACCOUNT_WRITE');
    expect(adapter).not.toHaveBeenCalled();
    expect(mockGetItem).not.toHaveBeenCalled();
  });

  it('keeps an explicitly public OAuth write account-neutral during bootstrap', async () => {
    mockPeekSession.mockReturnValue({ready: false, session: null, epoch: 75});
    const adapter = jest.fn(async config => ({
      config,
      data: {ok: true},
      headers: {},
      status: 200,
      statusText: 'OK',
    }));

    await publicRequest.post('social-auth/complete', {code: 'provider-code'}, {
      adapter,
      skipAuthorization: true,
    } as RoknRequestConfig);

    expect(adapter).toHaveBeenCalledTimes(1);
    expect(adapter.mock.calls[0][0].headers.has('Authorization')).toBe(false);
    expect(mockGetItem).not.toHaveBeenCalled();
  });

  it('binds direct axios mutations through the same request policy', async () => {
    mockPeekSession.mockReturnValue({
      ready: true,
      session: {api_token: 'user-one'},
      epoch: 80,
    });
    const adapter = jest.fn(async config => ({
      config,
      data: {ok: true},
      headers: {},
      status: 200,
      statusText: 'OK',
    }));
    await publicRequest.request({
      method: 'post',
      url: 'claim-coins',
      adapter,
    });

    expect(adapter).toHaveBeenCalledTimes(1);
    expect(adapter.mock.calls[0][0].headers.get('Authorization')).toBe(
      'Bearer user-one',
    );
  });

  it('retries a transient read once through the captured request policy', async () => {
    const requestResult = {data: {ok: true}};
    const request = jest
      .spyOn(publicRequest, 'request')
      .mockResolvedValueOnce(requestResult);
    const config = {
      method: 'get',
      url: 'wallet',
      headers: new AxiosHeaders(),
      roknSessionEpoch: 1,
      roknNetworkRetryDeadlineAt: Date.now() + 5_000,
    };

    await expect(
      onRejectedResponse({
        code: 'ERR_NETWORK',
        message: 'Network Error',
        config,
      }),
    ).resolves.toBe(requestResult);
    expect(request).toHaveBeenCalledTimes(1);
    expect(request.mock.calls[0][0]).toEqual(
      expect.objectContaining({roknNetworkRetryCount: 1}),
    );
    request.mockRestore();
  });

  it('never replays a write after an ambiguous transport failure', async () => {
    const request = jest.spyOn(publicRequest, 'request');
    const failure = {
      code: 'ERR_NETWORK',
      message: 'Network Error',
      config: {
        method: 'post',
        url: 'claim-coins',
        headers: new AxiosHeaders(),
        roknSessionEpoch: 1,
      },
    };

    await expect(onRejectedResponse(failure)).rejects.toBe(failure);
    expect(request).not.toHaveBeenCalled();
    request.mockRestore();
  });

  it('never invents a GET when an interceptor error has no request config', async () => {
    const request = jest.spyOn(publicRequest, 'request');
    const failure = {code: 'ERR_NETWORK', message: 'Network Error'};

    await expect(onRejectedResponse(failure)).rejects.toBe(failure);
    expect(request).not.toHaveBeenCalled();
    request.mockRestore();
  });

  it('does not start a second retry ladder after the shared ladder is spent', async () => {
    const request = jest.spyOn(publicRequest, 'request');
    const failure = {
      code: 'ERR_NETWORK',
      message: 'Network Error',
      config: {
        method: 'get',
        url: 'wallet',
        headers: new AxiosHeaders(),
        roknSessionEpoch: 1,
        roknNetworkRetryCount: 3,
        roknNetworkRetryDeadlineAt: Date.now() + 5_000,
      },
    };

    await expect(onRejectedResponse(failure)).rejects.toBe(failure);
    expect(request).not.toHaveBeenCalled();
    request.mockRestore();
  });

  it('cancels a scheduled read retry as soon as its screen aborts', async () => {
    const controller = new AbortController();
    const request = jest.spyOn(publicRequest, 'request');
    const retry = onRejectedResponse({
      code: 'ERR_NETWORK',
      message: 'Network Error',
      config: {
        method: 'get',
        url: 'notifications',
        headers: new AxiosHeaders(),
        signal: controller.signal,
        roknSessionEpoch: 1,
        roknNetworkRetryDeadlineAt: Date.now() + 5_000,
      },
    });
    controller.abort();

    await expect(retry).rejects.toMatchObject({code: 'ERR_CANCELED'});
    expect(request).not.toHaveBeenCalled();
    request.mockRestore();
  });
});
