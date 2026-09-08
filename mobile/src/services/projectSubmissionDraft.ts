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

export type ProjectSubmissionDraft = {
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

const writeDraft = async (
  projectId: string,
  key: string,
  draft: ProjectSubmissionDraft,
  boundary: AccountSessionBoundary,
) => {
  assertAccountSessionBoundary(boundary);
  if (!draft.note.trim() && !draft.files?.length) {
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
    await retainLearnerDraftFiles(
      submissionReferenceOwner(projectId),
      [],
      boundary.scope,
    ).catch(() => undefined);
    assertAccountSessionBoundary(boundary);
    return;
  }
  const previous = await AsyncStorage.getItem(key);
  let previousFiles: SelectedProjectFile[] = [];
  if (previous) {
    try {
      const stored = JSON.parse(previous) as Partial<ProjectSubmissionDraft>;
      previousFiles = Array.isArray(stored.files) ? stored.files : [];
    } catch {}
  }
  assertAccountSessionBoundary(boundary);
  // Both versions own their files until the new record is durable. Replacing
  // these references early lets another owner's cleanup erase the old draft
  // when the following storage write fails.
  await retainLearnerDraftFiles(
    submissionReferenceOwner(projectId),
    [...previousFiles, ...(draft.files || [])],
    boundary.scope,
  );
  assertAccountSessionBoundary(boundary);
  await AsyncStorage.setItem(key, JSON.stringify(draft));
  assertAccountSessionBoundary(boundary);
  // This is maintenance after a committed save. A failed trim keeps extra
  // references safely until reconciliation, not a false failed draft copy.
  await retainLearnerDraftFiles(
    submissionReferenceOwner(projectId),
    draft.files || [],
    boundary.scope,
  ).catch(() => undefined);
  assertAccountSessionBoundary(boundary);
};

export const loadProjectSubmissionDraft = async (
  projectId: string,
  ownerBoundary?: AccountSessionBoundary,
): Promise<ProjectSubmissionDraft | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await keyFor(projectId, boundary);
  return withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    assertAccountSessionBoundary(boundary);
    if (!raw) {
      await retainLearnerDraftFiles(
        submissionReferenceOwner(projectId),
        [],
        boundary.scope,
      );
      assertAccountSessionBoundary(boundary);
      return null;
    }
    let draft: Partial<ProjectSubmissionDraft> | null = null;
    try {
      draft = JSON.parse(raw) as Partial<ProjectSubmissionDraft>;
    } catch {}
    const files = Array.isArray(draft?.files) ? draft.files : [];
    if (
      !draft ||
      typeof draft.note !== 'string' ||
      !Number.isFinite(draft.updatedAt) ||
      Date.now() - Number(draft.updatedAt) > TTL_MS
    ) {
      // Only confirmed invalid/expired data is disposable. Storage, native
      // file inspection and ownership failures below must remain retryable.
      await AsyncStorage.removeItem(key);
      assertAccountSessionBoundary(boundary);
      await retainLearnerDraftFiles(
        submissionReferenceOwner(projectId),
        [],
        boundary.scope,
      );
      assertAccountSessionBoundary(boundary);
      await Promise.all(files.map(removeLearnerDraftFile));
      assertAccountSessionBoundary(boundary);
      return null;
    }
    const readable = (
      await Promise.all(
        files.map(async file =>
          (await learnerDraftFileIsReadable(file)) ? file : null,
        ),
      )
    ).filter((file): file is SelectedProjectFile => Boolean(file));
    assertAccountSessionBoundary(boundary);
    if (readable.length !== files.length) {
      const repaired = {
        files: readable,
        note: draft.note,
        updatedAt: Number(draft.updatedAt),
      };
      await AsyncStorage.setItem(key, JSON.stringify(repaired));
      assertAccountSessionBoundary(boundary);
      await retainLearnerDraftFiles(
        submissionReferenceOwner(projectId),
        readable,
        boundary.scope,
      );
      assertAccountSessionBoundary(boundary);
      await Promise.all(
        files
          .filter(file => !readable.includes(file))
          .map(removeLearnerDraftFile),
      );
      assertAccountSessionBoundary(boundary);
      return repaired;
    }
    return {...draft, files} as ProjectSubmissionDraft;
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
  await withDraftLock(key, () => writeDraft(projectId, key, draft, boundary));
};

export type ProjectDraftCopyResult =
  | {kind: 'copied'}
  | {kind: 'conflict'; destinationSnapshot: string};

/** Copy only the editor draft, never a submitted attempt or its retry identity. */
export const copyProjectSubmissionDraft = async (
  sourceProjectId: string,
  currentProjectId: string,
  draft: ProjectSubmissionDraft,
  boundary: AccountSessionBoundary,
  confirmedDestinationSnapshot?: string,
): Promise<ProjectDraftCopyResult> => {
  const sourceKey = await keyFor(sourceProjectId, boundary);
  const destinationKey = await keyFor(currentProjectId, boundary);
  if (sourceKey === destinationKey)
    throw new Error('INVALID_PROJECT_DRAFT_DESTINATION');
  const [firstKey, secondKey] = [sourceKey, destinationKey].sort();
  return withDraftLock(firstKey, () =>
    withDraftLock(secondKey, async () => {
      // Preserve the complete source before touching the destination. Its own
      // reference stays alive even after the new project accepts an edited copy.
      await writeDraft(sourceProjectId, sourceKey, draft, boundary);
      const destinationSnapshot = await AsyncStorage.getItem(destinationKey);
      assertAccountSessionBoundary(boundary);
      let sameContent = false;
      if (destinationSnapshot) {
        try {
          const existing = JSON.parse(
            destinationSnapshot,
          ) as ProjectSubmissionDraft;
          sameContent =
            existing.note === draft.note &&
            JSON.stringify(existing.files || []) ===
              JSON.stringify(draft.files || []);
        } catch {}
      }
      if (
        destinationSnapshot &&
        !sameContent &&
        destinationSnapshot !== confirmedDestinationSnapshot
      ) {
        return {kind: 'conflict', destinationSnapshot};
      }
      await writeDraft(currentProjectId, destinationKey, draft, boundary);
      return {kind: 'copied'};
    }),
  );
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
