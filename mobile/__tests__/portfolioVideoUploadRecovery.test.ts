/*
 * The real tus-js-client browser build runs here (jest.config.js maps the
 * package to the same build Metro resolves); only XMLHttpRequest, the file
 * system and the Rokn API are faked, as the dashboard's
 * bunny-upload-modules.test.mjs does.
 */
const mockStorage = new Map<string, string>();
const mockPost = jest.fn();
const mockReportClientError = jest.fn();
const mockReads: Array<{offset: number; length: number}> = [];
let mockEpoch = 1;
let mockFileBytes = new Uint8Array(0);

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
    stat: jest.fn(async () => ({size: mockFileBytes.length})),
    hash: jest.fn(async () => 'a'.repeat(64)),
    read: jest.fn(async (_path: string, length: number, offset: number) => {
      mockReads.push({offset, length});
      return Buffer.from(
        mockFileBytes.subarray(offset, offset + length),
      ).toString('base64');
    }),
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

import {
  PORTFOLIO_VIDEO_CHUNK_BYTES as CHUNK,
  uploadPortfolioVideo,
} from '../src/services/portfolioVideoUpload';

const REQUEST_ID = '11111111-1111-4111-8111-111111111111';
const STORAGE = '@portfolio-video:user-a';
const ENDPOINT = 'https://video.bunnycdn.com/tusupload';
const FIRST_UPLOAD_URL = 'https://video.bunnycdn.com/tus/video-1';

type Scripted =
  | {status: number; headers?: Record<string, string>}
  | 'network-error'
  | 'hang'
  | null
  | undefined;
type ProgressEvent = {lengthComputable: boolean; loaded: number; total: number};
type Listener = (event?: unknown) => void;
type FakeRequest = {
  method: string;
  url: string;
  headers: Record<string, string>;
  aborted: boolean;
  upload: {onprogress: ((event: ProgressEvent) => void) | null};
};

/*
 * A scripted TUS server behind a fake XMLHttpRequest. `respond` may return
 * {status, headers}, 'network-error' or 'hang'. By default it implements
 * creation, HEAD offset lookup and PATCH append for every upload URL.
 */
const tusServer = (
  respond: (xhr: FakeRequest, body: unknown, count: number) => Scripted = () =>
    null,
) => {
  const log: Array<{
    method: string;
    url: string;
    headers: Record<string, string>;
    bodySize: number;
    request: FakeRequest;
  }> = [];
  const offsets = new Map<string, number>();
  const live = new Set<FakeXMLHttpRequest>();
  const offsetMismatches: string[] = [];
  let created = 0;
  const standard = (xhr: FakeRequest, bodySize: number): Scripted => {
    if (xhr.method === 'POST') {
      created += 1;
      const location = `/tus/video-${created}`;
      offsets.set(new URL(location, xhr.url).toString(), 0);
      return {status: 201, headers: {Location: location}};
    }
    if (!offsets.has(xhr.url)) return {status: 404};
    if (xhr.method === 'HEAD') {
      return {
        status: 200,
        headers: {
          'Upload-Offset': String(offsets.get(xhr.url)),
          'Upload-Length': String(mockFileBytes.length),
        },
      };
    }
    const offset = Number(xhr.headers['Upload-Offset']);
    if (offset !== offsets.get(xhr.url)) {
      offsetMismatches.push(`${offset} != ${offsets.get(xhr.url)}`);
      return {status: 409};
    }
    const next = offset + bodySize;
    offsets.set(xhr.url, next);
    return {status: 204, headers: {'Upload-Offset': String(next)}};
  };
  const emitter = () => {
    const listeners = new Map<string, Listener[]>();
    return {
      addEventListener: (type: string, listener: Listener) => {
        listeners.set(type, [...(listeners.get(type) || []), listener]);
      },
      dispatch: (type: string, event?: unknown) =>
        (listeners.get(type) || []).forEach(listener => listener(event)),
    };
  };
  class FakeXMLHttpRequest implements FakeRequest {
    method = '';
    url = '';
    headers: Record<string, string> = {};
    status = 0;
    responseText = '';
    responseHeaders: Record<string, string> = {};
    aborted = false;
    onload: (() => void) | null = null;
    onerror: ((error: unknown) => void) | null = null;
    private events = emitter();
    upload: {
      onprogress: ((event: ProgressEvent) => void) | null;
      addEventListener: (type: string, listener: Listener) => void;
      dispatch: (type: string, event?: unknown) => void;
    } = {onprogress: null, ...emitter()};

    open(method: string, url: string) {
      this.method = method;
      this.url = url;
    }
    setRequestHeader(name: string, value: string) {
      this.headers[name] = value;
    }
    getResponseHeader(name: string) {
      const key = Object.keys(this.responseHeaders).find(
        header => header.toLowerCase() === name.toLowerCase(),
      );
      return key === undefined ? null : this.responseHeaders[key];
    }
    addEventListener(type: string, listener: Listener) {
      this.events.addEventListener(type, listener);
    }
    dispatch(type: string) {
      this.events.dispatch(type);
    }
    send(body: unknown) {
      const bodySize = Number(
        (body as {size?: number; byteLength?: number} | null)?.size ??
          (body as {byteLength?: number} | null)?.byteLength ??
          0,
      );
      log.push({
        method: this.method,
        url: this.url,
        headers: {...this.headers},
        bodySize,
        request: this,
      });
      live.add(this);
      setTimeout(() => {
        if (this.aborted) return;
        const scripted = respond(this, body, log.length);
        if (scripted === 'hang') return;
        live.delete(this);
        if (scripted === 'network-error') {
          this.onerror?.(new Error('network'));
          this.dispatch('loadend');
          return;
        }
        const result = (scripted || standard(this, bodySize)) as {
          status: number;
          headers?: Record<string, string>;
        };
        this.status = result.status;
        this.responseHeaders = result.headers || {};
        if (bodySize) {
          const progress = {
            lengthComputable: true,
            loaded: bodySize,
            total: bodySize,
          };
          this.upload.onprogress?.(progress);
          this.upload.dispatch('progress', progress);
        }
        this.onload?.();
        this.dispatch('loadend');
      }, 0);
    }
    abort() {
      this.aborted = true;
      live.delete(this);
      this.dispatch('loadend');
    }
  }
  global.XMLHttpRequest =
    FakeXMLHttpRequest as unknown as typeof XMLHttpRequest;
  return {
    log,
    offsets,
    live,
    offsetMismatches,
    methods: () => log.map(entry => entry.method),
  };
};

const bunnyHeaders = (signature: string) => ({
  AuthorizationSignature: signature,
  AuthorizationExpire: '1999999999',
  VideoId: 'video-guid',
  LibraryId: '42',
});
const issued = (overrides: Record<string, unknown> = {}) => ({
  data: {
    data: {
      upload_endpoint: ENDPOINT,
      claim: 'claim-1',
      headers: bunnyHeaders('issued'),
      authorization_expires_in_seconds: 300,
      ...overrides,
    },
  },
});
const renewed = (overrides: Record<string, unknown> = {}) => ({
  data: {
    data: {
      claim: 'claim-2',
      headers: bunnyHeaders('renewed'),
      attached: false,
      authorization_expires_in_seconds: 300,
      ...overrides,
    },
  },
});
const media = {
  data: {data: {id: 11, file_type: 'video', status: 'processing'}},
};

const api = (
  handlers: {
    issue?: () => unknown;
    renew?: () => unknown;
    claim?: () => unknown;
  } = {},
) => {
  mockPost.mockImplementation(async (endpoint: string) => {
    if (endpoint.endsWith('/renew')) return handlers.renew?.() ?? renewed();
    if (endpoint.endsWith('/claim')) return handlers.claim?.() ?? media;
    return handlers.issue?.() ?? issued();
  });
};
const calls = (suffix: string) =>
  mockPost.mock.calls.filter(([endpoint]) => String(endpoint).endsWith(suffix));
const decodeMetadata = (header: string) =>
  Object.fromEntries(
    header.split(',').map(pair => {
      const [key, value] = pair.split(' ');
      return [key, Buffer.from(value, 'base64').toString('utf8')];
    }),
  );

const videoFile = (size: number) => {
  mockFileBytes = new Uint8Array(size).fill(7);
  return {
    uri: 'file:///portfolio.mp4',
    type: 'video/mp4',
    fileName: 'portfolio.mp4',
    size,
  };
};
const upload = (
  file: ReturnType<typeof videoFile>,
  signal?: AbortSignal,
  observer?: jest.Mock,
) =>
  uploadPortfolioVideo(
    '42',
    file,
    REQUEST_ID,
    {epoch: 1, scope: 'user-a'},
    observer,
    signal,
  );
const seedRecord = (overrides: Record<string, unknown> = {}) =>
  mockStorage.set(
    STORAGE,
    JSON.stringify({
      [REQUEST_ID]: {
        upload_endpoint: ENDPOINT,
        claim: 'claim-1',
        headers: bunnyHeaders('stale'),
        attached: false,
        projectId: '42',
        clientRequestId: REQUEST_ID,
        uploadUrl: FIRST_UPLOAD_URL,
        size: mockFileBytes.length,
        mime: 'video/mp4',
        originalName: 'portfolio.mp4',
        sha256: 'a'.repeat(64),
        ...overrides,
      },
    }),
  );
const savedRecord = () =>
  JSON.parse(mockStorage.get(STORAGE) || '{}')[REQUEST_ID];
const until = async (condition: () => boolean) => {
  for (let turn = 0; turn < 200 && !condition(); turn += 1) {
    await new Promise(resolve => setImmediate(resolve));
  }
  expect(condition()).toBe(true);
};

describe('portfolio resumable video upload through tus-js-client', () => {
  const originalXhr = global.XMLHttpRequest;

  beforeEach(() => {
    jest.clearAllMocks();
    mockEpoch = 1;
    mockStorage.clear();
    mockReads.length = 0;
    mockPost.mockReset();
    api();
  });

  afterAll(() => {
    global.XMLHttpRequest = originalXhr;
  });

  it('creates the upload with Bunny headers and metadata, sends bounded chunks, then claims', async () => {
    const size = 2 * CHUNK + 5;
    const file = videoFile(size);
    let recordAtFirstPatch: string | undefined;
    const server = tusServer(xhr => {
      if (xhr.method === 'PATCH' && recordAtFirstPatch === undefined) {
        recordAtFirstPatch = savedRecord()?.uploadUrl;
      }
      return null;
    });
    const observer = jest.fn();

    await expect(upload(file, undefined, observer)).resolves.toMatchObject({
      id: 11,
      status: 'processing',
    });

    expect(server.methods()).toEqual(['POST', 'PATCH', 'PATCH', 'PATCH']);
    expect(server.offsetMismatches).toEqual([]);
    const [create, ...patches] = server.log;
    expect(create.url).toBe(ENDPOINT);
    expect(create.headers).toMatchObject({
      ...bunnyHeaders('issued'),
      'Tus-Resumable': '1.0.0',
      'Upload-Length': String(size),
    });
    expect(decodeMetadata(create.headers['Upload-Metadata'])).toEqual({
      filename: 'portfolio.mp4',
      filetype: 'video/mp4',
    });
    expect(patches.map(entry => entry.headers['Upload-Offset'])).toEqual([
      '0',
      String(CHUNK),
      String(2 * CHUNK),
    ]);
    expect(patches.map(entry => entry.bodySize)).toEqual([CHUNK, CHUNK, 5]);
    patches.forEach(entry =>
      expect(entry.headers).toMatchObject({
        ...bunnyHeaders('issued'),
        'Tus-Resumable': '1.0.0',
        'Content-Type': 'application/offset+octet-stream',
      }),
    );
    // Bounded reads: one ranged read per PATCH, never larger than chunkSize.
    expect(mockReads).toEqual([
      {offset: 0, length: CHUNK},
      {offset: CHUNK, length: CHUNK},
      {offset: 2 * CHUNK, length: 5},
    ]);
    expect(Math.max(...mockReads.map(read => read.length))).toBe(CHUNK);
    // The upload URL is durable before the first byte is sent.
    expect(recordAtFirstPatch).toBe(FIRST_UPLOAD_URL);
    // A freshly issued authorization (300 s) is not renewed between chunks.
    expect(calls('/renew')).toHaveLength(0);
    expect(calls('/video-uploads')).toHaveLength(1);
    const [[, claimBody, claimOptions]] = calls('/claim');
    expect(claimBody).toEqual({claim: 'claim-1'});
    expect(claimOptions.headers['Idempotency-Key']).toBe(REQUEST_ID);
    expect(mockStorage.has(STORAGE)).toBe(false);
    expect(observer.mock.calls[0][0]).toEqual({
      loaded: 0,
      total: size,
      phase: 'preparing',
    });
    expect(observer).toHaveBeenCalledWith({
      loaded: CHUNK,
      total: size,
      phase: 'uploading',
    });
    expect(observer).toHaveBeenLastCalledWith({
      loaded: size,
      total: size,
      phase: 'saving',
    });
    expect(
      observer.mock.calls.every(
        ([progress]) => progress.loaded <= size && progress.total === size,
      ),
    ).toBe(true);
  });

  it('resumes a stored upload from the server offset after renewing, reading only the remaining bytes', async () => {
    const size = CHUNK + 5;
    const file = videoFile(size);
    seedRecord();
    const server = tusServer();
    server.offsets.set(FIRST_UPLOAD_URL, CHUNK);

    await expect(upload(file)).resolves.toMatchObject({id: 11});

    expect(server.methods()).toEqual(['HEAD', 'PATCH']);
    expect(calls('/video-uploads')).toHaveLength(0);
    expect(calls('/renew')).toHaveLength(1);
    // Authorization persisted by an earlier run is renewed before its first request.
    expect(server.log[0].headers).toMatchObject(bunnyHeaders('renewed'));
    expect(server.log[1].headers['Upload-Offset']).toBe(String(CHUNK));
    expect(mockReads).toEqual([{offset: CHUNK, length: 5}]);
    expect(calls('/claim')[0][1]).toEqual({claim: 'claim-2'});
    expect(mockStorage.has(STORAGE)).toBe(false);
  });

  it('claims a stored upload the server already holds completely without reading the file', async () => {
    const file = videoFile(5);
    seedRecord();
    const server = tusServer();
    server.offsets.set(FIRST_UPLOAD_URL, 5);

    await expect(upload(file)).resolves.toMatchObject({id: 11});
    expect(server.methods()).toEqual(['HEAD']);
    expect(mockReads).toEqual([]);
    expect(calls('/claim')).toHaveLength(1);
  });

  it('renews before every request while the authorization lifetime is unknown', async () => {
    const file = videoFile(CHUNK + 5);
    let renewals = 0;
    api({
      issue: () => issued({authorization_expires_in_seconds: undefined}),
      renew: () => {
        renewals += 1;
        return renewed({
          headers: bunnyHeaders(`renewed-${renewals}`),
          authorization_expires_in_seconds: undefined,
        });
      },
    });
    const server = tusServer();

    await expect(upload(file)).resolves.toMatchObject({id: 11});
    expect(server.methods()).toEqual(['POST', 'PATCH', 'PATCH']);
    expect(calls('/renew')).toHaveLength(3);
    expect(
      server.log.map(entry => entry.headers.AuthorizationSignature),
    ).toEqual(['renewed-1', 'renewed-2', 'renewed-3']);
  });

  it('renews through our API after a 401 and retries the same resumable upload', async () => {
    const file = videoFile(5);
    const server = tusServer((xhr, _body, count) =>
      xhr.method === 'PATCH' && count === 2 ? {status: 401} : null,
    );

    await expect(upload(file)).resolves.toMatchObject({id: 11});
    expect(server.methods()).toEqual(['POST', 'PATCH', 'HEAD', 'PATCH']);
    expect(server.log[1].headers).toMatchObject(bunnyHeaders('issued'));
    expect(server.log[2].headers).toMatchObject(bunnyHeaders('renewed'));
    expect(server.log[3].headers).toMatchObject(bunnyHeaders('renewed'));
    expect(calls('/renew')).toHaveLength(1);
    expect(calls('/video-uploads')).toHaveLength(1);
    expect(calls('/claim')[0][1]).toEqual({claim: 'claim-2'});
  });

  it('stops after one renewed authorization instead of looping forever', async () => {
    const file = videoFile(5);
    const server = tusServer(xhr =>
      xhr.method === 'PATCH' ? {status: 403} : null,
    );

    await expect(upload(file)).rejects.toMatchObject({
      message: 'PORTFOLIO_VIDEO_UPLOAD_FAILED',
      status: 403,
    });
    expect(server.methods()).toEqual(['POST', 'PATCH', 'HEAD', 'PATCH']);
    expect(calls('/renew')).toHaveLength(1);
    expect(calls('/claim')).toHaveLength(0);
    expect(savedRecord()).toMatchObject({
      uploadUrl: FIRST_UPLOAD_URL,
      claim: 'claim-2',
    });
  });

  it('re-allocates once when the provider lost the video, then fails without an allocation loop', async () => {
    const file = videoFile(5);
    const server = tusServer(xhr =>
      xhr.method === 'POST' ? {status: 404} : null,
    );

    const error = await upload(file).catch((value: unknown) => value);
    expect(error).toBeInstanceOf(Error);
    expect((error as Error).message).toBe(
      'PORTFOLIO_VIDEO_UPLOAD_START_FAILED',
    );
    // A provider 404 is not the project's 404: no status for the outbox to discard on.
    expect((error as {status?: unknown}).status).toBeUndefined();
    expect(calls('/video-uploads')).toHaveLength(2);
    expect(server.methods()).toEqual(['POST', 'POST']);
    expect(calls('/claim')).toHaveLength(0);
  });

  it('recreates a forgotten upload URL through tus and re-allocates once when creation is gone too', async () => {
    const file = videoFile(5);
    seedRecord();
    let creations = 0;
    const server = tusServer(xhr => {
      if (xhr.method !== 'POST') return null;
      creations += 1;
      return creations === 1 ? {status: 410} : null;
    });

    await expect(upload(file)).resolves.toMatchObject({id: 11});
    expect(server.methods()).toEqual(['HEAD', 'POST', 'POST', 'PATCH']);
    expect(calls('/video-uploads')).toHaveLength(1);
    expect(server.log[2].headers).toMatchObject(bunnyHeaders('issued'));
    expect(calls('/claim')[0][1]).toEqual({claim: 'claim-1'});
  });

  it('returns provider and transport failures to the outbox with their codes, without an inner retry clock', async () => {
    tusServer(xhr => (xhr.method === 'PATCH' ? 'network-error' : null));
    const network = await upload(videoFile(5)).catch((value: unknown) => value);
    expect((network as Error).message).toBe('PORTFOLIO_VIDEO_UPLOAD_FAILED');
    expect((network as {status?: unknown}).status).toBeUndefined();
    expect(calls('/claim')).toHaveLength(0);

    mockStorage.clear();
    const rejected = tusServer(xhr =>
      xhr.method === 'PATCH' ? {status: 413} : null,
    );
    await expect(upload(videoFile(5))).rejects.toMatchObject({
      message: 'PORTFOLIO_VIDEO_UPLOAD_FAILED',
      status: 413,
    });
    expect(rejected.methods()).toEqual(['POST', 'PATCH']);
  });

  it('rejects a provider acknowledgement outside the declared length or without progress', async () => {
    tusServer(xhr =>
      xhr.method === 'PATCH'
        ? {status: 204, headers: {'Upload-Offset': '9'}}
        : null,
    );
    await expect(upload(videoFile(5))).rejects.toThrow(
      'PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID',
    );

    mockStorage.clear();
    tusServer(xhr =>
      xhr.method === 'PATCH'
        ? {status: 204, headers: {'Upload-Offset': '0'}}
        : null,
    );
    await expect(upload(videoFile(5))).rejects.toThrow(
      'PORTFOLIO_VIDEO_UPLOAD_STALLED',
    );
    expect(calls('/claim')).toHaveLength(0);
  });

  it('pause aborts the in-flight PATCH, keeps the record and claim, and the next run resumes it', async () => {
    const file = videoFile(5);
    let hang = true;
    const server = tusServer(xhr =>
      xhr.method === 'PATCH' && hang ? 'hang' : null,
    );
    const controller = new AbortController();
    const observer = jest.fn();

    const running = upload(file, controller.signal, observer);
    await until(() => server.methods().includes('PATCH'));
    controller.abort();
    await expect(running).rejects.toThrow('PORTFOLIO_UPLOAD_PAUSED');

    expect(server.log[1].request.aborted).toBe(true);
    expect(server.live.size).toBe(0);
    expect(savedRecord()).toMatchObject({
      uploadUrl: FIRST_UPLOAD_URL,
      claim: 'claim-1',
    });
    expect(calls('/claim')).toHaveLength(0);
    const callbacks = observer.mock.calls.length;
    server.log[1].request.upload.onprogress?.({
      lengthComputable: true,
      loaded: 3,
      total: 5,
    });
    expect(observer).toHaveBeenCalledTimes(callbacks);

    hang = false;
    await expect(upload(file)).resolves.toMatchObject({id: 11});
    expect(server.methods()).toEqual(['POST', 'PATCH', 'HEAD', 'PATCH']);
    expect(calls('/video-uploads')).toHaveLength(1);
  });

  it('abandons a request that makes no progress for the stall window', async () => {
    jest.useFakeTimers();
    try {
      const file = videoFile(5);
      const server = tusServer(xhr => (xhr.method === 'PATCH' ? 'hang' : null));
      const running = upload(file);
      const settled = running.catch((value: unknown) => value);
      while (!server.methods().includes('PATCH')) {
        await jest.advanceTimersByTimeAsync(1);
      }
      await jest.advanceTimersByTimeAsync(119_000);
      expect(server.log[1].request.aborted).toBe(false);
      await jest.advanceTimersByTimeAsync(2_000);
      expect((await settled) as Error).toMatchObject({
        message: 'PORTFOLIO_VIDEO_UPLOAD_TIMEOUT',
      });
      expect(server.log[1].request.aborted).toBe(true);
      expect(calls('/claim')).toHaveLength(0);
    } finally {
      jest.useRealTimers();
    }
  });

  it('discards the transfer when the account changes mid-upload', async () => {
    const file = videoFile(CHUNK + 5);
    const observer = jest.fn();
    const server = tusServer(xhr => {
      if (xhr.method === 'PATCH') mockEpoch = 2;
      return null;
    });

    await expect(upload(file, undefined, observer)).rejects.toThrow(
      'ACCOUNT_CHANGED',
    );
    expect(server.methods()).toEqual(['POST', 'PATCH']);
    expect(calls('/claim')).toHaveLength(0);
    expect(
      observer.mock.calls.every(([progress]) => progress.loaded < CHUNK),
    ).toBe(true);
  });

  it('returns confirmed media when removing the old upload record fails', async () => {
    const storage = require('@react-native-async-storage/async-storage');
    storage.removeItem.mockRejectedValueOnce(new Error('disk I/O'));
    const server = tusServer();
    api({issue: () => ({data: {data: {attached: true, claim: 'claim-1'}}})});

    await expect(upload(videoFile(4))).resolves.toMatchObject({id: 11});
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(server.log).toEqual([]);
    expect(mockReportClientError).toHaveBeenCalledWith(
      expect.objectContaining({message: 'PORTFOLIO_VIDEO_TERMINAL_CLEANUP'}),
      {source: 'portfolio_video_terminal_cleanup'},
    );
  });

  it('reclaims the same confirmed media from a retained record without uploading a second chunk', async () => {
    const storage = require('@react-native-async-storage/async-storage');
    storage.removeItem.mockRejectedValueOnce(new Error('disk I/O'));
    const server = tusServer();
    const file = videoFile(4);
    let renewals = 0;
    api({
      renew: () => {
        renewals += 1;
        return {data: {data: {attached: true}}};
      },
    });

    await expect(upload(file)).resolves.toMatchObject({id: 11});
    expect(server.methods()).toEqual(['POST', 'PATCH']);
    expect(savedRecord().uploadUrl).toBe(FIRST_UPLOAD_URL);
    expect(renewals).toBe(0);

    await expect(upload(file)).resolves.toMatchObject({id: 11});
    expect(server.methods()).toEqual(['POST', 'PATCH']);
    expect(renewals).toBe(1);
    expect(mockStorage.has(STORAGE)).toBe(false);
    const claims = calls('/claim');
    expect(claims).toHaveLength(2);
    claims.forEach(([, body, options]) => {
      expect(body).toEqual({claim: 'claim-1'});
      expect(options.headers['Idempotency-Key']).toBe(REQUEST_ID);
    });
    expect(mockPost.mock.calls.map(([endpoint]) => endpoint).slice(2)).toEqual([
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
    const server = tusServer();
    api({issue: () => ({data: {data: {attached: true, claim: 'claim-1'}}})});

    await expect(upload(videoFile(4))).rejects.toThrow('ACCOUNT_CHANGED');
    expect(mockPost).toHaveBeenCalledTimes(2);
    expect(server.log).toEqual([]);
    expect(mockReportClientError).not.toHaveBeenCalled();
  });
});
