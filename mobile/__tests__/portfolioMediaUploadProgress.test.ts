const mockDeliver = jest.fn();
let mockEpoch = 1;
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: (boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: jest.fn(),
  learnerDraftFileIsManaged: jest.fn(),
  removeLearnerDraftFile: jest.fn(),
}));
jest.mock('../src/services/portfolioMediaOutbox', () => ({
  stagePortfolioMediaUpload: jest.fn(),
}));
jest.mock('../src/services/portfolioMediaDelivery', () => ({
  deliverPortfolioMedia: (...args: unknown[]) => mockDeliver(...args),
}));
import {uploadPortfolioMediaFiles} from '../src/services/portfolioMediaUpload';

describe('portfolio batch progress presentation isolation', () => {
  const entries = [1, 2].map(id => ({
    projectId: '42',
    clientRequestId: `request-${id}`,
    createdAt: id,
    file: {uri: `file:///image-${id}.jpg`, type: 'image/jpeg', size: 100},
  }));
  beforeEach(() => {
    jest.clearAllMocks();
    mockEpoch = 1;
  });

  it('continues every file despite observer errors at init, transfer and settle', async () => {
    mockDeliver.mockImplementation(async (_entry, _boundary, progress) => {
      progress({loaded: 50, total: 100, phase: 'uploading'});
      return {
        state: 'uploaded',
        media: {id: '9', type: 'image', status: 'ready'},
      };
    });
    const onProgress = jest.fn(() => {
      throw new Error('presentation');
    });
    const onUploaded = jest.fn();
    await expect(
      uploadPortfolioMediaFiles({
        boundary: {scope: 'user-a', epoch: 1},
        entries,
        onProgress,
        onUploaded,
      }),
    ).resolves.toEqual({interrupted: false, discardedFiles: 0});
    expect(mockDeliver).toHaveBeenCalledTimes(2);
    expect(onUploaded).toHaveBeenCalledTimes(2);
    expect(onProgress.mock.calls).toHaveLength(5); // init + transfer/settle each file
  });

  it('still rejects a changed account before a second file even if presentation throws', async () => {
    mockDeliver.mockResolvedValue({
      state: 'uploaded',
      media: {id: '9', type: 'image', status: 'ready'},
    });
    const onUploaded = jest.fn(() => {
      mockEpoch = 2;
    });
    await expect(
      uploadPortfolioMediaFiles({
        boundary: {scope: 'user-a', epoch: 1},
        entries,
        onProgress: () => {
          throw new Error('presentation');
        },
        onUploaded,
      }),
    ).rejects.toThrow('ACCOUNT_CHANGED_DURING_REQUEST');
    expect(mockDeliver).toHaveBeenCalledTimes(1);
  });
});
