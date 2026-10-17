export type CoinCheckoutResult = {
  success: boolean;
  pending: boolean;
  cancelled: boolean;
  coinsAdded: number;
  orderRef?: string;
  /** The provider reported a submitted payment: a success/pending callback or
   * a store transaction. A pending result without it means the learner left
   * the hosted page before paying, which is not a payment under review. */
  paymentReported?: boolean;
  /** The store charged the learner but verification could not finish in time.
   * The unfinished transaction is verified by launch/foreground recovery. */
  activationDeferred?: boolean;
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
