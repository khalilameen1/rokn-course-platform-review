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
jest.mock('../src/services/learningPlayerState', () => ({
  isWatchHistoryEnabled: jest.fn(async () => true),
  updatePlayerStateForScope: jest.fn(async () => undefined),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../src/constants/api';
import {resetDurableOutboxForTests} from '../src/services/durableOutbox';
import {updatePlayerStateForScope} from '../src/services/learningPlayerState';
import {
  reportPlaybackSessionEvent,
  resetPlaybackRuntimeState,
  retryPendingPlaybackPositions,
  savePlaybackPosition,
} from '../src/components/VideoPlayer/courseLearning/playback';

// The durable outbox keeps one record per account; the v1 per-lesson key is
// migrated on first read.
const key = '@rokn/watch-evidence/v2:user-a';
const legacyKey = '@rokn/watch-evidence/v1:user-a:72';
const sampleIn = (raw: string | null, lessonId = 72) =>
  raw === null
    ? null
    : (JSON.parse(raw) as Array<{id: string; payload: unknown}>).find(
        item => item.id === String(lessonId),
      )?.payload ?? null;
const storedSample = async (storageKey = key) =>
  sampleIn(await AsyncStorage.getItem(storageKey));
const seed = (sample: unknown) =>
  AsyncStorage.setItem(
    key,
    JSON.stringify([
      {
        id: '72',
        generation: 1,
        payload: sample,
        createdAt: new Date().toISOString(),
        expiresAt: new Date(Date.now() + 86_400_000).toISOString(),
        attempts: 0,
        nextAttemptAt: new Date().toISOString(),
      },
    ]),
  );
const originalRemove = jest
  .mocked(AsyncStorage.removeItem)
  .getMockImplementation()!;
const originalMultiGet = jest
  .mocked(AsyncStorage.multiGet)
  .getMockImplementation()!;
const originalGet = jest.mocked(AsyncStorage.getItem).getMockImplementation()!;
const originalSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const apiPost = jest.mocked(publicRequest.post);
const originalPlayerUpdate = jest
  .mocked(updatePlayerStateForScope)
  .getMockImplementation()!;
const ticks = async () => {
  for (let index = 0; index < 200; index++) await Promise.resolve();
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
    resetDurableOutboxForTests();
    apiPost.mockReset().mockResolvedValue({} as never);
  });

  afterEach(() => {
    resetPlaybackRuntimeState();
    jest.useRealTimers();
  });

  it('delivers completion before a later stop even while the local resume write is blocked', async () => {
    const resume = deferred();
    jest
      .mocked(updatePlayerStateForScope)
      .mockImplementationOnce(async (...args) => {
        await resume.promise;
        return originalPlayerUpdate(...args);
      });
    const saving = savePlaybackPosition('31', 'reel-72', 60, '72', 60, true, {
      playbackSessionId: 'session-completing',
    });
    await ticks();
    const stopping = reportPlaybackSessionEvent({
      lessonId: '72',
      playbackSessionId: 'session-completing',
      eventType: 'stop',
      positionSeconds: 60,
      durationSeconds: 60,
      endReason: 'lesson_changed',
    });
    await ticks();
    resume.resolve();
    await Promise.all([saving, stopping]);
    expect(apiPost.mock.calls[0]?.[1]).toEqual(
      expect.objectContaining({
        playback_session_id: 'session-completing',
        is_completed: true,
      }),
    );
  });

  it('does not let an offline completion from an old session replace a new session sample', async () => {
    apiPost.mockRejectedValue(new Error('offline'));
    await savePlaybackPosition('31', 'reel-72', 57, '72', 60, true, {
      playbackSessionId: 'old-session',
    });
    apiPost.mockClear().mockResolvedValue({} as never);
    await savePlaybackPosition('31', 'reel-72', 60, '72', 60, true, {
      playbackSessionId: 'new-session',
    });
    expect(apiPost).toHaveBeenCalledWith(
      'user/watch-history',
      expect.objectContaining({
        playback_session_id: 'new-session',
        position_seconds: 60,
        sequence: 1,
      }),
    );
    await retryPendingPlaybackPositions();
    expect(apiPost).toHaveBeenCalledWith(
      'user/watch-history',
      expect.objectContaining({
        playback_session_id: 'old-session',
        position_seconds: 57,
        sequence: 1,
      }),
    );
  });

  it('drains completion behind an in-flight heartbeat before closing the same session', async () => {
    const heartbeatResponse = deferred();
    apiPost.mockImplementationOnce(async () => {
      await heartbeatResponse.promise;
      return {} as never;
    });
    const heartbeat = savePlaybackPosition(
      '31',
      'reel-72',
      30,
      '72',
      60,
      false,
      {
        playbackSessionId: 'active-session',
      },
    );
    await ticks();
    expect(apiPost).toHaveBeenCalledTimes(1);
    const completion = savePlaybackPosition(
      '31',
      'reel-72',
      60,
      '72',
      60,
      true,
      {
        playbackSessionId: 'active-session',
      },
    );
    const stopping = reportPlaybackSessionEvent({
      lessonId: '72',
      playbackSessionId: 'active-session',
      eventType: 'stop',
      positionSeconds: 60,
      durationSeconds: 60,
      endReason: 'lesson_changed',
    });
    await ticks();
    expect(apiPost).toHaveBeenCalledTimes(1);
    heartbeatResponse.resolve();
    await Promise.all([heartbeat, completion, stopping]);
    expect(apiPost).toHaveBeenCalledTimes(2);
    expect(apiPost.mock.calls[1][1]).toEqual(
      expect.objectContaining({
        event_type: 'stop',
        is_completed: true,
        sequence: 3,
        playback_session_id: 'active-session',
        position_seconds: 60,
      }),
    );
  });

  it.each([false, true])(
    'records a rewind and new progress after a completion claim in the same session (offline: %s)',
    async offline => {
      if (offline) apiPost.mockRejectedValue(new Error('offline'));
      const context = {playbackSessionId: 'rewatch-session'};
      await savePlaybackPosition('31', 'reel-72', 57, '72', 60, true, context);
      await savePlaybackPosition('31', 'reel-72', 0, '72', 60, false, context);
      apiPost.mockResolvedValue({} as never);
      await retryPendingPlaybackPositions();
      expect(apiPost).toHaveBeenLastCalledWith(
        'user/watch-history',
        expect.objectContaining({
          playback_session_id: 'rewatch-session',
          position_seconds: 0,
          is_completed: false,
          event_type: 'heartbeat',
          sequence: 2,
        }),
      );
      await savePlaybackPosition('31', 'reel-72', 50, '72', 60, false, context);
      await retryPendingPlaybackPositions();
      expect(apiPost).toHaveBeenLastCalledWith(
        'user/watch-history',
        expect.objectContaining({position_seconds: 50, sequence: 3}),
      );
    },
  );

  it('retries a failed terminal lifecycle event through the same durable evidence path', async () => {
    apiPost.mockRejectedValue(new Error('offline'));
    await expect(
      reportPlaybackSessionEvent({
        lessonId: '72',
        playbackSessionId: 'offline-stop',
        eventType: 'stop',
        positionSeconds: 40,
        durationSeconds: 60,
        endReason: 'user_exit',
      }),
    ).resolves.toBe(false);
    resetPlaybackRuntimeState();
    apiPost.mockClear().mockResolvedValue({} as never);
    await retryPendingPlaybackPositions();
    expect(apiPost).toHaveBeenCalledWith(
      'user/watch-history',
      expect.objectContaining({
        playback_session_id: 'offline-stop',
        event_type: 'stop',
        sequence: 1,
        position_seconds: 40,
      }),
    );
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

        expect(await storedSample()).toEqual(
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
        if (
          storageKey === key &&
          (sampleIn(raw) as {positionSeconds?: number} | null)
            ?.positionSeconds === 24
        ) {
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
      expect(await storedSample()).toEqual(
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
    // The record whose retirement failed keeps its id; at most the same
    // accepted sample is replayed (idempotent), never an invented one.
    apiPost.mock.calls.forEach(call =>
      expect(call[1]).toEqual(expect.objectContaining({position_seconds: 12})),
    );
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
      expect(await storedSample('@rokn/watch-evidence/v2:user-b')).toEqual(
        expect.objectContaining({positionSeconds: 36}),
      );
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
    await seed(pending);
    jest
      .mocked(AsyncStorage.getItem)
      .mockRejectedValueOnce(new Error('disk unavailable'));
    await retryPendingPlaybackPositions();
    expect(apiPost).not.toHaveBeenCalled();
    expect(AsyncStorage.removeItem).not.toHaveBeenCalled();
    expect(await storedSample()).toEqual(pending);
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
      expect(await storedSample()).toEqual(
        expect.objectContaining({positionSeconds: 24}),
      );
    } finally {
      cleanup.resolve();
      await Promise.all([retrying, saving]);
      await ticks();
    }
  });

  it('migrates a v1 per-lesson sample on first read and sends it exactly once', async () => {
    const pending = {
      lessonId: 72,
      positionSeconds: 41,
      durationSeconds: 60,
      completed: false,
      playbackSessionId: 'session-legacy',
      sequence: 7,
      writeOwner: '3:9',
    };
    await AsyncStorage.setItem(legacyKey, JSON.stringify(pending));

    await retryPendingPlaybackPositions();

    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(apiPost).toHaveBeenCalledWith(
      'user/watch-history',
      expect.objectContaining({
        lesson_id: 72,
        position_seconds: 41,
        playback_session_id: 'session-legacy',
        sequence: 7,
      }),
    );
    await ticks();
    expect(await AsyncStorage.getItem(legacyKey)).toBeNull();
    expect(await AsyncStorage.getItem(key)).toBeNull();
  });

  it('keeps a migrated v1 sample when the first send fails', async () => {
    await AsyncStorage.setItem(
      legacyKey,
      JSON.stringify({lessonId: 72, positionSeconds: 41, completed: true}),
    );
    apiPost.mockRejectedValueOnce(new Error('offline'));

    await retryPendingPlaybackPositions();

    expect(await AsyncStorage.getItem(legacyKey)).toBeNull();
    expect(await storedSample()).toEqual(
      expect.objectContaining({positionSeconds: 41, completed: true}),
    );
  });
});
