const mockStorage = new Map<string, string>();
const mockPost = jest.fn();

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
  assertAccountSessionBoundary: jest.fn(),
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
        headers: {get: (name: string) => (name === 'Upload-Offset' ? '0' : null)},
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
});
