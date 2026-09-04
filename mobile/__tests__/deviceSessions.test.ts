const mockPost = jest.fn();
const mockGet = jest.fn();

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    post: (...args: unknown[]) => mockPost(...args),
    get: (...args: unknown[]) => mockGet(...args),
    delete: jest.fn(),
  },
}));

import {
  getDeviceSessions,
  revokeCurrentDeviceSession,
} from '../src/services/deviceSessions';

describe('device session revocation', () => {
  beforeEach(() => {
    mockPost.mockReset().mockResolvedValue({});
    mockGet.mockReset();
  });

  it('keeps the server phone or tablet class and rejects arbitrary values', async () => {
    mockGet.mockResolvedValue({
      data: {
        data: [
          {
            id: '47bb65db-2c84-4268-9187-1d00e4d8ff5a',
            platform: 'android',
            device_class: 'tablet',
            current: true,
          },
          {
            id: '0a41a7b7-e246-4465-a1d7-0ce9bf286a4a',
            platform: 'ios',
            device_class: 'desktop',
            current: false,
          },
        ],
      },
    });

    await expect(getDeviceSessions()).resolves.toEqual([
      expect.objectContaining({device_class: 'tablet'}),
      expect.objectContaining({device_class: null}),
    ]);
  });

  it('lets an ordinary logout invalidate its matching persisted session', async () => {
    await revokeCurrentDeviceSession('push-token');

    expect(mockPost).toHaveBeenCalledWith(
      'logout',
      {device_token: 'push-token'},
      undefined,
    );
  });

  it('does not let a superseded bearer delete the replacement transaction', async () => {
    await revokeCurrentDeviceSession(null, {
      preservePersistedSessionOnUnauthorized: true,
    });

    expect(mockPost).toHaveBeenCalledWith(
      'logout',
      {},
      {skipPersistedSessionInvalidation: true},
    );
  });

  it('binds an explicit logout to the account that owned the tap', async () => {
    await revokeCurrentDeviceSession(null, {
      preservePersistedSessionOnUnauthorized: true,
      session: {epoch: 7, token: 'account-a-token'},
    });

    expect(mockPost).toHaveBeenCalledWith(
      'logout',
      {},
      {
        headers: {Authorization: 'Bearer account-a-token'},
        roknSessionBoundAtCall: true,
        roknSessionEpoch: 7,
        roknSessionToken: 'account-a-token',
        skipPersistedSessionInvalidation: true,
      },
    );
  });
});
