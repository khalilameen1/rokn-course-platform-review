const mockAppend = jest.fn();
let mockActiveBoundary = {epoch: 7, scope: 'user-a'};

jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockActiveBoundary}),
  assertAccountSessionBoundary: (boundary: {epoch: number; scope: string}) => {
    if (
      boundary.epoch !== mockActiveBoundary.epoch ||
      boundary.scope !== mockActiveBoundary.scope
    ) {
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
    }
  },
}));

jest.mock('../src/services/api/profile', () => ({
  appendPortfolioMedia: (...args: unknown[]) =>
    (mockAppend as (...values: unknown[]) => unknown)(...args),
}));

jest.mock('../src/services/learnerDraftFiles', () => ({
  learnerDraftFileIsReadable: jest.fn(async () => true),
  removeLearnerDraftFile: jest.fn(async () => undefined),
  retainLearnerDraftFiles: jest.fn(async () => undefined),
}));

import AsyncStorage from '@react-native-async-storage/async-storage';
import {resetDurableOutboxForTests} from '../src/services/durableOutbox';
import {
  deliverPortfolioMedia,
  pausePortfolioMediaDelivery,
  replayPendingPortfolioMediaUploads,
  resetPortfolioMediaDeliveryForTests,
} from '../src/services/portfolioMediaDelivery';
import {
  listPortfolioMediaUploads,
  setPortfolioMediaUploadsPaused,
  stagePortfolioMediaUpload,
  type PortfolioMediaOutboxEntry,
} from '../src/services/portfolioMediaOutbox';

const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};
const settle = async () => {
  for (let turn = 0; turn < 20; turn += 1) {
    await new Promise(resolve => setImmediate(resolve));
  }
};
const ready = {id: '9', type: 'image', status: 'ready'};
const pending = () => listPortfolioMediaUploads(undefined, mockActiveBoundary);

describe('portfolio media delivery owner', () => {
  let entry: PortfolioMediaOutboxEntry;

  beforeEach(async () => {
    jest.restoreAllMocks();
    mockAppend.mockReset();
    mockActiveBoundary = {epoch: 7, scope: 'user-a'};
    await AsyncStorage.clear();
    resetDurableOutboxForTests();
    resetPortfolioMediaDeliveryForTests();
    entry = await stagePortfolioMediaUpload(
      {
        projectId: '42',
        clientRequestId: '11111111-1111-4111-8111-111111111111',
        file: {uri: 'file:///project.jpg', type: 'image/jpeg'},
        createdAt: 1,
      },
      {...mockActiveBoundary},
    );
  });

  it('shares one upload between foreground and replay callers', async () => {
    const request = deferred<typeof ready>();
    mockAppend.mockReturnValue(request.promise);
    const boundary = {...mockActiveBoundary};

    const foreground = deliverPortfolioMedia(entry, boundary);
    const replay = replayPendingPortfolioMediaUploads();
    await settle();

    expect(mockAppend).toHaveBeenCalledTimes(1);
    request.resolve(ready);
    await expect(foreground).resolves.toEqual({state: 'uploaded', media: ready});
    await expect(replay).resolves.toMatchObject({completed: 1});
    expect(await pending()).toEqual([]);
  });

  it('leaves an old-account entry retryable after a late response', async () => {
    const request = deferred<typeof ready>();
    mockAppend.mockReturnValue(request.promise);
    const upload = deliverPortfolioMedia(entry, {...mockActiveBoundary});
    await settle();

    mockActiveBoundary = {epoch: 8, scope: 'user-b'};
    request.resolve(ready);

    await expect(upload).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    mockActiveBoundary = {epoch: 7, scope: 'user-a'};
    expect((await pending()).map(item => item.clientRequestId)).toEqual([
      entry.clientRequestId,
    ]);
  });

  it('persists the pause before aborting one shared foreground/replay request', async () => {
    let signal!: AbortSignal;
    mockAppend.mockImplementation(
      (_id, _file, _request, _boundary, _progress, ownerSignal: AbortSignal) => {
        signal = ownerSignal;
        return new Promise((_resolve, reject) =>
          ownerSignal.addEventListener('abort', () =>
            reject(new Error('cancelled')),
          ),
        );
      },
    );
    const boundary = {...mockActiveBoundary};
    const foreground = deliverPortfolioMedia(entry, boundary);
    const replay = replayPendingPortfolioMediaUploads();
    await settle();
    const storage = deferred<void>();
    const write = AsyncStorage.setItem;
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementationOnce(async (...args) => {
        await storage.promise;
        return write(...args);
      });
    const pause = pausePortfolioMediaDelivery(entry.projectId, boundary);
    await settle();
    expect(signal.aborted).toBe(false);
    storage.resolve();
    await expect(pause).resolves.toBe(true);
    expect(signal.aborted).toBe(true);
    await expect(foreground).resolves.toEqual({state: 'paused'});
    await expect(replay).resolves.toMatchObject({completed: 0});
    expect(mockAppend).toHaveBeenCalledTimes(1);
    expect((await pending()).map(item => item.paused)).toEqual([true]);
  });

  it('rechecks a stale active snapshot and sends no bytes for a persisted pause', async () => {
    await setPortfolioMediaUploadsPaused('42', true, {...mockActiveBoundary});
    await expect(
      deliverPortfolioMedia(entry, {...mockActiveBoundary}),
    ).resolves.toEqual({state: 'paused'});
    expect(mockAppend).not.toHaveBeenCalled();
  });

  it('does not acknowledge a failed pause write or downgrade an accepted attachment', async () => {
    const response = deferred<typeof ready>();
    let signal!: AbortSignal;
    mockAppend.mockImplementation(
      (_id, _file, _request, _boundary, _progress, ownerSignal: AbortSignal) => {
        signal = ownerSignal;
        return response.promise;
      },
    );
    const boundary = {...mockActiveBoundary};
    const upload = deliverPortfolioMedia(entry, boundary);
    await settle();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockRejectedValueOnce(new Error('storage full'));
    await expect(
      pausePortfolioMediaDelivery(entry.projectId, boundary),
    ).rejects.toThrow('storage full');
    expect(signal.aborted).toBe(false);
    await expect(
      pausePortfolioMediaDelivery(entry.projectId, boundary),
    ).resolves.toBe(true);
    expect(signal.aborted).toBe(true);
    // The server may accept just before native cancellation. Its receipt wins.
    response.resolve(ready);
    await expect(upload).resolves.toMatchObject({
      state: 'uploaded',
      media: {id: '9'},
    });
    expect(await pending()).toEqual([]);
  });

  it('lets foreground subscribe to an already running replay without another request', async () => {
    const request = deferred<typeof ready>();
    mockAppend.mockReturnValue(request.promise);
    const boundary = {...mockActiveBoundary};
    const replay = replayPendingPortfolioMediaUploads();
    await settle();
    const publish = mockAppend.mock.calls[0][4];
    publish({loaded: 30, total: 100, phase: 'uploading'});
    const observer = jest.fn();
    const foreground = deliverPortfolioMedia(entry, boundary, observer);
    await settle();
    expect(observer).toHaveBeenLastCalledWith({
      loaded: 30,
      total: 100,
      phase: 'uploading',
    });
    publish({loaded: 100, total: 100, phase: 'saving'});
    expect(observer).toHaveBeenLastCalledWith({
      loaded: 100,
      total: 100,
      phase: 'saving',
    });
    expect(mockAppend).toHaveBeenCalledTimes(1);
    request.resolve(ready);
    await Promise.all([replay, foreground]);
    const calls = observer.mock.calls.length;
    publish({loaded: 10, total: 100, phase: 'uploading'});
    expect(observer).toHaveBeenCalledTimes(calls);
  });

  it('suppresses progress from the old account and presentation failures cannot retry an upload', async () => {
    const request = deferred<typeof ready>();
    mockAppend.mockReturnValue(request.promise);
    const observer = jest.fn(() => {
      throw new Error('presentation');
    });
    const upload = deliverPortfolioMedia(
      entry,
      {...mockActiveBoundary},
      observer,
    );
    await settle();
    const publish = mockAppend.mock.calls[0][4];
    expect(() =>
      publish({loaded: 1, total: 100, phase: 'uploading'}),
    ).not.toThrow();
    mockActiveBoundary = {scope: 'user-b', epoch: 8};
    publish({loaded: 2, total: 100, phase: 'uploading'});
    expect(observer).toHaveBeenCalledTimes(1);
    request.resolve(ready);
    await expect(upload).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });
});
