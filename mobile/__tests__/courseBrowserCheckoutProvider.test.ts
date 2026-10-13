const mockOpenBrowser = jest.fn();
const mockOpenUrl = jest.fn();
const mockStateRemove = jest.fn();
const mockLinkRemove = jest.fn();
let mockStateHandler: (state: string) => void;
let mockLinkHandler: (event: {url: string}) => void;
jest.mock('react-native', () => ({
  Platform: {OS: 'android'},
  NativeModules: {
    RoknCheckout: {
      openBrowser: (...args: unknown[]) => mockOpenBrowser(...args),
    },
  },
  AppState: {
    addEventListener: (_: string, handler: typeof mockStateHandler) => {
      mockStateHandler = handler;
      return {remove: mockStateRemove};
    },
  },
  Linking: {
    openURL: (...args: unknown[]) => mockOpenUrl(...args),
    addEventListener: (_: string, handler: typeof mockLinkHandler) => {
      mockLinkHandler = handler;
      return {remove: mockLinkRemove};
    },
  },
}));
jest.mock('expo-web-browser', () => ({openAuthSessionAsync: jest.fn()}));
jest.mock('../src/constants/apiBaseUrl', () => ({
  roknApiUrl: 'https://rokn.app/api/v1/',
}));
jest.mock('../src/constants/distribution', () => ({
  DISTRIBUTION_CHANNEL: 'play',
}));
import {openCourseBrowserCheckoutSurface} from '../src/services/coinCheckoutProvider';
import {Platform} from 'react-native';
import {openAuthSessionAsync} from 'expo-web-browser';

const url = `https://rokn.app/course-payment/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa?order=12&expires=1900000000&signature=${'a'.repeat(
  64,
)}`;

beforeEach(() => {
  jest.clearAllMocks();
  Platform.OS = 'android';
  mockOpenBrowser.mockReset().mockResolvedValue(undefined);
  mockOpenUrl.mockReset().mockResolvedValue(undefined);
});

it.each(['android', 'ios'] as const)(
  'opens an external browser on %s and reconciles manual return without cancellation',
  async platform => {
    Platform.OS = platform;
    const pending = openCourseBrowserCheckoutSurface(url);
    expect(
      platform === 'android' ? mockOpenBrowser : mockOpenUrl,
    ).toHaveBeenCalledWith(url);
    expect(
      platform === 'android' ? mockOpenUrl : mockOpenBrowser,
    ).not.toHaveBeenCalled();
    expect(openAuthSessionAsync).not.toHaveBeenCalled();
    mockStateHandler('background');
    mockStateHandler('active');
    await expect(pending).resolves.toBe('');
    expect(mockLinkRemove).toHaveBeenCalledTimes(1);
    expect(mockStateRemove).toHaveBeenCalledTimes(1);
  },
);

it.each(['android', 'ios'] as const)(
  'accepts a callback on %s but ignores unrelated app links',
  async platform => {
    Platform.OS = platform;
    const pending = openCourseBrowserCheckoutSurface(url);
    mockLinkHandler({url: 'rokn://course/5'});
    expect(mockLinkRemove).not.toHaveBeenCalled();
    const callback =
      'rokn://payment-result?status=success&order_ref=PKG-12345678&coins=350';
    mockLinkHandler({url: callback});
    await expect(pending).resolves.toBe(callback);
  },
);

it.each(['android', 'ios'] as const)(
  'cleans up when launching the %s browser fails',
  async platform => {
    Platform.OS = platform;
    (platform === 'android' ? mockOpenBrowser : mockOpenUrl).mockRejectedValue(
      new Error('no browser'),
    );
    await expect(openCourseBrowserCheckoutSurface(url)).rejects.toThrow(
      'no browser',
    );
    expect(mockLinkRemove).toHaveBeenCalledTimes(1);
  },
);

it.each(['android', 'ios'] as const)(
  'never launches a third-party or unsigned payment URL on %s',
  async platform => {
    Platform.OS = platform;
    await expect(
      openCourseBrowserCheckoutSurface('https://evil.example/'),
    ).rejects.toThrow('PAYMENT_URL_INVALID');
    expect(mockOpenBrowser).not.toHaveBeenCalled();
    expect(mockOpenUrl).not.toHaveBeenCalled();
  },
);
