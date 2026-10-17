import {
  accountScopedStorageKey,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import type {CoinCheckoutResult} from './coinCheckoutTypes';
import {notifyWalletSettlement} from './walletSettlement';

const emittedCredits = new Set<string>();
const MAX_EMITTED_CREDIT_KEYS = 128;

/** Best-effort UI delivery only; receipt verification and settlement live elsewhere.
 * A verified store credit is announced on the one wallet settlement channel. */
export const emitNativeStoreCreditOnce = async (
  receiptKey: string,
  result: CoinCheckoutResult,
  accountScope: string,
): Promise<void> => {
  if (!result.success || emittedCredits.has(receiptKey)) return;
  let boundary: AccountSessionBoundary;
  let currentScope: string;
  try {
    boundary = await captureAccountSessionBoundary();
    currentScope = await accountScopedStorageKey(
      '@rokn/native-store-reconciliation/v1',
      boundary,
    );
  } catch {
    // An unavailable local account key cannot turn an accepted payment into a
    // rejection. Do not consume the notification key; recovery may retry it.
    return;
  }
  if (currentScope !== accountScope || emittedCredits.has(receiptKey)) return;
  emittedCredits.add(receiptKey);
  while (emittedCredits.size > MAX_EMITTED_CREDIT_KEYS) {
    const oldest = emittedCredits.values().next().value;
    if (typeof oldest !== 'string') break;
    emittedCredits.delete(oldest);
  }
  try {
    notifyWalletSettlement(boundary);
  } catch {
    // The account changed after verification. Its balance is no longer shown.
  }
};
