const mockGetRandomBytes = jest.fn();
const mockAppleAvailability = jest.fn();
const mockAppleSignIn = jest.fn();
const mockPost = jest.fn();

jest.mock('react-native', () => ({
  Platform: {OS: 'ios'},
}));

jest.mock('expo-crypto', () => ({
  getRandomBytesAsync: (length: number) => mockGetRandomBytes(length),
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

jest.mock('expo-apple-authentication', () => ({
  AppleAuthenticationScope: {FULL_NAME: 0, EMAIL: 1},
  isAvailableAsync: () => mockAppleAvailability(),
  signInAsync: (options: unknown) => mockAppleSignIn(options),
}));

jest.mock('expo-web-browser', () => ({
  maybeCompleteAuthSession: jest.fn(),
  openAuthSessionAsync: jest.fn(),
}));

jest.mock('../src/constants/api', () => ({
  mainUrl: 'https://rokn.app/api/v1/',
  publicRequest: {
    get: jest.fn(),
    post: (url: string, payload: unknown) => mockPost(url, payload),
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
  loadSecureSession: jest.fn(async () => ({api_token: 'rokn-session-token'})),
  saveSecureSession: jest.fn(async () => undefined),
  savePendingSocialAuthAttempt: jest.fn(async () => undefined),
  replacePendingSocialAuthAttempt: jest.fn(async () => true),
  loadPendingSocialAuthAttempt: jest.fn(async () => null),
  deletePendingSocialAuthAttempt: jest.fn(async () => true),
}));

import {signInWithSocialProvider} from '../src/services/socialAuth';
import {deletePendingSocialAuthAttempt} from '../src/services/secureSession';

describe('Apple sign-in nonce binding', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockAppleAvailability.mockResolvedValue(true);
    mockGetRandomBytes.mockResolvedValue(
      Uint8Array.from({length: 32}, (_, index) => index),
    );
    mockAppleSignIn.mockResolvedValue({
      identityToken: 'signed-apple-identity-token',
      fullName: {givenName: 'Rokn', familyName: 'Learner'},
    });
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
            social_provider: 'apple',
          },
        },
      },
    });
  });

  it('sends the SHA-256 nonce to Apple and only its random preimage to the API', async () => {
    const rawNonce = Array.from({length: 32}, (_, index) =>
      index.toString(16).padStart(2, '0'),
    ).join('');

    await expect(
      signInWithSocialProvider('apple', {
        providers: ['apple'],
        authorizationUrls: {},
        authorizationApiUrl: 'https://rokn.app/api/v1',
        welcomeBonus: null,
        recommendedProvider: 'apple',
        recommendationText: null,
      }),
    ).resolves.toMatchObject({api_token: 'rokn-session-token'});

    expect(mockGetRandomBytes).toHaveBeenCalledWith(32);
    expect(mockAppleSignIn).toHaveBeenCalledWith(
      expect.objectContaining({
        nonce:
          '6c86c6aac5fb24bcf5d9939cb7d7d5645ce39418f449e03b262dd4fa14b4b92b',
      }),
    );
    expect(mockPost).toHaveBeenCalledWith(
      'social-login',
      expect.objectContaining({
        provider: 'apple',
        token: 'signed-apple-identity-token',
        nonce: rawNonce,
      }),
    );
    expect(mockPost.mock.calls[0][1]).not.toEqual(
      expect.objectContaining({
        nonce:
          '6c86c6aac5fb24bcf5d9939cb7d7d5645ce39418f449e03b262dd4fa14b4b92b',
      }),
    );
  });

  it('retires the owned attempt when Apple is cancelled', async () => {
    mockAppleSignIn.mockRejectedValueOnce({code: 'ERR_REQUEST_CANCELED'});

    await expect(
      signInWithSocialProvider('apple', {
        providers: ['apple'],
        authorizationUrls: {},
        authorizationApiUrl: 'https://rokn.app/api/v1',
        welcomeBonus: null,
        recommendedProvider: 'apple',
        recommendationText: null,
      }),
    ).rejects.toThrow('LOGIN_CANCELLED');

    expect(deletePendingSocialAuthAttempt).toHaveBeenCalledWith(
      expect.objectContaining({provider: 'apple', flow: 'native'}),
    );
  });

  it('does not leave a native attempt behind when Apple returns no token', async () => {
    mockAppleSignIn.mockResolvedValueOnce({identityToken: null});

    await expect(
      signInWithSocialProvider('apple', {
        providers: ['apple'],
        authorizationUrls: {},
        authorizationApiUrl: 'https://rokn.app/api/v1',
        welcomeBonus: null,
        recommendedProvider: 'apple',
        recommendationText: null,
      }),
    ).rejects.toThrow('LOGIN_SESSION_INVALID');

    expect(deletePendingSocialAuthAttempt).toHaveBeenCalledWith(
      expect.objectContaining({provider: 'apple', flow: 'native'}),
    );
  });
});
