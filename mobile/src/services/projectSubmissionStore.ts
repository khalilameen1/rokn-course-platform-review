import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  PROJECT_SUBMISSION_MAX_BYTES,
  validateProjectFile,
} from '../config/projects';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsManaged,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';
import {cleanUnicodeText} from '../utils/unicodeText';
import type {SelectedProjectFile} from '../components/VideoPlayer/types';
import {isAiConsentRequired} from './aiConsent';
import {
  createDurableOutbox,
  type DurableOutboxItem,
  type DurableOutboxOutcome,
} from './durableOutbox';
import {learnerDraftStorage} from './learnerDraftStorage';
import {
  assertProjectSubmissionOwner,
  type ProjectSubmissionOperation,
} from './projectSubmissionOwnership';
import {
  LEGACY_PROJECT_SUBMISSION_PREFIX,
  PUBLIC_SUBMISSION_ID_PATTERN,
  type PendingProjectSubmission,
} from './projectSubmissionTypes';

/**
 * Typed M2 adapter: one pending project submission per project and account.
 * The engine owns durability, file references, generations and the server
 * cooldown (`notBefore`); the course flow owns the submission state machine.
 */
const REFERENCE_OWNER = 'project-submission-outbox';
const legacyFileOwner = (projectId: string) =>
  `project-outbox:${projectId.replace(/[^a-z0-9_-]/gi, '').slice(-64)}`;

const requestStatus = (error: unknown): number | null => {
  if (!error || typeof error !== 'object') return null;
  const candidate = error as {
    status?: unknown;
    response?: {status?: unknown};
  };
  const status = Number(candidate.status ?? candidate.response?.status);
  return Number.isFinite(status) && status > 0 ? status : null;
};

export const retryableProjectSubmissionFailure = (error: unknown) => {
  const status = requestStatus(error);
  return status === null || status === 408 || status === 429 || status >= 500;
};

/** A refused POST carries the server's cooldown; it is never bypassed. */
const rateLimitedUntil = (error: unknown): number | null => {
  const candidate = error as {message?: unknown; retryAfterSeconds?: unknown};
  const seconds = Number(candidate?.retryAfterSeconds);
  return candidate?.message === 'PROJECT_SUBMISSION_RATE_LIMITED' &&
    Number.isFinite(seconds) &&
    seconds > 0
    ? Date.now() + seconds * 1000
    : null;
};

/** Background replay keeps every unsettled submission for the next opening. */
export const classifyBackgroundSubmissionFailure = (
  error: unknown,
): DurableOutboxOutcome => {
  const retryAt = rateLimitedUntil(error);
  return retryAt ? {retryAt} : 'keep';
};

/**
 * A learner tap that the server definitively refused retires the attempt;
 * transport failures and missing AI consent keep the same identity.
 */
const classifyForegroundSubmissionFailure = (
  error: unknown,
): DurableOutboxOutcome => {
  const retryAt = rateLimitedUntil(error);
  if (retryAt) return {retryAt};
  return retryableProjectSubmissionFailure(error) || isAiConsentRequired(error)
    ? 'keep'
    : 'drop';
};

const validPending = (value: unknown): value is PendingProjectSubmission => {
  if (!value || typeof value !== 'object') return false;
  const pending = value as Partial<PendingProjectSubmission>;
  return (
    /^\d+$/.test(String(pending.projectId || '')) &&
    typeof pending.accountScope === 'string' &&
    Boolean(pending.accountScope) &&
    typeof pending.clientSubmissionId === 'string' &&
    Boolean(pending.clientSubmissionId) &&
    typeof pending.fingerprint === 'string' &&
    Boolean(pending.fingerprint) &&
    (!pending.publicId || PUBLIC_SUBMISSION_ID_PATTERN.test(pending.publicId))
  );
};

const legacyRecords = async (scope: string) => {
  const prefix = `${LEGACY_PROJECT_SUBMISSION_PREFIX}:${scope}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(prefix),
  );
  return {prefix, keys};
};

export const projectSubmissionOutbox = createDurableOutbox<
  PendingProjectSubmission,
  SelectedProjectFile
>({
  namespace: learnerDraftStorage.projectSubmission.namespace,
  accountScoped: true,
  maxItems: 50,
  // An unsent learner submission outlives a long offline period.
  ttlMs: 90 * 24 * 60 * 60 * 1000,
  backoff: {baseMs: 30_000, maxMs: 60 * 60 * 1000},
  decode: value => (validPending(value) ? value : null),
  groupOf: pending => pending.projectId,
  classify: classifyForegroundSubmissionFailure,
  references: {
    of: pending => pending.selectedFiles || [],
    key: file => file.uri,
    retain: (files, scope) =>
      retainLearnerDraftFiles(REFERENCE_OWNER, files, scope),
    release: file => removeLearnerDraftFile(file),
  },
  legacy: {
    read: async scope => {
      const {prefix, keys} = await legacyRecords(scope);
      if (!keys.length) return [];
      return (await AsyncStorage.multiGet(keys)).flatMap(([key, raw]) => {
        const projectId = key.slice(prefix.length);
        let parsed: unknown;
        try {
          parsed = raw ? JSON.parse(raw) : null;
        } catch {
          return [];
        }
        if (
          !validPending(parsed) ||
          parsed.projectId !== projectId ||
          parsed.accountScope !== scope
        ) {
          return [];
        }
        const {retryAfterAt, ...pending} = parsed as PendingProjectSubmission & {
          retryAfterAt?: number;
        };
        return [
          {
            id: projectId,
            payload: pending,
            ...(Number(retryAfterAt) > 0 ? {notBefore: Number(retryAfterAt)} : {}),
          },
        ];
      });
    },
    // The engine has already retained these files under the outbox owner.
    clear: async scope => {
      const {prefix, keys} = await legacyRecords(scope);
      await Promise.all(keys.map(key => AsyncStorage.removeItem(key)));
      await Promise.all(
        keys.map(key =>
          retainLearnerDraftFiles(
            legacyFileOwner(key.slice(prefix.length)),
            [],
            scope,
          ).catch(() => undefined),
        ),
      );
    },
  },
});

const hashText = (value: string) => {
  let hash = 5381;
  for (let index = 0; index < value.length; index += 1) {
    hash = (hash * 33 + value.charCodeAt(index)) % 2147483647;
  }
  return hash.toString(36);
};

const submissionFingerprint = (
  projectId: string,
  selectedFiles: SelectedProjectFile[] = [],
  submissionText?: string,
) =>
  hashText(
    [
      projectId,
      ...selectedFiles.flatMap(file => [
        file.uri,
        file.name,
        file.type,
        file.size || 0,
      ]),
      cleanUnicodeText(submissionText || ''),
    ].join('|'),
  );

export const pendingSubmissionMatchesInput = (
  pending: PendingProjectSubmission,
  selectedFiles: SelectedProjectFile[],
  submissionText?: string,
) =>
  pending.fingerprint ===
  submissionFingerprint(pending.projectId, selectedFiles, submissionText);

const createClientSubmissionId = (projectId: string, fingerprint: string) =>
  `rokn-${projectId
    .replace(/[^a-zA-Z0-9_-]/g, '')
    .slice(-18)}-${Date.now().toString(36)}-${fingerprint}`;

/** Throw the server's cooldown before any new request for this project. */
export const assertSubmissionRetryWindow = (
  item: Pick<DurableOutboxItem<PendingProjectSubmission>, 'notBefore'>,
) => {
  const seconds = Math.ceil(
    (Date.parse(item.notBefore || '') - Date.now()) / 1000,
  );
  if (seconds > 0) {
    throw Object.assign(new Error('PROJECT_SUBMISSION_RATE_LIMITED'), {
      status: 429,
      retryAfterSeconds: seconds,
    });
  }
};

export const readPendingProjectSubmission = async (
  projectId: string,
  operation: ProjectSubmissionOperation,
) => {
  assertProjectSubmissionOwner(operation);
  // An unreadable outbox may hold the only identity of an uncertain upload:
  // a read failure stops this attempt instead of being treated as empty.
  const item = await projectSubmissionOutbox.get(projectId, {
    boundary: operation.boundary,
  });
  assertProjectSubmissionOwner(operation);
  if (item && item.payload.accountScope !== operation.boundary.scope) {
    await projectSubmissionOutbox.remove(projectId, {
      boundary: operation.boundary,
    });
    return null;
  }
  return item;
};

/** Delivery progress of the same intent (upload marker, server identity). */
export const savePendingProjectSubmission = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
) => {
  assertProjectSubmissionOwner(operation);
  if (pending.accountScope !== operation.boundary.scope) {
    throw new Error('PROJECT_SUBMISSION_SCOPE_MISMATCH');
  }
  // New file references are protected before the write; references it drops
  // are released only after it. If the account changes during the write, the
  // account-scoped record survives for that account's next login.
  const saved = await projectSubmissionOutbox.update(
    pending.projectId,
    () => ({...pending}),
    {boundary: operation.boundary},
  );
  if (!saved) throw new Error('PROJECT_SUBMISSION_OUTBOX_MISSING');
  assertProjectSubmissionOwner(operation);
};

export const clearPendingProjectSubmission = async (
  pending: Pick<PendingProjectSubmission, 'projectId'>,
  operation: ProjectSubmissionOperation,
) => {
  assertProjectSubmissionOwner(operation);
  await projectSubmissionOutbox.remove(pending.projectId, {
    boundary: operation.boundary,
  });
  assertProjectSubmissionOwner(operation);
};

const cachePendingProjectFile = async (
  selectedFile: SelectedProjectFile,
  operation: ProjectSubmissionOperation,
): Promise<SelectedProjectFile> => {
  const size = await validateProjectFile(selectedFile);
  assertProjectSubmissionOwner(operation);
  if (learnerDraftFileIsManaged(selectedFile)) {
    return {...selectedFile, size};
  }
  const cached = await cacheLearnerDraftFile(
    'project',
    {
      uri: selectedFile.uri,
      fileName: selectedFile.name,
      type: selectedFile.type,
      size,
    },
    PROJECT_SUBMISSION_MAX_BYTES,
    operation.boundary,
  );
  assertProjectSubmissionOwner(operation);
  return {
    ...selectedFile,
    uri: cached.uri,
    name: cached.fileName || selectedFile.name,
    type: cached.type || selectedFile.type,
    size: cached.size || size,
  };
};

export const getOrCreatePendingProjectSubmission = async (
  projectId: string,
  operation: ProjectSubmissionOperation,
  selectedFiles: SelectedProjectFile[] = [],
  submissionText?: string,
): Promise<DurableOutboxItem<PendingProjectSubmission>> => {
  assertProjectSubmissionOwner(operation);
  const normalizedSubmissionText = cleanUnicodeText(submissionText || '');
  const fingerprint = submissionFingerprint(
    projectId,
    selectedFiles,
    normalizedSubmissionText,
  );
  const existing = await readPendingProjectSubmission(projectId, operation);
  assertProjectSubmissionOwner(operation);
  if (
    existing &&
    (existing.payload.fingerprint === fingerprint || existing.payload.publicId)
  ) {
    return existing;
  }

  const cachedSelectedFiles: SelectedProjectFile[] = [];
  try {
    for (const file of selectedFiles) {
      cachedSelectedFiles.push(await cachePendingProjectFile(file, operation));
    }
    assertProjectSubmissionOwner(operation);
    // A new input is a new intent: a new generation and idempotency key.
    // The outbox releases the replaced attempt's files after this commit.
    const item = await projectSubmissionOutbox.enqueue(
      projectId,
      {
        projectId,
        accountScope: operation.boundary.scope,
        selectedFiles: cachedSelectedFiles,
        submissionText: normalizedSubmissionText || undefined,
        fingerprint,
        clientSubmissionId: createClientSubmissionId(projectId, fingerprint),
        uploadAttempted: false,
      },
      {boundary: operation.boundary},
    );
    assertProjectSubmissionOwner(operation);
    return item;
  } catch (error) {
    // Files that never became part of a committed intent are unreferenced.
    await Promise.all(
      cachedSelectedFiles.map(file =>
        removeLearnerDraftFile(file).catch(() => undefined),
      ),
    );
    throw error;
  }
};
