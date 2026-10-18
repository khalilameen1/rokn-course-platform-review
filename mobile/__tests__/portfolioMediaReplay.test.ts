const mockAppend = jest.fn();
let mockActive = {epoch: 7, scope: 'user-account-a'};

jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({...mockActive})),
  assertAccountSessionBoundary: (boundary: {epoch: number; scope: string}) => {
    if (
      boundary.epoch !== mockActive.epoch ||
      boundary.scope !== mockActive.scope
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
  replayPendingPortfolioMediaUploads,
  resetPortfolioMediaDeliveryForTests,
} from '../src/services/portfolioMediaDelivery';
import {
  listPortfolioMediaUploads,
  setPortfolioMediaUploadsPaused,
  stagePortfolioMediaUpload,
} from '../src/services/portfolioMediaOutbox';

const owner = () => ({...mockActive});
let created = 1;
const stage = (projectId: string, clientRequestId: string) =>
  stagePortfolioMediaUpload(
    {
      projectId,
      clientRequestId,
      file: {uri: `file:///${clientRequestId}.jpg`},
      createdAt: created++,
    },
    owner(),
  );
const ready = (id: string) => ({id, type: 'image', status: 'ready'});
const requestIds = () => mockAppend.mock.calls.map(call => call[2]);
const ID = {
  one: '11111111-1111-4111-8111-111111111111',
  two: '22222222-2222-4222-8222-222222222222',
  three: '33333333-3333-4333-8333-333333333333',
};

describe('portfolio media replay on the durable outbox', () => {
  beforeEach(async () => {
    jest.clearAllMocks();
    mockAppend.mockReset();
    mockActive = {epoch: 7, scope: 'user-account-a'};
    await AsyncStorage.clear();
    resetDurableOutboxForTests();
    resetPortfolioMediaDeliveryForTests();
  });

  it('coalesces startup, foreground and screen replay for one account', async () => {
    await stage('42', ID.one);
    let finishUpload: ((value: unknown) => void) | undefined;
    mockAppend.mockImplementation(
      () =>
        new Promise(resolve => {
          finishUpload = resolve;
        }),
    );

    const startup = replayPendingPortfolioMediaUploads();
    const foreground = replayPendingPortfolioMediaUploads();
    for (let turn = 0; turn < 30 && !finishUpload; turn += 1) {
      await new Promise(resolve => setImmediate(resolve));
    }

    expect(mockAppend).toHaveBeenCalledTimes(1);
    finishUpload?.(ready('media-1'));
    const expected = {
      attempted: 1,
      completed: 1,
      completedProjectIds: ['42'],
      completionRevision: 1,
    };
    await expect(Promise.all([startup, foreground])).resolves.toEqual([
      expected,
      expected,
    ]);
    expect(await listPortfolioMediaUploads(undefined, owner())).toEqual([]);
  });

  it('never restarts a paused project on startup or project-open replay', async () => {
    await stage('42', ID.one);
    await setPortfolioMediaUploadsPaused('42', true, owner());
    await stage('51', ID.two);
    mockAppend.mockResolvedValue(ready('9'));

    await expect(replayPendingPortfolioMediaUploads()).resolves.toMatchObject({
      attempted: 1,
      completedProjectIds: ['51'],
    });
    expect(requestIds()).toEqual([ID.two]);
    expect(
      (await listPortfolioMediaUploads('42', owner())).map(item => item.paused),
    ).toEqual([true]);
  });

  it('does not let one failed project block another or retry its siblings', async () => {
    await stage('42', ID.one);
    await stage('42', ID.two);
    await stage('51', ID.three);
    mockAppend
      .mockRejectedValueOnce(Object.assign(new Error('busy'), {status: 503}))
      .mockResolvedValueOnce(ready('media-2'));

    await expect(replayPendingPortfolioMediaUploads()).resolves.toEqual({
      attempted: 2,
      completed: 1,
      completedProjectIds: ['51'],
      completionRevision: 1,
    });
    expect(requestIds()).toEqual([ID.one, ID.three]);
    const left = await listPortfolioMediaUploads(undefined, owner());
    expect(left.map(item => item.clientRequestId)).toEqual([ID.one, ID.two]);
  });

  it('drops a permanently rejected file and continues the same project', async () => {
    await stage('42', ID.one);
    await stage('42', ID.two);
    mockAppend
      .mockRejectedValueOnce(Object.assign(new Error('invalid'), {status: 422}))
      .mockResolvedValueOnce(ready('media-2'));

    await expect(replayPendingPortfolioMediaUploads()).resolves.toEqual({
      attempted: 2,
      completed: 1,
      completedProjectIds: ['42'],
      completionRevision: 1,
    });
    expect(await listPortfolioMediaUploads(undefined, owner())).toEqual([]);
  });

  it('discards every pending file of a project the server no longer has', async () => {
    await stage('42', ID.one);
    await stage('42', ID.two);
    await stage('51', ID.three);
    mockAppend
      .mockRejectedValueOnce(Object.assign(new Error('gone'), {status: 404}))
      .mockResolvedValueOnce(ready('media-3'));

    await expect(replayPendingPortfolioMediaUploads()).resolves.toMatchObject({
      attempted: 2,
      completedProjectIds: ['51'],
    });
    expect(await listPortfolioMediaUploads(undefined, owner())).toEqual([]);
  });

  it('exposes a missed completion revision to a later screen caller', async () => {
    await stage('42', ID.one);
    mockAppend.mockResolvedValue(ready('media-1'));

    await expect(replayPendingPortfolioMediaUploads()).resolves.toMatchObject({
      completed: 1,
      completionRevision: 1,
    });
    await expect(replayPendingPortfolioMediaUploads()).resolves.toEqual({
      attempted: 0,
      completed: 0,
      completedProjectIds: [],
      completionRevision: 1,
    });
  });

  it('aborts an old account flight instead of classifying it as a retry', async () => {
    await stage('42', ID.one);
    mockAppend.mockImplementation(async () => {
      mockActive = {epoch: 8, scope: 'user-account-b'};
      return ready('media-1');
    });

    await expect(replayPendingPortfolioMediaUploads()).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(mockAppend).toHaveBeenCalledTimes(1);
    mockActive = {epoch: 7, scope: 'user-account-a'};
    const left = await listPortfolioMediaUploads(undefined, owner());
    expect(left.map(item => [item.clientRequestId, item.paused])).toEqual([
      [ID.one, false],
    ]);
  });
});
