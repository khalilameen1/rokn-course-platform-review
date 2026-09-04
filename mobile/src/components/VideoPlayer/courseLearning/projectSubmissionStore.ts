import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  PROJECT_SUBMISSION_MAX_BYTES,
  validateProjectFile,
} from '../../../config/projects';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsManaged,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from '../../../services/learnerDraftFiles';
import {cleanUnicodeText} from '../../../utils/unicodeText';
import type {SelectedProjectFile} from '../types';
import {
  assertProjectSubmissionOwner,
  type ProjectSubmissionOperation,
} from './projectSubmissionOwnership';
import {
  PROJECT_SUBMISSION_PREFIX,
  PUBLIC_SUBMISSION_ID_PATTERN,
  type PendingProjectSubmission,
} from './projectSubmissionTypes';

const submissionFileOwner = (projectId: string) =>
  `project-outbox:${projectId.replace(/[^a-z0-9_-]/gi, '').slice(-64)}`;

export const projectSubmissionKey = (projectId: string, accountScope: string) =>
  `${PROJECT_SUBMISSION_PREFIX}:${accountScope}:${projectId}`;

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

const createClientSubmissionId = (projectId: string, fingerprint: string) =>
  `rokn-${projectId
    .replace(/[^a-zA-Z0-9_-]/g, '')
    .slice(-18)}-${Date.now().toString(36)}-${fingerprint}`;

const validPending = (
  value: unknown,
  projectId: string,
  accountScope: string,
): value is PendingProjectSubmission => {
  if (!value || typeof value !== 'object') return false;
  const pending = value as Partial<PendingProjectSubmission>;
  return (
    pending.projectId === projectId &&
    pending.accountScope === accountScope &&
    typeof pending.clientSubmissionId === 'string' &&
    Boolean(pending.clientSubmissionId) &&
    typeof pending.fingerprint === 'string' &&
    Boolean(pending.fingerprint) &&
    (!pending.publicId || PUBLIC_SUBMISSION_ID_PATTERN.test(pending.publicId))
  );
};

export const removePendingProjectFiles = async (
  pending?: PendingProjectSubmission | null,
  releaseOwner = true,
) => {
  if (!pending) return;
  if (releaseOwner) {
    await retainLearnerDraftFiles(
      submissionFileOwner(pending.projectId),
      [],
      pending.accountScope,
    );
  }
  await Promise.all(
    (pending.selectedFiles || []).map(file =>
      removeLearnerDraftFile(file).catch(() => undefined),
    ),
  );
};

const discardInvalidPending = async (
  storageKey: string,
  projectId: string,
  accountScope: string,
  parsed?: unknown,
) => {
  if (parsed && typeof parsed === 'object') {
    await removePendingProjectFiles({
      ...(parsed as PendingProjectSubmission),
      projectId,
      accountScope,
    }).catch(() => undefined);
  } else {
    await retainLearnerDraftFiles(
      submissionFileOwner(projectId),
      [],
      accountScope,
    ).catch(() => undefined);
  }
  await AsyncStorage.removeItem(storageKey).catch(() => undefined);
};

export const readPendingProjectSubmission = async (
  projectId: string,
  operation: ProjectSubmissionOperation,
) => {
  assertProjectSubmissionOwner(operation);
  const accountScope = operation.boundary.scope;
  const storageKey = projectSubmissionKey(projectId, accountScope);
  let parsed: unknown;
  try {
    const raw = await AsyncStorage.getItem(storageKey);
    assertProjectSubmissionOwner(operation);
    if (!raw) return null;
    parsed = JSON.parse(raw);
  } catch {
    assertProjectSubmissionOwner(operation);
    await discardInvalidPending(storageKey, projectId, accountScope, parsed);
    return null;
  }
  if (!validPending(parsed, projectId, accountScope)) {
    await discardInvalidPending(storageKey, projectId, accountScope, parsed);
    assertProjectSubmissionOwner(operation);
    return null;
  }
  return parsed;
};

export const savePendingProjectSubmission = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
  rollbackFiles: SelectedProjectFile[] = [],
) => {
  assertProjectSubmissionOwner(operation);
  if (pending.accountScope !== operation.boundary.scope) {
    throw new Error('PROJECT_SUBMISSION_SCOPE_MISMATCH');
  }
  const storageKey = projectSubmissionKey(
    pending.projectId,
    pending.accountScope,
  );
  const owner = submissionFileOwner(pending.projectId);
  const selectedFiles = pending.selectedFiles || [];
  // New references are write-ahead protected. Removing the last reference is
  // the inverse: commit the no-file outbox first, then release its old files.
  // A process death can therefore leak a reclaimable cache file but can never
  // leave a durable unsent submission pointing at a deleted file.
  if (selectedFiles.length) {
    await retainLearnerDraftFiles(owner, selectedFiles, pending.accountScope);
  }
  try {
    assertProjectSubmissionOwner(operation);
    await AsyncStorage.setItem(storageKey, JSON.stringify(pending));
  } catch (error) {
    await retainLearnerDraftFiles(
      owner,
      rollbackFiles,
      pending.accountScope,
    ).catch(() => undefined);
    throw error;
  }
  if (!selectedFiles.length) {
    await retainLearnerDraftFiles(owner, [], pending.accountScope).catch(
      () => undefined,
    );
  }
  // Ownership is checked by the caller immediately after this durable commit.
  // If it changed during the write, the account-scoped outbox must survive for
  // that account's next login instead of being mistaken for an uncommitted file.
};

export const clearPendingProjectSubmission = async (
  pending: PendingProjectSubmission,
  operation: ProjectSubmissionOperation,
) => {
  assertProjectSubmissionOwner(operation);
  await AsyncStorage.removeItem(
    projectSubmissionKey(pending.projectId, pending.accountScope),
  );
  // Once terminal cleanup has removed the durable record, finish releasing the
  // explicitly scoped files even if the active account changes during I/O.
  await removePendingProjectFiles(pending);
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
) => {
  assertProjectSubmissionOwner(operation);
  const normalizedSubmissionText = cleanUnicodeText(submissionText || '');
  const fingerprint = submissionFingerprint(
    projectId,
    selectedFiles,
    normalizedSubmissionText,
  );
  const existing = await readPendingProjectSubmission(projectId, operation);
  assertProjectSubmissionOwner(operation);
  if (existing?.fingerprint === fingerprint || existing?.publicId) {
    return existing;
  }

  const cachedSelectedFiles: SelectedProjectFile[] = [];
  let pendingCommitted = false;
  try {
    for (const file of selectedFiles) {
      cachedSelectedFiles.push(await cachePendingProjectFile(file, operation));
    }
    assertProjectSubmissionOwner(operation);
    const pending: PendingProjectSubmission = {
      projectId,
      accountScope: operation.boundary.scope,
      selectedFiles: cachedSelectedFiles,
      submissionText: normalizedSubmissionText || undefined,
      fingerprint,
      clientSubmissionId: createClientSubmissionId(projectId, fingerprint),
    };
    await savePendingProjectSubmission(
      pending,
      operation,
      existing?.selectedFiles || [],
    );
    pendingCommitted = true;
    assertProjectSubmissionOwner(operation);
    // Releasing the previous registry after the replacement commit lets the
    // shared file registry preserve any file URI used by both snapshots.
    await removePendingProjectFiles(existing, false);
    return pending;
  } catch (error) {
    if (!pendingCommitted) {
      // savePendingProjectSubmission restores the previous owner's registry on
      // failure. Remove only the new unreferenced files; do not clear that
      // restored registry or a prior durable submission loses its attachment.
      await removePendingProjectFiles(
        {
          projectId,
          accountScope: operation.boundary.scope,
          selectedFiles: cachedSelectedFiles,
          fingerprint,
          clientSubmissionId: '',
        },
        false,
      );
    }
    throw error;
  }
};

export const listPendingProjectSubmissions = async (
  operation: ProjectSubmissionOperation,
) => {
  assertProjectSubmissionOwner(operation);
  const accountScope = operation.boundary.scope;
  const prefix = `${PROJECT_SUBMISSION_PREFIX}:${accountScope}:`;
  const keys = (await AsyncStorage.getAllKeys()).filter(key =>
    key.startsWith(prefix),
  );
  assertProjectSubmissionOwner(operation);
  if (!keys.length) return [];
  const entries = await AsyncStorage.multiGet(keys);
  assertProjectSubmissionOwner(operation);
  const pending: Array<{key: string; value: PendingProjectSubmission}> = [];
  for (const [key, raw] of entries) {
    assertProjectSubmissionOwner(operation);
    const projectId = key.slice(prefix.length);
    let parsed: unknown;
    try {
      parsed = raw ? JSON.parse(raw) : null;
    } catch {
      // Handled as invalid below.
    }
    if (
      !/^\d+$/.test(projectId) ||
      !validPending(parsed, projectId, accountScope)
    ) {
      await discardInvalidPending(key, projectId, accountScope, parsed);
      continue;
    }
    pending.push({key, value: parsed});
  }
  assertProjectSubmissionOwner(operation);
  return pending;
};
