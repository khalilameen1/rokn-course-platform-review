import AsyncStorage from '@react-native-async-storage/async-storage';

import type {ChatAttachmentDraft} from '../components/VideoPlayer/types';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';

export type ProjectFeedbackDraft = {
  text: string;
  attachments: ChatAttachmentDraft[];
  requestId?: string;
  fingerprint?: string;
  updatedAt: number;
};

const STORAGE_KEY = '@rokn/project-feedback-draft/v1';
const MAX_AGE_MS = 14 * 24 * 60 * 60 * 1000;
const MAX_ATTACHMENT_BYTES = 8 * 1024 * 1024;
const draftOperations = new Map<string, Promise<unknown>>();

const withDraftLock = <T>(key: string, operation: () => Promise<T>) => {
  const previous = draftOperations.get(key) || Promise.resolve();
  const result = previous.then(operation, operation);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  draftOperations.set(key, tail);
  void tail.finally(() => {
    if (draftOperations.get(key) === tail) draftOperations.delete(key);
  });
  return result;
};

const safeThreadId = (threadId: string) =>
  String(threadId).replace(/[^a-z0-9_-]/gi, '');

const storageKeyFor = async (
  threadId: string,
  boundary?: AccountSessionBoundary,
) =>
  `${await accountScopedStorageKey(STORAGE_KEY, boundary)}:${safeThreadId(
    threadId,
  )}`;

const referenceOwner = (threadId: string) =>
  `project-feedback:${safeThreadId(threadId)}`;

export const cacheProjectFeedbackFile = async (
  file: ChatAttachmentDraft,
  boundary?: AccountSessionBoundary,
): Promise<ChatAttachmentDraft> => {
  if (file.serverId || !file.uri) return file;
  const cached = await cacheLearnerDraftFile(
    'project',
    {
      uri: file.uri,
      fileName: file.name,
      type: file.type,
      size: file.size,
    },
    MAX_ATTACHMENT_BYTES,
    boundary,
  );
  return {
    ...file,
    uri: cached.uri,
    name: cached.fileName || file.name,
    type: cached.type || file.type,
    size: cached.size,
  };
};

export const loadProjectFeedbackDraft = async (
  threadId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<ProjectFeedbackDraft | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await storageKeyFor(threadId, boundary);
  return withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    if (!raw) {
      await retainLearnerDraftFiles(
        referenceOwner(threadId),
        [],
        boundary.scope,
      );
      return null;
    }

    let storedFiles: ChatAttachmentDraft[] = [];
    try {
      const draft = JSON.parse(raw) as ProjectFeedbackDraft;
      storedFiles = Array.isArray(draft.attachments) ? draft.attachments : [];
      if (
        typeof draft.text !== 'string' ||
        !Array.isArray(draft.attachments) ||
        !Number.isFinite(draft.updatedAt) ||
        Date.now() - draft.updatedAt > MAX_AGE_MS
      ) {
        throw new Error('INVALID_PROJECT_FEEDBACK_DRAFT');
      }

      const attachments: ChatAttachmentDraft[] = [];
      for (const file of storedFiles) {
        if (file.serverId || (await learnerDraftFileIsReadable(file))) {
          attachments.push(file);
        } else {
          await removeLearnerDraftFile(file);
        }
      }
      await retainLearnerDraftFiles(
        referenceOwner(threadId),
        attachments,
        boundary.scope,
      );
      assertAccountSessionBoundary(boundary);
      return {...draft, attachments};
    } catch {
      await AsyncStorage.removeItem(key);
      await retainLearnerDraftFiles(
        referenceOwner(threadId),
        [],
        boundary.scope,
      );
      await Promise.all(
        storedFiles.map(file =>
          removeLearnerDraftFile(file).catch(() => undefined),
        ),
      );
      return null;
    }
  });
};

export const saveProjectFeedbackDraft = async (
  threadId: string,
  draft: ProjectFeedbackDraft,
  boundary?: AccountSessionBoundary,
): Promise<void> => {
  const ownerBoundary = boundary || (await captureAccountSessionBoundary());
  const key = await storageKeyFor(threadId, ownerBoundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(ownerBoundary);
    if (!draft.text.trim() && draft.attachments.length === 0) {
      await AsyncStorage.removeItem(key);
      await retainLearnerDraftFiles(
        referenceOwner(threadId),
        [],
        ownerBoundary.scope,
      );
      return;
    }
    await retainLearnerDraftFiles(
      referenceOwner(threadId),
      draft.attachments,
      ownerBoundary.scope,
    );
    await AsyncStorage.setItem(key, JSON.stringify(draft));
    assertAccountSessionBoundary(ownerBoundary);
  });
};

export const clearProjectFeedbackDraft = async (
  threadId: string,
  files: ChatAttachmentDraft[] = [],
  boundary?: AccountSessionBoundary,
): Promise<void> => {
  const ownerBoundary = boundary || (await captureAccountSessionBoundary());
  const key = await storageKeyFor(threadId, ownerBoundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(ownerBoundary);
    const raw = await AsyncStorage.getItem(key);
    let storedFiles: ChatAttachmentDraft[] = [];
    if (raw) {
      try {
        storedFiles =
          (JSON.parse(raw) as ProjectFeedbackDraft).attachments || [];
      } catch {}
    }
    await AsyncStorage.removeItem(key);
    await retainLearnerDraftFiles(
      referenceOwner(threadId),
      [],
      ownerBoundary.scope,
    );
    await Promise.all(
      [...storedFiles, ...files]
        .filter(file => !file.serverId || Boolean(file.uri))
        .map(file => removeLearnerDraftFile(file).catch(() => undefined)),
    );
  });
};
