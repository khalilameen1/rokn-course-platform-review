import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import type {SelectedProjectFile} from '../components/VideoPlayer/types';
import {
  cacheLearnerDraftFile,
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';

type ProjectSubmissionDraft = {
  files?: SelectedProjectFile[];
  note: string;
  updatedAt: number;
};

const KEY = '@rokn/project-editor-draft/v1';
const TTL_MS = 14 * 24 * 60 * 60 * 1000;
const MAX_BYTES = 25 * 1024 * 1024;
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

const keyFor = async (projectId: string, boundary?: AccountSessionBoundary) =>
  `${await accountScopedStorageKey(KEY, boundary)}:${String(projectId).replace(
    /[^a-z0-9_-]/gi,
    '',
  )}`;
const submissionReferenceOwner = (projectId: string) =>
  `project-submission:${String(projectId).replace(/[^a-z0-9_-]/gi, '')}`;

export const loadProjectSubmissionDraft = async (
  projectId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<ProjectSubmissionDraft | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await keyFor(projectId, boundary);
  return withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    if (!raw) {
      await retainLearnerDraftFiles(
        submissionReferenceOwner(projectId),
        [],
        boundary.scope,
      );
      return null;
    }
    let parsed: Partial<ProjectSubmissionDraft> | null = null;
    try {
      const draft = JSON.parse(raw) as Partial<ProjectSubmissionDraft>;
      parsed = draft;
      if (
        typeof draft.note !== 'string' ||
        !Number.isFinite(draft.updatedAt) ||
        Date.now() - Number(draft.updatedAt) > TTL_MS
      ) {
        throw new Error('INVALID_PROJECT_DRAFT');
      }
      const files = Array.isArray(draft.files) ? draft.files : [];
      const readable = (
        await Promise.all(
          files.map(async file =>
            (await learnerDraftFileIsReadable(file)) ? file : null,
          ),
        )
      ).filter((file): file is SelectedProjectFile => Boolean(file));
      if (readable.length !== files.length) {
        await Promise.all(
          files
            .filter(file => !readable.includes(file))
            .map(removeLearnerDraftFile),
        );
        const repaired = {
          files: readable,
          note: draft.note,
          updatedAt: Number(draft.updatedAt),
        };
        await AsyncStorage.setItem(key, JSON.stringify(repaired));
        await retainLearnerDraftFiles(
          submissionReferenceOwner(projectId),
          readable,
          boundary.scope,
        );
        assertAccountSessionBoundary(boundary);
        return repaired;
      }
      return {...draft, files} as ProjectSubmissionDraft;
    } catch {
      await retainLearnerDraftFiles(
        submissionReferenceOwner(projectId),
        [],
        boundary.scope,
      );
      await Promise.all((parsed?.files || []).map(removeLearnerDraftFile));
      await AsyncStorage.removeItem(key);
      return null;
    }
  });
};

export const cacheProjectDraftFile = async (
  file: SelectedProjectFile,
  ownerBoundary?: AccountSessionBoundary,
): Promise<SelectedProjectFile> => {
  const cached = await cacheLearnerDraftFile(
    'project',
    {
      uri: file.uri,
      fileName: file.name,
      type: file.type,
      size: file.size,
    },
    MAX_BYTES,
    ownerBoundary,
  );
  return {
    uri: cached.uri,
    name: cached.fileName || file.name,
    type: cached.type || file.type,
    size: cached.size,
  };
};

export const saveProjectSubmissionDraft = async (
  projectId: string,
  draft: ProjectSubmissionDraft,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await keyFor(projectId, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    if (!draft.note.trim() && !draft.files?.length) {
      await retainLearnerDraftFiles(
        submissionReferenceOwner(projectId),
        [],
        boundary.scope,
      );
      await AsyncStorage.removeItem(key);
      return;
    }
    await retainLearnerDraftFiles(
      submissionReferenceOwner(projectId),
      draft.files || [],
      boundary.scope,
    );
    await AsyncStorage.setItem(key, JSON.stringify(draft));
    assertAccountSessionBoundary(boundary);
  });
};

export const clearProjectSubmissionDraft = async (
  projectId: string,
  input: SelectedProjectFile | SelectedProjectFile[] | null = [],
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const files = Array.isArray(input) ? input : input ? [input] : [];
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await keyFor(projectId, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    let storedFiles: SelectedProjectFile[] = [];
    if (raw) {
      try {
        const draft = JSON.parse(raw) as Partial<ProjectSubmissionDraft>;
        storedFiles = draft.files || [];
      } catch {}
    }
    // Removing the outbox record is the durable local acknowledgement. Only
    // after it succeeds may its file references be released. File deletion is
    // maintenance and remains safely retryable by the registry sweeper.
    await AsyncStorage.removeItem(key);
    await retainLearnerDraftFiles(
      submissionReferenceOwner(projectId),
      [],
      boundary.scope,
    );
    await Promise.all(
      [...storedFiles, ...files].map(file =>
        removeLearnerDraftFile(file).catch(() => undefined),
      ),
    );
  });
};
