const mockStartBrowser = jest.fn();
const mockStartApple = jest.fn();
const mockGetRequiredInstallationId = jest.fn(
  async () => '11111111-1111-4111-8111-111111111111',
);

jest.mock('expo-web-browser', () => ({
  maybeCompleteAuthSession: jest.fn(),
}));
jest.mock('../src/services/socialAuthBrowser', () => ({
  startBrowserSocialAuth: (...args: unknown[]) => mockStartBrowser(...args),
}));
jest.mock('../src/services/socialAuthApple', () => ({
  startAppleSocialAuth: (...args: unknown[]) => mockStartApple(...args),
}));
jest.mock('../src/services/socialAuthCompletion', () => ({
  resumePendingSocialAuth: jest.fn(),
}));
jest.mock('../src/services/socialAuthDiscovery', () => ({
  getDeviceSocialAuthMethods: jest.fn(),
}));
jest.mock('../src/services/installationIdentity', () => ({
  getRequiredInstallationId: () => mockGetRequiredInstallationId(),
}));

import {
  signInWithSocialProvider,
  type SocialAuthMethods,
} from '../src/services/socialAuth';

const methods: SocialAuthMethods = {
  providers: ['google', 'facebook'],
  authorizationUrls: {
    google: 'https://rokn.app/api/v1/social-auth/google/start',
    facebook: 'https://rokn.app/api/v1/social-auth/facebook/start',
  },
  authorizationApiUrl: 'https://rokn.app/api/v1',
  welcomeBonus: null,
  recommendedProvider: 'google',
  recommendationText: null,
};

describe('social auth facade ownership', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockGetRequiredInstallationId.mockResolvedValue(
      '11111111-1111-4111-8111-111111111111',
    );
  });

  it('does not open a provider that the device cannot finish', async () => {
    mockGetRequiredInstallationId.mockRejectedValueOnce(
      new Error('SESSION_STORAGE_UNAVAILABLE_INSTALLATION_ID'),
    );

    await expect(signInWithSocialProvider('google', methods)).rejects.toThrow(
      'SESSION_STORAGE_UNAVAILABLE_INSTALLATION_ID',
    );
    expect(mockStartBrowser).not.toHaveBeenCalled();
  });

  it('joins an identical tap and rejects a competing provider attempt', async () => {
    let finish!: (value: {api_token: string; user: never}) => void;
    mockStartBrowser.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );

    const first = signInWithSocialProvider('google', methods);
    const duplicate = signInWithSocialProvider('google', methods);
    const competing = signInWithSocialProvider('facebook', methods);

    expect(duplicate).toBe(first);
    await expect(competing).rejects.toThrow('SOCIAL_LOGIN_IN_PROGRESS');
    expect(mockStartBrowser).toHaveBeenCalledTimes(1);

    finish({api_token: 'session-token', user: {} as never});
    await first;
  });

  it('does not let a login overwrite a live reauthentication attempt', async () => {
    let finish!: (value: {api_token: string; user: never}) => void;
    mockStartBrowser.mockReturnValueOnce(
      new Promise(resolve => {
        finish = resolve;
      }),
    );

    const reauth = signInWithSocialProvider('google', methods, {
      purpose: 'reauth',
    });
    await expect(signInWithSocialProvider('google', methods)).rejects.toThrow(
      'SOCIAL_LOGIN_IN_PROGRESS',
    );

    finish({api_token: 'reauth-token', user: {} as never});
    await reauth;
  });
});
