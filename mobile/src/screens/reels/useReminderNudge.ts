import {useCallback, useEffect, useRef, useState} from 'react';
import {AppState} from 'react-native';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  getItem,
  saveItem,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {
  enableSmartReminders,
  getSmartRemindersEnabled,
  scheduleNextLearningReminder,
  setSmartRemindersEnabled,
} from '../../services/smartReminders';
import {hasSession, updateNotificationStatus} from '../../services/roknApi';
import {registerPushDeviceIfEligible} from '../../services/pushDeviceRegistration';

type NudgeOwner = {
  scopeKey: string;
  retired: boolean;
  shown: boolean;
  readFlight: symbol | null;
  enableFlight: Promise<boolean> | null;
};
type Presentation = {owner: NudgeOwner; boundary: AccountSessionBoundary};

export const useReminderNudge = ({
  active,
  blocked,
  contextKey,
  courseId,
  courseTitle,
  scopeKey,
}: {
  active: boolean;
  blocked: boolean;
  contextKey: string;
  courseId?: string;
  courseTitle?: string;
  scopeKey: string;
}) => {
  const foreground = useAppForegroundState();
  const ownerRef = useRef<NudgeOwner>({
    scopeKey,
    retired: false,
    shown: false,
    readFlight: null,
    enableFlight: null,
  });
  if (ownerRef.current.scopeKey !== scopeKey) {
    ownerRef.current.retired = true;
    ownerRef.current = {
      scopeKey,
      retired: false,
      shown: false,
      readFlight: null,
      enableFlight: null,
    };
  }
  const owner = ownerRef.current;
  const [presentation, setPresentation] = useState<Presentation | null>(null);
  const presentationRef = useRef(presentation);
  presentationRef.current = presentation;
  const availabilityRef = useRef({active, blocked, contextKey, foreground});
  availabilityRef.current = {active, blocked, contextKey, foreground};

  useEffect(() => {
    owner.retired = false;
    return () => {
      owner.retired = true;
      owner.readFlight = null;
    };
  }, [owner]);

  useEffect(() => {
    if (!active || blocked || !foreground) owner.readFlight = null;
    if (!active || blocked) {
      setPresentation(current => (current?.owner === owner ? null : current));
    }
    // Backgrounding for OS permission/settings must not dismantle the primer's
    // own return-from-settings lifecycle. Only new presentation is suspended.
  }, [active, blocked, foreground, owner]);

  useEffect(() => {
    // A completed reel offers one opportunity, not a pending campaign which
    // may interrupt a different reel/project after a slow device read.
    owner.readFlight = null;
  }, [contextKey, owner]);

  const maybeOfferReminders = useCallback(() => {
    const offeredContext = availabilityRef.current.contextKey;
    const available = () => {
      const current = availabilityRef.current;
      return (
        ownerRef.current === owner &&
        !owner.retired &&
        current.active &&
        current.foreground &&
        current.contextKey === offeredContext &&
        !current.blocked
      );
    };
    if (!available() || owner.shown || owner.readFlight) return;
    const flight = Symbol('reminder-read');
    owner.readFlight = flight;
    void captureAccountSessionBoundary()
      .then(async boundary => {
        if (!available() || owner.readFlight !== flight) return;
        assertAccountSessionBoundary(boundary);
        const [enabled, seen] = await Promise.all([
          getSmartRemindersEnabled(boundary),
          accountScopedStorageKey(
            '@rokn/reminders/nudge-seen/v1',
            boundary,
          ).then(getItem),
        ]);
        assertAccountSessionBoundary(boundary);
        if (!available() || owner.readFlight !== flight) return;
        if (enabled !== true && !seen) {
          owner.shown = true;
          const next = {owner, boundary};
          presentationRef.current = next;
          setPresentation(next);
        }
      })
      .catch(() => undefined)
      .finally(() => {
        if (owner.readFlight === flight) owner.readFlight = null;
      });
  }, [owner]);

  const closeReminderNudge = useCallback(() => {
    if (
      !presentation ||
      presentation.owner !== owner ||
      owner.retired ||
      presentationRef.current !== presentation
    )
      return;
    const {boundary} = presentation;
    assertAccountSessionBoundary(boundary);
    presentationRef.current = null;
    setPresentation(null);
    void (async () => {
      assertAccountSessionBoundary(boundary);
      const key = await accountScopedStorageKey(
        '@rokn/reminders/nudge-seen/v1',
        boundary,
      );
      assertAccountSessionBoundary(boundary);
      await saveItem(key, true);
      assertAccountSessionBoundary(boundary);
    })().catch(() => undefined);
  }, [owner, presentation]);

  const enableRemindersFromNudge = useCallback((): Promise<boolean> => {
    if (owner.enableFlight) return owner.enableFlight;
    if (
      !presentation ||
      presentation.owner !== owner ||
      owner.retired ||
      presentationRef.current !== presentation ||
      !availabilityRef.current.active ||
      availabilityRef.current.blocked ||
      AppState.currentState !== 'active'
    )
      return Promise.reject(new Error('REMINDER_PRESENTATION_RETIRED'));
    const {boundary} = presentation;
    const assertPresentation = () => {
      assertAccountSessionBoundary(boundary);
      if (
        ownerRef.current !== owner ||
        owner.retired ||
        presentationRef.current !== presentation ||
        !availabilityRef.current.active ||
        availabilityRef.current.blocked
      ) {
        throw new Error('REMINDER_PRESENTATION_RETIRED');
      }
    };
    const flight = (async () => {
      assertPresentation();
      const granted = await enableSmartReminders(boundary, assertPresentation);
      // Once the learner answers the OS request, preference/token settlement
      // belongs to their captured account, not the lifetime of a Reels modal.
      assertAccountSessionBoundary(boundary);
      if (!granted) return false;
      const stored = await setSmartRemindersEnabled(true, boundary);
      assertAccountSessionBoundary(boundary);
      if (!stored) throw new Error('NOTIFICATION_PREFERENCE_STORAGE_FAILED');
      if (await hasSession()) {
        assertAccountSessionBoundary(boundary);
        try {
          const remoteEnabled = await updateNotificationStatus(true, boundary);
          if (remoteEnabled !== true) {
            throw new Error('NOTIFICATION_PREFERENCE_NOT_CONFIRMED');
          }
        } catch (error) {
          // Do not leave a switch that looks enabled while the backend still
          // excludes this account from every push campaign. An unknown write
          // outcome is explicitly rolled back on both sides and can be retried.
          assertAccountSessionBoundary(boundary);
          await Promise.allSettled([
            setSmartRemindersEnabled(false, boundary),
            updateNotificationStatus(false, boundary),
          ]);
          assertAccountSessionBoundary(boundary);
          throw error;
        }
        assertAccountSessionBoundary(boundary);
        const registered = await registerPushDeviceIfEligible({
          requestPermission: false,
          ownerBoundary: boundary,
        }).catch(() => false);
        assertAccountSessionBoundary(boundary);
        if (!registered) {
          await Promise.allSettled([
            setSmartRemindersEnabled(false, boundary),
            updateNotificationStatus(false, boundary),
          ]);
          assertAccountSessionBoundary(boundary);
          throw new Error('PUSH_DEVICE_REGISTRATION_FAILED');
        }
      }
      assertAccountSessionBoundary(boundary);
      await scheduleNextLearningReminder({courseId, courseTitle}, boundary);
      assertAccountSessionBoundary(boundary);
      return true;
    })();
    owner.enableFlight = flight;
    void flight.then(
      () => {
        if (owner.enableFlight === flight) owner.enableFlight = null;
      },
      () => {
        if (owner.enableFlight === flight) owner.enableFlight = null;
      },
    );
    return flight;
  }, [courseId, courseTitle, owner, presentation]);

  return {
    closeReminderNudge,
    enableRemindersFromNudge,
    maybeOfferReminders,
    reminderNudgeVisible:
      presentation?.owner === owner && !owner.retired && active && !blocked,
  };
};
