const mockAppend = jest.fn();
const mockComplete = jest.fn(async () => undefined);
const mockDiscard = jest.fn(async () => undefined);
const mockPaused = jest.fn(async () => false);
const mockSetPaused = jest.fn(async () => 1);
let mockActiveBoundary = {epoch: 7, scope: 'user-a'};

jest.mock('../src/constants/helpers', () => ({
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

jest.mock('../src/services/portfolioMediaOutbox', () => ({
  portfolioMediaUploadIsPaused: (...args: unknown[]) => (mockPaused as (...values: unknown[]) => unknown)(...args),
  setPortfolioMediaUploadsPaused: (...args: unknown[]) => (mockSetPaused as (...values: unknown[]) => unknown)(...args),
  completePortfolioMediaUpload: (...args: unknown[]) =>
    (mockComplete as (...values: unknown[]) => unknown)(...args),
  discardPortfolioMediaUploads: (...args: unknown[]) =>
    (mockDiscard as (...values: unknown[]) => unknown)(...args),
}));

import {
  deliverPortfolioMedia,
  resetPortfolioMediaDeliveryForTests,
  pausePortfolioMediaDelivery,
} from '../src/services/portfolioMediaDelivery';

const entry = {
  projectId: '42',
  clientRequestId: '11111111-1111-4111-8111-111111111111',
  file: {uri: 'file:///project.jpg', type: 'image/jpeg'},
  createdAt: 1,
  storageKey: '@test/portfolio:user-a',
};

const deferred = <T>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(next => {
    resolve = next;
  });
  return {promise, resolve};
};

describe('portfolio media delivery owner', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockActiveBoundary = {epoch: 7, scope: 'user-a'};
    resetPortfolioMediaDeliveryForTests();
    mockPaused.mockResolvedValue(false);
    mockSetPaused.mockResolvedValue(1);
  });

  it('shares one upload between foreground and replay callers', async () => {
    const request = deferred<{id: string; type: 'image'; status: 'ready'}>();
    mockAppend.mockReturnValue(request.promise);
    const boundary = {...mockActiveBoundary};

    const foreground = deliverPortfolioMedia(entry, boundary);
    const replay = deliverPortfolioMedia(entry, boundary);
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();

    expect(mockAppend).toHaveBeenCalledTimes(1);
    request.resolve({id: '9', type: 'image', status: 'ready'});
    await expect(Promise.all([foreground, replay])).resolves.toEqual([
      {
        state: 'uploaded',
        media: {id: '9', type: 'image', status: 'ready'},
      },
      {
        state: 'uploaded',
        media: {id: '9', type: 'image', status: 'ready'},
      },
    ]);
    expect(mockComplete).toHaveBeenCalledTimes(1);
  });

  it('leaves an old-account entry retryable after a late response', async () => {
    const request = deferred<{id: string; type: 'image'; status: 'ready'}>();
    mockAppend.mockReturnValue(request.promise);
    const upload = deliverPortfolioMedia(entry, {...mockActiveBoundary});
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();

    mockActiveBoundary = {epoch: 8, scope: 'user-b'};
    request.resolve({id: '9', type: 'image', status: 'ready'});

    await expect(upload).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockComplete).not.toHaveBeenCalled();
    expect(mockDiscard).not.toHaveBeenCalled();
  });

  it('persists the pause before aborting one shared foreground/replay request', async () => {
    let signal!: AbortSignal;
    mockAppend.mockImplementation((_id, _file, _request, _boundary, _progress, ownerSignal: AbortSignal) => {
      signal = ownerSignal;
      return new Promise((_resolve, reject) => ownerSignal.addEventListener('abort', () => reject(new Error('cancelled'))));
    });
    const boundary = {...mockActiveBoundary};
    const foreground = deliverPortfolioMedia(entry, boundary);
    const replay = deliverPortfolioMedia(entry, boundary);
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();
    const storage = deferred<number>();
    mockSetPaused.mockReturnValueOnce(storage.promise);
    const pause = pausePortfolioMediaDelivery(entry.projectId, boundary);
    expect(signal.aborted).toBe(false);
    storage.resolve(1);
    await expect(pause).resolves.toBe(true);
    expect(signal.aborted).toBe(true);
    await expect(Promise.all([foreground, replay])).resolves.toEqual([{state: 'paused'}, {state: 'paused'}]);
    expect(mockAppend).toHaveBeenCalledTimes(1);
    expect(mockComplete).not.toHaveBeenCalled();
    expect(mockDiscard).not.toHaveBeenCalled();
  });

  it('rechecks a stale active replay snapshot and sends no bytes for a persisted pause', async () => {
    mockPaused.mockResolvedValue(true);
    await expect(deliverPortfolioMedia(entry, {...mockActiveBoundary})).resolves.toEqual({state: 'paused'});
    expect(mockAppend).not.toHaveBeenCalled();
  });

  it('does not acknowledge a failed pause write or downgrade an accepted attachment', async () => {
    const response = deferred<{id: string; type: 'image'; status: 'ready'}>();
    let signal!: AbortSignal;
    mockAppend.mockImplementation((_id, _file, _request, _boundary, _progress, ownerSignal: AbortSignal) => { signal = ownerSignal; return response.promise; });
    const boundary = {...mockActiveBoundary};
    const upload = deliverPortfolioMedia(entry, boundary);
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();
    mockSetPaused.mockRejectedValueOnce(new Error('storage full'));
    await expect(pausePortfolioMediaDelivery(entry.projectId, boundary)).rejects.toThrow('storage full');
    expect(signal.aborted).toBe(false);
    await expect(pausePortfolioMediaDelivery(entry.projectId, boundary)).resolves.toBe(true);
    expect(signal.aborted).toBe(true);
    // The server may accept just before native cancellation. Its receipt wins.
    response.resolve({id: '9', type: 'image', status: 'ready'});
    await expect(upload).resolves.toMatchObject({state: 'uploaded', media: {id: '9'}});
    expect(mockComplete).toHaveBeenCalledTimes(1);
  });

  it('lets foreground subscribe to an already running replay without another request', async () => {
    const request = deferred<{id: string; type: 'image'; status: 'ready'}>();
    mockAppend.mockReturnValue(request.promise);
    const boundary = {...mockActiveBoundary};
    const replay = deliverPortfolioMedia(entry, boundary);
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();
    const publish = mockAppend.mock.calls[0][4];
    publish({loaded: 30, total: 100, phase: 'uploading'});
    const observer = jest.fn();
    const foreground = deliverPortfolioMedia(entry, boundary, observer);
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
    request.resolve({id: '9', type: 'image', status: 'ready'});
    await Promise.all([replay, foreground]);
    const calls = observer.mock.calls.length;
    publish({loaded: 10, total: 100, phase: 'uploading'});
    expect(observer).toHaveBeenCalledTimes(calls);
  });

  it('suppresses progress from the old account and presentation failures cannot retry an upload', async () => {
    const request = deferred<{id: string; type: 'image'; status: 'ready'}>();
    mockAppend.mockReturnValue(request.promise);
    const observer = jest.fn(() => {
      throw new Error('presentation');
    });
    const upload = deliverPortfolioMedia(
      entry,
      {...mockActiveBoundary},
      observer,
    );
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();
    const publish = mockAppend.mock.calls[0][4];
    expect(() =>
      publish({loaded: 1, total: 100, phase: 'uploading'}),
    ).not.toThrow();
    mockActiveBoundary = {scope: 'user-b', epoch: 8};
    publish({loaded: 2, total: 100, phase: 'uploading'});
    expect(observer).toHaveBeenCalledTimes(1);
    request.resolve({id: '9', type: 'image', status: 'ready'});
    await expect(upload).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
  });
});
