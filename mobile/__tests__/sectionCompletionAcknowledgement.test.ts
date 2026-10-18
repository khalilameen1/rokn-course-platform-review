import AsyncStorage from '@react-native-async-storage/async-storage';

let mockBoundary = {scope: 'user-completion-1', epoch: 1};
jest.mock('../src/constants/api', () => ({publicRequest: {post: jest.fn()}}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockBoundary}),
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
  getCurrentAccountStorageScope: async () => mockBoundary.scope,
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${key}:${boundary.scope}`,
}));
jest.mock('../src/services/roknApi', () => ({hasSession: async () => true}));

import {publicRequest} from '../src/constants/api';
import {
  markSectionComplete,
  resetSectionCompletionRuntime,
  retryPendingSectionCompletions,
} from '../src/services/sectionCompletionOutbox';
import {resetDurableOutboxForTests} from '../src/services/durableOutbox';

const mockPost = publicRequest.post as jest.Mock;
const rawSet = jest.mocked(AsyncStorage.setItem).getMockImplementation()!;
const rawRemove = jest.mocked(AsyncStorage.removeItem).getMockImplementation()!;
const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};
const tick = async () => {
  for (let i = 0; i < 50; i++) await Promise.resolve();
};
/** The v1 per-section record; still seeded to prove first-read migration. */
const pendingKey = () =>
  `@rokn/section-completion/v1:${mockBoundary.scope}:31:72`;
const outboxKey = () => `@rokn/section-completion/v2:${mockBoundary.scope}`;
const outboxPayloads = async () =>
  JSON.parse((await AsyncStorage.getItem(outboxKey())) || '[]').map(
    (item: {payload: unknown}) => item.payload,
  );
const playerKey = () => `@rokn/course-player/v3:${mockBoundary.scope}`;
let ownerSequence = 0;

describe('authoritative completion delivery with optional cache repair', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    await AsyncStorage.clear();
    mockBoundary = {
      scope: `user-completion-${++ownerSequence}`,
      epoch: ownerSequence,
    };
    resetSectionCompletionRuntime();
    resetDurableOutboxForTests();
    mockPost.mockReset().mockResolvedValue({});
  });
  afterEach(async () => {
    await tick();
    jest.restoreAllMocks();
  });

  it('returns confirmed completion while the real player-cache writer is pending', async () => {
    const write = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementationOnce((key, value) =>
        write.promise.then(() => rawSet(key, value)),
      );
    let delivered = false;
    const completed = markSectionComplete('31', '72').then(value => {
      delivered = value;
      return value;
    });
    try {
      await tick();
      expect(mockPost).toHaveBeenCalledWith('courses/31/sections/72/complete');
      expect(AsyncStorage.setItem).toHaveBeenCalledWith(
        playerKey(),
        expect.any(String),
      );
      expect(delivered).toBe(true);
      await expect(completed).resolves.toBe(true);
    } finally {
      write.resolve();
      await tick();
    }
    expect(
      JSON.parse((await AsyncStorage.getItem(playerKey()))!).completedSections,
    ).toContain('72');
  });

  it('does not delay acknowledgement or replay a confirmed journal record while its native removal is pending', async () => {
    await AsyncStorage.setItem(
      pendingKey(),
      JSON.stringify({courseId: '31', sectionId: '72'}),
    );
    const cleanup = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'removeItem')
      .mockImplementationOnce(key =>
        cleanup.promise.then(() => rawRemove(key)),
      );
    let retrySettled = false;
    try {
      await expect(markSectionComplete('31', '72')).resolves.toBe(true);
      await tick();
      expect(AsyncStorage.removeItem).toHaveBeenCalledWith(pendingKey());
      const retry = retryPendingSectionCompletions().then(() => {
        retrySettled = true;
      });
      await tick();
      expect(retrySettled).toBe(false);
      expect(mockPost).toHaveBeenCalledTimes(1);
      cleanup.resolve();
      await retry;
      expect(mockPost).toHaveBeenCalledTimes(1);
      expect(await AsyncStorage.getItem(pendingKey())).toBeNull();
      expect(await outboxPayloads()).toEqual([]);
    } finally {
      cleanup.resolve();
      await tick();
    }
  });

  it('migrates a v1 record on first retry and replays it once', async () => {
    await AsyncStorage.setItem(
      pendingKey(),
      JSON.stringify({courseId: '31', sectionId: '72'}),
    );
    await AsyncStorage.setItem(
      `@rokn/section-completion/v1:${mockBoundary.scope}:31:73`,
      JSON.stringify({courseId: '31', sectionId: '73'}),
    );
    mockPost
      .mockResolvedValueOnce({})
      .mockRejectedValueOnce({response: {status: 503}});

    await retryPendingSectionCompletions();

    expect(mockPost.mock.calls.map(call => call[0]).sort()).toEqual([
      'courses/31/sections/72/complete',
      'courses/31/sections/73/complete',
    ]);
    expect(await AsyncStorage.getItem(pendingKey())).toBeNull();
    const left = JSON.parse((await AsyncStorage.getItem(outboxKey()))!);
    expect(left).toHaveLength(1);
    expect(left[0].attempts).toBe(1);
  });

  it('still requires durable retry recording when the server has not acknowledged completion', async () => {
    mockPost.mockRejectedValueOnce({response: {status: 503}});
    const write = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementationOnce((key, value) =>
        write.promise.then(() => rawSet(key, value)),
      );
    let settled = false;
    const completed = markSectionComplete('31', '72').then(value => {
      settled = true;
      return value;
    });
    try {
      await tick();
      expect(settled).toBe(false);
      expect(AsyncStorage.setItem).toHaveBeenCalledWith(
        outboxKey(),
        expect.stringContaining('"payload":{"courseId":"31","sectionId":"72"}'),
      );
      write.resolve();
      await expect(completed).resolves.toBe(false);
      expect(await AsyncStorage.getItem(playerKey())).toBeNull();
      expect(await outboxPayloads()).toEqual([
        {courseId: '31', sectionId: '72'},
      ]);
    } finally {
      write.resolve();
      await tick();
    }
  });

  it('keeps a new durable retry record after old native cleanup crosses a runtime reset', async () => {
    await AsyncStorage.setItem(
      pendingKey(),
      JSON.stringify({courseId: '31', sectionId: '72'}),
    );
    const cleanup = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'removeItem')
      .mockImplementationOnce(key =>
        cleanup.promise.then(() => rawRemove(key)),
      );
    try {
      await expect(markSectionComplete('31', '72')).resolves.toBe(true);
      await tick();
      resetSectionCompletionRuntime();
      mockPost.mockRejectedValueOnce({response: {status: 503}});
      let settled = false;
      const fresh = markSectionComplete('31', '72').then(value => {
        settled = true;
        return value;
      });
      await tick();
      expect(settled).toBe(false);
      expect(mockPost).toHaveBeenCalledTimes(2);
      cleanup.resolve();
      await expect(fresh).resolves.toBe(false);
      expect(await outboxPayloads()).toEqual([
        {courseId: '31', sectionId: '72'},
      ]);
      expect(await AsyncStorage.getItem(pendingKey())).toBeNull();
    } finally {
      cleanup.resolve();
      await tick();
    }
  });
});
