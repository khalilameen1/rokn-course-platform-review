import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';
import {DetailedError, Upload} from 'tus-js-client';

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {publicRequest} from '../constants/api';
import type {LearnerDraftFile} from './learnerDraftFiles';
import {readJsonOrQuarantine} from './recoverableJsonStorage';
import type {PortfolioTransferObserver} from './portfolioUploadProgress';
import {assertPortfolioTransferActive} from './portfolioTransferControl';

/*
 * Bunny Stream direct upload through tus-js-client, the same engine and
 * option set as the dashboard's course-studio-bunny-upload-transfer.js
 * (Bunny's documented integration: endpoint https://video.bunnycdn.com/tusupload,
 * AuthorizationSignature / AuthorizationExpire / VideoId / LibraryId headers,
 * filename + filetype metadata, https://docs.bunny.net/reference/tus-resumable-uploads).
 *
 * tus-js-client owns the protocol: creation (POST + Location), offset lookup
 * (HEAD), PATCH chunks with Upload-Offset, offset acknowledgement and
 * re-creation when a stored upload URL is gone. Rokn owns only what tus does
 * not know: the server allocation and signed claim, authorization renewal,
 * the account-scoped resume record, bounded file reads, progress phases and
 * the stable error codes the durable outbox classifies.
 */

type Authorization = {
  upload_endpoint?: string;
  claim?: string;
  headers?: Record<string, string>;
  attached?: boolean;
  authorization_expires_in_seconds?: number;
};

type UploadRecord = {
  upload_endpoint: string;
  claim: string;
  headers: Record<string, string>;
  attached?: boolean;
  projectId: string;
  clientRequestId: string;
  uploadUrl?: string;
  size: number;
  mime: string;
  originalName: string;
  sha256: string;
};

type UploadIdentity = Pick<
  UploadRecord,
  'projectId' | 'clientRequestId' | 'size' | 'mime' | 'originalName' | 'sha256'
>;

/** One transfer attempt: the durable record plus its in-memory authorization state. */
type UploadSession = {
  record: UploadRecord;
  /** Epoch ms until which `record.headers` are known to be accepted; 0 = unknown. */
  authorizationDeadline: number;
  authorizationRefreshes: number;
};

type TransferContext = {
  key: string;
  boundary: AccountSessionBoundary;
  signal?: AbortSignal;
  path: string;
  report: (loaded: number, phase: 'preparing' | 'uploading' | 'saving') => void;
};

/** Errors raised by Rokn's own tus hooks; surfaced to callers unchanged. */
type HookError = Error & {
  roknHook?: boolean;
  attached?: boolean;
  authorizationRejected?: boolean;
  status?: number;
};

/** A Uint8Array body measured the way tus-js-client measures a Blob (`size`). */
type TransferChunk = Uint8Array & {size: number};

type StallWatchedRequest = {
  abort: () => void;
  onerror?: ((error: unknown) => void) | null;
  addEventListener?: (type: string, listener: () => void) => void;
  upload?: {addEventListener?: (type: string, listener: () => void) => void};
};

const STORAGE_KEY = '@rokn/portfolio-direct-video/v1';
export const PORTFOLIO_VIDEO_CHUNK_BYTES = 4 * 1024 * 1024;
const AUTHORIZATION_MARGIN_MS = 60_000;
const STALL_TIMEOUT_MS = 120_000;

const hookError = (message: string, extra: Partial<HookError> = {}) =>
  Object.assign(new Error(message), {roknHook: true, ...extra}) as HookError;

const responseData = <T>(response: unknown): T => {
  const root = (response as {data?: unknown})?.data as
    | {data?: unknown}
    | undefined;
  return ((root && 'data' in root ? root.data : root) ?? {}) as T;
};

let recordsOperation: Promise<unknown> = Promise.resolve();
const withRecordsLock = <T>(callback: () => Promise<T>): Promise<T> => {
  const result = recordsOperation.then(callback, callback);
  recordsOperation = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

const readRecordsUnlocked = async (
  key: string,
): Promise<Record<string, UploadRecord>> =>
  readJsonOrQuarantine(
    key,
    () => ({}),
    parsed =>
      parsed && typeof parsed === 'object' && !Array.isArray(parsed)
        ? (parsed as Record<string, UploadRecord>)
        : null,
  );

const readRecords = (key: string, boundary: AccountSessionBoundary) =>
  withRecordsLock(async () => {
    assertAccountSessionBoundary(boundary);
    const records = await readRecordsUnlocked(key);
    assertAccountSessionBoundary(boundary);
    return records;
  });

const saveRecord = (
  key: string,
  boundary: AccountSessionBoundary,
  record: UploadRecord,
) =>
  withRecordsLock(async () => {
    assertAccountSessionBoundary(boundary);
    const records = await readRecordsUnlocked(key);
    records[record.clientRequestId] = record;
    await AsyncStorage.setItem(key, JSON.stringify(records));
    assertAccountSessionBoundary(boundary);
  });

const removeRecord = (
  key: string,
  boundary: AccountSessionBoundary,
  clientRequestId: string,
) =>
  withRecordsLock(async () => {
    assertAccountSessionBoundary(boundary);
    try {
      const records = await readRecordsUnlocked(key);
      assertAccountSessionBoundary(boundary);
      delete records[clientRequestId];
      if (Object.keys(records).length) {
        await AsyncStorage.setItem(key, JSON.stringify(records));
      } else {
        await AsyncStorage.removeItem(key);
      }
    } catch {
      // Claim already attached the video. An old resume record must not make
      // that confirmed media disappear or invite a second upload.
      assertAccountSessionBoundary(boundary);
      void import('./operationalTelemetry')
        .then(({reportClientError}) =>
          reportClientError(new Error('PORTFOLIO_VIDEO_TERMINAL_CLEANUP'), {
            source: 'portfolio_video_terminal_cleanup',
          }),
        )
        .catch(() => undefined);
    }
    assertAccountSessionBoundary(boundary);
  });

const localPath = (uri: string) => {
  const value = uri.startsWith('file://') ? uri.slice(7) : uri;
  try {
    return decodeURIComponent(value);
  } catch {
    return value;
  }
};

/**
 * Reads exactly [offset, offset + length) through react-native-fs. Nothing
 * else of the file is ever resident: one chunk (base64 text, its decoded
 * bytes) at a time, released once tus-js-client's PATCH settles.
 */
const readChunk = async (
  path: string,
  offset: number,
  length: number,
): Promise<TransferChunk> => {
  const base64 =
    length > 0 ? await RNFS.read(path, length, offset, 'base64') : '';
  const decoded = global.atob(base64);
  const bytes = new Uint8Array(decoded.length);
  for (let index = 0; index < decoded.length; index += 1) {
    bytes[index] = decoded.charCodeAt(index);
  }
  // tus-js-client measures a chunk by `size` (the Blob contract) to detect a
  // source that ends before the declared upload length.
  return Object.assign(bytes, {size: bytes.byteLength});
};

/**
 * tus-js-client `fileReader` over ranged reads. The library's React Native
 * default fetches the whole `file.uri` as one Blob; this FileSource hands tus
 * only the `[start, end)` slice it asks for per PATCH (`chunkSize`).
 */
const boundedFileReader = (path: string, size: number) => ({
  openFile: async () => ({
    size,
    slice: async (start: number, end: number) => {
      const stop = Math.min(end, size);
      if (!Number.isSafeInteger(start) || start < 0 || start > stop) {
        throw hookError('PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID');
      }
      return {
        value: await readChunk(path, start, stop - start),
        done: stop >= size,
      };
    },
    close: () => undefined,
  }),
});

const applyAuthorization = (session: UploadSession, auth: Authorization) => {
  if (!auth.headers || typeof auth.headers !== 'object') {
    throw new Error('PORTFOLIO_VIDEO_UPLOAD_AUTH_INVALID');
  }
  const ttlSeconds = Number(auth.authorization_expires_in_seconds || 0);
  session.record.headers = auth.headers;
  session.authorizationDeadline =
    Number.isFinite(ttlSeconds) && ttlSeconds > 0
      ? Date.now() + ttlSeconds * 1000
      : 0;
  if (auth.claim) session.record.claim = auth.claim;
};

/** Rokn API call, not TUS. Resolves true when the claim is already attached. */
const renew = async (
  session: UploadSession,
  {key, boundary, signal}: TransferContext,
): Promise<boolean> => {
  assertAccountSessionBoundary(boundary);
  assertPortfolioTransferActive(signal);
  const {record} = session;
  const auth = responseData<Authorization>(
    await publicRequest.post(
      `portfolio/${record.projectId}/media/video-uploads/renew`,
      {claim: record.claim},
      {signal},
    ),
  );
  assertAccountSessionBoundary(boundary);
  if (auth.attached === true) return true;
  applyAuthorization(session, auth);
  await saveRecord(key, boundary, record);
  return false;
};

/** Bunny headers for the next request; renews through our API once stale. */
const freshAuthorization = async (
  session: UploadSession,
  context: TransferContext,
) => {
  if (
    session.record.headers &&
    session.authorizationDeadline > Date.now() + AUTHORIZATION_MARGIN_MS
  ) {
    return session.record.headers;
  }
  if (await renew(session, context)) {
    throw hookError('PORTFOLIO_VIDEO_UPLOAD_ATTACHED', {attached: true});
  }
  return session.record.headers;
};

/**
 * tus-js-client's XHR stack has no timeout, and React Native's XHR reports a
 * timeout as its own event that the stack does not listen to. A request that
 * makes no progress for STALL_TIMEOUT_MS becomes a normal request error;
 * progress re-arms the timer, so a slow but moving uplink is never cut off.
 */
const armStallWatchdog = (xhr: StallWatchedRequest | undefined) => {
  if (!xhr?.addEventListener) return () => undefined;
  let timer: ReturnType<typeof setTimeout> | undefined;
  const clear = () => {
    if (timer) clearTimeout(timer);
    timer = undefined;
  };
  const arm = () => {
    clear();
    timer = setTimeout(() => {
      xhr.abort();
      xhr.onerror?.(hookError('PORTFOLIO_VIDEO_UPLOAD_TIMEOUT'));
    }, STALL_TIMEOUT_MS);
  };
  xhr.upload?.addEventListener?.('progress', arm);
  xhr.addEventListener('loadend', clear);
  arm();
  return clear;
};

const statusOf = (error: DetailedError) =>
  Number(error.originalResponse?.getStatus?.() || 0);

/**
 * Maps a tus-js-client failure to the codes callers and the outbox classify.
 * Errors from Rokn's own hooks (renewal, account boundary, pause, state) pass
 * through unchanged; only provider (Bunny) failures are translated.
 */
const transferFailure = (error: unknown): HookError => {
  if (!(error instanceof DetailedError)) {
    return error instanceof Error
      ? error
      : new Error('PORTFOLIO_VIDEO_UPLOAD_FAILED');
  }
  const cause = (error.causingError ?? undefined) as HookError | undefined;
  if (cause?.roknHook) return cause;
  const status = cause?.authorizationRejected
    ? Number(cause.status || 403)
    : statusOf(error);
  const method = error.originalRequest?.getMethod?.() || 'PATCH';
  if (status >= 200 && status < 300) {
    // tus rejects a 2xx only when Location or Upload-Offset is unusable.
    return new Error(
      method === 'POST'
        ? 'PORTFOLIO_VIDEO_UPLOAD_START_FAILED'
        : 'PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID',
    );
  }
  if (method === 'POST')
    return new Error('PORTFOLIO_VIDEO_UPLOAD_START_FAILED');
  if (method === 'HEAD')
    return new Error('PORTFOLIO_VIDEO_UPLOAD_RESUME_FAILED');
  return Object.assign(
    new Error('PORTFOLIO_VIDEO_UPLOAD_FAILED'),
    status ? {status} : {},
  );
};

/** The provider no longer knows this video (deleted or expired upload resource). */
const providerLostVideo = (error: unknown) => {
  if (!(error instanceof DetailedError)) return false;
  const cause = (error.causingError ?? undefined) as HookError | undefined;
  if (cause?.roknHook) return false;
  return [404, 410].includes(statusOf(error));
};

const sendWithTus = (
  session: UploadSession,
  context: TransferContext,
): Promise<void> =>
  new Promise<void>((resolve, reject) => {
    const {record} = session;
    const {key, boundary, signal, path, report} = context;
    let settled = false;
    let persisted: Promise<void> = Promise.resolve();
    let clearWatchdog: () => void = () => undefined;
    const abortListener: {current: () => void} = {current: () => undefined};
    const finish = (callback: () => void) => {
      if (settled) return;
      settled = true;
      clearWatchdog();
      signal?.removeEventListener('abort', abortListener.current);
      callback();
    };
    const upload = new Upload(
      {
        uri: path,
        name: record.originalName,
        type: record.mime,
        size: record.size,
      } as unknown as Blob,
      {
        endpoint: record.upload_endpoint,
        uploadUrl: record.uploadUrl || null,
        chunkSize: PORTFOLIO_VIDEO_CHUNK_BYTES,
        // One retry slot, used only for the renewed-authorization hop below.
        // Every other failure returns to the durable outbox, the one retry
        // clock for portfolio media (ARCHITECTURE.md M2).
        retryDelays: [0],
        // The account-scoped record is the only resume store.
        storeFingerprintForResuming: false,
        fileReader: boundedFileReader(path, record.size),
        metadata: {filename: record.originalName, filetype: record.mime},
        onBeforeRequest: async request => {
          try {
            await persisted;
            assertAccountSessionBoundary(boundary);
            assertPortfolioTransferActive(signal);
            const headers = await freshAuthorization(session, context);
            Object.entries(headers).forEach(([name, value]) =>
              request.setHeader(name, String(value)),
            );
            assertAccountSessionBoundary(boundary);
            assertPortfolioTransferActive(signal);
          } catch (error) {
            throw Object.assign(error as Error, {roknHook: true});
          }
          clearWatchdog();
          clearWatchdog = armStallWatchdog(request.getUnderlyingObject());
        },
        onAfterResponse: (_request, response) => {
          const status = response.getStatus();
          // An expired signature must renew and retry, not make tus discard
          // the resumable URL as an unknown upload.
          if (status === 401 || status === 403) {
            session.authorizationDeadline = 0;
            throw Object.assign(
              new Error('PORTFOLIO_VIDEO_UPLOAD_AUTH_REJECTED'),
              {
                authorizationRejected: true,
                status,
              },
            );
          }
        },
        onShouldRetry: error => {
          if (settled || signal?.aborted) return false;
          const cause = (error.causingError ?? undefined) as
            | HookError
            | undefined;
          // A fresh Bunny authorization may legitimately be needed once while
          // a chunk is in flight. If Bunny rejects the renewed credentials
          // too, stop this delivery attempt and leave the outbox entry for a
          // later replay instead of spinning forever on the same chunk.
          if (
            cause?.authorizationRejected &&
            session.authorizationRefreshes < 1
          ) {
            session.authorizationRefreshes += 1;
            return true;
          }
          return false;
        },
        onUploadUrlAvailable: () => {
          if (!upload.url || record.uploadUrl === upload.url) return;
          record.uploadUrl = upload.url;
          // Durable before the first PATCH: the next onBeforeRequest awaits it.
          persisted = saveRecord(key, boundary, record);
          persisted.catch(() => undefined);
        },
        onProgress: (sent, total) => {
          if (settled) return;
          const loaded = Math.min(sent, total);
          report(loaded, loaded >= total ? 'saving' : 'uploading');
        },
        onChunkComplete: (chunkSize, accepted) => {
          // Rokn's invariant on the provider's acknowledgement: the offset
          // must advance and stay inside the declared upload length.
          if (accepted > record.size) {
            throw hookError('PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID');
          }
          if (chunkSize <= 0) throw hookError('PORTFOLIO_VIDEO_UPLOAD_STALLED');
          session.authorizationRefreshes = 0;
        },
        onSuccess: () => finish(resolve),
        onError: error => {
          const cause = ((error as DetailedError).causingError ?? undefined) as
            | HookError
            | undefined;
          // Renewal learned the claim is already attached: nothing to send.
          if (cause?.roknHook && cause.attached) finish(resolve);
          else finish(() => reject(error));
        },
      },
    );
    // abort(false) keeps the remote upload so the next replay resumes it.
    abortListener.current = () => {
      upload.abort(false).catch(() => undefined);
      finish(() => reject(new Error('PORTFOLIO_UPLOAD_PAUSED')));
    };
    if (signal?.aborted) {
      abortListener.current();
      return;
    }
    signal?.addEventListener('abort', abortListener.current);
    upload.start();
  });

const sameIdentity = (
  record: UploadRecord | undefined,
  identity: UploadIdentity,
): record is UploadRecord =>
  Boolean(
    record &&
      record.projectId === identity.projectId &&
      record.size === identity.size &&
      record.mime === identity.mime &&
      record.sha256 === identity.sha256,
  );

type Allocation =
  | {kind: 'attached'; claim: string}
  | {kind: 'session'; session: UploadSession};

/** Rokn API call, not TUS: the server-issued allocation and signed claim. */
const allocate = async (
  identity: UploadIdentity,
  {key, boundary, signal}: TransferContext,
): Promise<Allocation> => {
  assertAccountSessionBoundary(boundary);
  assertPortfolioTransferActive(signal);
  const issued = responseData<Authorization>(
    await publicRequest.post(
      `portfolio/${identity.projectId}/media/video-uploads`,
      {
        idempotency_key: identity.clientRequestId,
        size: identity.size,
        mime: identity.mime,
        original_name: identity.originalName,
        sha256: identity.sha256,
      },
      {headers: {'Idempotency-Key': identity.clientRequestId}, signal},
    ),
  );
  assertAccountSessionBoundary(boundary);
  if (issued.attached === true && issued.claim) {
    return {kind: 'attached', claim: issued.claim};
  }
  if (!issued.upload_endpoint || !issued.claim || !issued.headers) {
    throw new Error('PORTFOLIO_VIDEO_UPLOAD_AUTH_INVALID');
  }
  const session: UploadSession = {
    record: {
      ...identity,
      upload_endpoint: issued.upload_endpoint,
      claim: issued.claim,
      headers: issued.headers,
      attached: false,
    },
    authorizationDeadline: 0,
    authorizationRefreshes: 0,
  };
  applyAuthorization(session, issued);
  await saveRecord(key, boundary, session.record);
  return {kind: 'session', session};
};

export const uploadPortfolioVideo = async (
  projectId: string,
  file: LearnerDraftFile,
  clientRequestId: string,
  ownerBoundary?: AccountSessionBoundary,
  onProgress?: PortfolioTransferObserver,
  signal?: AbortSignal,
): Promise<unknown> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  assertPortfolioTransferActive(signal);
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  assertAccountSessionBoundary(boundary);
  const path = localPath(file.uri);
  const stat = await RNFS.stat(path);
  const size = Number(file.size || stat.size || 0);
  const report: TransferContext['report'] = (loaded, phase) => {
    try {
      assertAccountSessionBoundary(boundary);
      if (!signal?.aborted) onProgress?.({loaded, total: size, phase});
    } catch {
      /* Progress is presentation, not a mutation result. */
    }
  };
  report(0, 'preparing');
  const mime = String(file.type || 'video/mp4').toLowerCase();
  const extension =
    mime === 'video/quicktime' ? 'mov' : mime === 'video/webm' ? 'webm' : 'mp4';
  const originalName =
    file.fileName || `portfolio-${clientRequestId}.${extension}`;
  const sha256 = await RNFS.hash(path, 'sha256');
  assertAccountSessionBoundary(boundary);
  assertPortfolioTransferActive(signal);
  const identity: UploadIdentity = {
    projectId,
    clientRequestId,
    size,
    mime,
    originalName,
    sha256,
  };
  const context: TransferContext = {key, boundary, signal, path, report};
  const claim = async (value: string) => {
    assertAccountSessionBoundary(boundary);
    assertPortfolioTransferActive(signal);
    report(size, 'saving');
    const media = responseData<unknown>(
      await publicRequest.post(
        `portfolio/${projectId}/media/video-uploads/claim`,
        {claim: value},
        {headers: {'Idempotency-Key': clientRequestId}, signal},
      ),
    );
    assertAccountSessionBoundary(boundary);
    await removeRecord(key, boundary, clientRequestId);
    return media;
  };

  const settledClaim = async (): Promise<string> => {
    const stored = (await readRecords(key, boundary))[clientRequestId];
    let session: UploadSession | undefined = sameIdentity(stored, identity)
      ? // Authorization from an earlier run is renewed before its first request.
        {record: stored, authorizationDeadline: 0, authorizationRefreshes: 0}
      : undefined;
    for (let allocations = 0; ; allocations += 1) {
      if (!session) {
        const allocation = await allocate(identity, context);
        if (allocation.kind === 'attached') return allocation.claim;
        session = allocation.session;
      }
      try {
        await sendWithTus(session, context);
        return session.record.claim;
      } catch (error) {
        // The provider no longer knows this video (deleted or expired):
        // allocate once more, never loop.
        if (providerLostVideo(error)) {
          session = undefined;
          if (allocations < 1) continue;
          throw new Error('PORTFOLIO_VIDEO_UPLOAD_START_FAILED');
        }
        throw transferFailure(error);
      }
    }
  };
  return claim(await settledClaim());
};
