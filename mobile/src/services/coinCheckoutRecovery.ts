import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {
  clearCoinCheckoutAttempt,
  readCoinCheckoutAttempts,
} from './coinCheckoutAttemptStore';
import {
  abandonCoinCheckoutOrder,
  reconcileCoinCheckoutOrder,
} from './coinCheckoutHttp';
import type {
  CoinCheckoutAttempt,
  CoinCheckoutResult,
} from './coinCheckoutTypes';

export const reconcileCoinCheckoutAttempt = async (
  attempt: CoinCheckoutAttempt,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutResult> => {
  assertAccountSessionBoundary(boundary);
  if (!attempt.orderRef) {
    // Recovery may reconcile an order which the learner actually opened, but
    // it must never create one from a client-only intent. Doing so on launch,
    // foreground or package switch manufactures a `pending` payment which the
    // learner never saw. If initiation previously reached the server but its
    // response was lost, the next explicit tap is still recovered by the
    // server's `pending_checkout_exists` contract.
    await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
    return {
      success: false,
      pending: false,
      cancelled: true,
      coinsAdded: 0,
    };
  }

  const status = await reconcileCoinCheckoutOrder(
    attempt.orderRef,
    boundary,
    1,
  );
  if (!status.pending) {
    await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
  }
  return {
    success: status.approved,
    pending: status.pending,
    cancelled: false,
    coinsAdded: status.approved ? status.coinsAdded : 0,
    orderRef: attempt.orderRef,
  };
};

export const reconcilePendingCoinCheckoutAttempts = async (
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutResult | null> => {
  assertAccountSessionBoundary(boundary);
  const attempts = await readCoinCheckoutAttempts(boundary);
  if (attempts.length === 0) return null;

  let pending: CoinCheckoutResult | null = null;
  let approved: CoinCheckoutResult | null = null;
  for (const attempt of attempts) {
    assertAccountSessionBoundary(boundary);
    const result = await reconcileCoinCheckoutAttempt(attempt, boundary);
    if (result.success) approved = result;
    else if (result.pending && !pending) pending = result;
  }

  return (
    approved ??
    pending ?? {
      success: false,
      pending: false,
      cancelled: false,
      coinsAdded: 0,
    }
  );
};

export const retireCoinCheckoutAttempt = async (
  attempt: CoinCheckoutAttempt,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutResult> => {
  let orderRef = attempt.orderRef;
  if (!orderRef) {
    const recovered = await reconcileCoinCheckoutAttempt(attempt, boundary);
    if (recovered.success || !recovered.pending || !recovered.orderRef) {
      return recovered;
    }
    orderRef = recovered.orderRef;
  }

  const abandoned = await abandonCoinCheckoutOrder(orderRef, boundary);
  if (!abandoned.pending) {
    await clearCoinCheckoutAttempt(attempt.idempotencyKey, boundary);
  }
  return {
    success: abandoned.approved,
    pending: abandoned.pending,
    cancelled: !abandoned.approved && !abandoned.pending,
    coinsAdded: abandoned.approved ? abandoned.coinsAdded : 0,
    orderRef,
  };
};

export const reconcileCoinCheckoutPackageSwitch = async (
  selectedPackageId: number,
  boundary: AccountSessionBoundary,
): Promise<CoinCheckoutResult | null> => {
  const attempts = await readCoinCheckoutAttempts(boundary);
  for (const attempt of attempts) {
    if (attempt.packageId === selectedPackageId) continue;
    assertAccountSessionBoundary(boundary);
    const retired = await retireCoinCheckoutAttempt(attempt, boundary);
    if (retired.success || retired.pending) return retired;
  }
  return null;
};
