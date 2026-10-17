import type {AccountSessionBoundary} from '../constants/helpers';
import type {CoinCheckoutResult} from './coinCheckoutTypes';
import {notifyWalletSettlement} from './walletSettlement';

type CoinCheckoutFlight = {
  intentKey: string;
  promise: Promise<CoinCheckoutResult>;
};

const checkoutFlights = new Map<string, CoinCheckoutFlight>();
const reconciliationFlights = new Map<
  string,
  Promise<CoinCheckoutResult | null>
>();
const emittedCredits = new Set<string>();
const MAX_EMITTED_CREDITS = 128;

export const runCoinCheckoutSingleFlight = (
  ownerKey: string,
  intentKey: string,
  operation: () => Promise<CoinCheckoutResult>,
) => {
  const current = checkoutFlights.get(ownerKey);
  if (current) {
    if (current.intentKey === intentKey) return current.promise;
    const error = new Error('Coin checkout already in progress') as Error & {
      code?: string;
    };
    error.code = 'coin_checkout_in_progress';
    return Promise.reject(error);
  }

  const reconciliation = reconciliationFlights.get(ownerKey);
  let flight: Promise<CoinCheckoutResult>;
  flight = (
    reconciliation
      ? reconciliation
          .catch(() => null)
          .then(recovered =>
            // This tap was made against the balance before recovery settled.
            // Deliver that credit before offering another explicit payment.
            recovered?.success ? recovered : operation(),
          )
      : operation()
  ).finally(() => {
    if (checkoutFlights.get(ownerKey)?.promise === flight) {
      checkoutFlights.delete(ownerKey);
    }
  });
  checkoutFlights.set(ownerKey, {intentKey, promise: flight});
  return flight;
};

export const runCoinCheckoutReconciliationSingleFlight = (
  ownerKey: string,
  operation: () => Promise<CoinCheckoutResult | null>,
) => {
  const current = reconciliationFlights.get(ownerKey);
  if (current) return current;
  const checkout = checkoutFlights.get(ownerKey);
  if (checkout) {
    // Foreground recovery must not race the checkout which owns the provider
    // surface. The checkout operation emits its successful credit; pending
    // state and failures still reach the runtime for a later recovery pass.
    // Do not replay the checkout or emit its successful credit a second time.
    return checkout.promise.then(result => (result.pending ? result : null));
  }

  let flight: Promise<CoinCheckoutResult | null>;
  flight = operation().finally(() => {
    if (reconciliationFlights.get(ownerKey) === flight) {
      reconciliationFlights.delete(ownerKey);
    }
  });
  reconciliationFlights.set(ownerKey, flight);
  return flight;
};

/** Announces a verified credit once per account and order on the one wallet
 * channel every balance reader listens to. */
export const emitCoinCheckoutCreditOnce = (
  boundary: AccountSessionBoundary,
  result: CoinCheckoutResult,
) => {
  if (!result.success || !result.orderRef) return;
  const creditKey = `${boundary.scope}:${result.orderRef}`;
  if (emittedCredits.has(creditKey)) return;

  emittedCredits.add(creditKey);
  while (emittedCredits.size > MAX_EMITTED_CREDITS) {
    const oldest = emittedCredits.values().next().value;
    if (typeof oldest !== 'string') break;
    emittedCredits.delete(oldest);
  }
  try {
    notifyWalletSettlement(boundary);
  } catch {
    // The account changed after settlement. Its balance is no longer shown.
  }
};
