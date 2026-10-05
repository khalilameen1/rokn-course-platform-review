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
        return new Response(null, {
          status: 201,
          headers: {Location: '/upload/1'},
        });
      }
      return new Response(null, {
        status: 200,
        headers: {'Upload-Offset': '0'},
      });
    });

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

  it('aborts the native PATCH and retains the same resume record and claim', async () => {
    let sent!: () => void;
    const sending = new Promise<void>(resolve => {
      sent = resolve;
    });
    const aborted = jest.fn();
    class CancelPatchRequest {
      timeout = 0;
      onabort: (() => void) | null = null;
      open() {}
      setRequestHeader() {}
      send() {
        sent();
      }
      abort() {
        aborted();
        this.onabort?.();
      }
    }
    global.XMLHttpRequest =
      CancelPatchRequest as unknown as typeof XMLHttpRequest;
    const controller = new AbortController();
    const requestId = '11111111-1111-4111-8111-111111111111';
    const upload = uploadPortfolioVideo(
      '42',
      {uri: 'file:///portfolio.mp4', type: 'video/mp4', size: 4},
      requestId,
      {epoch: 1, scope: 'user-a'},
      undefined,
      controller.signal,
    );
    await sending;
    controller.abort();
    await expect(upload).rejects.toThrow('PORTFOLIO_UPLOAD_PAUSED');
    expect(aborted).toHaveBeenCalledTimes(1);
    const saved = JSON.parse(mockStorage.get('@portfolio-video:user-a')!)[
      requestId
    ];
    expect(saved.uploadUrl).toBe('https://video.example/upload/1');
    expect(saved.claim).toBe('claim-2');
    expect(mockPost.mock.calls.some(([url]) => url.endsWith('/claim'))).toBe(
      false,
    );
  });

  it('reports resumed payload bytes but waits for valid PATCH offset and server claim', async () => {
    let sent!: (request: ProgressPatchRequest) => void;
    const sending = new Promise<ProgressPatchRequest>(resolve => {
      sent = resolve;
    });
    let claimed!: () => void;
    const claiming = new Promise<void>(resolve => {
      claimed = resolve;
    });
    let finishClaim!: (response: unknown) => void;
    const claimResponse = new Promise(resolve => {
      finishClaim = resolve;
    });
    class ProgressPatchRequest {
      status = 204;
      timeout = 0;
      upload: {onprogress: ((event: {loaded: number}) => void) | null} = {
        onprogress: null,
      };
      onload: (() => void) | null = null;
      open() {}
      setRequestHeader() {}
      getResponseHeader() {
        return '8';
      }
      send() {
        sent(this);
      }
    }
    global.XMLHttpRequest =
      ProgressPatchRequest as unknown as typeof XMLHttpRequest;
    global.fetch = jest.fn(
      async (_url: unknown, init?: RequestInit) =>
        new Response(null, {
          status: 200,
          headers:
            init?.method === 'POST'
              ? {Location: '/upload/1'}
              : {'Upload-Offset': '4'},
        }),
    );
    mockPost.mockReset();
    mockPost.mockImplementation((endpoint: string) => {
      if (endpoint.endsWith('/claim')) {
        claimed();
        return claimResponse;
      }
      return Promise.resolve({
        data: {
          data: {
            upload_endpoint: 'https://video.example/tus',
            claim: 'claim-1',
            headers: {Authorization: 'upload-only'},
          },
        },
      });
    });
    const observer = jest.fn();
    const upload = uploadPortfolioVideo(
      '42',
      {uri: 'file:///portfolio.mp4', type: 'video/mp4', size: 8},
      '11111111-1111-4111-8111-111111111111',
      {epoch: 1, scope: 'user-a'},
      observer,
    );
    const patch = await sending;
    expect(observer).toHaveBeenLastCalledWith({
      loaded: 4,
      total: 8,
      phase: 'uploading',
    });
    const nativeProgress = patch.upload.onprogress!;
    nativeProgress({loaded: 2});
    expect(observer).toHaveBeenLastCalledWith({
      loaded: 6,
      total: 8,
      phase: 'uploading',
    });
    nativeProgress({loaded: 99});
    expect(observer).toHaveBeenLastCalledWith({
      loaded: 8,
      total: 8,
      phase: 'saving',
    });
    expect(mockPost.mock.calls.some(([url]) => url.endsWith('/claim'))).toBe(
      false,
    );
    patch.onload?.();
    await claiming;
    expect(patch.upload.onprogress).toBeNull();
    const callbacks = observer.mock.calls.length;
    nativeProgress({loaded: 1});
    expect(observer).toHaveBeenCalledTimes(callbacks);
    finishClaim({
      data: {data: {id: 11, file_type: 'video', status: 'processing'}},
    });
    await expect(upload).resolves.toMatchObject({id: 11, status: 'processing'});
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
