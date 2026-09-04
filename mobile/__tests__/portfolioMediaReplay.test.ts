jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 7,
    scope: 'user-account-a',
  })),
  assertAccountSessionBoundary: jest.fn(),
}));

jest.mock('../src/services/portfolioMediaDelivery', () => ({
  deliverPortfolioMedia: jest.fn(),
}));

jest.mock('../src/services/portfolioMediaOutbox', () => ({
  listPortfolioMediaUploads: jest.fn(),
}));

import {deliverPortfolioMedia} from '../src/services/portfolioMediaDelivery';
import {listPortfolioMediaUploads} from '../src/services/portfolioMediaOutbox';
import {
  replayPendingPortfolioMediaUploads,
  resetPortfolioMediaReplayForTests,
} from '../src/services/portfolioMediaReplay';

const entry = (projectId: string, clientRequestId: string) => ({
  projectId,
  clientRequestId,
  file: {uri: `file:///${clientRequestId}.jpg`},
  createdAt: 1,
  storageKey: '@test/portfolio-outbox:user-account-a',
});

describe('portfolio media replay', () => {
  beforeEach(() => {
    resetPortfolioMediaReplayForTests();
    jest.clearAllMocks();
  });

  it('coalesces startup, foreground and screen replay for one account', async () => {
    const pending = entry('42', '11111111-1111-4111-8111-111111111111');
    (listPortfolioMediaUploads as jest.Mock).mockResolvedValue([pending]);
    let finishUpload:
      | ((value: {state: 'uploaded'; media: {id: string}}) => void)
      | undefined;
    (deliverPortfolioMedia as jest.Mock).mockImplementation(
      () =>
        new Promise(resolve => {
          finishUpload = resolve;
        }),
    );

    const startup = replayPendingPortfolioMediaUploads();
    const foreground = replayPendingPortfolioMediaUploads();
    for (let turn = 0; turn < 4; turn += 1) await Promise.resolve();

    expect(listPortfolioMediaUploads).toHaveBeenCalledTimes(1);
    expect(deliverPortfolioMedia).toHaveBeenCalledTimes(1);
    finishUpload?.({state: 'uploaded', media: {id: 'media-1'}});
    await expect(Promise.all([startup, foreground])).resolves.toEqual([
      {
        attempted: 1,
        completed: 1,
        completedProjectIds: ['42'],
        completionRevision: 1,
      },
      {
        attempted: 1,
        completed: 1,
        completedProjectIds: ['42'],
        completionRevision: 1,
      },
    ]);
  });

  it('does not let one failed project block another or retry its siblings', async () => {
    const first = entry('42', '11111111-1111-4111-8111-111111111111');
    const sameProject = entry('42', '22222222-2222-4222-8222-222222222222');
    const otherProject = entry('51', '33333333-3333-4333-8333-333333333333');
    (listPortfolioMediaUploads as jest.Mock).mockResolvedValue([
      first,
      sameProject,
      otherProject,
    ]);
    (deliverPortfolioMedia as jest.Mock)
      .mockResolvedValueOnce({state: 'retry'})
      .mockResolvedValueOnce({
        state: 'uploaded',
        media: {id: 'media-2'},
      });

    await expect(replayPendingPortfolioMediaUploads()).resolves.toEqual({
      attempted: 2,
      completed: 1,
      completedProjectIds: ['51'],
      completionRevision: 1,
    });
    expect(deliverPortfolioMedia).toHaveBeenNthCalledWith(
      1,
      first,
      expect.objectContaining({scope: 'user-account-a'}),
    );
    expect(deliverPortfolioMedia).toHaveBeenNthCalledWith(
      2,
      otherProject,
      expect.objectContaining({scope: 'user-account-a'}),
    );
  });

  it('drops a permanently rejected file and continues the same project', async () => {
    const invalid = entry('42', '11111111-1111-4111-8111-111111111111');
    const valid = entry('42', '22222222-2222-4222-8222-222222222222');
    (listPortfolioMediaUploads as jest.Mock).mockResolvedValue([
      invalid,
      valid,
    ]);
    (deliverPortfolioMedia as jest.Mock)
      .mockResolvedValueOnce({state: 'discarded_file'})
      .mockResolvedValueOnce({
        state: 'uploaded',
        media: {id: 'media-2'},
      });

    await expect(replayPendingPortfolioMediaUploads()).resolves.toEqual({
      attempted: 2,
      completed: 1,
      completedProjectIds: ['42'],
      completionRevision: 1,
    });
    expect(deliverPortfolioMedia).toHaveBeenCalledTimes(2);
  });

  it('exposes a missed completion revision to a later screen caller', async () => {
    const pending = entry('42', '11111111-1111-4111-8111-111111111111');
    (listPortfolioMediaUploads as jest.Mock)
      .mockResolvedValueOnce([pending])
      .mockResolvedValueOnce([]);
    (deliverPortfolioMedia as jest.Mock).mockResolvedValue({
      state: 'uploaded',
      media: {id: 'media-1'},
    });

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
    const pending = entry('42', '11111111-1111-4111-8111-111111111111');
    (listPortfolioMediaUploads as jest.Mock).mockResolvedValue([pending]);
    (deliverPortfolioMedia as jest.Mock).mockRejectedValue(
      new Error('ACCOUNT_CHANGED_DURING_REQUEST'),
    );

    await expect(replayPendingPortfolioMediaUploads()).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
    expect(deliverPortfolioMedia).toHaveBeenCalledTimes(1);
  });
});
