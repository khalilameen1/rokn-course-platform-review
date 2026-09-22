const mockGet = jest.fn();
const mockPost = jest.fn();
const mockVideo = jest.fn();
let mockBoundary = {epoch: 1, scope: 'portfolio-access-owner'};

jest.mock('../src/constants/api', () => ({
  publicRequest: {
    get: (...args: unknown[]) => mockGet(...args),
    post: (...args: unknown[]) => mockPost(...args),
  },
}));
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => mockBoundary,
  assertAccountSessionBoundary: (boundary: typeof mockBoundary) => {
    if (boundary !== mockBoundary)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  accountScopedStorageKey: async () => 'portfolio-test-cache',
  saveItem: async () => true,
}));
jest.mock('../src/services/portfolioVideoUpload', () => ({
  uploadPortfolioVideo: (...args: unknown[]) => mockVideo(...args),
}));

import {
  appendPortfolioMedia,
  assertPortfolioUploadAccess,
} from '../src/services/api/portfolio';

describe('portfolio storage entitlement preflight', () => {
  beforeEach(() => {
    jest.resetAllMocks();
    mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
  });

  it.each(['image/jpeg', 'video/mp4'])(
    'refuses %s before sending bytes or resuming a saved video upload',
    async type => {
      mockGet.mockResolvedValue({data: {data: {can_upload: false}}});
      await expect(
        appendPortfolioMedia(
          '1',
          {uri: 'file:///work', type},
          'request',
          mockBoundary,
        ),
      ).rejects.toMatchObject({
        status: 403,
        data: {code: 'PORTFOLIO_CERTIFICATE_SUBSCRIPTION_REQUIRED'},
      });
      expect(mockPost).not.toHaveBeenCalled();
      expect(mockVideo).not.toHaveBeenCalled();
    },
  );

  it.each([{}, null, {can_upload: 'true'}])(
    'fails closed on an invalid or old server contract %p',
    async data => {
      mockGet.mockResolvedValue({data: {data}});
      await expect(assertPortfolioUploadAccess(mockBoundary)).rejects.toThrow(
        'PORTFOLIO_UPLOAD_ACCESS_CONTRACT_INVALID',
      );
    },
  );

  it('does not reuse a previous permission after expiry or server failure', async () => {
    mockGet.mockResolvedValueOnce({data: {data: {can_upload: true}}});
    await expect(
      assertPortfolioUploadAccess(mockBoundary),
    ).resolves.toBeUndefined();
    mockGet.mockResolvedValueOnce({data: {data: {can_upload: false}}});
    await expect(
      assertPortfolioUploadAccess(mockBoundary),
    ).rejects.toMatchObject({status: 403});
    mockGet.mockRejectedValueOnce(new Error('offline'));
    await expect(
      appendPortfolioMedia(
        '1',
        {uri: 'file:///work.jpg', type: 'image/jpeg'},
        'request',
        mockBoundary,
      ),
    ).rejects.toThrow('offline');
    expect(mockPost).not.toHaveBeenCalled();
  });

  it('rejects a permission response from the previous account', async () => {
    const boundary = mockBoundary;
    mockGet.mockImplementation(async () => {
      mockBoundary = {...mockBoundary, epoch: mockBoundary.epoch + 1};
      return {data: {data: {can_upload: true}}};
    });
    await expect(assertPortfolioUploadAccess(boundary)).rejects.toThrow(
      'ACCOUNT_CHANGED_DURING_REQUEST',
    );
  });

  it('sends the file only after permission and preserves a server revocation response', async () => {
    mockGet.mockResolvedValue({data: {data: {can_upload: true}}});
    const denied = {
      status: 403,
      data: {code: 'PORTFOLIO_CERTIFICATE_SUBSCRIPTION_REQUIRED'},
    };
    mockPost.mockRejectedValue(denied);
    await expect(
      appendPortfolioMedia(
        '1',
        {uri: 'file:///work.jpg', type: 'image/jpeg'},
        'request',
        mockBoundary,
      ),
    ).rejects.toEqual(denied);
    expect(mockGet).toHaveBeenCalledWith('portfolio/upload-access');
    expect(mockPost).toHaveBeenCalledTimes(1);
    expect(mockGet.mock.invocationCallOrder[0]).toBeLessThan(
      mockPost.mock.invocationCallOrder[0],
    );
  });
});
