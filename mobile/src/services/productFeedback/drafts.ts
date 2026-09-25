import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {secureRandomUuid} from '../../utils/secureRandom';
import {learnerDraftStorage} from '../learnerDraftStorage';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
} from '../learnerDraftFiles';
import {
  isUuid,
  isProductFeedbackCategory,
  type FeedbackAttachment,
  type ProductFeedbackDraft,
  type ProductFeedbackReplyDraft,
  type ProductFeedbackDraftConflict,
} from './contracts';

const DRAFT_KEY = learnerDraftStorage.feedbackDraft.namespace;

const REPLY_DRAFT_PREFIX = `${learnerDraftStorage.feedbackReply.namespace}:`;

const MIGRATED_DRAFT_CONFLICTS_KEY =
  learnerDraftStorage.feedbackConflicts.namespace;

const DRAFT_TTL_MS = 30 * 24 * 60 * 60 * 1000;

let draftOperation: Promise<unknown> = Promise.resolve();

const withDraftLock = <T>(operation: () => Promise<T>) => {
  const result = draftOperation.then(operation, operation);
  draftOperation = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

export const loadProductFeedbackDraftConflicts = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(
    MIGRATED_DRAFT_CONFLICTS_KEY,
    boundary,
  );
  return withDraftLock(async (): Promise<ProductFeedbackDraftConflict[]> => {
    try {
      assertAccountSessionBoundary(boundary);
      const entries = JSON.parse(
        (await AsyncStorage.getItem(key)) || '[]',
      ) as Array<{
        id?: unknown;
        baseKey?: unknown;
        raw?: unknown;
      }>;
      assertAccountSessionBoundary(boundary);
      return entries
        .filter(
          entry =>
            typeof entry.id === 'string' && typeof entry.raw === 'string',
        )
        .map(entry => {
          const baseKey = String(entry.baseKey || '');
          const publicId = baseKey.startsWith(REPLY_DRAFT_PREFIX)
            ? baseKey.slice(REPLY_DRAFT_PREFIX.length)
            : undefined;
          return {
            id: String(entry.id),
            publicId,
            type: publicId ? 'reply' : 'new',
          };
        });
    } catch {
      return [];
    }
  });
};

/** Swap a migrated conflict into the active slot without discarding either copy. */
export const restoreProductFeedbackDraftConflict = async (
  id: string,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const conflictsKey = await accountScopedStorageKey(
    MIGRATED_DRAFT_CONFLICTS_KEY,
    boundary,
  );
  return withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    const entries = JSON.parse(
      (await AsyncStorage.getItem(conflictsKey)) || '[]',
    ) as Array<{
      id: string;
      baseKey: string;
      raw: string;
    }>;
    assertAccountSessionBoundary(boundary);
    const index = entries.findIndex(entry => entry.id === id);
    if (index < 0) return false;
    const conflict = entries[index];
    const targetKey = await accountScopedStorageKey(conflict.baseKey, boundary);
    const active = await AsyncStorage.getItem(targetKey);
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(targetKey, conflict.raw);
    if (active === null) entries.splice(index, 1);
    else entries[index] = {...conflict, raw: active};
    await AsyncStorage.setItem(conflictsKey, JSON.stringify(entries));
    assertAccountSessionBoundary(boundary);
    return true;
  });
};

const replyDraftKey = (publicId: string, boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(`${REPLY_DRAFT_PREFIX}${publicId}`, boundary);

/** The old screenshot remains recoverable until its replacement is durable. */
const replaceFeedbackDraft = async (
  key: string,
  draft: ProductFeedbackDraft | ProductFeedbackReplyDraft | null,
  boundary: AccountSessionBoundary,
  discardedAttachments: FeedbackAttachment[] = [],
) => {
  const raw = await AsyncStorage.getItem(key);
  assertAccountSessionBoundary(boundary);
  let previous: FeedbackAttachment | undefined;
  if (raw) {
    try {
      previous = JSON.parse(raw)?.attachment;
    } catch {}
  }
  if (draft) await AsyncStorage.setItem(key, JSON.stringify(draft));
  else await AsyncStorage.removeItem(key);
  assertAccountSessionBoundary(boundary);
  const retired = new Map(
    [previous, ...discardedAttachments]
      .filter(
        (file): file is FeedbackAttachment =>
          Boolean(file?.uri) && file?.uri !== draft?.attachment?.uri,
      )
      .map(file => [file.uri, file]),
  );
  // Only obsolete files are queued; native cleanup cannot turn a committed
  // draft into a failed save or block sending that already-durable draft.
  void Promise.all(
    [...retired.values()].map(file => removeLearnerDraftFile(file)),
  ).catch(() => undefined);
};

export const loadProductFeedbackReplyDraft = async (
  publicId: string,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await replyDraftKey(publicId, boundary);
  return withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    const value = String((await AsyncStorage.getItem(key)) || '');
    assertAccountSessionBoundary(boundary);
    if (!value) return null;
    let parsed: Partial<ProductFeedbackReplyDraft> | null = null;
    try {
      parsed = JSON.parse(value) as Partial<ProductFeedbackReplyDraft>;
    } catch {}
    if (typeof parsed?.message === 'string' && isUuid(parsed.clientRequestId)) {
      let attachment = parsed.attachment;
      let clientRequestId = String(parsed.clientRequestId);
      if (attachment && !(await learnerDraftFileIsReadable(attachment))) {
        assertAccountSessionBoundary(boundary);
        await removeLearnerDraftFile(attachment);
        attachment = undefined;
        // The body no longer matches the original request fingerprint. A
        // new logical attempt is safer than retrying one key with a changed
        // multipart body and becoming permanently stuck on HTTP 409.
        clientRequestId = '';
      }
      assertAccountSessionBoundary(boundary);
      return {
        attachment,
        clientRequestId,
        message: parsed.message.slice(0, 2000),
      } satisfies ProductFeedbackReplyDraft;
    }
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
    return null;
  });
};

export const saveProductFeedbackReplyDraft = async (
  publicId: string,
  draft: ProductFeedbackReplyDraft | null,
  ownerBoundary?: AccountSessionBoundary,
  discardedAttachments: FeedbackAttachment[] = [],
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await replyDraftKey(publicId, boundary);
  await withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    const normalized = draft?.message.slice(0, 2000) || '';
    if (
      (normalized.trim() || draft?.attachment) &&
      isUuid(draft?.clientRequestId)
    ) {
      await replaceFeedbackDraft(
        key,
        {
          attachment: draft?.attachment,
          clientRequestId: draft!.clientRequestId,
          message: normalized,
        } satisfies ProductFeedbackReplyDraft,
        boundary,
        discardedAttachments,
      );
      assertAccountSessionBoundary(boundary);
      return;
    }
    await replaceFeedbackDraft(key, null, boundary, discardedAttachments);
    assertAccountSessionBoundary(boundary);
  });
};

export const loadProductFeedbackDraft = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<ProductFeedbackDraft | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(DRAFT_KEY, boundary);
  return withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    const raw = await AsyncStorage.getItem(key);
    assertAccountSessionBoundary(boundary);
    if (!raw) return null;
    let parsed: Partial<ProductFeedbackDraft> | null = null;
    try {
      parsed = JSON.parse(raw) as Partial<ProductFeedbackDraft>;
    } catch {}
    const valid =
      parsed &&
      isProductFeedbackCategory(parsed.category) &&
      typeof parsed.message === 'string' &&
      parsed.message.length <= 1600 &&
      typeof parsed.includeDiagnostics === 'boolean' &&
      (parsed.sourceScreen === undefined ||
        (typeof parsed.sourceScreen === 'string' &&
          parsed.sourceScreen.length <= 64)) &&
      isUuid(parsed.clientRequestId) &&
      Number.isFinite(parsed.updatedAt) &&
      Number(parsed.updatedAt) <= Date.now() + 5 * 60 * 1000 &&
      Date.now() - Number(parsed.updatedAt) <= DRAFT_TTL_MS;
    if (valid) {
      const value = parsed as ProductFeedbackDraft;
      const readable =
        !value.attachment ||
        (await learnerDraftFileIsReadable(value.attachment));
      assertAccountSessionBoundary(boundary);
      if (readable) return value;
      const repaired = {...value, attachment: undefined};
      await AsyncStorage.setItem(key, JSON.stringify(repaired));
      assertAccountSessionBoundary(boundary);
      await removeLearnerDraftFile(value.attachment);
      assertAccountSessionBoundary(boundary);
      return repaired;
    }
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
    await removeLearnerDraftFile(parsed?.attachment);
    assertAccountSessionBoundary(boundary);
    return null;
  });
};

export const saveProductFeedbackDraft = async (
  draft: ProductFeedbackDraft,
  ownerBoundary?: AccountSessionBoundary,
  discardedAttachments: FeedbackAttachment[] = [],
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(DRAFT_KEY, boundary);
  await withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    if (
      !isProductFeedbackCategory(draft.category) ||
      !isUuid(draft.clientRequestId) ||
      typeof draft.includeDiagnostics !== 'boolean' ||
      (draft.sourceScreen !== undefined &&
        (typeof draft.sourceScreen !== 'string' ||
          draft.sourceScreen.length > 64)) ||
      typeof draft.message !== 'string' ||
      !Number.isFinite(draft.updatedAt) ||
      draft.message.length > 1600
    ) {
      throw new Error('INVALID_FEEDBACK_DRAFT');
    }
    if (!draft.message.trim() && !draft.attachment) {
      await replaceFeedbackDraft(key, null, boundary, discardedAttachments);
      assertAccountSessionBoundary(boundary);
      return;
    }
    await replaceFeedbackDraft(key, draft, boundary, discardedAttachments);
    assertAccountSessionBoundary(boundary);
  });
};

export const clearProductFeedbackDraft = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(DRAFT_KEY, boundary);
  await withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    await replaceFeedbackDraft(key, null, boundary);
  });
};

const scopedFeedbackKey = (base: string, scope: string) => `${base}:${scope}`;

export const migrateGuestFeedbackDrafts = async (
  guestScope: string,
  accountScope: string,
  publicIds: string[],
  accountBoundary?: AccountSessionBoundary,
): Promise<void> =>
  withDraftLock(async () => {
    const assertOwner = () => {
      if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
    };
    assertOwner();
    const conflictsKey = scopedFeedbackKey(
      MIGRATED_DRAFT_CONFLICTS_KEY,
      accountScope,
    );
    const moveIfMissing = async (baseKey: string) => {
      assertOwner();
      const sourceKey = scopedFeedbackKey(baseKey, guestScope);
      const targetKey = scopedFeedbackKey(baseKey, accountScope);
      const [[, source], [, target]] = await AsyncStorage.multiGet([
        sourceKey,
        targetKey,
      ]);
      assertOwner();
      if (source !== null && target === null) {
        await AsyncStorage.setItem(targetKey, source);
        assertOwner();
        await AsyncStorage.removeItem(sourceKey);
      } else if (source !== null && target !== null && source !== target) {
        let conflicts: Array<{id: string; baseKey: string; raw: string}> = [];
        try {
          const parsed = JSON.parse(
            (await AsyncStorage.getItem(conflictsKey)) || '[]',
          );
          if (Array.isArray(parsed)) conflicts = parsed;
        } catch {}
        assertOwner();
        if (
          !conflicts.some(
            entry => entry.baseKey === baseKey && entry.raw === source,
          )
        ) {
          conflicts.push({id: secureRandomUuid(), baseKey, raw: source});
          await AsyncStorage.setItem(
            conflictsKey,
            JSON.stringify(conflicts.slice(-30)),
          );
          assertOwner();
        }
        await AsyncStorage.removeItem(sourceKey);
      }
      assertOwner();
    };

    await moveIfMissing(DRAFT_KEY);
    for (const publicId of publicIds) {
      await moveIfMissing(`${REPLY_DRAFT_PREFIX}${publicId}`);
    }
    assertOwner();
  });
