const mockValues = new Map<string, unknown>();
const mockSave = jest.fn();
const mockRemove = jest.fn();
const mockUpdate = jest.fn();
let mockBoundary = {scope: 'user-7', epoch: 0};

jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (
      boundary.scope !== mockBoundary.scope ||
      boundary.epoch !== mockBoundary.epoch
    )
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async (key: string, boundary: typeof mockBoundary) =>
    `${boundary.scope}:${key}`,
  getItem: async (key: string) => mockValues.get(key) ?? null,
  saveItem: (...args: unknown[]) => mockSave(...args),
  removeItem: (...args: unknown[]) => mockRemove(...args),
}));
jest.mock('../src/services/roknApi', () => ({
  updatePlaybackPreferences: (...args: unknown[]) => mockUpdate(...args),
}));

import {
  flushPlaybackPreferenceWrites,
  PENDING_PLAYBACK_PREFERENCES_KEY,
  readPendingPlaybackPreferences,
  savePlaybackPreferencePatch,
} from '../src/services/playbackPreferenceSync';

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<T>((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
};
const settleScheduledWork = async () => {
  for (let tick = 0; tick < 24; tick += 1) await Promise.resolve();
};
const key = (field: string) => `${mockBoundary.scope}:${field}`;
const pendingKey = () => key(PENDING_PLAYBACK_PREFERENCES_KEY);
const journal = () => mockValues.get(pendingKey());
const write = async (
  patch: Parameters<typeof savePlaybackPreferencePatch>[0],
) => {
  await savePlaybackPreferencePatch(patch, mockBoundary);
  await flushPlaybackPreferenceWrites(mockBoundary);
};

describe('durable playback preferences using the real account write queue', () => {
  beforeEach(() => {
    mockBoundary = {scope: 'user-7', epoch: mockBoundary.epoch + 1};
    mockValues.clear();
    mockSave
      .mockReset()
      .mockImplementation(async (storageKey: string, value: unknown) => {
        mockValues.set(storageKey, value);
        return true;
      });
    mockRemove.mockReset().mockImplementation(async (storageKey: string) => {
      mockValues.delete(storageKey);
      return true;
    });
    mockUpdate.mockReset().mockRejectedValue(new Error('offline'));
  });

  it('keeps quality and speed together while offline instead of accepting an old profile', async () => {
    await write({videoQualityPreference: '720p'});
    await write({playbackSpeed: 1.5});
    expect(journal()).toEqual({
      revision: 2,
      patch: {videoQualityPreference: '720p', playbackSpeed: 1.5},
    });
    expect(await readPendingPlaybackPreferences(mockBoundary)).toEqual({
      videoQualityPreference: '720p',
      playbackSpeed: 1.5,
    });
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('720p');
    expect(mockValues.get(key('VIDEO_PLAYBACK_SPEED'))).toBe(1.5);
    expect(mockRemove).not.toHaveBeenCalled();
  });

  it('replays a durable command in a new session epoch and clears it only after acknowledgement', async () => {
    await write({videoQualityPreference: '480p', playbackSpeed: 1.25});
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
    mockUpdate.mockClear().mockResolvedValue(undefined);
    await flushPlaybackPreferenceWrites(mockBoundary);
    expect(mockUpdate).toHaveBeenCalledTimes(1);
    expect(mockUpdate).toHaveBeenCalledWith(
      {videoQualityPreference: '480p', playbackSpeed: 1.25},
      mockBoundary,
    );
    expect(journal()).toBeUndefined();
  });

  it('does not let a slow network block local changes or acknowledge a newer revision', async () => {
    const firstRequest = deferred<void>();
    mockUpdate
      .mockResolvedValue(undefined)
      .mockReturnValueOnce(firstRequest.promise);
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '720p'},
      mockBoundary,
    );
    const flight = flushPlaybackPreferenceWrites(mockBoundary);
    await settleScheduledWork();
    expect(mockUpdate).toHaveBeenCalledTimes(1);
    await savePlaybackPreferencePatch(
      {playbackSpeed: 1.5, videoQualityPreference: '480p'},
      mockBoundary,
    );
    expect(journal()).toEqual({
      revision: 2,
      patch: {videoQualityPreference: '480p', playbackSpeed: 1.5},
    });
    firstRequest.resolve();
    await flight;
    expect(mockUpdate).toHaveBeenCalledTimes(2);
    expect(mockUpdate).toHaveBeenLastCalledWith(
      {videoQualityPreference: '480p', playbackSpeed: 1.5},
      mockBoundary,
    );
    expect(journal()).toBeUndefined();
  });

  it('retains A to B to A intent even though its final value matches the in-flight request', async () => {
    const firstRequest = deferred<void>();
    mockUpdate
      .mockResolvedValue(undefined)
      .mockReturnValueOnce(firstRequest.promise);
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '720p'},
      mockBoundary,
    );
    const flight = flushPlaybackPreferenceWrites(mockBoundary);
    await settleScheduledWork();
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '480p'},
      mockBoundary,
    );
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '720p'},
      mockBoundary,
    );
    expect(journal()).toEqual({
      revision: 3,
      patch: {videoQualityPreference: '720p'},
    });
    firstRequest.resolve();
    await flight;
    expect(mockUpdate).toHaveBeenCalledTimes(2);
    expect(journal()).toBeUndefined();
  });

  it('drains an edit queued while the previous journal deletion is still pending', async () => {
    const deletion = deferred<boolean>();
    mockUpdate.mockResolvedValue(undefined);
    mockRemove.mockImplementationOnce(async (storageKey: string) => {
      const removed = await deletion.promise;
      if (removed) mockValues.delete(storageKey);
      return removed;
    });
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '720p'},
      mockBoundary,
    );
    const firstFlight = flushPlaybackPreferenceWrites(mockBoundary);
    await settleScheduledWork();
    expect(mockRemove).toHaveBeenCalledTimes(1);
    const nextChoice = savePlaybackPreferencePatch(
      {videoQualityPreference: '480p'},
      mockBoundary,
    );
    deletion.resolve(true);
    await nextChoice;
    await firstFlight;
    await flushPlaybackPreferenceWrites(mockBoundary);
    expect(mockUpdate).toHaveBeenCalledTimes(2);
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('480p');
    expect(journal()).toBeUndefined();
  });

  it('repairs a failed optimistic cache write before retiring its durable command', async () => {
    mockUpdate.mockResolvedValue(undefined);
    const originalSave = mockSave.getMockImplementation()!;
    let failCacheOnce = true;
    mockSave.mockImplementation(async (storageKey: string, value: unknown) => {
      if (storageKey === key('VIDEO_QUALITY') && failCacheOnce) {
        failCacheOnce = false;
        return false;
      }
      return originalSave(storageKey, value);
    });
    await write({videoQualityPreference: '480p'});
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('480p');
    expect(journal()).toBeUndefined();
  });

  it('retains acknowledged intent if its cache remains unavailable then retries on foreground', async () => {
    mockUpdate.mockResolvedValue(undefined);
    const originalSave = mockSave.getMockImplementation()!;
    mockSave.mockImplementation(async (storageKey: string, value: unknown) =>
      storageKey === key('VIDEO_QUALITY')
        ? false
        : originalSave(storageKey, value),
    );
    await write({videoQualityPreference: '480p'});
    expect(await readPendingPlaybackPreferences(mockBoundary)).toEqual({
      videoQualityPreference: '480p',
    });
    expect(mockRemove).not.toHaveBeenCalled();
    mockSave.mockImplementation(originalSave);
    await flushPlaybackPreferenceWrites(mockBoundary);
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('480p');
    expect(journal()).toBeUndefined();
  });

  it('does not spin when native deletion fails and can settle on a later lifecycle call', async () => {
    mockUpdate.mockResolvedValue(undefined);
    const originalRemove = mockRemove.getMockImplementation()!;
    mockRemove.mockResolvedValue(false);
    await write({playbackSpeed: 1.25});
    expect(mockUpdate).toHaveBeenCalledTimes(1);
    expect(journal()).toBeDefined();
    mockRemove.mockImplementation(originalRemove);
    await flushPlaybackPreferenceWrites(mockBoundary);
    expect(journal()).toBeUndefined();
  });

  it('does not send or change the cache if persisting the command fails', async () => {
    mockValues.set(key('VIDEO_QUALITY'), 'auto');
    mockSave.mockResolvedValue(false);
    await expect(
      savePlaybackPreferencePatch(
        {videoQualityPreference: '720p'},
        mockBoundary,
      ),
    ).rejects.toThrow('SETTINGS_STORAGE_WRITE_FAILED');
    expect(mockUpdate).not.toHaveBeenCalled();
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('auto');
  });

  it('stores guest choices locally without a server command', async () => {
    mockBoundary = {scope: 'guest-installation', epoch: mockBoundary.epoch + 1};
    await write({videoQualityPreference: 'data_saver', playbackSpeed: 1.5});
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('data_saver');
    expect(mockValues.get(key('VIDEO_PLAYBACK_SPEED'))).toBe(1.5);
    expect(journal()).toBeUndefined();
    expect(mockUpdate).not.toHaveBeenCalled();
  });

  it('cannot apply an old acknowledgement to a replacement account', async () => {
    const oldRequest = deferred<void>();
    mockUpdate
      .mockResolvedValue(undefined)
      .mockReturnValueOnce(oldRequest.promise);
    const previousBoundary = mockBoundary;
    const previousKey = pendingKey();
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '720p'},
      previousBoundary,
    );
    const oldFlight = flushPlaybackPreferenceWrites(previousBoundary);
    const oldResult = oldFlight.catch((error: Error) => error.message);
    await settleScheduledWork();
    mockBoundary = {scope: 'user-8', epoch: mockBoundary.epoch + 1};
    await write({videoQualityPreference: '480p'});
    oldRequest.resolve();
    expect(await oldResult).toBe('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockValues.get(previousKey)).toBeDefined();
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('480p');
    expect(journal()).toBeUndefined();
  });

  it('serializes server writes across a same-account profile epoch change without blocking edits', async () => {
    const oldRequest = deferred<void>();
    mockUpdate
      .mockResolvedValue(undefined)
      .mockReturnValueOnce(oldRequest.promise);
    const previousBoundary = mockBoundary;
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '720p'},
      previousBoundary,
    );
    const oldFlight = flushPlaybackPreferenceWrites(previousBoundary);
    const oldResult = oldFlight.catch((error: Error) => error.message);
    await settleScheduledWork();
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '480p'},
      mockBoundary,
    );
    const nextFlight = flushPlaybackPreferenceWrites(mockBoundary);
    await settleScheduledWork();
    expect(mockValues.get(key('VIDEO_QUALITY'))).toBe('480p');
    expect(mockUpdate).toHaveBeenCalledTimes(1);
    oldRequest.resolve();
    expect(await oldResult).toBe('ACCOUNT_CHANGED_DURING_REQUEST');
    await nextFlight;
    expect(mockUpdate).toHaveBeenCalledTimes(2);
    expect(mockUpdate).toHaveBeenLastCalledWith(
      {videoQualityPreference: '480p'},
      mockBoundary,
    );
    expect(journal()).toBeUndefined();
  });

  it('probes again if internet returns before the original offline request has failed', async () => {
    const offlineRequest = deferred<void>();
    mockUpdate
      .mockResolvedValue(undefined)
      .mockReturnValueOnce(offlineRequest.promise);
    await savePlaybackPreferencePatch(
      {videoQualityPreference: '720p'},
      mockBoundary,
    );
    await settleScheduledWork();
    const recovery = flushPlaybackPreferenceWrites(mockBoundary, {
      afterCurrent: true,
    });
    offlineRequest.reject(new Error('offline'));
    await recovery;
    expect(mockUpdate).toHaveBeenCalledTimes(2);
    expect(journal()).toBeUndefined();
  });
});
