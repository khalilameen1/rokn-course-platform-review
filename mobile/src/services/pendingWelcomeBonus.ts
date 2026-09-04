import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  AsyncKeys,
  captureAccountSessionBoundary,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
  type AccountSessionBoundary,
} from '../constants/helpers';

const PENDING_WELCOME_BONUS_KEY = '@rokn/pending-welcome-bonus/v2';

const currentKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(PENDING_WELCOME_BONUS_KEY, boundary);

export const savePendingWelcomeBonus = async (
  amount: unknown,
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const normalized = Math.max(0, Number(amount) || 0);
  const session = await getItem(AsyncKeys.USER_DATA);
  assertAccountSessionBoundary(boundary);
  if (!normalized || !extractApiToken(session)) {
    return false;
  }
  const saved = await saveItem(await currentKey(boundary), normalized);
  assertAccountSessionBoundary(boundary);
  return saved;
};

export const getPendingWelcomeBonus = async (
  ownerBoundary?: AccountSessionBoundary,
): Promise<number | null> => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  // Old builds stored this UI receipt globally. Discard it instead of showing
  // one learner's message after an account switch on the same phone.
  await removeItem(AsyncKeys.PENDING_WELCOME_BONUS);
  assertAccountSessionBoundary(boundary);
  if (!extractApiToken(await getItem(AsyncKeys.USER_DATA))) return null;
  assertAccountSessionBoundary(boundary);
  const amount = Number(await getItem(await currentKey(boundary)));
  assertAccountSessionBoundary(boundary);
  return amount > 0 ? amount : null;
};

export const clearPendingWelcomeBonus = async (
  ownerBoundary?: AccountSessionBoundary,
) => {
  const boundary = ownerBoundary || (await captureAccountSessionBoundary());
  const removed = await removeItem(await currentKey(boundary));
  assertAccountSessionBoundary(boundary);
  return removed;
};
