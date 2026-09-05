import {Dimensions, Platform} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';

import appConfig from '../../app.json';
import {publicRequest} from '../constants/api';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {firstBoolean} from './api/common';
import {secureRandomUuid} from '../utils/secureRandom';
import {
  learnerDraftFileIsReadable,
  removeLearnerDraftFile,
} from './learnerDraftFiles';

export type ProductFeedbackCategory =
  | 'problem'
  | 'idea'
  | 'content'
  | 'playback';

export type FeedbackAttachment = {
  fileName?: string;
  size?: number;
  type?: string;
  uri: string;
};

export type ProductFeedbackContext = {
  includeDiagnostics?: boolean;
  locale?: string;
  sourceScreen?: string;
};

export type ProductFeedbackDraft = {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  clientRequestId: string;
  includeDiagnostics: boolean;
  message: string;
  updatedAt: number;
};

export type ProductFeedbackReceipt = {
  accessToken?: string;
  attachments: ProductFeedbackArtifact[];
  caseNumber: string;
  createdAt: string;
  messages: ProductFeedbackMessage[];
  publicId: string;
  replayed: boolean;
  status: string;
};

export type ProductFeedbackMessage = {
  attachments: ProductFeedbackArtifact[];
  author: 'learner' | 'support';
  createdAt: string;
  hasAttachment: boolean;
  publicId: string;
  text: string;
};

export type ProductFeedbackArtifact = {
  expiresAt: string;
  height?: number;
  id: string;
  mime: string;
  name: string;
  size: number;
  url: string;
  width?: number;
};

export type ProductFeedbackReplyDraft = {
  attachment?: FeedbackAttachment;
  clientRequestId: string;
  message: string;
};

export type ProductFeedbackCase = Omit<ProductFeedbackReceipt, 'replayed'> & {
  category: string;
  message: string;
  updatedAt: string;
};

type StoredCaseReceipt = {
  accessToken?: string;
  publicId: string;
  updatedAt: number;
};

const DRAFT_KEY = '@rokn/product-feedback-draft/v1';
const RECEIPTS_KEY = '@rokn/product-feedback-receipts/v1';
const REPLY_DRAFT_PREFIX = '@rokn/product-feedback-reply/v1:';
const MIGRATED_DRAFT_CONFLICTS_KEY =
  '@rokn/product-feedback-draft-conflicts/v1';
const DRAFT_TTL_MS = 30 * 24 * 60 * 60 * 1000;
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
let draftOperation: Promise<unknown> = Promise.resolve();

const withDraftLock = <T>(operation: () => Promise<T>) => {
  const result = draftOperation.then(operation, operation);
  draftOperation = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

export type ProductFeedbackDraftConflict = {
  id: string;
  publicId?: string;
  type: 'new' | 'reply';
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

const backendCategory: Record<ProductFeedbackCategory, string> = {
  problem: 'bug',
  idea: 'suggestion',
  content: 'course_content',
  playback: 'playback',
};

const isUuid = (value: unknown) => UUID_PATTERN.test(String(value || ''));
const isProductFeedbackCategory = (
  value: unknown,
): value is ProductFeedbackCategory =>
  Object.prototype.hasOwnProperty.call(backendCategory, String(value));

const normalizeScreenKey = (value?: string) => {
  const normalized = String(value || 'feedback')
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9._-]+/g, '_')
    .replace(/^_+|_+$/g, '')
    .slice(0, 64);
  return normalized || 'feedback';
};

const osMajor = () => {
  const value = Number.parseInt(String(Platform.Version).split('.')[0], 10);
  return Number.isInteger(value) && value > 0 && value <= 255
    ? value
    : undefined;
};

const buildNumber = () => {
  const value = Number(
    Platform.OS === 'ios'
      ? appConfig.expo.ios?.buildNumber
      : appConfig.expo.android?.versionCode,
  );
  return Number.isInteger(value) && value > 0 ? value : undefined;
};

type NativeUpload = {name: string; type: string; uri: string};
type NativeFormData = FormData & {
  append(name: string, value: string | NativeUpload): void;
};

const createFeedbackBody = ({
  attachment,
  category,
  context,
  message,
  clientRequestId,
}: {
  attachment?: FeedbackAttachment;
  category: ProductFeedbackCategory;
  context?: ProductFeedbackContext;
  message: string;
  clientRequestId: string;
}) => {
  const form = new FormData() as NativeFormData;
  form.append('client_request_id', clientRequestId);
  form.append('category', backendCategory[category]);
  form.append('message', message.trim());
  if (context?.includeDiagnostics) {
    const screen = Dimensions.get('window');
    form.append('platform', Platform.OS);
    form.append('app_version', appConfig.expo.version);
    form.append('screen_key', normalizeScreenKey(context.sourceScreen));
    form.append('locale', String(context.locale || 'ar').slice(0, 16));
    form.append(
      'screen_size',
      `${Math.round(screen.width)}x${Math.round(screen.height)}`,
    );
    form.append('font_scale', String(screen.fontScale || 1));
    const currentBuildNumber = buildNumber();
    if (currentBuildNumber) {
      form.append('build_number', String(currentBuildNumber));
    }
    const currentOsMajor = osMajor();
    if (currentOsMajor) {
      form.append('os_major', String(currentOsMajor));
    }
    form.append('device_tier', 'unknown');
    form.append('network_type', 'unknown');
  }
  if (attachment) {
    form.append('screenshot', {
      name: attachment.fileName || `rokn-feedback-${Date.now()}.jpg`,
      type: attachment.type || 'image/jpeg',
      uri: attachment.uri,
    });
  }
  return form;
};

/**
 * Feedback remains server-owned. A pending screenshot is copied only into the
 * app's private draft directory so an interrupted send can be resumed.
 */
export const submitProductFeedback = async (
  input: {
    attachment?: FeedbackAttachment;
    category: ProductFeedbackCategory;
    context?: ProductFeedbackContext;
    message: string;
    clientRequestId: string;
  },
  ownerBoundary?: AccountSessionBoundary,
): Promise<ProductFeedbackReceipt> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  if (
    !isUuid(input.clientRequestId) ||
    input.message.trim().length < 10 ||
    input.message.length > 2000
  ) {
    throw new Error('INVALID_FEEDBACK_ATTEMPT');
  }
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.post(
    'feedback',
    createFeedbackBody(input),
    {
      timeout: 30000,
      headers: {'Idempotency-Key': input.clientRequestId},
    },
  );
  assertAccountSessionBoundary(boundary);
  const payload =
    (response.data as {data?: Record<string, unknown>})?.data || {};
  const publicId = String(payload.public_id || '').trim();
  const createdAt = String(payload.created_at || '').trim();
  if (
    !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId) ||
    !createdAt ||
    !Number.isFinite(Date.parse(createdAt))
  ) {
    throw new Error('INVALID_FEEDBACK_RECEIPT');
  }
  const receipt = {
    accessToken: safeAccessToken(payload.access_token),
    attachments: parseArtifacts(payload.attachments),
    caseNumber: safeCaseNumber(payload.case_number, publicId),
    publicId,
    status: String(payload.status || 'new'),
    createdAt,
    replayed: firstBoolean(payload.replayed) ?? false,
    messages: parseMessages(payload.messages),
  };
  await rememberCaseReceipt(receipt, boundary);
  return receipt;
};

const safeAccessToken = (value: unknown) => {
  const token = String(value || '').trim();
  return /^[A-Za-z0-9_-]{32,128}$/.test(token) ? token : undefined;
};

const safeCaseNumber = (value: unknown, publicId: string) => {
  const candidate = String(value || '')
    .trim()
    .toUpperCase();
  return /^[0-9A-Z]{6,12}$/.test(candidate)
    ? candidate
    : publicId.slice(-8).toUpperCase();
};

const isRecord = (value: unknown): value is Record<string, unknown> =>
  typeof value === 'object' && value !== null;

const parseArtifacts = (value: unknown): ProductFeedbackArtifact[] =>
  (Array.isArray(value) ? value : [])
    .map((item): ProductFeedbackArtifact | null => {
      if (!isRecord(item)) return null;
      const id = String(item.id || '').trim();
      const name = String(item.name || '').trim();
      const mime = String(item.mime || '')
        .trim()
        .toLowerCase();
      const url = String(item.url || '').trim();
      const expiresAt = String(item.expires_at || '').trim();
      if (
        !/^\d+$/.test(id) ||
        !name ||
        !mime.startsWith('image/') ||
        !/^https:\/\//i.test(url) ||
        !Number.isFinite(Date.parse(expiresAt))
      )
        return null;
      const width = Number(item.width);
      const height = Number(item.height);
      return {
        expiresAt,
        height: Number.isFinite(height) && height > 0 ? height : undefined,
        id,
        mime,
        name,
        size: Math.max(0, Number(item.size) || 0),
        url,
        width: Number.isFinite(width) && width > 0 ? width : undefined,
      };
    })
    .filter((item): item is ProductFeedbackArtifact => item !== null);

const parseMessages = (value: unknown): ProductFeedbackMessage[] =>
  (Array.isArray(value) ? value : [])
    .map(item => {
      if (!isRecord(item)) return null;
      const publicId = String(item.public_id || '').trim();
      const text = String(item.text || '').trim();
      const createdAt = String(item.created_at || '').trim();
      if (!publicId || !text || !Number.isFinite(Date.parse(createdAt)))
        return null;
      return {
        attachments: parseArtifacts(item.attachments),
        author:
          item.author === 'learner'
            ? ('learner' as const)
            : ('support' as const),
        createdAt,
        hasAttachment: firstBoolean(item.has_attachment) ?? false,
        publicId,
        text,
      };
    })
    .filter((item): item is ProductFeedbackMessage => item !== null);

const parseCase = (value: unknown): ProductFeedbackCase => {
  if (!isRecord(value)) throw new Error('INVALID_SUPPORT_CASE');
  const publicId = String(value.public_id || '').trim();
  const createdAt = String(value.created_at || '').trim();
  const updatedAt = String(value.updated_at || '').trim();
  const message = String(value.message || '').trim();
  if (
    !/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId) ||
    !message ||
    !Number.isFinite(Date.parse(createdAt)) ||
    !Number.isFinite(Date.parse(updatedAt))
  ) {
    throw new Error('INVALID_SUPPORT_CASE');
  }
  return {
    attachments: parseArtifacts(value.attachments),
    caseNumber: safeCaseNumber(value.case_number, publicId),
    category: String(value.category || 'bug'),
    createdAt,
    message,
    messages: parseMessages(value.messages),
    publicId,
    status: String(value.status || 'in_progress'),
    updatedAt,
  };
};

const loadStoredReceiptsFromKey = async (
  key: string,
  boundary?: AccountSessionBoundary,
): Promise<StoredCaseReceipt[]> => {
  const raw = await AsyncStorage.getItem(key);
  if (boundary) assertAccountSessionBoundary(boundary);
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return (Array.isArray(parsed) ? parsed : [])
      .map((item: unknown): StoredCaseReceipt | null => {
        if (!isRecord(item)) return null;
        const publicId = String(item.publicId || '').trim();
        if (!/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId)) return null;
        return {
          publicId,
          accessToken: safeAccessToken(item.accessToken),
          updatedAt: Number(item.updatedAt) || 0,
        };
      })
      .filter(
        (item: StoredCaseReceipt | null): item is StoredCaseReceipt =>
          item !== null,
      )
      .slice(0, 20);
  } catch {
    if (boundary) assertAccountSessionBoundary(boundary);
    await AsyncStorage.removeItem(key);
    if (boundary) assertAccountSessionBoundary(boundary);
    return [];
  }
};

const loadStoredReceipts = async (
  boundary: AccountSessionBoundary,
): Promise<StoredCaseReceipt[]> =>
  loadStoredReceiptsFromKey(
    await accountScopedStorageKey(RECEIPTS_KEY, boundary),
    boundary,
  );

const scopedFeedbackKey = (base: string, scope: string) => `${base}:${scope}`;

/**
 * Attach guest support history to the account created from that guest journey.
 * Local copy-before-delete keeps cases and reply drafts visible immediately;
 * the authenticated claim remains retryable until every server case is owned.
 */
export const migrateGuestProductFeedback = async (
  guestScope: string,
  accountScope: string,
  claimRemote = false,
  accountBoundary?: AccountSessionBoundary,
): Promise<boolean> => {
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  if (
    !/^[a-z0-9_-]+$/i.test(guestScope) ||
    !/^[a-z0-9_-]+$/i.test(accountScope) ||
    guestScope === accountScope
  ) {
    return false;
  }

  const guestReceiptsKey = scopedFeedbackKey(RECEIPTS_KEY, guestScope);
  const accountReceiptsKey = scopedFeedbackKey(RECEIPTS_KEY, accountScope);
  const [guestReceipts, accountReceipts] = await Promise.all([
    loadStoredReceiptsFromKey(guestReceiptsKey, accountBoundary),
    loadStoredReceiptsFromKey(accountReceiptsKey, accountBoundary),
  ]);
  const merged = new Map<string, StoredCaseReceipt>();
  [...accountReceipts, ...guestReceipts]
    .sort((left, right) => left.updatedAt - right.updatedAt)
    .forEach(receipt => merged.set(receipt.publicId, receipt));
  let receipts = [...merged.values()]
    .sort((left, right) => right.updatedAt - left.updatedAt)
    .slice(0, 20);

  const conflictsKey = scopedFeedbackKey(
    MIGRATED_DRAFT_CONFLICTS_KEY,
    accountScope,
  );
  const moveIfMissing = async (baseKey: string) => {
    const sourceKey = scopedFeedbackKey(baseKey, guestScope);
    const targetKey = scopedFeedbackKey(baseKey, accountScope);
    const [[, source], [, target]] = await AsyncStorage.multiGet([
      sourceKey,
      targetKey,
    ]);
    if (source !== null && target === null) {
      await AsyncStorage.setItem(targetKey, source);
      await AsyncStorage.removeItem(sourceKey);
    } else if (source !== null && target !== null && source !== target) {
      let conflicts: Array<{id: string; baseKey: string; raw: string}> = [];
      try {
        const parsed = JSON.parse(
          (await AsyncStorage.getItem(conflictsKey)) || '[]',
        );
        if (Array.isArray(parsed)) conflicts = parsed;
      } catch {}
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
      }
      await AsyncStorage.removeItem(sourceKey);
    }
  };

  await AsyncStorage.setItem(accountReceiptsKey, JSON.stringify(receipts));
  await moveIfMissing(DRAFT_KEY);
  for (const receipt of guestReceipts) {
    await moveIfMissing(`${REPLY_DRAFT_PREFIX}${receipt.publicId}`);
  }
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  await AsyncStorage.removeItem(guestReceiptsKey);

  if (!claimRemote) return true;
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  const claimable = receipts.filter(receipt => receipt.accessToken);
  if (!claimable.length) return true;
  const results = await Promise.allSettled(
    claimable.map(receipt =>
      publicRequest.post(
        `feedback/${encodeURIComponent(receipt.publicId)}/claim`,
        {},
        {headers: accessHeaders(receipt.accessToken)},
      ),
    ),
  );
  const claimedIds = new Set(
    results.flatMap((result, index) =>
      result.status === 'fulfilled' ? [claimable[index].publicId] : [],
    ),
  );
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  if (claimedIds.size) {
    receipts = receipts.map(receipt =>
      claimedIds.has(receipt.publicId)
        ? {...receipt, accessToken: undefined}
        : receipt,
    );
    await AsyncStorage.setItem(accountReceiptsKey, JSON.stringify(receipts));
  }
  return results.every(result => result.status === 'fulfilled');
};

const rememberCaseReceipt = async (
  receipt: ProductFeedbackReceipt,
  boundary: AccountSessionBoundary,
) => {
  assertAccountSessionBoundary(boundary);
  const key = await accountScopedStorageKey(RECEIPTS_KEY, boundary);
  // Read and write through one resolved owner. Recomputing the key after an
  // account switch could otherwise merge the next learner's case ids into the
  // previous learner's receipt list.
  const current = await loadStoredReceiptsFromKey(key, boundary);
  const next = [
    {
      publicId: receipt.publicId,
      accessToken: receipt.accessToken,
      updatedAt: Date.now(),
    },
    ...current.filter(item => item.publicId !== receipt.publicId),
  ].slice(0, 20);
  await AsyncStorage.setItem(key, JSON.stringify(next));
  assertAccountSessionBoundary(boundary);
};

const accessHeaders = (accessToken?: string) =>
  accessToken ? {'X-Support-Access': accessToken} : undefined;

export const loadProductFeedbackCase = async (
  publicId: string,
  accessToken?: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<ProductFeedbackCase> => {
  if (!/^[0-9A-HJKMNP-TV-Z]{26}$/i.test(publicId)) {
    throw new Error('INVALID_SUPPORT_CASE');
  }
  const boundary = accountBoundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.get(
    `feedback/${encodeURIComponent(publicId)}`,
    {
      headers: accessHeaders(accessToken),
    },
  );
  assertAccountSessionBoundary(boundary);
  return parseCase((response.data as {data?: unknown})?.data);
};

export const loadProductFeedbackCases = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<ProductFeedbackCase[]> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const accountOwned = boundary.scope.startsWith('user-');
  const cases = new Map<string, ProductFeedbackCase>();
  let loadError: unknown;
  let accountIndexError: unknown;
  if (accountOwned) {
    try {
      assertAccountSessionBoundary(boundary);
      const response = await publicRequest.get('feedback');
      assertAccountSessionBoundary(boundary);
      const data = (response.data as {data?: unknown})?.data;
      if (!isRecord(data) || !Array.isArray(data.items)) {
        throw new Error('INVALID_SUPPORT_CASES_RESPONSE');
      }
      // A partially accepted response silently hides the malformed case from
      // its owner. Reject the snapshot and keep the screen's last known list so
      // the same support history can be retried intact.
      data.items.map(parseCase).forEach(item => cases.set(item.publicId, item));
    } catch (error) {
      loadError = error;
      accountIndexError = error;
    }
  }

  assertAccountSessionBoundary(boundary);
  const receipts = await loadStoredReceipts(boundary);
  assertAccountSessionBoundary(boundary);
  const receiptsToLoad = loadError
    ? receipts
    : receipts.filter(receipt => !cases.has(receipt.publicId));
  const settled = await Promise.allSettled(
    receiptsToLoad.map(async receipt => ({
      accessToken: receipt.accessToken,
      case: await loadProductFeedbackCase(
        receipt.publicId,
        receipt.accessToken,
        boundary,
      ),
    })),
  );
  assertAccountSessionBoundary(boundary);
  settled.forEach(result => {
    if (result.status === 'fulfilled') {
      cases.set(result.value.case.publicId, {
        ...result.value.case,
        accessToken: result.value.accessToken,
      });
    } else if (!loadError) {
      loadError = result.reason;
    }
  });
  // The account index is authoritative. Returning only this installation's
  // receipts after that request failed turns a refresh into apparent data loss:
  // cases created on another device disappear even though they still exist.
  // Reject the partial snapshot so the screen keeps its last complete list.
  if (accountOwned && accountIndexError) throw accountIndexError;
  if (!cases.size && loadError) throw loadError;
  return [...cases.values()].sort(
    (a, b) =>
      b.updatedAt.localeCompare(a.updatedAt) ||
      b.publicId.localeCompare(a.publicId),
  );
};

export const replyToProductFeedback = async (
  input: {
    accessToken?: string;
    attachment?: FeedbackAttachment;
    clientRequestId: string;
    message: string;
    publicId: string;
  },
  ownerBoundary?: AccountSessionBoundary,
): Promise<ProductFeedbackCase> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  if (
    !isUuid(input.clientRequestId) ||
    input.message.trim().length < 2 ||
    input.message.length > 2000
  ) {
    throw new Error('INVALID_SUPPORT_REPLY_ATTEMPT');
  }
  const form = new FormData() as NativeFormData;
  form.append('client_request_id', input.clientRequestId);
  form.append('message', input.message.trim());
  if (input.attachment) {
    form.append('screenshot', {
      name: input.attachment.fileName || 'rokn-support.jpg',
      type: input.attachment.type || 'image/jpeg',
      uri: input.attachment.uri,
    });
  }
  assertAccountSessionBoundary(boundary);
  const response = await publicRequest.post(
    `feedback/${encodeURIComponent(input.publicId)}/messages`,
    form,
    {
      timeout: 30000,
      headers: {
        'Idempotency-Key': input.clientRequestId,
        ...(accessHeaders(input.accessToken) || {}),
      },
    },
  );
  assertAccountSessionBoundary(boundary);
  return parseCase((response.data as {data?: unknown})?.data);
};

const replyDraftKey = (publicId: string, boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(`${REPLY_DRAFT_PREFIX}${publicId}`, boundary);

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
    try {
      const parsed = JSON.parse(value) as Partial<ProductFeedbackReplyDraft>;
      if (
        typeof parsed.message === 'string' &&
        isUuid(parsed.clientRequestId)
      ) {
        let attachment = parsed.attachment;
        let clientRequestId = String(parsed.clientRequestId);
        if (attachment && !(await learnerDraftFileIsReadable(attachment))) {
          await removeLearnerDraftFile(attachment);
          attachment = undefined;
          // The body no longer matches the original request fingerprint. A
          // new logical attempt is safer than retrying one key with a changed
          // multipart body and becoming permanently stuck on HTTP 409.
          clientRequestId = '';
        }
        return {
          attachment,
          clientRequestId,
          message: parsed.message.slice(0, 2000),
        } satisfies ProductFeedbackReplyDraft;
      }
    } catch {}
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
    return null;
  });
};

export const saveProductFeedbackReplyDraft = async (
  publicId: string,
  draft: ProductFeedbackReplyDraft | null,
  ownerBoundary?: AccountSessionBoundary,
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
      await AsyncStorage.setItem(
        key,
        JSON.stringify({
          attachment: draft?.attachment,
          clientRequestId: draft!.clientRequestId,
          message: normalized,
        } satisfies ProductFeedbackReplyDraft),
      );
      assertAccountSessionBoundary(boundary);
      return;
    }
    await AsyncStorage.removeItem(key);
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
      const draft = JSON.parse(raw) as Partial<ProductFeedbackDraft>;
      parsed = draft;
      const valid =
        isProductFeedbackCategory(draft.category) &&
        typeof draft.message === 'string' &&
        draft.message.length <= 1600 &&
        typeof draft.includeDiagnostics === 'boolean' &&
        isUuid(draft.clientRequestId) &&
        Number.isFinite(draft.updatedAt) &&
        Number(draft.updatedAt) <= Date.now() + 5 * 60 * 1000 &&
        Date.now() - Number(draft.updatedAt) <= DRAFT_TTL_MS;
      if (valid) {
        const value = draft as ProductFeedbackDraft;
        if (
          !value.attachment ||
          (await learnerDraftFileIsReadable(value.attachment))
        ) {
          return value;
        }
        await removeLearnerDraftFile(value.attachment);
        assertAccountSessionBoundary(boundary);
        const repaired = {...value, attachment: undefined};
        await AsyncStorage.setItem(key, JSON.stringify(repaired));
        assertAccountSessionBoundary(boundary);
        return repaired;
      }
    } catch {}
    await removeLearnerDraftFile(parsed?.attachment);
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
    return null;
  });
};

export const saveProductFeedbackDraft = async (
  draft: ProductFeedbackDraft,
  ownerBoundary?: AccountSessionBoundary,
): Promise<void> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const key = await accountScopedStorageKey(DRAFT_KEY, boundary);
  await withDraftLock(async () => {
    assertAccountSessionBoundary(boundary);
    if (
      !isProductFeedbackCategory(draft.category) ||
      !isUuid(draft.clientRequestId) ||
      typeof draft.includeDiagnostics !== 'boolean' ||
      typeof draft.message !== 'string' ||
      !Number.isFinite(draft.updatedAt) ||
      draft.message.length > 1600
    ) {
      throw new Error('INVALID_FEEDBACK_DRAFT');
    }
    if (!draft.message.trim() && !draft.attachment) {
      await AsyncStorage.removeItem(key);
      assertAccountSessionBoundary(boundary);
      return;
    }
    await AsyncStorage.setItem(key, JSON.stringify(draft));
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
    const raw = await AsyncStorage.getItem(key);
    assertAccountSessionBoundary(boundary);
    if (raw) {
      try {
        const draft = JSON.parse(raw) as Partial<ProductFeedbackDraft>;
        await removeLearnerDraftFile(draft.attachment);
        assertAccountSessionBoundary(boundary);
      } catch {}
    }
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
  });
};
