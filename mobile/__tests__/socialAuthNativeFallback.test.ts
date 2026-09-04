const mockNativeCapability = jest.fn();
const mockNativeSignIn = jest.fn();
const mockOpenAndroidAuthSession = jest.fn();
const mockPost = jest.fn();
const mockSaveSession = jest.fn();
let mockPendingAttempt: Record<string, unknown> | null = null;

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
}));

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  getRandomBytesAsync: jest.fn(),
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

jest.mock('../src/services/nativeSocialAuth', () => ({
  hasNativeSocialCapability: (...args: unknown[]) =>
    mockNativeCapability(...args),
  signInWithNativeSocialProvider: (...args: unknown[]) =>
    mockNativeSignIn(...args),
}));

jest.mock('../src/services/androidAuthSession', () => ({
  openAndroidAuthSession: (...args: unknown[]) =>
    mockOpenAndroidAuthSession(...args),
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: jest.fn(),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));

jest.mock('../src/services/installationIdentity', () => ({
  getInstallationId: jest.fn(async () => null),
}));

jest.mock('../src/services/pendingWelcomeBonus', () => ({
  savePendingWelcomeBonus: jest.fn(async () => undefined),
}));

jest.mock('../src/services/secureSession', () => ({
  extractApiToken: (value: {api_token?: string} | null) =>
    value?.api_token || null,
  loadSecureSession: jest.fn(async () => null),
  saveSecureSession: (...args: unknown[]) => mockSaveSession(...args),
  savePendingSocialAuthAttempt: jest.fn(
    async (attempt: Record<string, unknown>) => {
      mockPendingAttempt = attempt;
    },
  ),
  replacePendingSocialAuthAttempt: jest.fn(
    async (
      _expected: Record<string, unknown>,
      replacement: Record<string, unknown>,
    ) => {
      mockPendingAttempt = replacement;
      return true;
    },
  ),
  loadPendingSocialAuthAttempt: jest.fn(async () => mockPendingAttempt),
  deletePendingSocialAuthAttempt: jest.fn(async () => {
    mockPendingAttempt = null;
    return true;
  }),
}));

import {
  signInWithSocialProvider,
  type SocialAuthMethods,
} from '../src/services/socialAuth';

const methods: SocialAuthMethods = {
  providers: ['google'],
  authorizationUrls: {
    google: 'https://rokn.app/api/v1/social-auth/google/start',
  },
  authorizationApiUrl: 'https://rokn.app/api/v1',
  welcomeBonus: 20,
  recommendedProvider: 'google',
  recommendationText: null,
};

describe('canonical browser social transport', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockPendingAttempt = null;
    mockNativeCapability.mockReturnValue(true);
    mockSaveSession.mockResolvedValue(undefined);
  });

  it('uses browser PKCE even when a native Google bridge is installed', async () => {
    mockOpenAndroidAuthSession.mockImplementation(
      async (_url: string, _returnUrl: string, challenge: string) => ({
        type: 'success',
        url: `rokn://auth?code=browser-code&attempt=${challenge}`,
      }),
    );
    mockPost.mockResolvedValue({
      data: {
        status: 200,
        success: true,
        data: {
          api_token: 'rokn-session-token',
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
      signInWithSocialProvider('google', methods),
    ).resolves.toMatchObject({api_token: 'rokn-session-token'});

    expect(mockPost).toHaveBeenCalledWith(
      'social-auth/complete',
      expect.objectContaining({
        code: 'browser-code',
        code_verifier: expect.any(String),
      }),
      expect.objectContaining({skipAuthorization: true}),
    );
    expect(mockSaveSession).toHaveBeenCalledWith(
      expect.objectContaining({api_token: 'rokn-session-token'}),
    );
    expect(mockNativeSignIn).not.toHaveBeenCalled();
  });

  it('retires a failed native attempt and falls back to browser PKCE', async () => {
    mockNativeSignIn.mockResolvedValue({type: 'fallback'});
    mockOpenAndroidAuthSession.mockResolvedValue({type: 'cancel'});

    await expect(signInWithSocialProvider('google', methods)).rejects.toThrow(
      'LOGIN_CANCELLED',
    );

    expect(mockOpenAndroidAuthSession).toHaveBeenCalledWith(
      expect.stringMatching(
        /^https:\/\/rokn\.app\/api\/v1\/social-auth\/google\/start\?return_to=rokn%3A%2F%2Fauth&code_challenge=[A-Za-z0-9_-]{43}&code_challenge_method=S256$/,
      ),
      'rokn://auth',
      expect.stringMatching(/^[A-Za-z0-9_-]{43}$/),
    );
    expect(mockPost).not.toHaveBeenCalled();
    expect(mockSaveSession).not.toHaveBeenCalled();
  });

  it('keeps browser PKCE recoverable when Android delivers its callback late', async () => {
    mockNativeSignIn.mockResolvedValue({type: 'fallback'});
    mockOpenAndroidAuthSession.mockResolvedValue({
      type: 'cancel',
      recoverable: true,
    });

    await expect(signInWithSocialProvider('google', methods)).rejects.toThrow(
      'LOGIN_RESUMING',
    );

    expect(mockPendingAttempt).toMatchObject({
      provider: 'google',
      purpose: 'login',
      flow: 'browser',
    });
  });
});
