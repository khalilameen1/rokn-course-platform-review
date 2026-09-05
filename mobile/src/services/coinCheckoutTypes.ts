export type CoinCheckoutResult = {
  success: boolean;
  pending: boolean;
  cancelled: boolean;
  coinsAdded: number;
  orderRef?: string;
};

export type CoinCheckoutOutcome = 'paid' | 'pending' | 'cancelled' | 'failed';

export type CoinCheckoutFailureDisposition =
  | 'catalogue_changed'
  | 'opening_unavailable'
  | 'status_uncertain';

const catalogueChangedCodes = new Set([
  'package_terms_changed',
  'package_not_available',
]);

const openingUnavailableCodes = new Set([
  'recovery_in_progress',
  'recovery_verification_required',
  'feature_temporarily_unavailable',
  'FEATURE_CHECKOUT_DISABLED',
  'payment_configuration_unavailable',
  'checkout_temporarily_unavailable',
  'CHECKOUT_DISABLED_FOR_DISTRIBUTION',
]);

export const coinCheckoutFailureDisposition = (
  code: string,
): CoinCheckoutFailureDisposition => {
  if (catalogueChangedCodes.has(code)) return 'catalogue_changed';
  if (openingUnavailableCodes.has(code)) return 'opening_unavailable';
  return 'status_uncertain';
};

export const coinCheckoutOutcome = (
  result: CoinCheckoutResult,
): CoinCheckoutOutcome => {
  if (result.success) return 'paid';
  if (result.pending) return 'pending';
  if (result.cancelled) return 'cancelled';
  return 'failed';
};

export type CoinCheckoutOrderStatus = {
  approved: boolean;
  pending: boolean;
  coinsAdded: number;
};

export type CoinCheckoutAttempt = {
  idempotencyKey: string;
  packageId: number;
  expectedPrice: number;
  expectedCoins: number;
  createdAt: string;
  orderRef?: string;
};
