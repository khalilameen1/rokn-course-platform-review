/* eslint-env jest */

jest.mock('@react-native-async-storage/async-storage', () =>
  require('@react-native-async-storage/async-storage/jest/async-storage-mock'),
);

require('react-native-gesture-handler/jestSetup');

jest.mock('@react-native-community/netinfo', () =>
  require('@react-native-community/netinfo/jest/netinfo-mock'),
);

// react-native-fs ships Flow syntax in its CommonJS entrypoint. Native I/O is
// covered by focused suites; facade/import tests need a deterministic bridge.
jest.mock('react-native-fs', () => ({
  CachesDirectoryPath: '/tmp/rokn-cache',
  DocumentDirectoryPath: '/tmp/rokn-documents',
  MainBundlePath: '/tmp/rokn-bundle',
  copyFile: jest.fn(async () => undefined),
  downloadFile: jest.fn(() => ({
    jobId: 1,
    promise: Promise.resolve({statusCode: 200}),
  })),
  exists: jest.fn(async () => false),
  getFSInfo: jest.fn(async () => ({freeSpace: 1024 * 1024 * 1024})),
  hash: jest.fn(async () => 'a'.repeat(64)),
  mkdir: jest.fn(async () => undefined),
  moveFile: jest.fn(async () => undefined),
  read: jest.fn(async () => ''),
  readDir: jest.fn(async () => []),
  readFile: jest.fn(async () => ''),
  readFileAssets: jest.fn(async () => ''),
  stat: jest.fn(async () => ({isFile: () => true, size: 0})),
  stopDownload: jest.fn(),
  unlink: jest.fn(async () => undefined),
  writeFile: jest.fn(async () => undefined),
}));

jest.mock('expo-secure-store', () => ({
  WHEN_UNLOCKED_THIS_DEVICE_ONLY: 'WHEN_UNLOCKED_THIS_DEVICE_ONLY',
  isAvailableAsync: jest.fn(async () => true),
  getItemAsync: jest.fn(),
  setItemAsync: jest.fn(),
  deleteItemAsync: jest.fn(),
}));

// Expo Crypto is a native ESM module. Contract tests exercise callers rather
// than the platform bridge, so keep one complete deterministic bridge here.
jest.mock('expo-crypto', () => ({
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
  digestStringAsync: jest.fn(async (_algorithm, value) =>
    require('crypto').createHash('sha256').update(String(value)).digest('hex'),
  ),
  getRandomBytesAsync: jest.fn(async length => new Uint8Array(length)),
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
}));

// Native push providers are exercised with scenario-specific factories in the
// push suite. Keep unrelated contract tests independent from unavailable iOS
// and Android runtimes.
jest.mock('expo-notifications', () => ({
  AndroidImportance: {MAX: 5},
  AndroidNotificationVisibility: {PUBLIC: 1},
  addNotificationResponseReceivedListener: jest.fn(() => ({remove: jest.fn()})),
  addPushTokenListener: jest.fn(() => ({remove: jest.fn()})),
  getDevicePushTokenAsync: jest.fn(),
  getLastNotificationResponseAsync: jest.fn(async () => null),
  clearLastNotificationResponseAsync: jest.fn(async () => undefined),
  dismissAllNotificationsAsync: jest.fn(async () => undefined),
  setBadgeCountAsync: jest.fn(async () => true),
  getPermissionsAsync: jest.fn(async () => ({granted: false})),
  requestPermissionsAsync: jest.fn(async () => ({granted: false})),
  setNotificationChannelAsync: jest.fn(),
}));

jest.mock('@react-native-firebase/messaging', () => ({
  deleteToken: jest.fn(async () => undefined),
  getMessaging: jest.fn(() => ({})),
  getToken: jest.fn(async () => ''),
  onTokenRefresh: jest.fn(() => jest.fn()),
  registerDeviceForRemoteMessages: jest.fn(async () => undefined),
}));
