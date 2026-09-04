import {useCallback, useRef} from 'react';
import type {AccountSessionBoundary} from '../../constants/helpers';
import {WATCH_HISTORY_ENABLED_KEY} from '../../components/VideoPlayer/courseLearningApi';
import {
  queuePendingPrivacyPreferences,
  readPendingPrivacyPreferences,
  type PendingPrivacyPreferences,
} from '../../services/pendingAccountWrites';

export const MARKETING_NOTIFICATIONS_KEY = 'PREF_MARKETING_NOTIFICATIONS';

export {readPendingPrivacyPreferences};

export const usePrivacyPreferenceSync = () => {
  const dirtyKeysRef = useRef(new Set<string>());
  const queueRef = useRef<Promise<void>>(Promise.resolve());

  const queue = useCallback(
    (
      patch: PendingPrivacyPreferences = {},
      ownerBoundary?: AccountSessionBoundary,
    ) => {
      queueRef.current = queueRef.current
        .catch(() => undefined)
        .then(async () => {
          await queuePendingPrivacyPreferences(patch, ownerBoundary);
          const pending = await readPendingPrivacyPreferences(
            undefined,
            ownerBoundary,
          );
          if (typeof pending.watchHistoryEnabled !== 'boolean') {
            dirtyKeysRef.current.delete(WATCH_HISTORY_ENABLED_KEY);
          }
          if (typeof pending.marketingNotificationsEnabled !== 'boolean') {
            dirtyKeysRef.current.delete(MARKETING_NOTIFICATIONS_KEY);
          }
        });

      return queueRef.current;
    },
    [],
  );

  return {dirtyKeys: dirtyKeysRef.current, queue};
};
