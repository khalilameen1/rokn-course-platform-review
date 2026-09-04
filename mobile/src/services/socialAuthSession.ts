import {savePendingWelcomeBonus} from './pendingWelcomeBonus';
import {
  deletePendingSocialAuthAttempt,
  extractApiToken,
  loadPendingSocialAuthAttempt,
  loadSecureSession,
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
  await savePendingWelcomeBonus(session.welcome_bonus_granted).catch(
    () => false,
  );

  const afterWrite = await loadPendingSocialAuthAttempt().catch(() => null);
  if (afterWrite && sameAttempt(afterWrite, pending)) {
    await deletePendingSocialAuthAttempt(afterWrite).catch(() => undefined);
  }
  return true;
};
