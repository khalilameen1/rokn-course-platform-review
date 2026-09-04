import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import type {LearnerDraftFile} from './learnerDraftFiles';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';
import {readJsonOrQuarantine} from './recoverableJsonStorage';

export type PortfolioMediaOutboxEntry = {
  projectId: string;
  clientRequestId: string;
  file: LearnerDraftFile;
  createdAt: number;
  storageKey?: string;
};

const STORAGE_KEY = '@rokn/portfolio-media-outbox/v1';
const REFERENCE_OWNER = 'portfolio-media-outbox';
const TTL_MS = 30 * 24 * 60 * 60 * 1000;
const MAX_ENTRIES = 96;
const operations = new Map<string, Promise<unknown>>();

const withLock = <T>(key: string, callback: () => Promise<T>): Promise<T> => {
  const operation = operations.get(key) ?? Promise.resolve();
  const result = operation.then(callback, callback);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  operations.set(key, tail);
  void tail.finally(() => {
    if (operations.get(key) === tail) operations.delete(key);
  });
  return result;
};

const validEntry = (value: unknown): value is PortfolioMediaOutboxEntry => {
  if (!value || typeof value !== 'object') return false;
  const entry = value as Partial<PortfolioMediaOutboxEntry>;
  return (
    /^\d+$/.test(String(entry.projectId || '')) &&
    /^[0-9a-f-]{36}$/i.test(String(entry.clientRequestId || '')) &&
    Boolean(entry.file?.uri) &&
    Number.isFinite(entry.createdAt)
  );
};

export const decodePortfolioMediaOutboxEntries = (
  value: unknown,
): PortfolioMediaOutboxEntry[] | null =>
  Array.isArray(value) && value.every(validEntry) ? value : null;

const readStored = async (key: string): Promise<PortfolioMediaOutboxEntry[]> =>
  readJsonOrQuarantine(key, () => [], decodePortfolioMediaOutboxEntries);

const writeStored = async (
  key: string,
  entries: PortfolioMediaOutboxEntry[],
): Promise<void> => {
  if (!entries.length) {
    await AsyncStorage.removeItem(key);
    return;
  }
  await AsyncStorage.setItem(key, JSON.stringify(entries));
};

const retainStoredFiles = (
  entries: PortfolioMediaOutboxEntry[],
  accountScope: string,
) =>
  retainLearnerDraftFiles(
    REFERENCE_OWNER,
    entries.map(entry => entry.file),
    accountScope,
  );

const repairStored = async (
  key: string,
  entries: PortfolioMediaOutboxEntry[],
  accountScope: string,
): Promise<PortfolioMediaOutboxEntry[]> => {
  const now = Date.now();
  const inspected = await Promise.all(
    entries.map(async entry => ({
      entry,
      keep:
        now - entry.createdAt <= TTL_MS &&
        (await learnerDraftFileIsReadable(entry.file)),
    })),
  );
  const readable = inspected
    .filter(result => result.keep)
    .map(result => result.entry)
    .sort((left, right) => left.createdAt - right.createdAt);
  const kept = readable.slice(-MAX_ENTRIES);
  const keptIds = new Set(kept.map(entry => entry.clientRequestId));
  if (kept.length !== entries.length) await writeStored(key, kept);
  await retainStoredFiles(kept, accountScope);
  await Promise.all(
    inspected
      .filter(result => !keptIds.has(result.entry.clientRequestId))
      .map(result => removeLearnerDraftFile(result.entry.file)),
  );
  return kept;
};

const scopedKey = async (
  boundary: AccountSessionBoundary,
  supplied?: string,
): Promise<string> => {
  assertAccountSessionBoundary(boundary);
  const expected = await accountScopedStorageKey(STORAGE_KEY, boundary);
  if (supplied && supplied !== expected) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
  assertAccountSessionBoundary(boundary);
  return expected;
};

export const listPortfolioMediaUploads = async (
  projectId?: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioMediaOutboxEntry[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const key = await scopedKey(boundary);
  return withLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const entries = await repairStored(
      key,
      await readStored(key),
      boundary.scope,
    );
    assertAccountSessionBoundary(boundary);
    return entries
      .filter(
        entry =>
          projectId === undefined || entry.projectId === String(projectId),
      )
      .map(entry => ({...entry, storageKey: key}));
  });
};

export const stagePortfolioMediaUpload = async (
  entry: PortfolioMediaOutboxEntry,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioMediaOutboxEntry> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  if (!validEntry(entry) || !(await learnerDraftFileIsReadable(entry.file))) {
    throw new Error('PORTFOLIO_MEDIA_OUTBOX_INVALID');
  }
  const key = await scopedKey(boundary, entry.storageKey);
  const scopedEntry = {...entry, storageKey: key};
  await withLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const entries = await repairStored(
      key,
      await readStored(key),
      boundary.scope,
    );
    const replaced = entries.find(
      candidate => candidate.clientRequestId === scopedEntry.clientRequestId,
    );
    const next = [
      ...entries.filter(
        candidate => candidate.clientRequestId !== scopedEntry.clientRequestId,
      ),
      scopedEntry,
    ].sort((left, right) => left.createdAt - right.createdAt);
    const overflow = next.slice(0, Math.max(0, next.length - MAX_ENTRIES));
    const kept = next.slice(-MAX_ENTRIES);
    await retainStoredFiles(kept, boundary.scope);
    try {
      await writeStored(key, kept);
    } catch (error) {
      await retainStoredFiles(entries, boundary.scope).catch(() => undefined);
      throw error;
    }
    await Promise.all(
      overflow.map(candidate => removeLearnerDraftFile(candidate.file)),
    );
    if (replaced && replaced.file.uri !== scopedEntry.file.uri) {
      await removeLearnerDraftFile(replaced.file);
    }
    assertAccountSessionBoundary(boundary);
  });
  return scopedEntry;
};

export const completePortfolioMediaUpload = async (
  entry: Pick<PortfolioMediaOutboxEntry, 'clientRequestId' | 'storageKey'>,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await scopedKey(boundary, entry.storageKey);
  await withLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const entries = await readStored(key);
    const completed = entries.find(
      candidate => candidate.clientRequestId === entry.clientRequestId,
    );
    const remaining = entries.filter(
      candidate => candidate.clientRequestId !== entry.clientRequestId,
    );
    await writeStored(key, remaining);
    // The durable removal is the acknowledgement. Registry/file cleanup is
    // maintenance and must not turn a successful server upload into a false
    // retry after its outbox entry is already gone.
    await retainStoredFiles(remaining, boundary.scope).catch(() => undefined);
    await removeLearnerDraftFile(completed?.file).catch(() => undefined);
    assertAccountSessionBoundary(boundary);
  });
};

export const discardPortfolioMediaUploads = async (
  projectId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await scopedKey(boundary);
  await withLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const entries = await readStored(key);
    const discarded = entries.filter(
      entry => entry.projectId === String(projectId),
    );
    const remaining = entries.filter(
      entry => entry.projectId !== String(projectId),
    );
    await writeStored(key, remaining);
    await retainStoredFiles(remaining, boundary.scope).catch(() => undefined);
    await Promise.all(
      discarded.map(entry =>
        removeLearnerDraftFile(entry.file).catch(() => undefined),
      ),
    );
    assertAccountSessionBoundary(boundary);
  });
};
