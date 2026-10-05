import {useCallback, useRef} from 'react';
import type {AccountSessionBoundary} from '../../constants/helpers';
import {WATCH_HISTORY_ENABLED_KEY} from '../../components/VideoPlayer/courseLearningApi';
import {
  MARKETING_NOTIFICATIONS_KEY,
  queuePendingPrivacyPreferences,
  readPendingPrivacyPreferences,
  type PendingPrivacyPreferences,
} from '../../services/pendingAccountWrites';

export {MARKETING_NOTIFICATIONS_KEY, readPendingPrivacyPreferences} from '../../services/pendingAccountWrites';

export const usePrivacyPreferenceSync = () => {
  const dirtyKeysRef = useRef(new Set<string>());

  const queue = useCallback(
    (
      patch: PendingPrivacyPreferences = {},
      ownerBoundary?: AccountSessionBoundary,
    ) => {
      // The service serializes only native journal writes. Do not place a
      // component-level queue around HTTP or a later accepted tap stays volatile.
      return (async () => {
        await queuePendingPrivacyPreferences(patch, ownerBoundary);
        const pending = await readPendingPrivacyPreferences(undefined, ownerBoundary);
        if (typeof pending.watchHistoryEnabled !== 'boolean') {
          dirtyKeysRef.current.delete(WATCH_HISTORY_ENABLED_KEY);
        }
        if (typeof pending.marketingNotificationsEnabled !== 'boolean') {
          dirtyKeysRef.current.delete(MARKETING_NOTIFICATIONS_KEY);
        }
      })();
    },
    [],
  );

  return {dirtyKeys: dirtyKeysRef.current, queue};
};
