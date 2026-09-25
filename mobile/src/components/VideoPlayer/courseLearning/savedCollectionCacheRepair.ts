import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {settleWithin} from '../../../utils/settleWithin';

export const acceptRemoteCacheRepair = async (
  accountBoundary: AccountSessionBoundary,
  repair: () => Promise<unknown>,
) => {
  try {
    assertAccountSessionBoundary(accountBoundary);
    // Only the caller's wait is bounded. Each cache keeps its raw queue so a
    // late native write cannot overtake a newer repair or authoritative read.
    await settleWithin(repair(), undefined);
    assertAccountSessionBoundary(accountBoundary);
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      throw error;
    }
    // The server mutation is already authoritative. The next folder/library
    // read and the feed reconciliation rebuild both caches, so no mutation
    // outbox may replay a successful write or delete.
  }
};
