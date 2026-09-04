const mockAppend = jest.fn();
const mockComplete = jest.fn(async () => undefined);
const mockDiscard = jest.fn(async () => undefined);
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
  completePortfolioMediaUpload: (...args: unknown[]) =>
    (mockComplete as (...values: unknown[]) => unknown)(...args),
  discardPortfolioMediaUploads: (...args: unknown[]) =>
    (mockDiscard as (...values: unknown[]) => unknown)(...args),
}));

import {
  deliverPortfolioMedia,
  resetPortfolioMediaDeliveryForTests,
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
});
