import AsyncStorage from '@react-native-async-storage/async-storage';

jest.mock('../src/constants/api', () => ({
  publicRequest: {get: jest.fn()},
}));

import {publicRequest} from '../src/constants/api';
import {
  bootstrapProductFeatures,
  isProductFeatureEnabled,
  productFeatureSnapshotStorageKey,
  refreshProductFeatures,
  resetProductFeaturesForTests,
} from '../src/services/productFeatures';

const mockGet = publicRequest.get as jest.Mock;
const originalGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const tick = async () => {
  for (let i = 0; i < 30; i++) await Promise.resolve();
};
const flags = (enabled: boolean) => ({
  playback: enabled,
  checkout: enabled,
  project_uploads: enabled,
  ai_chat: enabled,
});
const remote = (version: string, enabled: boolean) => ({
  data: {
    data: {
      version,
      expires_at: new Date(Date.now() + 60_000).toISOString(),
      flags: flags(enabled),
    },
  },
});
const cache = (version: string, enabled: boolean, ttl = 60_000) =>
  JSON.stringify({
    version,
    retrievedAt: Date.now(),
    expiresAt: Date.now() + ttl,
    flags: flags(enabled),
  });

describe('product capability delivery is independent of optional persistence', () => {
  beforeEach(async () => {
    jest
      .mocked(AsyncStorage.getItem)
      .mockReset()
      .mockImplementation(originalGet);
    jest
      .mocked(AsyncStorage.setItem)
      .mockReset()
      .mockImplementation(originalSet);
    await AsyncStorage.clear();
    mockGet.mockReset();
    resetProductFeaturesForTests();
  });
  afterEach(() => {
    jest.useRealTimers();
    jest.restoreAllMocks();
  });

  it('shares both cold-start reads across all capabilities and bootstrap', async () => {
    const localRead = deferred<string | null>();
    const response = deferred<ReturnType<typeof remote>>();
    jest.mocked(AsyncStorage.getItem).mockReturnValueOnce(localRead.promise);
    mockGet.mockReturnValueOnce(response.promise);
    const enabled = Promise.all([
      isProductFeatureEnabled('playback'),
      isProductFeatureEnabled('checkout'),
      isProductFeatureEnabled('project_uploads'),
      isProductFeatureEnabled('ai_chat'),
    ]);
    const bootstrapped = bootstrapProductFeatures();
    await tick();
    expect(AsyncStorage.getItem).toHaveBeenCalledTimes(1);
    expect(mockGet).toHaveBeenCalledTimes(1);
    response.resolve(remote('live', true));
    await expect(enabled).resolves.toEqual([true, true, true, true]);
    await bootstrapped;
    localRead.resolve(null);
    await tick();
  });

  it('uses fresh cached flags while the remote refresh continues independently', async () => {
    await AsyncStorage.setItem(
      productFeatureSnapshotStorageKey,
      cache('cached', true),
    );
    const response = deferred<ReturnType<typeof remote>>();
    mockGet.mockReturnValueOnce(response.promise);
    await expect(isProductFeatureEnabled('checkout')).resolves.toBe(true);
    expect(mockGet).toHaveBeenCalledTimes(1);
    response.resolve(remote('disabled-remotely', false));
    await tick();
    await expect(isProductFeatureEnabled('checkout')).resolves.toBe(false);
  });

  it('does not authorize mutations with expired cached flags', async () => {
    await AsyncStorage.setItem(
      productFeatureSnapshotStorageKey,
      cache('expired', true, -1),
    );
    const response = deferred<ReturnType<typeof remote>>();
    mockGet.mockReturnValueOnce(response.promise);
    let settled = false;
    const enabled = isProductFeatureEnabled('checkout').then(value => {
      settled = true;
      return value;
    });
    await tick();
    expect(settled).toBe(false);
    response.resolve(remote('denied', false));
    await expect(enabled).resolves.toBe(false);
  });

  it('bounds a stuck cache read when remote flags are unavailable', async () => {
    jest.useFakeTimers();
    const localRead = deferred<string | null>();
    jest.mocked(AsyncStorage.getItem).mockReturnValueOnce(localRead.promise);
    mockGet.mockRejectedValueOnce(new Error('offline'));
    let settled = false;
    const enabled = isProductFeatureEnabled('project_uploads').then(value => {
      settled = true;
      return value;
    });
    await tick();
    expect(settled).toBe(false);
    await jest.advanceTimersByTimeAsync(750);
    const requiresRemoteFlags =
      process.env.EXPO_PUBLIC_REQUIRE_FEATURE_FLAGS === '1' ||
      process.env.EXPO_PUBLIC_BUILD_PROFILE === 'production';
    await expect(enabled).resolves.toBe(!requiresRemoteFlags);
    localRead.resolve(null);
    await tick();
  });

  it('serializes optional writes without delaying newer remote flag delivery', async () => {
    const firstWrite = deferred<void>();
    jest.mocked(AsyncStorage.setItem).mockReturnValueOnce(firstWrite.promise);
    mockGet.mockResolvedValueOnce(remote('first', true));
    await expect(refreshProductFeatures()).resolves.toMatchObject({
      version: 'first',
    });
    await tick();
    mockGet.mockResolvedValueOnce(remote('second', false));
    await expect(refreshProductFeatures()).resolves.toMatchObject({
      version: 'second',
    });
    await expect(isProductFeatureEnabled('checkout')).resolves.toBe(false);
    expect(AsyncStorage.setItem).toHaveBeenCalledTimes(1);
    firstWrite.resolve();
    await tick();
    expect(AsyncStorage.setItem).toHaveBeenCalledTimes(2);
    expect(
      await AsyncStorage.getItem(productFeatureSnapshotStorageKey),
    ).toContain('second');
  });
});
