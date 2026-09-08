import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';

type WalletSettlementListener = (boundary: AccountSessionBoundary) => void;
const listeners = new Set<WalletSettlementListener>();

/** An acknowledged wallet mutation invalidates snapshots, not purchase intent. */
export const notifyWalletSettlement = (boundary: AccountSessionBoundary) => {
  assertAccountSessionBoundary(boundary);
  listeners.forEach(listener => {
    try {
      listener(boundary);
    } catch {
      // A screen observer cannot turn a committed reward into a failed claim.
    }
  });
};

export const subscribeWalletSettlements = (
  listener: WalletSettlementListener,
) => {
  listeners.add(listener);
  return () => {
    listeners.delete(listener);
  };
};
