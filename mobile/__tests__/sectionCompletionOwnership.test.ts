jest.mock('../src/constants/api', () => ({
  publicRequest: {post: jest.fn()},
}));

let mockActiveBoundary = {epoch: 1, scope: 'user-a'};
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockActiveBoundary.epoch) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  captureAccountSessionBoundary: jest.fn(async () => ({...mockActiveBoundary})),
  getCurrentAccountStorageScope: jest.fn(async () => mockActiveBoundary.scope),
}));

jest.mock('../src/services/roknApi', () => ({
  hasSession: jest.fn(async () => true),
}));

jest.mock('../src/services/productFeatures', () => ({
  requireProductFeature: jest.fn(async () => undefined),
}));

jest.mock('../src/components/VideoPlayer/courseLearning/persistence', () => ({
  isWatchHistoryEnabled: jest.fn(async () => true),
  updatePlayerState: jest.fn(async () => undefined),
  updatePlayerStateForScope: jest.fn(async () => undefined),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../src/constants/api';
import {captureAccountSessionBoundary} from '../src/constants/helpers';
import {
  flushPendingPlaybackPositions,
  markSectionComplete,
  reportPlaybackSessionEvent,
  resetPlaybackRuntimeState,
  retryPendingSectionCompletions,
  savePlaybackPosition,
} from '../src/components/VideoPlayer/courseLearning/playback';
import {updatePlayerStateForScope} from '../src/components/VideoPlayer/courseLearning/persistence';

const apiPost = publicRequest.post as jest.MockedFunction<
  typeof publicRequest.post
>;
const scopedUpdate = updatePlayerStateForScope as jest.MockedFunction<
  typeof updatePlayerStateForScope
>;
const captureBoundary = captureAccountSessionBoundary as jest.MockedFunction<
  typeof captureAccountSessionBoundary
>;

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};

const settleMicrotasks = async (turns = 20) => {
  for (let index = 0; index < turns; index += 1) {
    await Promise.resolve();
  }
};

describe('section completion ownership', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockActiveBoundary = {epoch: 1, scope: 'user-a'};
    captureBoundary.mockReset().mockImplementation(async () => ({
      ...mockActiveBoundary,
    }));
    resetPlaybackRuntimeState();
  });

  it('coalesces duplicate completion actions for one account and section', async () => {
    const request = deferred<unknown>();
    apiPost.mockReturnValue(request.promise as never);

    const first = markSectionComplete('31', '72');
    const second = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();

    expect(apiPost).toHaveBeenCalledTimes(1);
    request.resolve({});
    await expect(Promise.all([first, second])).resolves.toEqual([true, true]);
    expect(scopedUpdate).toHaveBeenCalledTimes(1);
    expect(scopedUpdate).toHaveBeenCalledWith('user-a', expect.any(Function), {
      epoch: 1,
      scope: 'user-a',
    });
  });

  it('does not let an old response complete or block the new account', async () => {
    const oldRequest = deferred<unknown>();
    const newRequest = deferred<unknown>();
    apiPost
      .mockReturnValueOnce(oldRequest.promise as never)
      .mockReturnValueOnce(newRequest.promise as never);

    const oldCompletion = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();
    mockActiveBoundary = {epoch: 2, scope: 'user-b'};
    const newCompletion = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();

    expect(apiPost).toHaveBeenCalledTimes(2);
    newRequest.resolve({});
    await expect(newCompletion).resolves.toBe(true);
    oldRequest.resolve({});
    await expect(oldCompletion).resolves.toBe(false);
    expect(scopedUpdate).toHaveBeenCalledTimes(1);
    expect(scopedUpdate).toHaveBeenCalledWith('user-b', expect.any(Function), {
      epoch: 2,
      scope: 'user-b',
    });
    expect(
      (await AsyncStorage.getAllKeys()).some(key =>
        key.startsWith('@rokn/section-completion/v1:user-a:'),
      ),
    ).toBe(false);
  });

  it('shares one flight with durable retry instead of posting twice', async () => {
    await AsyncStorage.setItem(
      '@rokn/section-completion/v1:user-a:31:72',
      JSON.stringify({courseId: '31', sectionId: '72'}),
    );
    const request = deferred<unknown>();
    apiPost.mockReturnValue(request.promise as never);

    const direct = markSectionComplete('31', '72');
    await Promise.resolve();
    await Promise.resolve();
    const retry = retryPendingSectionCompletions();
    await Promise.resolve();
    await Promise.resolve();

    expect(apiPost).toHaveBeenCalledTimes(1);
    request.resolve({});
    await expect(Promise.all([direct, retry])).resolves.toEqual([
      true,
      undefined,
    ]);
    expect(scopedUpdate).toHaveBeenCalledTimes(1);
  });

  it('replays a durable completion with the account that owns the record', async () => {
    await AsyncStorage.setItem(
      '@rokn/section-completion/v1:user-a:31:72',
      JSON.stringify({courseId: '31', sectionId: '72'}),
    );
    captureBoundary
      .mockResolvedValueOnce({epoch: 1, scope: 'user-a'})
      .mockImplementationOnce(async () => {
        mockActiveBoundary = {epoch: 2, scope: 'user-b'};
        return {...mockActiveBoundary};
      });
    apiPost.mockResolvedValue({} as never);

    await retryPendingSectionCompletions();

    expect(captureBoundary).toHaveBeenCalledTimes(1);
    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(scopedUpdate).toHaveBeenCalledWith('user-a', expect.any(Function), {
      epoch: 1,
      scope: 'user-a',
    });
  });

  it('keeps playback sequence and terminal state isolated by account', async () => {
    apiPost.mockResolvedValue({} as never);

    await reportPlaybackSessionEvent({
      lessonId: '72',
      playbackSessionId: 'shared-session-id',
      eventType: 'heartbeat',
      positionSeconds: 10,
    });
    mockActiveBoundary = {epoch: 2, scope: 'user-b'};
    await reportPlaybackSessionEvent({
      lessonId: '72',
      playbackSessionId: 'shared-session-id',
      eventType: 'heartbeat',
      positionSeconds: 20,
    });

    expect(apiPost).toHaveBeenNthCalledWith(
      1,
      'user/watch-history',
      expect.objectContaining({sequence: 1, position_seconds: 10}),
    );
    expect(apiPost).toHaveBeenNthCalledWith(
      2,
      'user/watch-history',
      expect.objectContaining({sequence: 1, position_seconds: 20}),
    );
  });

  it('does not send a heartbeat after the same session has stopped', async () => {
    const stopRequest = deferred<unknown>();
    apiPost.mockReturnValue(stopRequest.promise as never);

    const stop = reportPlaybackSessionEvent({
      lessonId: '72',
      playbackSessionId: 'session-1',
      eventType: 'stop',
      endReason: 'user_exit',
      positionSeconds: 40,
    });
    await Promise.resolve();
    await Promise.resolve();
    const lateHeartbeat = reportPlaybackSessionEvent({
      lessonId: '72',
      playbackSessionId: 'session-1',
      eventType: 'heartbeat',
      positionSeconds: 39,
    });
    await Promise.resolve();
    await Promise.resolve();

    expect(apiPost).toHaveBeenCalledTimes(1);
    await expect(lateHeartbeat).resolves.toBe(true);
    stopRequest.resolve({});
    await expect(stop).resolves.toBe(true);
  });

  it('does not let an old settled flight erase a newer progress flight', async () => {
    const oldRequest = deferred<unknown>();
    const newRequest = deferred<unknown>();
    apiPost
      .mockReturnValueOnce(oldRequest.promise as never)
      .mockReturnValueOnce(newRequest.promise as never);

    const oldSave = savePlaybackPosition('course-1', 'reel-1', 12, '72', 60);
    await settleMicrotasks();
    expect(apiPost).toHaveBeenCalledTimes(1);

    resetPlaybackRuntimeState();
    const newSave = savePlaybackPosition('course-1', 'reel-1', 24, '72', 60);
    await settleMicrotasks();
    expect(apiPost).toHaveBeenCalledTimes(2);

    oldRequest.resolve({});
    await oldSave;
    const flush = flushPendingPlaybackPositions();
    await settleMicrotasks();
    expect(apiPost).toHaveBeenCalledTimes(2);

    newRequest.resolve({});
    await expect(Promise.all([newSave, flush])).resolves.toEqual([
      undefined,
      undefined,
    ]);
  });

  it('serializes native evidence writes across a runtime reset', async () => {
    const storageSet = AsyncStorage.setItem as jest.MockedFunction<
      typeof AsyncStorage.setItem
    >;
    const writeImplementation = storageSet.getMockImplementation();
    const oldWrite = deferred<void>();
    storageSet.mockImplementationOnce(async (key, value) => {
      await oldWrite.promise;
      return writeImplementation?.(key, value);
    });
    apiPost.mockResolvedValue({} as never);

    const oldSave = savePlaybackPosition('course-1', 'reel-1', 12, '72', 60);
    await settleMicrotasks();
    expect(storageSet).toHaveBeenCalledTimes(1);

    resetPlaybackRuntimeState();
    const newSave = savePlaybackPosition('course-1', 'reel-1', 24, '72', 60);
    await settleMicrotasks();
    expect(storageSet).toHaveBeenCalledTimes(1);

    oldWrite.resolve();
    await expect(oldSave).rejects.toThrow('ACCOUNT_SESSION_CHANGED');
    await expect(newSave).resolves.toBeUndefined();
    expect(storageSet).toHaveBeenCalledTimes(2);
    expect(apiPost).toHaveBeenCalledTimes(1);
    expect(apiPost).toHaveBeenCalledWith(
      'user/watch-history',
      expect.objectContaining({position_seconds: 24}),
    );
  });
});
