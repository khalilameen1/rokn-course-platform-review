const mockGet = jest.fn();
const mockStorageGet = jest.fn();
const mockStorageSet = jest.fn();
const mockStorageRemove = jest.fn();
const mockAppleAvailable = jest.fn();
let mockPlatformOS = 'android';

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: (...args: unknown[]) => mockStorageGet(...args),
  setItem: (...args: unknown[]) => mockStorageSet(...args),
  removeItem: (...args: unknown[]) => mockStorageRemove(...args),
}));

jest.mock('react-native', () => ({
  Platform: {get OS() { return mockPlatformOS; }},
  Dimensions: {get: () => ({width: 390, height: 844})},
  NativeModules: {StatusBarManager: {HEIGHT: 24}},
  StatusBar: {currentHeight: 24},
  StyleSheet: {create: (styles: unknown) => styles},
}));
jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));
jest.mock('expo-apple-authentication', () => ({
  AppleAuthenticationScope: {FULL_NAME: 0, EMAIL: 1},
  isAvailableAsync: (...args: unknown[]) => mockAppleAvailable(...args),
  signInAsync: jest.fn(),
}));
jest.mock('expo-web-browser', () => ({
  maybeCompleteAuthSession: jest.fn(),
  openAuthSessionAsync: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: jest.fn(),
  },
}));
jest.mock('../src/services/secureSession', () => ({
  loadPendingSocialAuthAttempt: jest.fn(),
  savePendingSocialAuthAttempt: jest.fn(),
  replacePendingSocialAuthAttempt: jest.fn(),
  deletePendingSocialAuthAttempt: jest.fn(),
  saveSecureSession: jest.fn(),
}));

import {getSocialAuthMethods} from '../src/services/socialAuth';

const authMethodsResponse = (data: Record<string, unknown>) => ({
  data: {
    status: 200,
    success: true,
    data: {
      authorization_api_url:
        'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1',
      ...data,
    },
  },
});

describe('social auth discovery', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockPlatformOS = 'android';
    mockAppleAvailable.mockResolvedValue(false);
    mockStorageGet.mockResolvedValue(null);
    mockStorageSet.mockResolvedValue(undefined);
    mockStorageRemove.mockResolvedValue(undefined);
  });

  it('does not disguise an old mismatched host as a successful active-host configuration', async () => {
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['google', 'tiktok'],
        authorization_urls: {
          google: 'https://rokn.app/api/v1/social-auth/google/start',
          tiktok: null,
        },
        recommended_provider: 'google',
      }),
    );

    await expect(getSocialAuthMethods()).rejects.toThrow(
      'SOCIAL_AUTH_METHODS_CONTRACT_INVALID',
    );
    expect(mockStorageRemove).toHaveBeenCalledWith(
      '@rokn/social-auth-methods/v1',
    );
    expect(mockGet).toHaveBeenCalledWith(
      'auth-methods',
      expect.objectContaining({
        skipAuthorization: true,
        timeout: 8_000,
        roknNetworkRetryDeadlineAt: expect.any(Number),
      }),
    );
  });

  it('uses the backend-declared independent OAuth API host', async () => {
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['google'],
        authorization_api_url: 'https://identity.rokn.app/api/v1',
        authorization_urls: {
          google: 'https://identity.rokn.app/api/v1/social-auth/google/start',
        },
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google'],
      authorizationApiUrl: 'https://identity.rokn.app/api/v1',
      authorizationUrls: {
        google: 'https://identity.rokn.app/api/v1/social-auth/google/start',
      },
    });
    expect(mockStorageSet).toHaveBeenCalledWith(
      '@rokn/social-auth-methods/v1',
      expect.stringContaining('identity.rokn.app'),
    );
  });

  it('does not advertise Apple when the current iOS device cannot use it', async () => {
    mockPlatformOS = 'ios';
    mockAppleAvailable.mockResolvedValue(false);
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['apple', 'google'],
        authorization_urls: {
          google:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
        },
        recommended_provider: 'apple',
        recommendation_badge: 'هدية إضافية',
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google'],
      recommendedProvider: 'google',
      recommendationText: null,
    });
    expect(mockAppleAvailable).toHaveBeenCalledTimes(1);
  });

  it('keeps Apple when both deployment and device support it', async () => {
    mockPlatformOS = 'ios';
    mockAppleAvailable.mockResolvedValue(true);
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['apple'],
        authorization_urls: {},
        recommended_provider: 'apple',
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['apple'],
      recommendedProvider: 'apple',
    });
  });

  it('returns live methods without waiting for cache persistence', async () => {
    mockStorageSet.mockReturnValue(new Promise(() => undefined));
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['google'],
        authorization_urls: {
          google:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
        },
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google'],
    });
  });

  it('returns live methods when the native cache bridge throws synchronously', async () => {
    mockStorageSet.mockImplementation(() => {
      throw new Error('STORAGE_UNAVAILABLE');
    });
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['google'],
        authorization_urls: {
          google:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
        },
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google'],
    });
  });

  it('keeps the last valid provider contract through a transient outage', async () => {
    mockGet.mockRejectedValue(new Error('NETWORK_UNAVAILABLE'));
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 60_000,
        apiUrl:
          'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
        methods: {
          providers: ['google'],
          authorizationApiUrl:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1',
          authorizationUrls: {
            google:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
          },
          recommendedProvider: 'google',
          recommendationText: null,
          welcomeBonus: 20,
        },
      }),
    );

    await expect(getSocialAuthMethods()).resolves.toMatchObject({
      providers: ['google'],
      recommendedProvider: 'google',
      welcomeBonus: 20,
    });
  });

  it('does not resurrect a missing provider URL from an older cache', async () => {
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['google', 'tiktok'],
        authorization_urls: {
          google:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
          tiktok: null,
        },
        recommended_provider: 'google',
      }),
    );
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 60_000,
        apiUrl:
          'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
        methods: {
          providers: ['google', 'tiktok'],
          authorizationApiUrl:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1',
          authorizationUrls: {
            google:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
            tiktok:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/tiktok/start',
          },
        },
      }),
    );

    await expect(getSocialAuthMethods()).rejects.toThrow(
      'SOCIAL_AUTH_METHODS_CONTRACT_INVALID',
    );
    expect(mockStorageRemove).toHaveBeenCalledWith(
      '@rokn/social-auth-methods/v1',
    );
  });

  it('does not let the cache conceal an explicitly unsafe provider URL', async () => {
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['google'],
        authorization_urls: {
          google: 'https://attacker.example/api/v1/social-auth/google/start',
        },
      }),
    );
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 60_000,
        apiUrl:
          'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
        methods: {
          providers: ['google'],
          authorizationApiUrl:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1',
          authorizationUrls: {
            google:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
          },
        },
      }),
    );

    await expect(getSocialAuthMethods()).rejects.toThrow(
      'SOCIAL_AUTH_METHODS_CONTRACT_INVALID',
    );
  });

  it('does not let an expired provider contract hide a real outage', async () => {
    mockGet.mockRejectedValue(new Error('NETWORK_UNAVAILABLE'));
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 25 * 60 * 60 * 1000,
        apiUrl:
          'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
        methods: {
          providers: ['google'],
          authorizationApiUrl: 'https://identity.rokn.app/api/v1',
          authorizationUrls: {
            google: 'https://identity.rokn.app/api/v1/social-auth/google/start',
          },
        },
      }),
    );

    await expect(getSocialAuthMethods()).rejects.toThrow('NETWORK_UNAVAILABLE');
  });

  it('does not let a stalled cache read extend an auth discovery outage', async () => {
    jest.useFakeTimers();
    mockGet.mockRejectedValue(new Error('NETWORK_UNAVAILABLE'));
    mockStorageGet.mockReturnValue(new Promise(() => undefined));

    const discovery = getSocialAuthMethods();
    await Promise.resolve();
    await Promise.resolve();
    jest.advanceTimersByTime(350);
    await expect(discovery).rejects.toThrow('NETWORK_UNAVAILABLE');
    jest.useRealTimers();
  });

  it('does not hide an invalid active API contract behind cached methods', async () => {
    mockGet.mockResolvedValue(
      authMethodsResponse({authorization_urls: {}}),
    );
    mockStorageGet.mockResolvedValue(
      JSON.stringify({
        savedAt: Date.now() - 60_000,
        apiUrl:
          'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/',
        methods: {
          providers: ['google'],
          authorizationApiUrl:
            'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1',
          authorizationUrls: {
            google:
              'https://rokn-course-platform-review-production-b7gpy1.laravel.cloud/api/v1/social-auth/google/start',
          },
        },
      }),
    );

    await expect(getSocialAuthMethods()).rejects.toThrow(
      'SOCIAL_AUTH_METHODS_CONTRACT_INVALID',
    );
    expect(mockStorageRemove).toHaveBeenCalledWith(
      '@rokn/social-auth-methods/v1',
    );
  });

  it('rejects a declared provider without a start URL when no valid cache exists', async () => {
    mockGet.mockResolvedValue(
      authMethodsResponse({
        providers: ['google'],
        authorization_urls: {google: null},
      }),
    );

    await expect(getSocialAuthMethods()).rejects.toThrow(
      'SOCIAL_AUTH_METHODS_CONTRACT_INVALID',
    );
  });
});
