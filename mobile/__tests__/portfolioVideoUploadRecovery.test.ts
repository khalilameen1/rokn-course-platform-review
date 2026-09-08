const mockStorage = new Map<string, string>();
const mockPost = jest.fn();
const mockReportClientError = jest.fn();
let mockEpoch = 1;

jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: (...args: unknown[]) => mockReportClientError(...args),
}));

jest.mock('@react-native-async-storage/async-storage', () => ({
  getItem: jest.fn(async (key: string) => mockStorage.get(key) ?? null),
  setItem: jest.fn(async (key: string, value: string) => {
    mockStorage.set(key, value);
  }),
  removeItem: jest.fn(async (key: string) => {
    mockStorage.delete(key);
  }),
}));

jest.mock('react-native-fs', () => ({
  __esModule: true,
  default: {
    stat: jest.fn(async () => ({size: 4})),
    hash: jest.fn(async () => 'a'.repeat(64)),
    read: jest.fn(async () => 'dGVzdA=='),
  },
}));

jest.mock('../src/constants/api', () => ({
  publicRequest: {post: (...args: unknown[]) => mockPost(...args)},
}));

jest.mock('../src/constants/helpers', () => ({
  accountScopedStorageKey: jest.fn(async () => '@portfolio-video:user-a'),
  assertAccountSessionBoundary: jest.fn((boundary: {epoch: number}) => {
    if (boundary.epoch !== mockEpoch) throw new Error('ACCOUNT_CHANGED');
  }),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-a',
  })),
}));

jest.mock('../src/services/recoverableJsonStorage', () => ({
  readJsonOrQuarantine: jest.fn(
    async (key: string, fallback: () => unknown) => {
      const raw = mockStorage.get(key);
      return raw ? JSON.parse(raw) : fallback();
    },
  ),
}));

import {uploadPortfolioVideo} from '../src/services/portfolioVideoUpload';

describe('portfolio resumable video authorization recovery', () => {
  const originalFetch = global.fetch;
  const originalXhr = global.XMLHttpRequest;

  beforeEach(() => {
    jest.clearAllMocks();
    mockEpoch = 1;
    mockStorage.clear();
    mockPost.mockReset();
    mockPost
      .mockResolvedValueOnce({
        data: {
          data: {
            upload_endpoint: 'https://video.example/tus',
            claim: 'claim-1',
            headers: {Authorization: 'initial'},
          },
        },
      })
      .mockResolvedValue({
        data: {
          data: {
            claim: 'claim-2',
            headers: {Authorization: 'renewed'},
            attached: false,
          },
        },
      });

    global.fetch = jest.fn(async (_url: unknown, init?: RequestInit) => {
      if (init?.method === 'POST') {
        return {
          ok: true,
          headers: {get: () => '/upload/1'},
        } as unknown as Response;
      }
      return {
        ok: true,
        status: 200,
        headers: {
          get: (name: string) => (name === 'Upload-Offset' ? '0' : null),
        },
      } as unknown as Response;
    }) as typeof fetch;

    class RejectedPatchRequest {
      status = 403;
      timeout = 0;
      onerror: (() => void) | null = null;
      onload: (() => void) | null = null;
      ontimeout: (() => void) | null = null;

      open() {}
      setRequestHeader() {}
      getResponseHeader() {
        return null;
      }
      send() {
        this.onload?.();
      }
    }
    global.XMLHttpRequest =
      RejectedPatchRequest as unknown as typeof XMLHttpRequest;
  });

  afterAll(() => {
    global.fetch = originalFetch;
    global.XMLHttpRequest = originalXhr;
  });

  it('stops after one renewed authorization instead of looping forever', async () => {
    await expect(
      uploadPortfolioVideo(
        '42',
        {
          uri: 'file:///portfolio.mp4',
          type: 'video/mp4',
          fileName: 'portfolio.mp4',
          size: 4,
        },
        '11111111-1111-4111-8111-111111111111',
        {epoch: 1, scope: 'user-a'},
      ),
    ).rejects.toMatchObject({status: 403});

    const renewCalls = mockPost.mock.calls.filter(([endpoint]) =>
      String(endpoint).endsWith('/renew'),
    );
    expect(renewCalls).toHaveLength(2);
    expect(mockStorage.has('@portfolio-video:user-a')).toBe(true);
  });

  it('returns confirmed media when removing the old upload record fails', async () => {
    const storage = require('@react-native-async-storage/async-storage');
    storage.removeItem.mockRejectedValueOnce(new Error('disk I/O'));
    mockPost.mockReset();
    mockPost
      .mockResolvedValueOnce({data: {data: {attached: true, claim: 'claim-1'}}})
      .mockResolvedValueOnce({data: {data: {id: 11, type: 'video'}}});

    await expect(
      uploadPortfolioVideo(
        '42',
        {uri: 'file:///portfolio.mp4', size: 4},
        '11111111-1111-4111-8111-111111111111',
        {epoch: 1, scope: 'user-a'},
      ),
    ).resolves.toEqual({id: 11, type: 'video'});
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(global.fetch).not.toHaveBeenCalled();
  });

  it('reclaims the same confirmed media from a retained record without uploading a second chunk', async () => {
    const storage = require('@react-native-async-storage/async-storage');
    storage.removeItem.mockRejectedValueOnce(new Error('disk I/O'));
    const patched = jest.fn();
    class SuccessfulPatchRequest {
      status = 204;
      onload: (() => void) | null = null;
      open() {}
      setRequestHeader() {}
      getResponseHeader() {
        return '4';
      }
      send() {
        patched();
        this.onload?.();
      }
    }
    global.XMLHttpRequest =
      SuccessfulPatchRequest as unknown as typeof XMLHttpRequest;
    mockPost.mockReset();
    mockPost
      .mockResolvedValueOnce({
        data: {
          data: {
            upload_endpoint: 'https://video.example/tus',
            claim: 'claim-1',
            headers: {Authorization: 'initial'},
          },
        },
      })
      .mockResolvedValueOnce({
        data: {
          data: {
            claim: 'claim-1',
            attached: false,
            headers: {Authorization: 'renewed'},
          },
        },
      })
      .mockResolvedValueOnce({data: {data: {id: 11, type: 'video'}}})
      .mockResolvedValueOnce({data: {data: {attached: true}}})
      .mockResolvedValueOnce({data: {data: {id: 11, type: 'video'}}});
    const requestId = '11111111-1111-4111-8111-111111111111';
    const upload = () =>
      uploadPortfolioVideo(
        '42',
        {
          uri: 'file:///portfolio.mp4',
          size: 4,
          type: 'video/mp4',
        },
        requestId,
        {epoch: 1, scope: 'user-a'},
      );

    await expect(upload()).resolves.toEqual({id: 11, type: 'video'});
    expect(patched).toHaveBeenCalledTimes(1);
    expect(
      JSON.parse(mockStorage.get('@portfolio-video:user-a')!)[requestId]
        .uploadUrl,
    ).toBe('https://video.example/upload/1');
    const transferCalls = (global.fetch as jest.Mock).mock.calls.length;

    await expect(upload()).resolves.toEqual({id: 11, type: 'video'});
    expect(patched).toHaveBeenCalledTimes(1);
    expect(global.fetch).toHaveBeenCalledTimes(transferCalls);
    expect(mockStorage.has('@portfolio-video:user-a')).toBe(false);
    const claims = mockPost.mock.calls.filter(([endpoint]) =>
      String(endpoint).endsWith('/claim'),
    );
    expect(claims).toHaveLength(2);
    claims.forEach(([, body, options]) => {
      expect(body).toEqual({claim: 'claim-1'});
      expect(options.headers['Idempotency-Key']).toBe(requestId);
    });
    expect(mockPost.mock.calls.map(([endpoint]) => endpoint).slice(3)).toEqual([
      'portfolio/42/media/video-uploads/renew',
      'portfolio/42/media/video-uploads/claim',
    ]);
  });

  it('rejects the old-account result when the account changes during failed terminal cleanup', async () => {
    const storage = require('@react-native-async-storage/async-storage');
    storage.removeItem.mockImplementationOnce(async () => {
      mockEpoch = 2;
      throw new Error('disk I/O');
    });
    mockPost.mockReset();
    mockPost
      .mockResolvedValueOnce({data: {data: {attached: true, claim: 'claim-1'}}})
      .mockResolvedValueOnce({data: {data: {id: 11, type: 'video'}}});

    await expect(
      uploadPortfolioVideo(
        '42',
        {
          uri: 'file:///portfolio.mp4',
          size: 4,
        },
        '11111111-1111-4111-8111-111111111111',
        {epoch: 1, scope: 'user-a'},
      ),
    ).rejects.toThrow('ACCOUNT_CHANGED');
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(global.fetch).not.toHaveBeenCalled();
    expect(mockReportClientError).not.toHaveBeenCalled();
  });
});
