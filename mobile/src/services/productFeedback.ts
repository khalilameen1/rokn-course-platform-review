/**
 * Coordinates feedback delivery, history and guest adoption.
 * Local durability lives in drafts/receipts; wire encoding lives in wire.
 */
import {publicRequest} from '../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {firstBoolean} from './api/common';
import {
  isUuid,
  isRecord,
  isFeedbackPublicId,
  type FeedbackAttachment,
  type ProductFeedbackCategory,
  type ProductFeedbackContext,
  type ProductFeedbackReceipt,
  type ProductFeedbackCase,
} from './productFeedback/contracts';
import {
  createFeedbackBody,
  createReplyBody,
  parseReceipt,
  parseCase,
} from './productFeedback/wire';
import {
  loadStoredReceipts,
  persistProductFeedbackReceipt,
  copyGuestFeedbackReceipts,
  removeGuestFeedbackReceipts,
  markFeedbackReceiptsClaimed,
} from './productFeedback/receipts';
import {migrateGuestFeedbackDrafts} from './productFeedback/drafts';
export type {
  ProductFeedbackCategory,
  FeedbackAttachment,
  ProductFeedbackContext,
  ProductFeedbackDraft,
  ProductFeedbackReceipt,
  ProductFeedbackMessage,
  ProductFeedbackArtifact,
  ProductFeedbackReplyDraft,
  ProductFeedbackCase,
  ProductFeedbackDraftConflict,
} from './productFeedback/contracts';
export {
  loadProductFeedbackDraftConflicts,
  restoreProductFeedbackDraftConflict,
  loadProductFeedbackReplyDraft,
  saveProductFeedbackReplyDraft,
  loadProductFeedbackDraft,
  saveProductFeedbackDraft,
  clearProductFeedbackDraft,
} from './productFeedback/drafts';
export {persistProductFeedbackReceipt} from './productFeedback/receipts';

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
  const receipt = parseReceipt(payload);
  const trackingSaved = await persistProductFeedbackReceipt(receipt, boundary);
  return {...receipt, trackingSaved};
};

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

  const copied = await copyGuestFeedbackReceipts(
    guestScope,
    accountScope,
    accountBoundary,
  );
  if (!copied) return false;
  const {guestReceipts, receipts} = copied;
  await migrateGuestFeedbackDrafts(
    guestScope,
    accountScope,
    guestReceipts.map(receipt => receipt.publicId),
    accountBoundary,
  );
  const removed = await removeGuestFeedbackReceipts(
    guestScope,
    accountBoundary,
  );
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
  if (
    claimedIds.size &&
    !(await markFeedbackReceiptsClaimed(
      accountScope,
      claimedIds,
      accountBoundary,
    ))
  )
    return false;
  return results.every(result => result.status === 'fulfilled');
};

const accessHeaders = (accessToken?: string) =>
  accessToken ? {'X-Support-Access': accessToken} : undefined;

export const loadProductFeedbackCase = async (
  publicId: string,
  accessToken?: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<ProductFeedbackCase> => {
  if (!isFeedbackPublicId(publicId)) {
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
  const form = createReplyBody(input);
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
