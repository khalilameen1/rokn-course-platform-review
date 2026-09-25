import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {serverNowMs} from '../utils/serverClock';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';
import {safeLoginReturnTo} from './authReturn';
import type {LoginReturnTo} from './types';

const CHECKOUT_RETURN_KEY = '@rokn/pending-checkout-return/v1';
const CHECKOUT_RETURN_TTL_MS = 30 * 60 * 1000;
const withCheckoutReturnWrite = createKeyedAsyncQueue();

type CheckoutReturnEnvelope = {
  returnTo: LoginReturnTo;
  createdAt: number;
};

export type PendingCheckoutReturnClaim = {
  accountBoundary: AccountSessionBoundary;
  returnTo: LoginReturnTo;
  receipt: string;
  storageKey: string;
};

const asRecord = (value: unknown): Record<string, unknown> | null =>
  typeof value === 'object' && value !== null && !Array.isArray(value)
    ? (value as Record<string, unknown>)
    : null;

const removeCheckoutReturnIfUnchanged = (
  storageKey: string,
  receipt: string,
  accountBoundary: AccountSessionBoundary,
) =>
  withCheckoutReturnWrite(storageKey, async () => {
    assertAccountSessionBoundary(accountBoundary);
    const current = await AsyncStorage.getItem(storageKey);
    assertAccountSessionBoundary(accountBoundary);
    if (current !== receipt) return false;
    await AsyncStorage.removeItem(storageKey);
    assertAccountSessionBoundary(accountBoundary);
    return true;
  });

/** Persist only a canonical in-app destination, never provider/browser data. */
export const savePendingCheckoutReturn = async (
  value: unknown,
  boundary?: AccountSessionBoundary,
) => {
  const returnTo = safeLoginReturnTo(value);
  if (!returnTo) return undefined;
  const owner = boundary || (await captureAccountSessionBoundary());
  assertAccountSessionBoundary(owner);
  const storageKey = await accountScopedStorageKey(CHECKOUT_RETURN_KEY, owner);
  const envelope: CheckoutReturnEnvelope = {
    returnTo,
    createdAt: serverNowMs(),
  };
  const receipt = JSON.stringify(envelope);
  await withCheckoutReturnWrite(storageKey, async () => {
    assertAccountSessionBoundary(owner);
    await AsyncStorage.setItem(storageKey, receipt);
    assertAccountSessionBoundary(owner);
  });
  return {
    accountBoundary: owner,
    returnTo,
    receipt,
    storageKey,
  } satisfies PendingCheckoutReturnClaim;
};

export const claimPendingCheckoutReturn = async (): Promise<
  PendingCheckoutReturnClaim | undefined
> => {
  const accountBoundary = await captureAccountSessionBoundary();
  const storageKey = await accountScopedStorageKey(
    CHECKOUT_RETURN_KEY,
    accountBoundary,
  );
  const receipt = await AsyncStorage.getItem(storageKey);
  assertAccountSessionBoundary(accountBoundary);
  if (!receipt) return undefined;
  let envelope: Record<string, unknown> | null;
  try {
    envelope = asRecord(JSON.parse(receipt));
  } catch {
    await removeCheckoutReturnIfUnchanged(
      storageKey,
      receipt,
      accountBoundary,
    );
    return undefined;
  }
  const createdAt = Number(envelope?.createdAt);
  const age = serverNowMs() - createdAt;
  const returnTo = safeLoginReturnTo(envelope?.returnTo);
  if (
    !returnTo ||
    !Number.isFinite(createdAt) ||
    age < -60_000 ||
    age > CHECKOUT_RETURN_TTL_MS
  ) {
    await removeCheckoutReturnIfUnchanged(
      storageKey,
      receipt,
      accountBoundary,
    );
    return undefined;
  }
  return {accountBoundary, returnTo, receipt, storageKey};
};

export const acknowledgePendingCheckoutReturn = async (
  claim: PendingCheckoutReturnClaim,
) =>
  removeCheckoutReturnIfUnchanged(
    claim.storageKey,
    claim.receipt,
    claim.accountBoundary,
  );
