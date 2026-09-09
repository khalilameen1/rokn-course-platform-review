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
import {settleWithin} from '../utils/settleWithin';
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
  sourceScreen?: string;
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
const receiptWrites = new Map<string, Promise<void>>();

const withReceiptWrite = <T>(
  key: string,
  operation: () => Promise<T>,
): Promise<T> => {
  const result = (receiptWrites.get(key) || Promise.resolve()).then(
    operation,
    operation,
  );
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  receiptWrites.set(key, tail);
  void tail.then(() => {
    if (receiptWrites.get(key) === tail) receiptWrites.delete(key);
  });
  return result;
};

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
): Promise<ProductFeedbackReceipt & {trackingSaved: boolean}> => {
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
  const trackingSaved = await persistProductFeedbackReceipt(receipt, boundary);
  return {...receipt, trackingSaved};
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
  // A timed-out native write can still land. Do not declare migration complete
  // or remove the guest's original recovery draft ahead of that raw queue.
  const ready = await settleWithin(
    Promise.all([
      receiptWrites.get(guestReceiptsKey),
      receiptWrites.get(accountReceiptsKey),
    ]).then(() => true),
    false,
  );
  if (!ready) return false;
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  const copied = await settleWithin(
    withReceiptWrite(accountReceiptsKey, async () => {
      if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
      const [guestReceipts, accountReceipts] = await Promise.all([
        loadStoredReceiptsFromKey(guestReceiptsKey, accountBoundary),
        loadStoredReceiptsFromKey(accountReceiptsKey, accountBoundary),
      ]);
      const merged = new Map<string, StoredCaseReceipt>();
      [...accountReceipts, ...guestReceipts]
        .sort((left, right) => left.updatedAt - right.updatedAt)
        .forEach(receipt => merged.set(receipt.publicId, receipt));
      const receipts = [...merged.values()]
        .sort((left, right) => right.updatedAt - left.updatedAt)
        .slice(0, 20);
      await AsyncStorage.setItem(accountReceiptsKey, JSON.stringify(receipts));
      if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
      return {guestReceipts, receipts};
    }),
    null,
  );
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  if (!copied) return false;
  const {guestReceipts, receipts} = copied;

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

  await moveIfMissing(DRAFT_KEY);
  for (const receipt of guestReceipts) {
    await moveIfMissing(`${REPLY_DRAFT_PREFIX}${receipt.publicId}`);
  }
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  const removed = await settleWithin(
    withReceiptWrite(guestReceiptsKey, async () => {
      if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
      await AsyncStorage.removeItem(guestReceiptsKey);
      return true;
    }),
    false,
  );
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  if (!removed) return false;

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
    const tracked = await settleWithin(
      withReceiptWrite(accountReceiptsKey, async () => {
        const latest = await loadStoredReceiptsFromKey(
          accountReceiptsKey,
          accountBoundary,
        );
        await AsyncStorage.setItem(
          accountReceiptsKey,
          JSON.stringify(
            latest.map(receipt =>
              claimedIds.has(receipt.publicId)
                ? {...receipt, accessToken: undefined}
                : receipt,
            ),
          ),
        );
        return true;
      }),
      false,
    );
    if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
    if (!tracked) return false;
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
  await withReceiptWrite(key, async () => {
    assertAccountSessionBoundary(boundary);
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
  });
};

export const persistProductFeedbackReceipt = async (
  receipt: ProductFeedbackReceipt,
  boundary: AccountSessionBoundary,
): Promise<boolean> => {
  assertAccountSessionBoundary(boundary);
  // Delivery is already confirmed. Keep late local writes ordered without
  // holding the received state hostage to native storage availability.
  const saved = await settleWithin(
    rememberCaseReceipt(receipt, boundary).then(() => true),
    false,
  );
  assertAccountSessionBoundary(boundary);
  return saved;
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
      let page = 1;
      while (true) {
        assertAccountSessionBoundary(boundary);
        const response =
          page === 1
            ? await publicRequest.get('feedback')
            : await publicRequest.get('feedback', {params: {page}});
        assertAccountSessionBoundary(boundary);
        const data = (response.data as {data?: unknown})?.data;
        if (!isRecord(data) || !Array.isArray(data.items)) {
          throw new Error('INVALID_SUPPORT_CASES_RESPONSE');
        }
        const items = data.items.map(parseCase);
        const pagination = isRecord(data.pagination) ? data.pagination : {};
        const lastPage = Number(pagination.last_page);
        const hasMore = firstBoolean(pagination.has_more);
        const expectedHasMore = page < lastPage;
        if (
          Number(pagination.current_page) !== page ||
          !Number.isSafeInteger(lastPage) ||
          lastPage < 1 ||
          lastPage > 100000 ||
          hasMore !== expectedHasMore ||
          (hasMore && items.length === 0) ||
          (lastPage < page && items.length > 0)
        ) {
          throw new Error('INVALID_SUPPORT_CASES_PAGINATION');
        }
        // Read every page before replacing the visible history. Reject repeated
        // entries instead of silently collapsing overlapping pages into one list.
        items.forEach(item => {
          if (cases.has(item.publicId))
            throw new Error('SUPPORT_CASES_CHANGED_DURING_READ');
          cases.set(item.publicId, item);
        });
        if (!hasMore) break;
        page += 1;
      }
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
