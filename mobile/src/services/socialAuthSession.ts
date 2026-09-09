import {savePendingWelcomeBonus} from './pendingWelcomeBonus';
import {settleWithin} from '../utils/settleWithin';
import {captureAccountSessionBoundary} from '../constants/helpers';
import {
  deletePendingSocialAuthAttempt,
  extractApiToken,
  loadPendingSocialAuthAttempt,
  loadSecureSession,
  peekSecureSession,
  replacePendingSocialAuthAttempt,
  saveSecureSession,
  type PendingSocialAuthAttempt,
} from './secureSession';
import type {SocialAuthSession} from './socialAuthTypes';

const sameAttempt = (
  left: PendingSocialAuthAttempt,
  right: PendingSocialAuthAttempt,
) =>
  left.provider === right.provider &&
  left.verifier === right.verifier &&
  left.startedAt === right.startedAt &&
  (left.purpose ?? 'login') === (right.purpose ?? 'login');

/**
 * Commits a completed login exactly once. The encrypted attempt is the small
 * recovery journal between the one-time provider exchange and the durable app
 * session, so a process death cannot turn a successful login into a dead code.
 */
export const persistCompletedSocialLogin = async (
  pending: PendingSocialAuthAttempt,
  session: SocialAuthSession,
) => {
  const current = await loadPendingSocialAuthAttempt();
  if (!current || !sameAttempt(current, pending)) {
    const committed = await loadSecureSession().catch(() => null);
    return extractApiToken(committed) === session.api_token;
  }

  // A retained post-commit journal may be resumed while optional receipt
  // cleanup is still pending. The exact bearer is already durable; committing
  // it again would advance the epoch and invalidate the new account's reads.
  const alreadyCommitted =
    extractApiToken(peekSecureSession().session) === session.api_token;
  if (!alreadyCommitted) {
    const staged = await replacePendingSocialAuthAttempt(current, {
      ...current,
      nativeToken: undefined,
      providerName: undefined,
      completedSession: session,
    });
    if (!staged) {
      const committed = await loadSecureSession().catch(() => null);
      return extractApiToken(committed) === session.api_token;
    }

    await saveSecureSession(session);
  }

  // Neither a welcome-popup receipt nor retirement of its completed journal
  // owns delivery of a committed credential to the UI. Keep the raw work and
  // its conditional journal deletion intact after this bounded wait ends.
  const cleanup = (async () => {
    // A retained journal must not recreate a popup already consumed after
    // credential delivery. Only the committing flight owns this UI receipt.
    if (!alreadyCommitted) {
      try {
        const owner = await captureAccountSessionBoundary();
        if (
          extractApiToken(peekSecureSession().session) === session.api_token
        ) {
          await savePendingWelcomeBonus(session.welcome_bonus_granted, owner);
        }
      } catch {
        // This popup is optional; credential delivery and journal retirement
        // do not depend on its local storage being available.
      }
    }

    const afterWrite = await loadPendingSocialAuthAttempt().catch(() => null);
    if (afterWrite && sameAttempt(afterWrite, pending)) {
      await deletePendingSocialAuthAttempt(afterWrite).catch(() => undefined);
    }
  })();
  await settleWithin(cleanup, undefined);
  return extractApiToken(peekSecureSession().session) === session.api_token;
};
