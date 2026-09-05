const mockPost = jest.fn();
const mockLoad = jest.fn();
const mockSave = jest.fn();
const mockDelete = jest.fn();
const mockReplace = jest.fn();
const mockSaveWelcomeBonus = jest.fn();
const mockGetRequiredInstallationId = jest.fn(
  async () => '11111111-1111-4111-8111-111111111111',
);

jest.mock('react-native', () => ({Platform: {OS: 'android'}}));
jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));
jest.mock('expo-apple-authentication', () => ({
  AppleAuthenticationScope: {FULL_NAME: 0, EMAIL: 1},
  isAvailableAsync: jest.fn(),
  signInAsync: jest.fn(),
}));
jest.mock('expo-web-browser', () => ({
  maybeCompleteAuthSession: jest.fn(),
  openAuthSessionAsync: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({
  mainUrl: 'https://rokn.app/api/v1/',
  publicRequest: {
    get: jest.fn(),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/services/secureSession', () => ({
  loadPendingSocialAuthAttempt: (...args: unknown[]) => mockLoad(...args),
  savePendingSocialAuthAttempt: (...args: unknown[]) => mockSave(...args),
  replacePendingSocialAuthAttempt: (...args: unknown[]) => mockReplace(...args),
  deletePendingSocialAuthAttempt: (...args: unknown[]) => mockDelete(...args),
  saveSecureSession: jest.fn(async () => undefined),
}));
jest.mock('../src/services/androidAuthSession', () => ({
  openAndroidAuthSession: jest.fn(),
}));
jest.mock('../src/services/installationIdentity', () => ({
  getRequiredInstallationId: () => mockGetRequiredInstallationId(),
}));
jest.mock('../src/services/pendingWelcomeBonus', () => ({
  savePendingWelcomeBonus: (...args: unknown[]) =>
    mockSaveWelcomeBonus(...args),
}));

import {resumePendingSocialAuth} from '../src/services/socialAuth';

describe('social auth cold-start recovery', () => {
  const pending = {
    provider: 'google',
    verifier:
      '1111111111114111811111111111111111111111111141118111111111111111',
    challenge: 'P'.repeat(43),
    flow: 'browser' as const,
    authorizationApiUrl: 'https://identity.rokn.app/api/v1',
    startedAt: new Date().toISOString(),
  };

  beforeEach(() => {
    jest.clearAllMocks();
    mockLoad.mockResolvedValue(pending);
    mockSave.mockResolvedValue(undefined);
    mockReplace.mockImplementation(async (_expected, replacement) => {
      mockSave(replacement);
      return true;
    });
    mockDelete.mockResolvedValue(true);
    mockSaveWelcomeBonus.mockResolvedValue(true);
    mockGetRequiredInstallationId.mockResolvedValue(
      '11111111-1111-4111-8111-111111111111',
    );
  });

  it('completes the initial deep link with the durable PKCE verifier', async () => {
    mockPost.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          api_token: 'session-token',
          user: {
            id: 7,
            name: 'Rokn Learner',
            email: 'learner@example.com',
            social_provider: 'google',
          },
        },
      },
    });

    await expect(
      resumePendingSocialAuth(
        `rokn://auth?code=one-time-code&attempt=${pending.challenge}`,
      ),
    ).resolves.toMatchObject({api_token: 'session-token'});
    expect(mockSave).toHaveBeenNthCalledWith(1, {
      ...pending,
      callbackUrl: `rokn://auth?code=one-time-code&attempt=${pending.challenge}`,
    });
    expect(mockSave).toHaveBeenNthCalledWith(
      2,
      expect.objectContaining({
        ...pending,
        completedSession: expect.objectContaining({api_token: 'session-token'}),
      }),
    );
    expect(mockPost).toHaveBeenCalledWith(
      'social-auth/complete',
      {
        code: 'one-time-code',
        code_verifier: pending.verifier,
        device_os: 'android',
        device_type: 'android',
        device_id: '11111111-1111-4111-8111-111111111111',
      },
      {
        timeout: 8_000,
        skipAuthorization: true,
        baseURL: 'https://identity.rokn.app/api/v1/',
      },
    );
    expect(mockDelete).toHaveBeenCalledTimes(1);
  });

  it('does not report a durable login as failed when the welcome receipt cannot be cached', async () => {
    mockPost.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          api_token: 'durable-session-token',
          welcome_bonus_granted: 20,
          user: {
            id: 8,
            name: 'Rokn Learner',
            social_provider: 'google',
          },
        },
      },
    });
    mockSaveWelcomeBonus.mockRejectedValue(
      new Error('ACCOUNT_CHANGED_DURING_REQUEST'),
    );

    await expect(
      resumePendingSocialAuth(
        `rokn://auth?code=durable-code&attempt=${pending.challenge}`,
      ),
    ).resolves.toMatchObject({api_token: 'durable-session-token'});
    expect(mockDelete).toHaveBeenCalledTimes(1);
  });

  it('keeps the callback for a later retry when the API is unavailable', async () => {
    const timeout = jest
      .spyOn(global, 'setTimeout')
      .mockImplementation(callback => {
        callback();
        return 0 as unknown as ReturnType<typeof setTimeout>;
      });
    mockPost.mockRejectedValue(new Error('network'));

    await expect(
      resumePendingSocialAuth(
        `rokn://auth?code=retryable-code&attempt=${pending.challenge}`,
      ),
    ).rejects.toThrow('network');
    expect(mockSave).toHaveBeenCalled();
    expect(mockDelete).not.toHaveBeenCalled();
    timeout.mockRestore();
  });

  it('keeps a cold-start callback when durable device identity is temporarily unavailable', async () => {
    mockGetRequiredInstallationId.mockRejectedValueOnce(
      new Error('SESSION_STORAGE_UNAVAILABLE_INSTALLATION_ID'),
    );

    await expect(
      resumePendingSocialAuth(
        `rokn://auth?code=retryable-code&attempt=${pending.challenge}`,
      ),
    ).rejects.toThrow('SESSION_STORAGE_UNAVAILABLE_INSTALLATION_ID');

    expect(mockPost).not.toHaveBeenCalled();
    expect(mockDelete).not.toHaveBeenCalled();
    expect(mockSave).toHaveBeenCalledWith(
      expect.objectContaining({
        callbackUrl: `rokn://auth?code=retryable-code&attempt=${pending.challenge}`,
      }),
    );
  });

  it('ignores a stale callback without consuming the newer PKCE attempt', async () => {
    const current = {
      ...pending,
      challenge: 'N'.repeat(43),
    };
    mockLoad.mockResolvedValue(current);

    await expect(
      resumePendingSocialAuth(
        `rokn://auth?code=stale-code&attempt=${'O'.repeat(43)}`,
      ),
    ).resolves.toBeNull();

    expect(mockPost).not.toHaveBeenCalled();
    expect(mockSave).not.toHaveBeenCalled();
    expect(mockDelete).not.toHaveBeenCalled();
  });

  it('does not let a delayed browser callback consume a native attempt', async () => {
    mockLoad.mockResolvedValue({
      ...pending,
      challenge: undefined,
      flow: 'native',
    });

    await expect(
      resumePendingSocialAuth(
        `rokn://auth?code=browser-code&attempt=${'B'.repeat(43)}`,
      ),
    ).resolves.toBeNull();

    expect(mockPost).not.toHaveBeenCalled();
    expect(mockSave).not.toHaveBeenCalled();
    expect(mockDelete).not.toHaveBeenCalled();
  });

  it('does not overwrite an attempt replaced after the callback was read', async () => {
    mockReplace.mockResolvedValueOnce(false);

    await expect(
      resumePendingSocialAuth(
        `rokn://auth?code=old-code&attempt=${pending.challenge}`,
      ),
    ).resolves.toBeNull();

    expect(mockPost).not.toHaveBeenCalled();
    expect(mockDelete).not.toHaveBeenCalled();
  });
});
