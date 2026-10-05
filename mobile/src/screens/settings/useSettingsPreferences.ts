import {useCallback, useEffect, useRef, useState} from 'react';
import {useFocusEffect} from '@react-navigation/native';
import {Alert} from 'react-native';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  extractApiToken,
  getItem,
  removeItem,
  saveItem,
  sessionIdentityKey,
  type AccountSessionBoundary,
} from '../../constants/helpers';
import {
  cancelLearningReminders,
  enableSmartReminders,
  getSmartReminderHour,
  getSmartRemindersEnabled,
  REMINDER_ENABLED_KEY,
  setSmartReminderHour,
  setSmartRemindersEnabled,
} from '../../services/smartReminders';
import {
  clearWatchHistory,
  getProfile,
  updateNotificationStatus,
} from '../../services/roknApi';
import {
  clearLocalWatchHistory,
  WATCH_HISTORY_ENABLED_KEY,
} from '../../components/VideoPlayer/courseLearningApi';
import {
  registerPushDeviceIfEligible,
  unregisterPushDevice,
} from '../../services/pushDeviceRegistration';
import type {SettingsChoice} from '../../components/settings/SettingsChoiceModal';
import {
  MARKETING_NOTIFICATIONS_KEY,
  readPendingPrivacyPreferences,
  usePrivacyPreferenceSync,
} from './usePrivacyPreferenceSync';
import {PENDING_WATCH_HISTORY_CLEAR_KEY} from './settingsData';
import {
  playbackPreferenceReadIsCurrent,
  playbackPreferenceVersions,
  privacyPreferenceReadIsCurrent,
  privacyPreferenceVersions,
  withAccountPreferenceWrite as withSettingsScopeWrite,
} from '../../services/accountPreferenceWrites';
import {
  flushPlaybackPreferenceWrites,
  readPendingPlaybackPreferences,
  savePlaybackPreferencePatch,
} from '../../services/playbackPreferenceSync';
import {createKeyedAsyncQueue} from '../../utils/keyedAsyncQueue';

// Keep each toggle's local/server transaction ordered across screen remounts.
// Network work belongs here, never in the shared native preference writer.
const toggleUpdates = createKeyedAsyncQueue();

const normalizeStoredQuality = (value: unknown) => {
  const candidate = typeof value === 'string' ? value : '';
  return ['auto', 'data_saver', '1080p', '720p', '480p', '360p'].includes(
    candidate,
  )
    ? candidate
    : 'auto';
};

type SettingsFocusOwner = {accountIdentity: string};
type SettingsChoicePresentation = {
  choice: Exclude<SettingsChoice, null>;
  focusOwner: SettingsFocusOwner;
  account: Promise<
    {boundary: AccountSessionBoundary} | {error: unknown}
  >;
};

export const useSettingsPreferences = ({
  hasAuthenticatedAccount,
  userData,
}: {
  hasAuthenticatedAccount: boolean;
  userData: unknown;
}) => {
  const [choicePresentation, setChoicePresentation] =
    useState<SettingsChoicePresentation | null>(null);
  const choiceOwnerRef = useRef<SettingsChoicePresentation | null>(null);
  const choiceModal = choicePresentation?.choice ?? null;
  const [notificationPrimer, setNotificationPrimer] = useState(false);
  const [quality, setQuality] = useState('auto');
  const [notifications, setNotifications] = useState(false);
  const [marketingNotifications, setMarketingNotifications] = useState(false);
  const [watchHistory, setWatchHistory] = useState(true);
  const [reminderHour, setReminderHour] = useState(20);
  const {dirtyKeys: privacyDirtyKeys, queue: queuePrivacyPreferenceSync} =
    usePrivacyPreferenceSync();
  const preferenceRevisionRef = useRef<Record<string, number>>({});
  // Rollback returns to a saved value, never an earlier optimistic tap.
  const savedPreferencesRef = useRef({
    quality: 'auto',
    reminderHour: 20,
    toggles: {
      [REMINDER_ENABLED_KEY]: false,
      [WATCH_HISTORY_ENABLED_KEY]: true,
      [MARKETING_NOTIFICATIONS_KEY]: false,
    } as Record<string, boolean>,
  });
  const accountIdentity = sessionIdentityKey(userData);
  const confirmationOwnerRef = useRef<SettingsFocusOwner | null>(null);
  useFocusEffect(
    useCallback(() => {
      const owner = {accountIdentity};
      confirmationOwnerRef.current = owner;
      return () => {
        if (confirmationOwnerRef.current === owner)
          confirmationOwnerRef.current = null;
        if (choiceOwnerRef.current?.focusOwner === owner) {
          choiceOwnerRef.current = null;
          setChoicePresentation(null);
        }
      };
    }, [accountIdentity]),
  );

  const markPreferenceMutation = (key: string) => {
    const revision = (preferenceRevisionRef.current[key] || 0) + 1;
    preferenceRevisionRef.current[key] = revision;
    return revision;
  };

  const enqueuePreferenceWrite = <T>(
    write: (
      boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
    ) => Promise<T>,
    boundary: AccountSessionBoundary,
  ) => {
    // The opened choice already owns its account. Do not recapture whichever
    // session happens to exist when its native row callback arrives.
    return withSettingsScopeWrite(boundary, async () => {
      assertAccountSessionBoundary(boundary);
      try {
        return await write(boundary);
      } finally {
        assertAccountSessionBoundary(boundary);
      }
    });
  };

  useEffect(() => {
    preferenceRevisionRef.current = {};
    savedPreferencesRef.current = {
      quality: 'auto',
      reminderHour: 20,
      toggles: {
        [REMINDER_ENABLED_KEY]: false,
        [WATCH_HISTORY_ENABLED_KEY]: true,
        [MARKETING_NOTIFICATIONS_KEY]: false,
      },
    };
    privacyDirtyKeys.clear();
    choiceOwnerRef.current = null;
    setChoicePresentation(null);
    setNotificationPrimer(false);
    setQuality('auto');
    setNotifications(false);
    setMarketingNotifications(false);
    setWatchHistory(true);
    setReminderHour(20);
  }, [accountIdentity, privacyDirtyKeys]);

  useEffect(() => {
    let active = true;
    void (async () => {
      const initialRevisions = {
        [REMINDER_ENABLED_KEY]:
          preferenceRevisionRef.current[REMINDER_ENABLED_KEY] || 0,
        VIDEO_QUALITY: preferenceRevisionRef.current.VIDEO_QUALITY || 0,
        REMINDER_HOUR: preferenceRevisionRef.current.REMINDER_HOUR || 0,
        [WATCH_HISTORY_ENABLED_KEY]:
          preferenceRevisionRef.current[WATCH_HISTORY_ENABLED_KEY] || 0,
        [MARKETING_NOTIFICATIONS_KEY]:
          preferenceRevisionRef.current[MARKETING_NOTIFICATIONS_KEY] || 0,
      };
      const isUnchanged = (key: keyof typeof initialRevisions) =>
        (preferenceRevisionRef.current[key] || 0) === initialRevisions[key];
      const boundary = await captureAccountSessionBoundary();
      const playbackVersions = playbackPreferenceVersions(boundary);
      let privacyVersions = privacyPreferenceVersions(boundary);
      const privacyIsUnchanged = (field: 'watchHistory' | 'marketing' | 'reminder') =>
        privacyPreferenceReadIsCurrent(boundary, privacyVersions, field);
      const scopedKey = (key: string) => accountScopedStorageKey(key, boundary);
      const [
        savedNotifications,
        savedQuality,
        savedReminderHour,
        savedWatchHistory,
        savedMarketingNotifications,
        pendingPlayback,
      ] = await Promise.all([
        getSmartRemindersEnabled(boundary),
        scopedKey('VIDEO_QUALITY').then(getItem),
        getSmartReminderHour(boundary),
        scopedKey(WATCH_HISTORY_ENABLED_KEY).then(getItem),
        scopedKey(MARKETING_NOTIFICATIONS_KEY).then(getItem),
        readPendingPlaybackPreferences(boundary),
      ]);
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      if (
        typeof savedNotifications === 'boolean' &&
        isUnchanged(REMINDER_ENABLED_KEY)
      ) {
        setNotifications(savedNotifications);
        savedPreferencesRef.current.toggles[REMINDER_ENABLED_KEY] =
          savedNotifications;
      }
      if (
        isUnchanged('VIDEO_QUALITY') &&
        playbackPreferenceReadIsCurrent(boundary, playbackVersions, 'quality')
      ) {
        const localQuality = normalizeStoredQuality(
          pendingPlayback.videoQualityPreference ?? savedQuality,
        );
        setQuality(localQuality);
        savedPreferencesRef.current.quality = localQuality;
      }
      if (
        typeof savedWatchHistory === 'boolean' &&
        privacyIsUnchanged('watchHistory') &&
        isUnchanged(WATCH_HISTORY_ENABLED_KEY)
      ) {
        setWatchHistory(savedWatchHistory);
        savedPreferencesRef.current.toggles[WATCH_HISTORY_ENABLED_KEY] =
          savedWatchHistory;
      }
      if (
        typeof savedMarketingNotifications === 'boolean' &&
        privacyIsUnchanged('marketing') &&
        isUnchanged(MARKETING_NOTIFICATIONS_KEY)
      ) {
        setMarketingNotifications(savedMarketingNotifications);
        savedPreferencesRef.current.toggles[MARKETING_NOTIFICATIONS_KEY] =
          savedMarketingNotifications;
      }
      if (
        [10, 15, 20].includes(Number(savedReminderHour)) &&
        privacyIsUnchanged('reminder') &&
        isUnchanged('REMINDER_HOUR')
      ) {
        setReminderHour(Number(savedReminderHour));
        savedPreferencesRef.current.reminderHour = Number(savedReminderHour);
      }
      if (hasAuthenticatedAccount) {
        const pending = await withSettingsScopeWrite(boundary, async () => {
          // Read inside the native writer so an older hydration cannot replay
          // its previously read patch over a newer choice from another visit.
          privacyVersions = privacyPreferenceVersions(boundary);
          const [latest, cachedWatchHistory, cachedMarketing, cachedReminder] = await Promise.all([
            readPendingPrivacyPreferences(undefined, boundary),
            scopedKey(WATCH_HISTORY_ENABLED_KEY).then(getItem),
            scopedKey(MARKETING_NOTIFICATIONS_KEY).then(getItem),
            getSmartReminderHour(boundary),
          ]);
          assertAccountSessionBoundary(boundary);
          if (!active) return latest;
          const localWatchHistory = latest.watchHistoryEnabled ?? cachedWatchHistory;
          const localMarketing = latest.marketingNotificationsEnabled ?? cachedMarketing;
          const localReminder = latest.learningReminderHour ?? cachedReminder;
          if (
            typeof localWatchHistory === 'boolean' &&
            isUnchanged(WATCH_HISTORY_ENABLED_KEY)
          ) {
            if (latest.watchHistoryEnabled !== undefined)
              privacyDirtyKeys.add(WATCH_HISTORY_ENABLED_KEY);
            setWatchHistory(localWatchHistory);
            savedPreferencesRef.current.toggles[WATCH_HISTORY_ENABLED_KEY] =
              localWatchHistory;
          }
          if (
            typeof localMarketing === 'boolean' &&
            isUnchanged(MARKETING_NOTIFICATIONS_KEY)
          ) {
            if (latest.marketingNotificationsEnabled !== undefined)
              privacyDirtyKeys.add(MARKETING_NOTIFICATIONS_KEY);
            setMarketingNotifications(localMarketing);
            savedPreferencesRef.current.toggles[MARKETING_NOTIFICATIONS_KEY] =
              localMarketing;
          }
          if (
            [10, 15, 20].includes(Number(localReminder)) &&
            isUnchanged('REMINDER_HOUR')
          ) {
            setReminderHour(Number(localReminder));
            savedPreferencesRef.current.reminderHour = Number(localReminder);
          }
          return latest;
        });
        if (!active) return;
        if (Object.keys(pending).length) {
          assertAccountSessionBoundary(boundary);
          await queuePrivacyPreferenceSync({}, boundary);
          assertAccountSessionBoundary(boundary);
          if (!active) return;
        }
        try {
          const pendingPlaybackAtRead = await readPendingPlaybackPreferences(
            boundary,
          );
          const pendingPrivacyAtRead = await readPendingPrivacyPreferences(undefined, boundary);
          void flushPlaybackPreferenceWrites(boundary).catch(() => undefined);
          const remoteProfile = await getProfile(boundary);
          assertAccountSessionBoundary(boundary);
          if (!active) return;
          const profileQuality = normalizeStoredQuality(
            savedPreferencesRef.current.quality === 'data_saver' &&
              remoteProfile.videoQualityPreference === '360p'
              ? 'data_saver'
              : remoteProfile.videoQualityPreference,
          );
          await withSettingsScopeWrite(boundary, async () => {
            const pendingReminder = await readPendingPrivacyPreferences(undefined, boundary);
            assertAccountSessionBoundary(boundary);
            if (
              active && isUnchanged('REMINDER_HOUR') &&
              privacyIsUnchanged('reminder') &&
              pendingPrivacyAtRead.learningReminderHour === undefined &&
              pendingReminder.learningReminderHour === undefined &&
              [10, 15, 20].includes(Number(remoteProfile.learningReminderHour))
            ) {
              const hour = Number(remoteProfile.learningReminderHour);
              setReminderHour(hour);
              savedPreferencesRef.current.reminderHour = hour;
              await setSmartReminderHour(hour, boundary);
              assertAccountSessionBoundary(boundary);
            }
            const pendingPlaybackNow = await readPendingPlaybackPreferences(
              boundary,
            );
            assertAccountSessionBoundary(boundary);
            if (!active) return;
            if (
              !privacyDirtyKeys.has(WATCH_HISTORY_ENABLED_KEY) &&
              privacyIsUnchanged('watchHistory') &&
              pendingPrivacyAtRead.watchHistoryEnabled === undefined &&
              pendingReminder.watchHistoryEnabled === undefined &&
              isUnchanged(WATCH_HISTORY_ENABLED_KEY)
            ) {
              setWatchHistory(remoteProfile.watchHistoryEnabled);
              savedPreferencesRef.current.toggles[WATCH_HISTORY_ENABLED_KEY] =
                remoteProfile.watchHistoryEnabled;
              await saveItem(
                await scopedKey(WATCH_HISTORY_ENABLED_KEY),
                remoteProfile.watchHistoryEnabled,
              );
              assertAccountSessionBoundary(boundary);
            }
            if (
              !privacyDirtyKeys.has(MARKETING_NOTIFICATIONS_KEY) &&
              privacyIsUnchanged('marketing') &&
              pendingPrivacyAtRead.marketingNotificationsEnabled === undefined &&
              pendingReminder.marketingNotificationsEnabled === undefined &&
              isUnchanged(MARKETING_NOTIFICATIONS_KEY)
            ) {
              setMarketingNotifications(
                remoteProfile.marketingNotificationsEnabled,
              );
              savedPreferencesRef.current.toggles[MARKETING_NOTIFICATIONS_KEY] =
                remoteProfile.marketingNotificationsEnabled;
              await saveItem(
                await scopedKey(MARKETING_NOTIFICATIONS_KEY),
                remoteProfile.marketingNotificationsEnabled,
              );
              assertAccountSessionBoundary(boundary);
            }
            if (
              isUnchanged('VIDEO_QUALITY') &&
              pendingPlaybackAtRead.videoQualityPreference === undefined &&
              pendingPlaybackNow.videoQualityPreference === undefined &&
              playbackPreferenceReadIsCurrent(
                boundary,
                playbackVersions,
                'quality',
              )
            ) {
              setQuality(profileQuality);
              savedPreferencesRef.current.quality = profileQuality;
              await scopedKey('VIDEO_QUALITY').then(key =>
                saveItem(key, profileQuality),
              );
            }
            if (
              pendingPlaybackAtRead.playbackSpeed === undefined &&
              pendingPlaybackNow.playbackSpeed === undefined &&
              playbackPreferenceReadIsCurrent(
                boundary,
                playbackVersions,
                'speed',
              )
            ) {
              await scopedKey('VIDEO_PLAYBACK_SPEED').then(key =>
                saveItem(key, remoteProfile.playbackSpeed),
              );
            }
            assertAccountSessionBoundary(boundary);
          });
        } catch {
          // Settings remain readable without replacing server values.
        }
      }
    })().catch(() => undefined);
    return () => {
      active = false;
    };
  }, [
    accountIdentity,
    hasAuthenticatedAccount,
    privacyDirtyKeys,
    queuePrivacyPreferenceSync,
  ]);

  useEffect(() => {
    if (!hasAuthenticatedAccount) return;
    void captureAccountSessionBoundary()
      .then(async boundary => {
        const key = await accountScopedStorageKey(
          PENDING_WATCH_HISTORY_CLEAR_KEY,
          boundary,
        );
        if (!(await getItem(key))) return;
        assertAccountSessionBoundary(boundary);
        await clearWatchHistory(boundary);
        assertAccountSessionBoundary(boundary);
        await removeItem(key);
        assertAccountSessionBoundary(boundary);
      })
      .catch(() => undefined);
  }, [accountIdentity, hasAuthenticatedAccount]);

  const updatePreference = (
    key: string,
    value: boolean,
    failureOwner: 'alert' | 'primer' = 'alert',
    ownerBoundary?: AccountSessionBoundary,
    admittedRevision?: number,
  ) => {
    if (
      admittedRevision !== undefined &&
      preferenceRevisionRef.current[key] !== admittedRevision
    ) {
      return Promise.reject(new Error('NOTIFICATION_PREFERENCE_SUPERSEDED'));
    }
    // Saving belongs to the account queue; its failure notice belongs only to
    // the latest tap in this focused visit. A retained screen may finish saving
    // while hidden without interrupting the learner on their next screen.
    const noticeOwner = confirmationOwnerRef.current;
    const revision = admittedRevision ?? markPreferenceMutation(key);
    if (
      key === WATCH_HISTORY_ENABLED_KEY ||
      key === MARKETING_NOTIFICATIONS_KEY
    ) {
      privacyDirtyKeys.add(key);
    }
    if (key === REMINDER_ENABLED_KEY) setNotifications(value);
    if (key === WATCH_HISTORY_ENABLED_KEY) setWatchHistory(value);
    if (key === MARKETING_NOTIFICATIONS_KEY) {
      setMarketingNotifications(value);
    }

    const boundaryFlight = ownerBoundary
      ? Promise.resolve(ownerBoundary)
      : captureAccountSessionBoundary();
    return boundaryFlight
      .then(boundary =>
        toggleUpdates(`${boundary.scope}:${key}`, async () => {
          assertAccountSessionBoundary(boundary);
          const previousValue = await withSettingsScopeWrite(
            boundary,
            async () => {
              const savedValue = savedPreferencesRef.current.toggles[key];
              if (key === REMINDER_ENABLED_KEY) {
                const stored = await setSmartRemindersEnabled(value, boundary);
                if (!stored) throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
              } else if (
                key === WATCH_HISTORY_ENABLED_KEY ||
                key === MARKETING_NOTIFICATIONS_KEY
              ) {
                const stored = await saveItem(
                  await accountScopedStorageKey(key, boundary),
                  value,
                );
                assertAccountSessionBoundary(boundary);
                if (!stored) {
                  throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
                }
              } else {
                const stored = await saveItem(key, value);
                if (!stored) throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
              }
              assertAccountSessionBoundary(boundary);
              return savedValue;
            },
          );
          if (key === REMINDER_ENABLED_KEY && extractApiToken(userData)) {
            try {
              const remoteValue = await updateNotificationStatus(
                value,
                boundary,
              );
              if (remoteValue !== value) {
                throw new Error('SETTINGS_REMOTE_WRITE_FAILED');
              }
            } catch (error) {
              // Enabling without the matching server preference leaves a switch
              // that looks active but can never receive a remote notification.
              // Keep disabling available offline: unregistering the device below
              // is sufficient to stop delivery and the server can catch up later.
              if (value) {
                await withSettingsScopeWrite(boundary, () =>
                  setSmartRemindersEnabled(previousValue, boundary),
                ).catch(() => undefined);
                throw error instanceof Error
                  ? error
                  : new Error('SETTINGS_REMOTE_WRITE_FAILED');
              }
            }
            assertAccountSessionBoundary(boundary);
          }
          if (hasAuthenticatedAccount && key === WATCH_HISTORY_ENABLED_KEY) {
            await queuePrivacyPreferenceSync(
              {watchHistoryEnabled: value},
              boundary,
            );
            assertAccountSessionBoundary(boundary);
          }
          if (hasAuthenticatedAccount && key === MARKETING_NOTIFICATIONS_KEY) {
            await queuePrivacyPreferenceSync(
              {marketingNotificationsEnabled: value},
              boundary,
            );
            assertAccountSessionBoundary(boundary);
          }
          assertAccountSessionBoundary(boundary);
          savedPreferencesRef.current.toggles[key] = value;
        }),
      )
      .then(() => true)
      .catch(error => {
        if (
          error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        ) {
          if (failureOwner === 'primer') throw error;
          return false;
        }
        if (preferenceRevisionRef.current[key] === revision) {
          const previousValue = savedPreferencesRef.current.toggles[key];
          if (key === REMINDER_ENABLED_KEY) setNotifications(previousValue);
          if (key === WATCH_HISTORY_ENABLED_KEY) {
            setWatchHistory(previousValue);
          }
          if (key === MARKETING_NOTIFICATIONS_KEY) {
            setMarketingNotifications(previousValue);
          }
          if (
            key === WATCH_HISTORY_ENABLED_KEY ||
            key === MARKETING_NOTIFICATIONS_KEY
          ) {
            privacyDirtyKeys.delete(key);
          }
        }
        // The primer owns its retry presentation. Do not convert a storage or
        // server failure into an OS denial, or stack a native alert over it.
        if (failureOwner === 'primer') throw error;
        if (
          noticeOwner &&
          confirmationOwnerRef.current === noticeOwner &&
          preferenceRevisionRef.current[key] === revision
        ) {
          Alert.alert(
            'لم يُحفظ التغيير',
            error instanceof Error &&
              error.message === 'SETTINGS_STORAGE_WRITE_FAILED'
              ? 'تعذّر حفظ الإعداد على الجهاز\nحاول مرة أخرى'
              : 'تعذّر إكمال التغيير الآن\nحاول مرة أخرى',
          );
        }
        return false;
      });
  };

  const updateNotifications = async (value: boolean) => {
    if (value) {
      setNotificationPrimer(true);
    } else {
      const noticeOwner = confirmationOwnerRef.current;
      const revision = markPreferenceMutation(REMINDER_ENABLED_KEY);
      try {
        const boundary = await captureAccountSessionBoundary();
        assertAccountSessionBoundary(boundary);
        const saving = updatePreference(
          REMINDER_ENABLED_KEY,
          false,
          'alert',
          boundary,
          revision,
        );
        const saved = await saving;
        assertAccountSessionBoundary(boundary);
        if (!saved) return;
        if (preferenceRevisionRef.current[REMINDER_ENABLED_KEY] !== revision)
          return;
        cancelLearningReminders();
        await unregisterPushDevice(boundary).catch(() => undefined);
      } catch (error) {
        if (
          error instanceof Error &&
          (error.message === 'ACCOUNT_CHANGED_DURING_REQUEST' ||
            error.message === 'NOTIFICATION_PREFERENCE_SUPERSEDED')
        )
          return;
        if (
          noticeOwner &&
          confirmationOwnerRef.current === noticeOwner &&
          preferenceRevisionRef.current[REMINDER_ENABLED_KEY] === revision
        ) {
          Alert.alert('لم يُحفظ التغيير', 'تعذّر إكمال التغيير الآن\nحاول مرة أخرى');
        }
      }
    }
  };

  const confirmNotifications = async () => {
    const owner = confirmationOwnerRef.current;
    if (!owner) throw new Error('NOTIFICATION_PRESENTATION_RETIRED');
    const revision = markPreferenceMutation(REMINDER_ENABLED_KEY);
    const boundary = await captureAccountSessionBoundary();
    const assertPreparation = () => {
      assertAccountSessionBoundary(boundary);
      if (confirmationOwnerRef.current !== owner)
        throw new Error('NOTIFICATION_PRESENTATION_RETIRED');
      if (preferenceRevisionRef.current[REMINDER_ENABLED_KEY] !== revision)
        throw new Error('NOTIFICATION_PREFERENCE_SUPERSEDED');
    };
    assertPreparation();
    const granted = await enableSmartReminders(boundary, assertPreparation);
    assertAccountSessionBoundary(boundary);
    if (preferenceRevisionRef.current[REMINDER_ENABLED_KEY] !== revision)
      throw new Error('NOTIFICATION_PREFERENCE_SUPERSEDED');
    if (!granted) return false;
    const saving = updatePreference(
      REMINDER_ENABLED_KEY,
      true,
      'primer',
      boundary,
      revision,
    );
    await saving;
    assertAccountSessionBoundary(boundary);
    if (preferenceRevisionRef.current[REMINDER_ENABLED_KEY] !== revision)
      throw new Error('NOTIFICATION_PREFERENCE_SUPERSEDED');
    // Guests own local learning reminders only. A backend push token belongs
    // to an authenticated inbox, so requiring one here made a valid guest
    // opt-in flip back to off after the OS had already granted permission.
    if (!hasAuthenticatedAccount) return true;
    const registered = await registerPushDeviceIfEligible({
      requestPermission: false,
      ownerBoundary: boundary,
    }).catch(() => false);
    assertAccountSessionBoundary(boundary);
    if (!registered) {
      if (preferenceRevisionRef.current[REMINDER_ENABLED_KEY] === revision) {
        await updatePreference(
          REMINDER_ENABLED_KEY,
          false,
          'primer',
          boundary,
        ).catch(() => undefined);
      }
      assertAccountSessionBoundary(boundary);
      throw new Error('PUSH_DEVICE_REGISTRATION_FAILED');
    }
    return true;
  };

  const openChoice = (choice: Exclude<SettingsChoice, null>) => {
    const focusOwner = confirmationOwnerRef.current;
    if (!focusOwner || focusOwner.accountIdentity !== accountIdentity) return;
    const presentation: SettingsChoicePresentation = {
      choice,
      focusOwner,
      // Settle capture failure immediately even if the learner only closes the
      // modal. A later selection must use this owner, not acquire a new one.
      account: captureAccountSessionBoundary().then(
        boundary => ({boundary}),
        (error: unknown) => ({error}),
      ),
    };
    choiceOwnerRef.current = presentation;
    setChoicePresentation(presentation);
  };

  const closeChoiceModal = () => {
    if (!choicePresentation || choiceOwnerRef.current !== choicePresentation)
      return;
    choiceOwnerRef.current = null;
    setChoicePresentation(null);
  };

  const selectChoice = (key: string) => {
    const presentation = choicePresentation;
    if (
      !presentation ||
      choiceOwnerRef.current !== presentation ||
      confirmationOwnerRef.current !== presentation.focusOwner ||
      presentation.focusOwner.accountIdentity !== accountIdentity
    )
      return;
    const reminder = presentation.choice === 'reminderTime';
    const validChoice = reminder
      ? ['10', '15', '20'].includes(key)
      : normalizeStoredQuality(key) === key;
    if (!validChoice) return;
    // Consume the exact native presentation synchronously. A repeated row tap,
    // its delayed close or a row retained across reopening cannot own a write.
    choiceOwnerRef.current = null;
    setChoicePresentation(null);
    const preferenceKey = reminder ? 'REMINDER_HOUR' : 'VIDEO_QUALITY';
    const revision = markPreferenceMutation(preferenceKey);
    let mutationBoundary: AccountSessionBoundary | null = null;
    void presentation.account
      .then(async captured => {
        if ('error' in captured) throw captured.error;
        const {boundary} = captured;
        mutationBoundary = boundary;
        assertAccountSessionBoundary(boundary);
        // A newer selection may win while opening-account preparation waits.
        // Once dispatched, the existing account writer owns durable settlement.
        if (preferenceRevisionRef.current[preferenceKey] !== revision) return;
        if (reminder) {
          const hour = Number(key);
          setReminderHour(hour);
          if (hasAuthenticatedAccount) {
            // Authenticated reminders belong to the server. Persist the time
            // with the existing account journal, including offline recovery.
            await queuePrivacyPreferenceSync({
              learningReminderHour: hour,
              learningReminderTimezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'Africa/Cairo',
            }, boundary);
            assertAccountSessionBoundary(boundary);
            if (preferenceRevisionRef.current[preferenceKey] === revision)
              savedPreferencesRef.current.reminderHour = hour;
          } else {
            await enqueuePreferenceWrite(async ownerBoundary => {
              const stored = await setSmartReminderHour(hour, ownerBoundary);
              if (!stored) throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
              assertAccountSessionBoundary(ownerBoundary);
              savedPreferencesRef.current.reminderHour = hour;
            }, boundary);
          }
        } else {
          setQuality(key);
          await savePlaybackPreferencePatch(
            {videoQualityPreference: key},
            boundary,
          );
          assertAccountSessionBoundary(boundary);
          savedPreferencesRef.current.quality = key;
        }
      })
      .catch(async error => {
        if (
          error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        )
          return;
        if (mutationBoundary) {
          try {
            assertAccountSessionBoundary(mutationBoundary);
          } catch {
            return;
          }
        }
        // A failed newer command may follow an accepted command whose hook
        // completion is still reading storage. Roll back to durable truth,
        // never to a stale completion's optimistic baseline.
        if (reminder && hasAuthenticatedAccount && mutationBoundary &&
          preferenceRevisionRef.current[preferenceKey] === revision) {
          try {
            const savedHour = await withSettingsScopeWrite(mutationBoundary, async () => {
              const pending = await readPendingPrivacyPreferences(undefined, mutationBoundary!);
              return pending.learningReminderHour ?? await getSmartReminderHour(mutationBoundary!);
            });
            if (preferenceRevisionRef.current[preferenceKey] === revision)
              savedPreferencesRef.current.reminderHour = savedHour;
          } catch {
            // Existing saved baseline remains available if native storage fails.
          }
          try {assertAccountSessionBoundary(mutationBoundary);} catch {return;}
        }
        const latest = preferenceRevisionRef.current[preferenceKey] === revision;
        const storageFailure =
          error instanceof Error &&
          error.message === 'SETTINGS_STORAGE_WRITE_FAILED';
        if (mutationBoundary && latest && (reminder || storageFailure)) {
          if (reminder) setReminderHour(savedPreferencesRef.current.reminderHour);
          else setQuality(savedPreferencesRef.current.quality);
        }
        if (
          latest &&
          (!mutationBoundary || reminder || storageFailure) &&
          confirmationOwnerRef.current === presentation.focusOwner
        ) {
          Alert.alert(
            'لم يُحفظ التغيير',
            reminder
              ? 'تعذّر حفظ وقت التذكير\nحاول مرة أخرى'
              : 'تعذّر حفظ جودة الفيديو\nحاول مرة أخرى',
          );
        }
      });
  };

  const confirmClearWatchHistory = () => {
    const owner = confirmationOwnerRef.current;
    if (!owner) return;
    // Capture at display time. Confirmation must never acquire a replacement
    // account merely because its native dialog outlived the original screen.
    const boundaryFlight = captureAccountSessionBoundary().then(
      boundary => ({boundary}),
      (error: unknown) => ({error}),
    );
    let confirmed = false;
    Alert.alert(
      'مسح سجل المشاهدة',
      'سنمسح آخر ما شاهدته فقط\nويبقى تقدمك وشهاداتك محفوظة',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'مسح السجل',
          style: 'destructive',
          onPress: async () => {
            if (confirmed || confirmationOwnerRef.current !== owner) return;
            confirmed = true;
            try {
              const captured = await boundaryFlight;
              if (confirmationOwnerRef.current !== owner) return;
              if ('error' in captured) throw captured.error;
              const boundary = captured.boundary;
              assertAccountSessionBoundary(boundary);
              await clearLocalWatchHistory(boundary);
              assertAccountSessionBoundary(boundary);
              let serverSynced = true;
              if (extractApiToken(userData)) {
                const pendingKey = await accountScopedStorageKey(
                  PENDING_WATCH_HISTORY_CLEAR_KEY,
                  boundary,
                );
                try {
                  await clearWatchHistory(boundary);
                  assertAccountSessionBoundary(boundary);
                  await removeItem(pendingKey);
                  assertAccountSessionBoundary(boundary);
                } catch {
                  assertAccountSessionBoundary(boundary);
                  serverSynced = false;
                  const queued = await saveItem(pendingKey, true);
                  assertAccountSessionBoundary(boundary);
                  if (!queued) {
                    Alert.alert(
                      'لم يكتمل المسح',
                      'تعذّر حفظ طلب المسح على الجهاز\nحاول مرة أخرى عند عودة الاتصال',
                    );
                    return;
                  }
                }
              }
              if (confirmationOwnerRef.current !== owner) return;
              Alert.alert(
                'تم مسح السجل',
                serverSynced
                  ? 'بقي تقدمك في الكورسات محفوظًا'
                  : 'مسحناه من هذا الجهاز\nوسيكتمل من حسابك عند عودة الاتصال',
              );
            } catch (error) {
              if (
                confirmationOwnerRef.current === owner &&
                !(
                  error instanceof Error &&
                  error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
                )
              ) {
                Alert.alert('لم يكتمل المسح', 'حاول مرة أخرى');
              }
            }
          },
        },
      ],
    );
  };

  return {
    choiceModal,
    closeChoiceModal,
    closeNotificationPrimer: () => setNotificationPrimer(false),
    confirmClearWatchHistory,
    confirmNotifications,
    marketingNotifications,
    notificationPrimer,
    notifications,
    openQualityChoice: () => openChoice('quality'),
    openReminderChoice: () => openChoice('reminderTime'),
    quality,
    reminderHour,
    selectChoice,
    toggleMarketing: (value: boolean) =>
      updatePreference(MARKETING_NOTIFICATIONS_KEY, value),
    toggleNotifications: updateNotifications,
    toggleWatchHistory: (value: boolean) =>
      updatePreference(WATCH_HISTORY_ENABLED_KEY, value),
    watchHistory,
  };
};
