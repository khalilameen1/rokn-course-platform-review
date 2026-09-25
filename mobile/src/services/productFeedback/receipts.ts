import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {settleWithin} from '../../utils/settleWithin';
import {
  isRecord,
  isFeedbackPublicId,
  safeAccessToken,
  type ProductFeedbackReceipt,
} from './contracts';

type StoredCaseReceipt = {
  accessToken?: string;
  publicId: string;
  updatedAt: number;
};

const RECEIPTS_KEY = '@rokn/product-feedback-receipts/v1';

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

const loadStoredReceiptsFromKey = async (
  key: string,
  boundary?: AccountSessionBoundary,
): Promise<StoredCaseReceipt[]> => {
  if (boundary) assertAccountSessionBoundary(boundary);
  const raw = await AsyncStorage.getItem(key);
  if (boundary) assertAccountSessionBoundary(boundary);
  if (!raw) return [];
  try {
    const parsed: unknown = JSON.parse(raw);
    return (Array.isArray(parsed) ? parsed : [])
      .map((item: unknown): StoredCaseReceipt | null => {
        if (!isRecord(item)) return null;
        const publicId = String(item.publicId || '').trim();
        if (!isFeedbackPublicId(publicId)) return null;
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
    // Reads never repair storage: a stale malformed snapshot can arrive after
    // a newer receipt was saved. The next serialized write replaces bad data
    // atomically with its normal setItem, without a separate deletion.
    return [];
  }
};

export const loadStoredReceipts = async (
  boundary: AccountSessionBoundary,
): Promise<StoredCaseReceipt[]> =>
  loadStoredReceiptsFromKey(
    await accountScopedStorageKey(RECEIPTS_KEY, boundary),
    boundary,
  );

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

const scopedFeedbackKey = (base: string, scope: string) => `${base}:${scope}`;

/** Copies before removal; late native writes remain serialized per owner. */
export const copyGuestFeedbackReceipts = async (
  guestScope: string,
  accountScope: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<{
  guestReceipts: StoredCaseReceipt[];
  receipts: StoredCaseReceipt[];
} | null> => {
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
  if (!ready) return null;
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
  return copied;
};

export const removeGuestFeedbackReceipts = async (
  guestScope: string,
  accountBoundary?: AccountSessionBoundary,
): Promise<boolean> => {
  const guestReceiptsKey = scopedFeedbackKey(RECEIPTS_KEY, guestScope);
  const removed = await settleWithin(
    withReceiptWrite(guestReceiptsKey, async () => {
      if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
      await AsyncStorage.removeItem(guestReceiptsKey);
      return true;
    }),
    false,
  );
  if (accountBoundary) assertAccountSessionBoundary(accountBoundary);
  return removed;
};

/** Merge the claim result into the latest receipts, never a stale copy. */
export const markFeedbackReceiptsClaimed = async (
  accountScope: string,
  claimedIds: Set<string>,
  accountBoundary?: AccountSessionBoundary,
): Promise<boolean> => {
  const accountReceiptsKey = scopedFeedbackKey(RECEIPTS_KEY, accountScope);
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
  return tracked;
};
