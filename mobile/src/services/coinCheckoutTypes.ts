export type CoinCheckoutResult = {
  success: boolean;
  pending: boolean;
  cancelled: boolean;
  coinsAdded: number;
  orderRef?: string;
};

export type CoinCheckoutOutcome = 'paid' | 'pending' | 'cancelled' | 'failed';

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
