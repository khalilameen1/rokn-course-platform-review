const mockOpenAuthSession = jest.fn();
const mockOpenUrl = jest.fn();
let redirectHandler: ((event: {url: string}) => void) | undefined;

jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  Dimensions: {get: () => ({width: 390, height: 844})},
  NativeModules: {StatusBarManager: {HEIGHT: 24}},
  StatusBar: {currentHeight: 24},
  StyleSheet: {create: (styles: unknown) => styles},
  Linking: {
    addEventListener: jest.fn(
      (_event: string, handler: (event: {url: string}) => void) => {
        redirectHandler = handler;
        return {remove: jest.fn()};
      },
    ),
    openURL: (...args: unknown[]) => mockOpenUrl(...args),
  },
  AppState: {
    addEventListener: jest.fn(() => ({remove: jest.fn()})),
  },
}));

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
  openAuthSessionAsync: (...args: unknown[]) => mockOpenAuthSession(...args),
}));

jest.mock('../src/constants/api', () => ({
  mainUrl: 'https://rokn.app/api/v1/',
  publicRequest: {get: jest.fn(), post: jest.fn()},
}));

jest.mock('../src/services/installationIdentity', () => ({
  getRequiredInstallationId: jest.fn(
    async () => '11111111-1111-4111-8111-111111111111',
  ),
}));

jest.mock('../src/services/secureSession', () => ({
  savePendingSocialAuthAttempt: jest.fn(async () => undefined),
  replacePendingSocialAuthAttempt: jest.fn(async () => true),
  loadPendingSocialAuthAttempt: jest.fn(async () => ({
    provider: 'google',
    verifier:
      '1111111111114111811111111111111111111111111141118111111111111111',
    startedAt: new Date().toISOString(),
  })),
  deletePendingSocialAuthAttempt: jest.fn(async () => true),
  saveSecureSession: jest.fn(async () => undefined),
}));

import {signInWithSocialProvider} from '../src/services/socialAuth';
import {
  deletePendingSocialAuthAttempt,
  savePendingSocialAuthAttempt,
} from '../src/services/secureSession';

describe('browser social auth launch', () => {
  it('opens a deterministic encoded PKCE request on Android', async () => {
    mockOpenUrl.mockImplementation(async (url: string) => {
      const attempt = new URL(url).searchParams.get('code_challenge');
      redirectHandler?.({
        url: `rokn://auth?attempt=${encodeURIComponent(
          attempt || '',
        )}&error=login_cancelled`,
      });
    });

    await expect(
      signInWithSocialProvider('google', {
        providers: ['google'],
        authorizationUrls: {
          google: 'https://rokn.app/api/v1/social-auth/google/start',
        },
        authorizationApiUrl: 'https://rokn.app/api/v1',
        welcomeBonus: 20,
        recommendedProvider: 'google',
        recommendationText: null,
      }),
    ).rejects.toThrow('LOGIN_CANCELLED');

    expect(mockOpenUrl).toHaveBeenCalledWith(
      expect.stringMatching(
        /^https:\/\/rokn\.app\/api\/v1\/social-auth\/google\/start\?return_to=rokn%3A%2F%2Fauth&code_challenge=[A-Za-z0-9_-]{43}&code_challenge_method=S256$/,
      ),
    );
    expect(mockOpenAuthSession).not.toHaveBeenCalled();
    expect(savePendingSocialAuthAttempt).toHaveBeenCalledWith(
      expect.objectContaining({
        authorizationApiUrl: 'https://rokn.app/api/v1',
      }),
    );
  });

  it('retires the exact PKCE attempt when the browser cannot open', async () => {
    mockOpenUrl.mockRejectedValueOnce(new Error('browser unavailable'));

    await expect(
      signInWithSocialProvider('google', {
        providers: ['google'],
        authorizationUrls: {
          google: 'https://rokn.app/api/v1/social-auth/google/start',
        },
        authorizationApiUrl: 'https://rokn.app/api/v1',
        welcomeBonus: 20,
        recommendedProvider: 'google',
        recommendationText: null,
      }),
    ).rejects.toThrow('LOGIN_BROWSER_UNAVAILABLE');

    expect(deletePendingSocialAuthAttempt).toHaveBeenCalledWith(
      expect.objectContaining({
        provider: 'google',
        flow: 'browser',
        challenge: expect.stringMatching(/^[A-Za-z0-9_-]{43}$/),
      }),
    );
  });
});
