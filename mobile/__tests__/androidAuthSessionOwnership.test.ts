const mockLinkListeners = new Set<(event: {url: string}) => void>();
const mockAppStateListeners = new Set<(state: string) => void>();
const mockNativeAuthOpen = jest.fn<Promise<boolean>, [string]>(async () => true);
const mockOpenBrowserAsync = jest.fn<
  Promise<{type: string}>,
  [string, Record<string, boolean>]
>(async () => ({type: 'opened'}));

jest.mock('expo-web-browser', () => ({
  openBrowserAsync: (url: string, options: Record<string, boolean>) =>
    mockOpenBrowserAsync(url, options),
}));

jest.mock('react-native', () => ({
  NativeModules: {
    RoknAuthBrowser: {open: (url: string) => mockNativeAuthOpen(url)},
  },
  Linking: {
    addEventListener: (
      _event: string,
      listener: (event: {url: string}) => void,
    ) => {
      mockLinkListeners.add(listener);
      return {remove: () => mockLinkListeners.delete(listener)};
    },
    openURL: jest.fn(async () => undefined),
  },
  AppState: {
    addEventListener: (_event: string, listener: (state: string) => void) => {
      mockAppStateListeners.add(listener);
      return {remove: () => mockAppStateListeners.delete(listener)};
    },
  },
}));

import {NativeModules} from 'react-native';

import {
  androidAuthSessionOwnsCallback,
  openAndroidAuthSession,
} from '../src/services/androidAuthSession';

describe('Android OAuth callback ownership', () => {
  afterEach(() => {
    mockLinkListeners.clear();
    mockAppStateListeners.clear();
    mockNativeAuthOpen.mockReset();
    mockNativeAuthOpen.mockResolvedValue(true);
    mockOpenBrowserAsync.mockReset();
    mockOpenBrowserAsync.mockResolvedValue({type: 'opened'});
    NativeModules.RoknAuthBrowser = {
      open: (url: string) => mockNativeAuthOpen(url),
    };
    jest.useRealTimers();
  });

  it('does not let a stale callback consume the active attempt', async () => {
    const currentAttempt = 'current-pkce-challenge';
    const session = openAndroidAuthSession(
      'https://identity.rokn.app/start',
      'rokn://auth',
      currentAttempt,
    );
    const oldCallback = 'rokn://auth?attempt=old-pkce-challenge&code=old';
    const currentCallback = `rokn://auth?attempt=${currentAttempt}&code=current`;

    expect(mockNativeAuthOpen).toHaveBeenCalledWith(
      'https://identity.rokn.app/start',
    );
    expect(mockOpenBrowserAsync).not.toHaveBeenCalled();

    expect(androidAuthSessionOwnsCallback(oldCallback)).toBe(false);
    expect(androidAuthSessionOwnsCallback(currentCallback)).toBe(true);

    for (const listener of mockLinkListeners) listener({url: oldCallback});
    expect(mockLinkListeners.size).toBe(1);

    for (const listener of [...mockLinkListeners]) {
      listener({url: currentCallback});
    }
    await expect(session).resolves.toEqual({
      type: 'success',
      url: currentCallback,
    });

    expect(androidAuthSessionOwnsCallback(oldCallback)).toBe(false);
    expect(androidAuthSessionOwnsCallback(currentCallback)).toBe(true);
  });

  it('falls back to the Expo browser only when the native bridge is absent', async () => {
    delete NativeModules.RoknAuthBrowser;
    const attempt = 'fallback-pkce-challenge';
    const callback = `rokn://auth?attempt=${attempt}&code=done`;
    const session = openAndroidAuthSession(
      'https://identity.rokn.app/fallback',
      'rokn://auth',
      attempt,
    );

    expect(mockOpenBrowserAsync).toHaveBeenCalledWith(
      'https://identity.rokn.app/fallback',
      {
        createTask: false,
        showInRecents: false,
        showTitle: true,
        enableDefaultShareMenuItem: false,
      },
    );
    for (const listener of [...mockLinkListeners]) listener({url: callback});

    await expect(session).resolves.toEqual({type: 'success', url: callback});
  });

  it('does not hide a native launch failure behind a second browser', async () => {
    mockNativeAuthOpen.mockRejectedValueOnce(new Error('native unavailable'));

    await expect(
      openAndroidAuthSession(
        'https://identity.rokn.app/unavailable',
        'rokn://auth',
        'native-failure',
      ),
    ).rejects.toThrow('LOGIN_BROWSER_UNAVAILABLE');
    expect(mockOpenBrowserAsync).not.toHaveBeenCalled();
  });

  it('accepts the owned deep link when Android delivers it after returning foreground', async () => {
    jest.useFakeTimers();
    const attempt = 'deferred-pkce-challenge';
    const callback = `rokn://auth?attempt=${attempt}&code=deferred`;
    const session = openAndroidAuthSession(
      'https://identity.rokn.app/deferred',
      'rokn://auth',
      attempt,
    );

    for (const listener of mockAppStateListeners) listener('background');
    for (const listener of mockAppStateListeners) listener('active');
    jest.advanceTimersByTime(7000);
    for (const listener of [...mockLinkListeners]) listener({url: callback});

    await expect(session).resolves.toEqual({type: 'success', url: callback});
    expect(androidAuthSessionOwnsCallback(callback)).toBe(true);
  });
});
