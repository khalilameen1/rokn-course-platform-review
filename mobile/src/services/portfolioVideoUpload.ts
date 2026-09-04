import AsyncStorage from '@react-native-async-storage/async-storage';
import RNFS from 'react-native-fs';

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {publicRequest} from '../constants/api';
import type {LearnerDraftFile} from './learnerDraftFiles';
import {readJsonOrQuarantine} from './recoverableJsonStorage';

type Authorization = {
  upload_endpoint?: string;
  claim: string;
  headers?: Record<string, string>;
  attached?: boolean;
};

type UploadRecord = Authorization & {
  upload_endpoint: string;
  headers: Record<string, string>;
  projectId: string;
  clientRequestId: string;
  uploadUrl?: string;
  size: number;
  mime: string;
  originalName: string;
  sha256: string;
};

const STORAGE_KEY = '@rokn/portfolio-direct-video/v1';
const CHUNK_BYTES = 4 * 1024 * 1024;

export const validatedTusOffset = (
  value: string | null,
  totalSize: number,
  expectedOffset?: number,
): number => {
  const offset = value === null ? Number.NaN : Number(value);
  if (
    !Number.isSafeInteger(offset) ||
    offset < 0 ||
    offset > totalSize ||
    (expectedOffset !== undefined && offset !== expectedOffset)
  ) {
    throw new Error('PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID');
  }
  return offset;
};

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
    const records = await readRecordsUnlocked(key);
    delete records[clientRequestId];
    if (Object.keys(records).length) {
      await AsyncStorage.setItem(key, JSON.stringify(records));
    } else {
      await AsyncStorage.removeItem(key);
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

const utf8Base64 = (value: string) =>
  global.btoa(unescape(encodeURIComponent(value)));

const createUpload = async (
  record: UploadRecord,
  key: string,
  boundary: AccountSessionBoundary,
) => {
  assertAccountSessionBoundary(boundary);
  const response = await fetch(record.upload_endpoint, {
    method: 'POST',
    headers: {
      ...record.headers,
      'Tus-Resumable': '1.0.0',
      'Upload-Length': String(record.size),
      'Upload-Metadata': `filename ${utf8Base64(
        record.originalName,
      )},filetype ${utf8Base64(record.mime)}`,
    },
  });
  assertAccountSessionBoundary(boundary);
  if (!response.ok) throw new Error('PORTFOLIO_VIDEO_UPLOAD_START_FAILED');
  const location = response.headers.get('Location');
  if (!location) throw new Error('PORTFOLIO_VIDEO_UPLOAD_START_FAILED');
  record.uploadUrl = new URL(location, record.upload_endpoint).toString();
  await saveRecord(key, boundary, record);
};

const renew = async (
  record: UploadRecord,
  key: string,
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  assertAccountSessionBoundary(boundary);
  const auth = responseData<Authorization>(
    await publicRequest.post(
      `portfolio/${record.projectId}/media/video-uploads/renew`,
      {claim: record.claim},
    ),
  );
  assertAccountSessionBoundary(boundary);
  if (auth.attached === true) return true;
  if (!auth.headers || typeof auth.headers !== 'object') {
    throw new Error('PORTFOLIO_VIDEO_UPLOAD_AUTH_INVALID');
  }
  if (auth.claim) record.claim = auth.claim;
  record.headers = auth.headers;
  await saveRecord(key, boundary, record);
  return false;
};

const remoteOffset = async (
  record: UploadRecord,
  key: string,
  boundary: AccountSessionBoundary,
): Promise<number> => {
  if (!record.uploadUrl) return 0;
  if (await renew(record, key, boundary)) return record.size;
  assertAccountSessionBoundary(boundary);
  const response = await fetch(record.uploadUrl, {
    method: 'HEAD',
    headers: {...record.headers, 'Tus-Resumable': '1.0.0'},
  });
  assertAccountSessionBoundary(boundary);
  if (response.status === 404 || response.status === 410) return -1;
  if (!response.ok) throw new Error('PORTFOLIO_VIDEO_UPLOAD_RESUME_FAILED');
  return validatedTusOffset(response.headers.get('Upload-Offset'), record.size);
};

const readChunk = async (path: string, offset: number, length: number) => {
  const base64 = await RNFS.read(path, length, offset, 'base64');
  const decoded = global.atob(base64);
  const bytes = new Uint8Array(decoded.length);
  for (let index = 0; index < decoded.length; index += 1) {
    bytes[index] = decoded.charCodeAt(index);
  }
  return bytes.buffer;
};

const patchChunk = (
  record: UploadRecord,
  body: ArrayBuffer,
  offset: number,
): Promise<number> =>
  new Promise((resolve, reject) => {
    const request = new XMLHttpRequest();
    request.open('PATCH', String(record.uploadUrl), true);
    request.timeout = 120000;
    Object.entries(record.headers).forEach(([name, value]) =>
      request.setRequestHeader(name, value),
    );
    request.setRequestHeader('Tus-Resumable', '1.0.0');
    request.setRequestHeader('Upload-Offset', String(offset));
    request.setRequestHeader('Content-Type', 'application/offset+octet-stream');
    request.onload = () => {
      if (request.status >= 200 && request.status < 300) {
        try {
          resolve(
            validatedTusOffset(
              request.getResponseHeader('Upload-Offset'),
              record.size,
              offset + body.byteLength,
            ),
          );
        } catch (error) {
          reject(error);
        }
      } else {
        reject(
          Object.assign(new Error('PORTFOLIO_VIDEO_UPLOAD_FAILED'), {
            status: request.status,
          }),
        );
      }
    };
    request.onerror = () => reject(new Error('PORTFOLIO_VIDEO_UPLOAD_FAILED'));
    request.ontimeout = () =>
      reject(new Error('PORTFOLIO_VIDEO_UPLOAD_TIMEOUT'));
    request.send(body);
  });

export const uploadPortfolioVideo = async (
  projectId: string,
  file: LearnerDraftFile,
  clientRequestId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<unknown> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  assertAccountSessionBoundary(boundary);
  const path = localPath(file.uri);
  const stat = await RNFS.stat(path);
  const size = Number(file.size || stat.size || 0);
  const mime = String(file.type || 'video/mp4').toLowerCase();
  const extension =
    mime === 'video/quicktime' ? 'mov' : mime === 'video/webm' ? 'webm' : 'mp4';
  const originalName =
    file.fileName || `portfolio-${clientRequestId}.${extension}`;
  const sha256 = await RNFS.hash(path, 'sha256');
  assertAccountSessionBoundary(boundary);
  const records = await readRecords(key, boundary);
  let record = records[clientRequestId];
  if (
    !record ||
    record.projectId !== projectId ||
    record.size !== size ||
    record.mime !== mime ||
    record.sha256 !== sha256
  ) {
    assertAccountSessionBoundary(boundary);
    const issued = responseData<Authorization>(
      await publicRequest.post(
        `portfolio/${projectId}/media/video-uploads`,
        {
          idempotency_key: clientRequestId,
          size,
          mime,
          original_name: originalName,
          sha256,
        },
        {headers: {'Idempotency-Key': clientRequestId}},
      ),
    );
    assertAccountSessionBoundary(boundary);
    if (issued.attached === true && issued.claim) {
      const media = responseData<unknown>(
        await publicRequest.post(
          `portfolio/${projectId}/media/video-uploads/claim`,
          {claim: issued.claim},
          {headers: {'Idempotency-Key': clientRequestId}},
        ),
      );
      assertAccountSessionBoundary(boundary);
      await removeRecord(key, boundary, clientRequestId);
      return media;
    }
    if (!issued.upload_endpoint || !issued.claim || !issued.headers) {
      throw new Error('PORTFOLIO_VIDEO_UPLOAD_AUTH_INVALID');
    }
    record = {
      upload_endpoint: issued.upload_endpoint,
      claim: issued.claim,
      headers: issued.headers,
      attached: false,
      projectId,
      clientRequestId,
      size,
      mime,
      originalName,
      sha256,
    };
    await saveRecord(key, boundary, record);
  }
  if (!record.uploadUrl) await createUpload(record, key, boundary);
  let offset = await remoteOffset(record, key, boundary);
  if (offset < 0) {
    record.uploadUrl = undefined;
    await createUpload(record, key, boundary);
    offset = 0;
  }
  if (!Number.isFinite(offset) || offset < 0 || offset > size) {
    throw new Error('PORTFOLIO_VIDEO_UPLOAD_STATE_INVALID');
  }
  while (offset < size) {
    assertAccountSessionBoundary(boundary);
    const length = Math.min(CHUNK_BYTES, size - offset);
    const chunk = await readChunk(path, offset, length);
    assertAccountSessionBoundary(boundary);
    try {
      const nextOffset = await patchChunk(record, chunk, offset);
      assertAccountSessionBoundary(boundary);
      if (nextOffset <= offset)
        throw new Error('PORTFOLIO_VIDEO_UPLOAD_STALLED');
      offset = nextOffset;
    } catch (error: unknown) {
      const status = Number((error as {status?: unknown})?.status || 0);
      if (status === 401 || status === 403) {
        if (await renew(record, key, boundary)) {
          offset = size;
        }
        continue;
      }
      throw error;
    }
  }
  assertAccountSessionBoundary(boundary);
  const media = responseData<unknown>(
    await publicRequest.post(
      `portfolio/${projectId}/media/video-uploads/claim`,
      {claim: record.claim},
      {headers: {'Idempotency-Key': clientRequestId}},
    ),
  );
  assertAccountSessionBoundary(boundary);
  await removeRecord(key, boundary, clientRequestId);
  return media;
};
