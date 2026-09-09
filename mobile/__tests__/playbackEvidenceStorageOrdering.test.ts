jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));

let mockBoundary = {epoch: 1, scope: 'user-a'};
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {epoch: number; scope: string}) => {
    if (
      boundary.epoch !== mockBoundary.epoch ||
      boundary.scope !== mockBoundary.scope
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  captureAccountSessionBoundary: jest.fn(async () => ({...mockBoundary})),
}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
}));
jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  isWatchHistoryEnabled: jest.fn(async () => true),
  updatePlayerStateForScope: jest.fn(async () => undefined),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../src/constants/api';
import {
  resetPlaybackRuntimeState,
  retryPendingPlaybackPositions,
  savePlaybackPosition,
} from '../src/components/VideoPlayer/courseLearning/playback';

const key = '@rokn/watch-evidence/v1:user-a:72';
const originalRemove = jest
  .mocked(AsyncStorage.removeItem)
  .getMockImplementation()!;
const originalMultiGet = jest
  .mocked(AsyncStorage.multiGet)
  .getMockImplementation()!;
const originalGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const apiPost = jest.mocked(publicRequest.post);
const ticks = async () => {
  for (let index = 0; index < 40; index++) await Promise.resolve();
};
const deferred = () => {
  let resolve!: () => void;
  const promise = new Promise<void>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('durable playback evidence acknowledgement ordering', () => {
  beforeEach(async () => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    jest.mocked(AsyncStorage.removeItem).mockImplementation(originalRemove);
    jest.mocked(AsyncStorage.multiGet).mockImplementation(originalMultiGet);
    jest.mocked(AsyncStorage.getItem).mockImplementation(originalGet);
    jest.mocked(AsyncStorage.setItem).mockImplementation(originalSet);
    await AsyncStorage.clear();
    mockBoundary = {epoch: 1, scope: 'user-a'};
    resetPlaybackRuntimeState();
    apiPost.mockReset().mockResolvedValue({} as never);
  });

  afterEach(() => {
    resetPlaybackRuntimeState();
    jest.useRealTimers();
  });

  it.each([false, true])(
    'keeps newer evidence when accepted cleanup finishes after another save (relogin: %s)',
    async relogin => {
      const cleanup = deferred();
      let cleanupStarted = false;
      jest
        .mocked(AsyncStorage.removeItem)
        .mockImplementation(async storageKey => {
          if (storageKey === key && !cleanupStarted) {
            cleanupStarted = true;
            await cleanup.promise;
          }
          return originalRemove(storageKey);
        });
      let saving: Promise<void> | undefined;
      try {
        // The first sample is accepted. Disk cleanup must not delay its caller.
        await savePlaybackPosition('31', 'reel-72', 12, '72', 60);
        expect(cleanupStarted).toBe(true);
        expect(apiPost).toHaveBeenCalledTimes(1);
        if (relogin) {
          resetPlaybackRuntimeState();
          mockBoundary = {epoch: 2, scope: 'user-a'};
          apiPost.mockRejectedValue(new Error('offline'));
        }
        saving = savePlaybackPosition('31', 'reel-72', 24, '72', 60);
        await ticks();
        cleanup.resolve();
        await saving;
        await ticks();

        expect(JSON.parse((await AsyncStorage.getItem(key)) || 'null')).toEqual(
          expect.objectContaining({positionSeconds: 24}),
        );
        // A runtime restart must still recover the latest unaccepted sample.
        resetPlaybackRuntimeState();
        apiPost.mockClear().mockResolvedValue({} as never);
        await retryPendingPlaybackPositions();
        expect(apiPost).toHaveBeenCalledTimes(1);
        expect(apiPost).toHaveBeenCalledWith(
          'user/watch-history',
          expect.objectContaining({lesson_id: 72, position_seconds: 24}),
        );
      } finally {
        cleanup.resolve();
        await saving;
        await ticks();
      }
    },
  );

  it('does not restore an acknowledged sample from a hydration snapshot taken before its ACK', async () => {
    const response = deferred();
    apiPost.mockImplementation(async () => {
      await response.promise;
      return {} as never;
    });
    const saving = savePlaybackPosition('31', 'reel-72', 12, '72', 60);
    await ticks();
    expect(apiPost).toHaveBeenCalledTimes(1);
    const read = deferred();
    let reading = false;
    jest.mocked(AsyncStorage.getItem).mockImplementation(async storageKey => {
      const snapshot = await originalGet(storageKey);
      if (storageKey === key && !reading) {
        reading = true;
        await read.promise;
      }
      return snapshot;
    });
    jest.mocked(AsyncStorage.multiGet).mockImplementationOnce(async keys => {
      const snapshot = await originalMultiGet(keys);
      await read.promise;
      return snapshot;
    });
    const retrying = retryPendingPlaybackPositions();
    try {
      await ticks();
      response.resolve();
      await saving;
      await ticks();
      read.resolve();
      await retrying;
      expect(apiPost).toHaveBeenCalledTimes(1);
    } finally {
      response.resolve();
      read.resolve();
      await Promise.all([saving, retrying]);
    }
  });

  it('does not delete a newer native write that was already running when the older sample was accepted', async () => {
    const response = deferred();
    const write = deferred();
    let writing = false;
    apiPost.mockImplementationOnce(async () => {
      await response.promise;
      return {} as never;
    });
    const first = savePlaybackPosition('31', 'reel-72', 12, '72', 60);
    await ticks();
    jest
      .mocked(AsyncStorage.setItem)
      .mockImplementation(async (storageKey, raw) => {
        if (storageKey === key && JSON.parse(raw).positionSeconds === 24) {
          writing = true;
          await write.promise;
        }
        return originalSet(storageKey, raw);
      });
    const second = savePlaybackPosition('31', 'reel-72', 24, '72', 60);
    try {
      await ticks();
      expect(writing).toBe(true);
      response.resolve();
      await first;
      write.resolve();
      await second;
      await ticks();
      expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual(
        expect.objectContaining({positionSeconds: 24}),
      );
      expect(apiPost).toHaveBeenCalledTimes(1);
      resetPlaybackRuntimeState();
      await retryPendingPlaybackPositions();
      expect(apiPost).toHaveBeenLastCalledWith(
        'user/watch-history',
        expect.objectContaining({position_seconds: 24}),
      );
    } finally {
      response.resolve();
      write.resolve();
      await Promise.all([first, second]);
      await ticks();
    }
  });

  it('keeps an accepted sample successful when ancillary cleanup fails and allows the next save', async () => {
    jest
      .mocked(AsyncStorage.removeItem)
      .mockRejectedValueOnce(new Error('disk unavailable'));
    await expect(
      savePlaybackPosition('31', 'reel-72', 12, '72', 60),
    ).resolves.toBeUndefined();
    await ticks();
    jest.advanceTimersByTime(30_000);
    await ticks();
    expect(apiPost).toHaveBeenCalledTimes(1);
    await savePlaybackPosition('31', 'reel-72', 24, '72', 60);
    expect(apiPost).toHaveBeenLastCalledWith(
      'user/watch-history',
      expect.objectContaining({position_seconds: 24}),
    );
    await ticks();
    expect(await AsyncStorage.getItem(key)).toBeNull();
  });

  it('keeps another account independent from a pending accepted cleanup', async () => {
    const cleanup = deferred();
    let cleaning = false;
    jest
      .mocked(AsyncStorage.removeItem)
      .mockImplementation(async storageKey => {
        if (storageKey === key) {
          cleaning = true;
          await cleanup.promise;
        }
        return originalRemove(storageKey);
      });
    try {
      await savePlaybackPosition('31', 'reel-72', 12, '72', 60);
      expect(cleaning).toBe(true);
      resetPlaybackRuntimeState();
      mockBoundary = {epoch: 2, scope: 'user-b'};
      apiPost.mockRejectedValue(new Error('offline'));
      await savePlaybackPosition('31', 'reel-72', 36, '72', 60);
      expect(apiPost).toHaveBeenCalledTimes(2);
      cleanup.resolve();
      await ticks();
      expect(
        JSON.parse(
          (await AsyncStorage.getItem('@rokn/watch-evidence/v1:user-b:72'))!,
        ),
      ).toEqual(expect.objectContaining({positionSeconds: 36}));
      expect(await AsyncStorage.getItem(key)).toBeNull();
    } finally {
      cleanup.resolve();
      await ticks();
    }
  });

  it('retains an unaccepted sample and its sequence after a failed send for exact retry', async () => {
    apiPost.mockRejectedValueOnce(new Error('offline'));
    await savePlaybackPosition('31', 'reel-72', 12, '72', 60, false, {
      playbackSessionId: 'session-72',
    });
    const sent = apiPost.mock.calls[0][1];
    resetPlaybackRuntimeState();
    await retryPendingPlaybackPositions();
    expect(apiPost).toHaveBeenCalledTimes(2);
    expect(apiPost.mock.calls[1][1]).toEqual(sent);
    await ticks();
    expect(await AsyncStorage.getItem(key)).toBeNull();
  });

  it('does not delete unreadable evidence or send an invented empty sample', async () => {
    const pending = {lessonId: 72, positionSeconds: 12, completed: false};
    await AsyncStorage.setItem(key, JSON.stringify(pending));
    jest
      .mocked(AsyncStorage.getItem)
      .mockRejectedValueOnce(new Error('disk unavailable'));
    await retryPendingPlaybackPositions();
    expect(apiPost).not.toHaveBeenCalled();
    expect(AsyncStorage.removeItem).not.toHaveBeenCalled();
    expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual(pending);
    await retryPendingPlaybackPositions();
    expect(apiPost).toHaveBeenCalledTimes(1);
    await ticks();
  });

  it('orders malformed-record cleanup before a new valid sample on the same key', async () => {
    await AsyncStorage.setItem(key, 'broken-json');
    const cleanup = deferred();
    let cleaning = false;
    jest
      .mocked(AsyncStorage.removeItem)
      .mockImplementation(async storageKey => {
        if (storageKey === key && !cleaning) {
          cleaning = true;
          await cleanup.promise;
        }
        return originalRemove(storageKey);
      });
    const retrying = retryPendingPlaybackPositions();
    let saving: Promise<void> | undefined;
    try {
      await ticks();
      expect(cleaning).toBe(true);
      apiPost.mockRejectedValue(new Error('offline'));
      saving = savePlaybackPosition('31', 'reel-72', 24, '72', 60);
      await ticks();
      cleanup.resolve();
      await Promise.all([retrying, saving]);
      expect(JSON.parse((await AsyncStorage.getItem(key))!)).toEqual(
        expect.objectContaining({positionSeconds: 24}),
      );
    } finally {
      cleanup.resolve();
      await Promise.all([retrying, saving]);
      await ticks();
    }
  });
});
