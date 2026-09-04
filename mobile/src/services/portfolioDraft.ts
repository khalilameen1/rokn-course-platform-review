import AsyncStorage from '@react-native-async-storage/async-storage';

import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import type {EligibleProject} from './api/profile';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
  retainLearnerDraftFiles,
} from './learnerDraftFiles';

export type PortfolioDraft = {
  clientRequestId: string;
  cover?: {uri: string; type?: string; fileName?: string; size?: number};
  media?: Array<{uri: string; type?: string; fileName?: string; size?: number}>;
  selectedSource?: EligibleProject;
  summary: string;
  title: string;
  updatedAt: number;
};

const STORAGE_KEY = '@rokn/portfolio-editor-draft/v1';
const REFERENCE_OWNER = 'portfolio-editor-draft';
const TTL_MS = 30 * 24 * 60 * 60 * 1000;
const draftOperations = new Map<string, Promise<unknown>>();

const withDraftLock = <T>(key: string, operation: () => Promise<T>) => {
  const previous = draftOperations.get(key) ?? Promise.resolve();
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

const draftFiles = (draft?: Partial<PortfolioDraft> | null) => [
  ...(Array.isArray(draft?.media) ? draft.media : []),
  ...(draft?.cover?.uri ? [draft.cover] : []),
];

const parseDraft = (raw: string): Partial<PortfolioDraft> | null => {
  try {
    const value = JSON.parse(raw);
    return value && typeof value === 'object'
      ? (value as Partial<PortfolioDraft>)
      : null;
  } catch {
    return null;
  }
};

const validDraft = (value: Partial<PortfolioDraft> | null) =>
  Boolean(
    value &&
      typeof value.title === 'string' &&
      typeof value.summary === 'string' &&
      /^[0-9a-f-]{36}$/i.test(String(value.clientRequestId || '')) &&
      Number.isFinite(value.updatedAt) &&
      Date.now() - Number(value.updatedAt) <= TTL_MS,
  );

export const readPortfolioEditorDraft = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<PortfolioDraft | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  return withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    if (!raw) {
      await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
      return null;
    }
    const parsed = parseDraft(raw);
    if (validDraft(parsed)) {
      const draft = parsed as PortfolioDraft;
      const media = await Promise.all(
        (draft.media || []).map(async file =>
          (await learnerDraftFileIsReadable(file)) ? file : null,
        ),
      );
      const readableMedia = media.filter(
        (file): file is NonNullable<typeof file> => file !== null,
      );
      const coverReadable = draft.cover
        ? await learnerDraftFileIsReadable(draft.cover)
        : true;
      const repaired = {
        ...draft,
        cover: coverReadable ? draft.cover : undefined,
        media: readableMedia,
      };
      const changed =
        readableMedia.length !== (draft.media || []).length || !coverReadable;
      if (changed) await AsyncStorage.setItem(key, JSON.stringify(repaired));
      await retainLearnerDraftFiles(
        REFERENCE_OWNER,
        draftFiles(repaired),
        boundary.scope,
      );
      if (changed) {
        const readableUris = new Set(
          draftFiles(repaired).map(file => file.uri),
        );
        await Promise.all(
          draftFiles(draft)
            .filter(file => !readableUris.has(file.uri))
            .map(removeLearnerDraftFile),
        );
      }
      assertAccountSessionBoundary(boundary);
      return repaired;
    }
    await AsyncStorage.removeItem(key);
    await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
    await Promise.all(draftFiles(parsed).map(removeLearnerDraftFile));
    assertAccountSessionBoundary(boundary);
    return null;
  });
};

export const writePortfolioEditorDraft = async (
  draft: PortfolioDraft,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const previous = parseDraft((await AsyncStorage.getItem(key)) || '');
    if (
      !draft.title.trim() &&
      !draft.summary.trim() &&
      !draft.cover &&
      !draft.media?.length
    ) {
      await AsyncStorage.removeItem(key);
      await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
      await Promise.all(draftFiles(previous).map(removeLearnerDraftFile));
      assertAccountSessionBoundary(boundary);
      return;
    }
    const nextFiles = draftFiles(draft);
    await retainLearnerDraftFiles(REFERENCE_OWNER, nextFiles, boundary.scope);
    try {
      await AsyncStorage.setItem(key, JSON.stringify(draft));
    } catch (error) {
      await retainLearnerDraftFiles(
        REFERENCE_OWNER,
        draftFiles(previous),
        boundary.scope,
      ).catch(() => undefined);
      throw error;
    }
    const nextUris = new Set(nextFiles.map(file => file.uri));
    await Promise.all(
      draftFiles(previous)
        .filter(file => !nextUris.has(file.uri))
        .map(removeLearnerDraftFile),
    );
    assertAccountSessionBoundary(boundary);
  });
};

export const clearPortfolioEditorDraft = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(STORAGE_KEY, boundary);
  await withDraftLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    await AsyncStorage.removeItem(key);
    await retainLearnerDraftFiles(REFERENCE_OWNER, [], boundary.scope);
    if (raw) {
      await Promise.all(
        draftFiles(parseDraft(raw)).map(removeLearnerDraftFile),
      );
    }
    assertAccountSessionBoundary(boundary);
  });
};
