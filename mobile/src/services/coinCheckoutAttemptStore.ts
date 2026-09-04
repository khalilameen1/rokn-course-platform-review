import * as Crypto from 'expo-crypto';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  getItem,
  removeItem,
  saveItem,
} from '../constants/helpers';
import type {AccountSessionBoundary} from '../constants/helpers';
import type {CoinCheckoutAttempt} from './coinCheckoutTypes';

type CoinCheckoutLedger = {
  attempts: CoinCheckoutAttempt[];
};

const CHECKOUT_ATTEMPT_KEY = '@rokn/coin-checkout-attempt/v2';
const UUID_PATTERN =
  /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i;
const ORDER_REFERENCE_PATTERN = /^[a-zA-Z0-9_-]{8,100}$/;
let storageTail: Promise<void> = Promise.resolve();

const withStorageLock = <T>(operation: () => Promise<T>): Promise<T> => {
  const result = storageTail.then(operation, operation);
  storageTail = result.then(
    () => undefined,
    () => undefined,
  );
  return result;
};

export const coinCheckoutOwnerKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(CHECKOUT_ATTEMPT_KEY, boundary);

const normalizeAttempt = (value: unknown): CoinCheckoutAttempt | null => {
  if (!value || typeof value !== 'object') return null;
  const candidate = value as Partial<CoinCheckoutAttempt>;
  const packageId = Number(candidate.packageId);
  const idempotencyKey = String(candidate.idempotencyKey || '').toLowerCase();
  const orderRef = String(candidate.orderRef || '').trim();
  const createdAt = String(candidate.createdAt || '').trim();
  const expectedPrice = Number(candidate.expectedPrice);
  const expectedCoins = Number(candidate.expectedCoins);

  if (
    !Number.isSafeInteger(packageId) ||
    packageId <= 0 ||
    !UUID_PATTERN.test(idempotencyKey) ||
    (orderRef !== '' && !ORDER_REFERENCE_PATTERN.test(orderRef)) ||
    !createdAt ||
    !Number.isFinite(Date.parse(createdAt)) ||
    !Number.isFinite(expectedPrice) ||
    expectedPrice <= 0 ||
    !Number.isSafeInteger(expectedCoins) ||
    expectedCoins <= 0
  ) {
    throw new Error('CHECKOUT_RECOVERY_RECORD_INVALID');
  }

  return {
    idempotencyKey,
    packageId,
    expectedPrice,
    expectedCoins,
    createdAt,
    ...(orderRef ? {orderRef} : {}),
  };
};

const normalizeLedger = (value: unknown): CoinCheckoutAttempt[] => {
  if (!value || typeof value !== 'object') return [];
  const rawAttempts = (value as Partial<CoinCheckoutLedger>).attempts;
  if (!Array.isArray(rawAttempts)) return [];

  const byPackage = new Map<number, CoinCheckoutAttempt>();
  rawAttempts.forEach(raw => {
    try {
      const attempt = normalizeAttempt(raw);
      if (attempt) byPackage.set(attempt.packageId, attempt);
    } catch {
      // One damaged row cannot block valid sibling attempts. The server still
      // owns the corresponding order and can recover it on the next tap.
    }
  });
  return [...byPackage.values()];
};

const saveAttempts = async (
  storageKey: string,
  attempts: CoinCheckoutAttempt[],
) => {
  if (attempts.length === 0) {
    await removeItem(storageKey);
    return true;
  }
  return saveItem(storageKey, {attempts} satisfies CoinCheckoutLedger);
};

export const readCoinCheckoutAttempts = async (
  boundary: AccountSessionBoundary,
) => {
  assertAccountSessionBoundary(boundary);
  const attempts = normalizeLedger(
    await getItem(await coinCheckoutOwnerKey(boundary)),
  );
  assertAccountSessionBoundary(boundary);
  return attempts;
};

export const readCoinCheckoutAttempt = async (
  packageId: number,
  boundary: AccountSessionBoundary,
) => {
  const attempts = await readCoinCheckoutAttempts(boundary);
  return attempts.find(attempt => attempt.packageId === packageId) ?? null;
};

export const getOrCreateCoinCheckoutAttempt = async (
  packageId: number,
  expectedPrice: number,
  expectedCoins: number,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutAttempt> =>
  withStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await coinCheckoutOwnerKey(boundary);
    const attempts = normalizeLedger(await getItem(storageKey));
    assertAccountSessionBoundary(boundary);
    const stored = attempts.find(attempt => attempt.packageId === packageId);
    if (stored) return stored;

    const attempt = {
      idempotencyKey: Crypto.randomUUID().toLowerCase(),
      packageId,
      expectedPrice,
      expectedCoins,
      createdAt: new Date().toISOString(),
    } satisfies CoinCheckoutAttempt;
    const persisted = await saveAttempts(storageKey, [...attempts, attempt]);
    if (!persisted) throw new Error('CHECKOUT_IDEMPOTENCY_UNAVAILABLE');
    return attempt;
  });

export const rememberCoinCheckoutOrder = async (
  attempt: CoinCheckoutAttempt,
  orderRef: string,
  boundary: AccountSessionBoundary,
) =>
  withStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await coinCheckoutOwnerKey(boundary);
    const attempts = normalizeLedger(await getItem(storageKey));
    assertAccountSessionBoundary(boundary);
    const current = attempts.find(
      candidate => candidate.idempotencyKey === attempt.idempotencyKey,
    );
    if (!current) {
      return saveAttempts(storageKey, [
        ...attempts.filter(
          candidate => candidate.packageId !== attempt.packageId,
        ),
        {...attempt, orderRef},
      ]);
    }
    return saveAttempts(
      storageKey,
      attempts.map(candidate =>
        candidate.idempotencyKey === attempt.idempotencyKey
          ? {...candidate, orderRef}
          : candidate,
      ),
    );
  });

export const reassociateCoinCheckoutAttempt = async (
  attempt: CoinCheckoutAttempt,
  serverAttempt: {
    packageId: number;
    orderRef: string;
    expectedPrice: number;
    expectedCoins: number;
  },
  boundary: AccountSessionBoundary,
) =>
  withStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await coinCheckoutOwnerKey(boundary);
    const attempts = normalizeLedger(await getItem(storageKey));
    assertAccountSessionBoundary(boundary);
    const replacement = normalizeAttempt({
      idempotencyKey: attempt.idempotencyKey,
      packageId: serverAttempt.packageId,
      expectedPrice: serverAttempt.expectedPrice,
      expectedCoins: serverAttempt.expectedCoins,
      createdAt: attempt.createdAt,
      orderRef: serverAttempt.orderRef,
    });
    if (!replacement) return false;

    return saveAttempts(storageKey, [
      ...attempts.filter(
        candidate =>
          candidate.idempotencyKey !== attempt.idempotencyKey &&
          candidate.packageId !== serverAttempt.packageId,
      ),
      replacement,
    ]);
  });

export const clearCoinCheckoutAttempt = async (
  expectedIdempotencyKey: string,
  boundary: AccountSessionBoundary,
) =>
  withStorageLock(async () => {
    assertAccountSessionBoundary(boundary);
    const storageKey = await coinCheckoutOwnerKey(boundary);
    const attempts = normalizeLedger(await getItem(storageKey));
    assertAccountSessionBoundary(boundary);
    await saveAttempts(
      storageKey,
      attempts.filter(
        attempt => attempt.idempotencyKey !== expectedIdempotencyKey,
      ),
    );
  });
