import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('expo-crypto', () => ({
  randomUUID: jest.fn(() => '11111111-1111-4111-8111-111111111111'),
  digestStringAsync: jest.fn(async () => 'a'.repeat(64)),
  CryptoDigestAlgorithm: {SHA256: 'SHA-256'},
}));

import {clearAccountScopedStorage} from '../src/constants/helpers';

describe('account-scoped storage cleanup', () => {
  beforeEach(async () => {
    await AsyncStorage.clear();
  });

  it('removes every key owned by the logging-out account only', async () => {
    await AsyncStorage.multiSet([
      ['@rokn/guest-storage-id/v1', 'guest-device'],
      ['@rokn/course-player/v3:account-alpha', '{}'],
      ['@rokn/catalogue/v1:account-alpha:2', '[]'],
      ['@rokn/course-player/v3:account-beta', '{}'],
      ['@rokn/push-token-invalidation-pending/v1', 'true'],
    ]);

    const removed = await clearAccountScopedStorage('account-alpha');

    expect(removed.sort()).toEqual([
      '@rokn/catalogue/v1:account-alpha:2',
      '@rokn/course-player/v3:account-alpha',
    ]);
    expect(await AsyncStorage.getItem('@rokn/guest-storage-id/v1')).toBe(
      'guest-device',
    );
    expect(
      await AsyncStorage.getItem('@rokn/course-player/v3:account-beta'),
    ).toBe('{}');
    expect(
      await AsyncStorage.getItem('@rokn/push-token-invalidation-pending/v1'),
    ).toBe('true');
  });

  it('rejects an empty or unsafe scope instead of deleting broad data', async () => {
    await expect(clearAccountScopedStorage('')).rejects.toThrow(
      'INVALID_ACCOUNT_STORAGE_SCOPE',
    );
    await expect(clearAccountScopedStorage('../')).rejects.toThrow(
      'INVALID_ACCOUNT_STORAGE_SCOPE',
    );
  });
});
