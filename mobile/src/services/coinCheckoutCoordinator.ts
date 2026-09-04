import type {CoinCheckoutResult} from './coinCheckoutTypes';

const checkoutFlights = new Map<string, Promise<CoinCheckoutResult>>();
const reconciliationFlights = new Map<
  string,
  Promise<CoinCheckoutResult | null>
>();
const emittedCredits = new Set<string>();
const MAX_EMITTED_CREDITS = 128;
type CoinCheckoutCreditListener = (
  result: CoinCheckoutResult,
  ownerScope: string,
) => void;
const creditListeners = new Set<CoinCheckoutCreditListener>();

export const runCoinCheckoutSingleFlight = (
  ownerKey: string,
  operation: () => Promise<CoinCheckoutResult>,
) => {
  const current = checkoutFlights.get(ownerKey);
  if (current) return current;

  const reconciliation = reconciliationFlights.get(ownerKey);
  let flight: Promise<CoinCheckoutResult>;
  flight = (
    reconciliation
      ? reconciliation.catch(() => null).then(operation)
      : operation()
  ).finally(() => {
    if (checkoutFlights.get(ownerKey) === flight) {
      checkoutFlights.delete(ownerKey);
    }
  });
  checkoutFlights.set(ownerKey, flight);
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
    // surface. Its screen already handles the terminal result, so observing
    // the same promise here would also emit a duplicate credit event.
    return checkout.then(
      () => null,
      () => null,
    );
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

export const emitCoinCheckoutCreditOnce = (
  ownerScope: string,
  result: CoinCheckoutResult,
) => {
  if (!result.success || !result.orderRef) return;
  const creditKey = `${ownerScope}:${result.orderRef}`;
  if (emittedCredits.has(creditKey)) return;

  emittedCredits.add(creditKey);
  while (emittedCredits.size > MAX_EMITTED_CREDITS) {
    const oldest = emittedCredits.values().next().value;
    if (typeof oldest !== 'string') break;
    emittedCredits.delete(oldest);
  }
  creditListeners.forEach(listener => {
    try {
      listener(result, ownerScope);
    } catch {
      // A screen observer cannot invalidate an authoritative payment result.
    }
  });
};

export const subscribeCoinCheckoutCredits = (
  listener: CoinCheckoutCreditListener,
) => {
  creditListeners.add(listener);
  return () => {
    creditListeners.delete(listener);
  };
};
