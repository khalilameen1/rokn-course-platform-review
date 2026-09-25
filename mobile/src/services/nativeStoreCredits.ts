import {accountScopedStorageKey} from '../constants/helpers';
import type {CoinCheckoutResult} from './coinCheckoutTypes';

const emittedCredits = new Set<string>();
const MAX_EMITTED_CREDIT_KEYS = 128;
const creditListeners = new Set<(result: CoinCheckoutResult) => void>();

/** Best-effort UI delivery only; receipt verification and settlement live elsewhere. */
export const emitNativeStoreCreditOnce = async (
  receiptKey: string,
  result: CoinCheckoutResult,
  accountScope: string,
): Promise<void> => {
  if (!result.success || emittedCredits.has(receiptKey)) return;
  let currentScope: string;
  try {
    currentScope = await accountScopedStorageKey(
      '@rokn/native-store-reconciliation/v1',
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
  creditListeners.forEach(listener => {
    try {
      listener(result);
    } catch {
      // One screen observer cannot hide the accepted credit from other screens
      // or change the authoritative payment outcome.
    }
  });
};

export const subscribeNativeStoreCredits = (
  listener: (result: CoinCheckoutResult) => void,
) => {
  creditListeners.add(listener);
  return () => {
    creditListeners.delete(listener);
  };
};
