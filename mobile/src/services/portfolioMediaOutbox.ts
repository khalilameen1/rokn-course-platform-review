import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {createDurableOutbox, type DurableOutboxItem} from './durableOutbox';
import type {LearnerDraftFile} from './learnerDraftFiles';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';
import {readJsonOrQuarantine} from './recoverableJsonStorage';
import {settleWithin} from '../utils/settleWithin';
import {learnerDraftStorage} from './learnerDraftStorage';
import {portfolioMediaFailureDisposition} from './portfolioMediaPolicy';

/** Typed M2 adapter: portfolio media uploads on the durable outbox engine. */
export type PortfolioMediaPayload = {
  projectId: string;
  clientRequestId: string;
  file: LearnerDraftFile;
  createdAt: number;
};

export type PortfolioMediaOutboxEntry = PortfolioMediaPayload & {
  storageKey?: string;
  paused?: boolean;
};

const REFERENCE_OWNER = 'portfolio-media-outbox';

const validPayload = (value: unknown): value is PortfolioMediaPayload => {
  if (!value || typeof value !== 'object') return false;
  const entry = value as Partial<PortfolioMediaOutboxEntry>;
  return (
    /^\d+$/.test(String(entry.projectId || '')) &&
    /^[0-9a-f-]{36}$/i.test(String(entry.clientRequestId || '')) &&
    Boolean(entry.file?.uri) &&
    Number.isFinite(entry.createdAt) &&
    (entry.paused === undefined || typeof entry.paused === 'boolean')
  );
};

const payloadOf = (entry: PortfolioMediaPayload): PortfolioMediaPayload => ({
  projectId: entry.projectId,
  clientRequestId: entry.clientRequestId,
  file: entry.file,
  createdAt: entry.createdAt,
});

/** Decoder of the v1 storage format (a bare entry array), kept for migration. */
export const decodePortfolioMediaOutboxEntries = (
  value: unknown,
): PortfolioMediaOutboxEntry[] | null =>
  Array.isArray(value) && value.every(validPayload)
    ? (value as PortfolioMediaOutboxEntry[])
    : null;

const legacyKey = (scope: string) =>
  `${learnerDraftStorage.portfolioMediaLegacy.namespace}:${scope}`;

export const portfolioMediaOutbox = createDurableOutbox<
  PortfolioMediaPayload,
  LearnerDraftFile
>({
  namespace: learnerDraftStorage.portfolioMedia.namespace,
  accountScoped: true,
  maxItems: 96,
  ttlMs: 30 * 24 * 60 * 60 * 1000,
  backoff: {baseMs: 2_000, maxMs: 15 * 60 * 1000},
  decode: value => (validPayload(value) ? payloadOf(value) : null),
  // A learner pauses and resumes one project; a failure never blocks another.
  groupOf: payload => payload.projectId,
  usable: payload => learnerDraftFileIsReadable(payload.file),
  references: {
    of: payload => [payload.file],
    key: file => file.uri,
    retain: (files, scope) =>
      retainLearnerDraftFiles(REFERENCE_OWNER, files, scope),
    release: file => removeLearnerDraftFile(file),
  },
  classify: error => {
    const status = Number(
      (error as {status?: unknown; response?: {status?: unknown}})?.status ??
        (error as {response?: {status?: unknown}})?.response?.status ??
        0,
    );
    const disposition = portfolioMediaFailureDisposition(status);
    return disposition === 'discard_project'
      ? 'drop_group'
      : disposition === 'discard_file'
      ? 'drop'
      : 'retry';
  },
  legacy: {
    read: async scope => {
      const entries = await readJsonOrQuarantine(
        legacyKey(scope),
        () => [],
        decodePortfolioMediaOutboxEntries,
      );
      return entries.map(entry => ({
        id: entry.clientRequestId,
        payload: payloadOf(entry),
        createdAt: entry.createdAt,
        paused: entry.paused === true,
      }));
    },
    clear: scope => AsyncStorage.removeItem(legacyKey(scope)),
  },
});

const ownerKey = (
  boundary: AccountSessionBoundary,
  supplied?: string,
): string => {
  assertAccountSessionBoundary(boundary);
  const expected = portfolioMediaOutbox.storageKey(boundary);
  if (supplied && supplied !== expected) {
    throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  }
  return expected;
};

export const portfolioMediaEntry = (
  item: DurableOutboxItem<PortfolioMediaPayload>,
  boundary: AccountSessionBoundary,
): PortfolioMediaOutboxEntry => ({
  ...item.payload,
  storageKey: portfolioMediaOutbox.storageKey(boundary),
  paused: item.paused === true,
});

export const listPortfolioMediaUploads = async (
  projectId?: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioMediaOutboxEntry[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  ownerKey(boundary);
  const items = await portfolioMediaOutbox.list({boundary});
  assertAccountSessionBoundary(boundary);
  return items
    .filter(
      item =>
        projectId === undefined || item.payload.projectId === String(projectId),
    )
    .sort((left, right) => left.payload.createdAt - right.payload.createdAt)
    .map(item => portfolioMediaEntry(item, boundary));
};

export const stagePortfolioMediaUpload = async (
  entry: PortfolioMediaOutboxEntry,
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioMediaOutboxEntry> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  if (!validPayload(entry) || !(await learnerDraftFileIsReadable(entry.file))) {
    throw new Error('PORTFOLIO_MEDIA_OUTBOX_INVALID');
  }
  ownerKey(boundary, entry.storageKey);
  const item = await portfolioMediaOutbox.enqueue(
    entry.clientRequestId,
    payloadOf(entry),
    {boundary, paused: entry.paused === true},
  );
  assertAccountSessionBoundary(boundary);
  return portfolioMediaEntry(item, boundary);
};

/** Durable local intent only; never downgrades an already accepted server file. */
export const setPortfolioMediaUploadsPaused = async (
  projectId: string,
  paused: boolean,
  boundary: AccountSessionBoundary,
): Promise<number> => {
  ownerKey(boundary);
  const changed = await portfolioMediaOutbox.setPaused(
    String(projectId),
    paused,
    {boundary},
  );
  assertAccountSessionBoundary(boundary);
  return changed;
};

export const discardPortfolioMediaUploads = async (
  projectId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  ownerKey(boundary);
  // Finalize, deletion and a missing remote project have already settled.
  // New staging still queues behind this exact account's cleanup.
  await settleWithin(
    portfolioMediaOutbox.removeGroup(String(projectId), {boundary}),
    false,
  );
  assertAccountSessionBoundary(boundary);
};
