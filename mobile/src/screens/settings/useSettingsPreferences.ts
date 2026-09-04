import {useEffect, useRef, useState} from 'react';
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
  updatePlaybackPreferences,
} from '../../services/roknApi';
import {
  clearLocalWatchHistory,
  WATCH_HISTORY_ENABLED_KEY,
} from '../../components/VideoPlayer/courseLearningApi';
import {
  registerPushDeviceIfEligible,
  unregisterPushDevice,
} from '../../services/pushNotifications';
import type {SettingsChoice} from '../../components/settings/SettingsChoiceModal';
import {
  MARKETING_NOTIFICATIONS_KEY,
  readPendingPrivacyPreferences,
  usePrivacyPreferenceSync,
} from './usePrivacyPreferenceSync';
import {PENDING_WATCH_HISTORY_CLEAR_KEY} from './settingsData';

const settingsScopeWriteTails = new Map<string, Promise<unknown>>();

const withSettingsScopeWrite = <T>(
  boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
  write: () => Promise<T>,
) => {
  const previous =
    settingsScopeWriteTails.get(boundary.scope) ?? Promise.resolve();
  const result = previous.then(write, write);
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  settingsScopeWriteTails.set(boundary.scope, tail);
  void tail.finally(() => {
    if (settingsScopeWriteTails.get(boundary.scope) === tail) {
      settingsScopeWriteTails.delete(boundary.scope);
    }
  });
  return result;
};

const normalizeStoredQuality = (value: unknown) => {
  const candidate = typeof value === 'string' ? value : '';
  return ['auto', 'data_saver', '1080p', '720p', '480p', '360p'].includes(
    candidate,
  )
    ? candidate
    : 'auto';
};

export const useSettingsPreferences = ({
  hasAuthenticatedAccount,
  userData,
}: {
  hasAuthenticatedAccount: boolean;
  userData: unknown;
}) => {
  const [choiceModal, setChoiceModal] = useState<SettingsChoice>(null);
  const [notificationPrimer, setNotificationPrimer] = useState(false);
  const [quality, setQuality] = useState('auto');
  const [notifications, setNotifications] = useState(false);
  const [marketingNotifications, setMarketingNotifications] = useState(false);
  const [watchHistory, setWatchHistory] = useState(true);
  const [reminderHour, setReminderHour] = useState(20);
  const {dirtyKeys: privacyDirtyKeys, queue: queuePrivacyPreferenceSync} =
    usePrivacyPreferenceSync();
  const preferenceRevisionRef = useRef<Record<string, number>>({});
  const accountIdentity = sessionIdentityKey(userData);

  const markPreferenceMutation = (key: string) => {
    const revision = (preferenceRevisionRef.current[key] || 0) + 1;
    preferenceRevisionRef.current[key] = revision;
    return revision;
  };

  const enqueuePreferenceWrite = <T>(
    write: (
      boundary: Awaited<ReturnType<typeof captureAccountSessionBoundary>>,
    ) => Promise<T>,
  ) => {
    // Capture the account at tap time, not when an older write finishes. This
    // keeps rapid changes ordered and prevents a queued write crossing logout.
    const boundaryFlight = captureAccountSessionBoundary();
    return boundaryFlight.then(boundary =>
      withSettingsScopeWrite(boundary, async () => {
        assertAccountSessionBoundary(boundary);
        const value = await write(boundary);
        assertAccountSessionBoundary(boundary);
        return value;
      }),
    );
  };

  useEffect(() => {
    preferenceRevisionRef.current = {};
    privacyDirtyKeys.clear();
    setChoiceModal(null);
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
      const scopedKey = (key: string) => accountScopedStorageKey(key, boundary);
      const [
        savedNotifications,
        savedQuality,
        savedReminderHour,
        savedWatchHistory,
        savedMarketingNotifications,
      ] = await Promise.all([
        getSmartRemindersEnabled(boundary),
        scopedKey('VIDEO_QUALITY').then(getItem),
        getSmartReminderHour(boundary),
        scopedKey(WATCH_HISTORY_ENABLED_KEY).then(getItem),
        scopedKey(MARKETING_NOTIFICATIONS_KEY).then(getItem),
      ]);
      assertAccountSessionBoundary(boundary);
      if (!active) return;
      if (
        typeof savedNotifications === 'boolean' &&
        isUnchanged(REMINDER_ENABLED_KEY)
      ) {
        setNotifications(savedNotifications);
      }
      if (isUnchanged('VIDEO_QUALITY')) {
        setQuality(normalizeStoredQuality(savedQuality));
      }
      if (
        typeof savedWatchHistory === 'boolean' &&
        isUnchanged(WATCH_HISTORY_ENABLED_KEY)
      ) {
        setWatchHistory(savedWatchHistory);
      }
      if (
        typeof savedMarketingNotifications === 'boolean' &&
        isUnchanged(MARKETING_NOTIFICATIONS_KEY)
      ) {
        setMarketingNotifications(savedMarketingNotifications);
      }
      if (
        [10, 15, 20].includes(Number(savedReminderHour)) &&
        isUnchanged('REMINDER_HOUR')
      ) {
        setReminderHour(Number(savedReminderHour));
      }
      if (hasAuthenticatedAccount) {
        const pending = await readPendingPrivacyPreferences(
          undefined,
          boundary,
        );
        if (!active) return;
        await withSettingsScopeWrite(boundary, async () => {
          assertAccountSessionBoundary(boundary);
          if (!active) return;
          if (
            typeof pending.watchHistoryEnabled === 'boolean' &&
            isUnchanged(WATCH_HISTORY_ENABLED_KEY)
          ) {
            privacyDirtyKeys.add(WATCH_HISTORY_ENABLED_KEY);
            setWatchHistory(pending.watchHistoryEnabled);
            await saveItem(
              await scopedKey(WATCH_HISTORY_ENABLED_KEY),
              pending.watchHistoryEnabled,
            );
            assertAccountSessionBoundary(boundary);
          }
          if (
            typeof pending.marketingNotificationsEnabled === 'boolean' &&
            isUnchanged(MARKETING_NOTIFICATIONS_KEY)
          ) {
            privacyDirtyKeys.add(MARKETING_NOTIFICATIONS_KEY);
            setMarketingNotifications(pending.marketingNotificationsEnabled);
            await saveItem(
              await scopedKey(MARKETING_NOTIFICATIONS_KEY),
              pending.marketingNotificationsEnabled,
            );
            assertAccountSessionBoundary(boundary);
          }
        });
        if (Object.keys(pending).length) {
          assertAccountSessionBoundary(boundary);
          await queuePrivacyPreferenceSync({}, boundary);
          assertAccountSessionBoundary(boundary);
          if (!active) return;
        }
        try {
          const remoteProfile = await getProfile(boundary);
          assertAccountSessionBoundary(boundary);
          if (!active) return;
          const profileQuality = normalizeStoredQuality(
            remoteProfile.videoQualityPreference,
          );
          await withSettingsScopeWrite(boundary, async () => {
            assertAccountSessionBoundary(boundary);
            if (!active) return;
            if (
              !privacyDirtyKeys.has(WATCH_HISTORY_ENABLED_KEY) &&
              isUnchanged(WATCH_HISTORY_ENABLED_KEY)
            ) {
              setWatchHistory(remoteProfile.watchHistoryEnabled);
              await saveItem(
                await scopedKey(WATCH_HISTORY_ENABLED_KEY),
                remoteProfile.watchHistoryEnabled,
              );
              assertAccountSessionBoundary(boundary);
            }
            if (
              !privacyDirtyKeys.has(MARKETING_NOTIFICATIONS_KEY) &&
              isUnchanged(MARKETING_NOTIFICATIONS_KEY)
            ) {
              setMarketingNotifications(
                remoteProfile.marketingNotificationsEnabled,
              );
              await saveItem(
                await scopedKey(MARKETING_NOTIFICATIONS_KEY),
                remoteProfile.marketingNotificationsEnabled,
              );
              assertAccountSessionBoundary(boundary);
            }
            if (isUnchanged('VIDEO_QUALITY')) {
              setQuality(profileQuality);
              await scopedKey('VIDEO_QUALITY').then(key =>
                saveItem(key, profileQuality),
              );
            }
            await scopedKey('VIDEO_PLAYBACK_SPEED').then(key =>
              saveItem(key, remoteProfile.playbackSpeed),
            );
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

  const updatePreference = (key: string, value: boolean) => {
    const revision = markPreferenceMutation(key);
    const previousValue =
      key === REMINDER_ENABLED_KEY
        ? notifications
        : key === WATCH_HISTORY_ENABLED_KEY
        ? watchHistory
        : marketingNotifications;
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

    return enqueuePreferenceWrite(async boundary => {
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
      if (key === REMINDER_ENABLED_KEY && extractApiToken(userData)) {
        try {
          const remoteValue = await updateNotificationStatus(value, boundary);
          if (remoteValue !== value) {
            throw new Error('SETTINGS_REMOTE_WRITE_FAILED');
          }
        } catch (error) {
          // Enabling without the matching server preference leaves a switch
          // that looks active but can never receive a remote notification.
          // Keep disabling available offline: unregistering the device below
          // is sufficient to stop delivery and the server can catch up later.
          if (value) {
            await setSmartRemindersEnabled(previousValue, boundary).catch(
              () => undefined,
            );
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
    })
      .then(() => true)
      .catch(error => {
        if (preferenceRevisionRef.current[key] === revision) {
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
        if (
          error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        ) {
          return false;
        }
        Alert.alert(
          'لم يُحفظ التغيير',
          error instanceof Error &&
            error.message === 'SETTINGS_STORAGE_WRITE_FAILED'
            ? 'تعذّر حفظ الإعداد على الجهاز\nحاول مرة أخرى'
            : 'تعذّر إكمال التغيير الآن\nحاول مرة أخرى',
        );
        return false;
      });
  };

  const updateNotifications = async (value: boolean) => {
    if (value) {
      setNotificationPrimer(true);
    } else {
      const saved = await updatePreference(REMINDER_ENABLED_KEY, false);
      if (!saved) return;
      cancelLearningReminders();
      await unregisterPushDevice().catch(() => undefined);
    }
  };

  const confirmNotifications = async () => {
    const boundary = await captureAccountSessionBoundary();
    const granted = await enableSmartReminders();
    assertAccountSessionBoundary(boundary);
    if (!granted) return false;
    const saved = await updatePreference(REMINDER_ENABLED_KEY, true);
    if (!saved) return false;
    assertAccountSessionBoundary(boundary);
    // Guests own local learning reminders only. A backend push token belongs
    // to an authenticated inbox, so requiring one here made a valid guest
    // opt-in flip back to off after the OS had already granted permission.
    if (!hasAuthenticatedAccount) return true;
    const registered = await registerPushDeviceIfEligible({
      requestPermission: false,
    }).catch(() => false);
    assertAccountSessionBoundary(boundary);
    if (!registered) {
      await updatePreference(REMINDER_ENABLED_KEY, false);
      assertAccountSessionBoundary(boundary);
      throw new Error('PUSH_DEVICE_REGISTRATION_FAILED');
    }
    return true;
  };

  const updateReminderHour = (hour: number) => {
    const revision = markPreferenceMutation('REMINDER_HOUR');
    const previousHour = reminderHour;
    setReminderHour(hour);
    setChoiceModal(null);
    return enqueuePreferenceWrite(async boundary => {
      const stored = await setSmartReminderHour(hour, boundary);
      if (!stored) throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
    }).catch(error => {
      if (preferenceRevisionRef.current.REMINDER_HOUR === revision) {
        setReminderHour(previousHour);
      }
      if (
        !(
          error instanceof Error &&
          error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
        )
      ) {
        Alert.alert('لم يُحفظ التغيير', 'تعذّر حفظ وقت التذكير\nحاول مرة أخرى');
      }
    });
  };

  const selectChoice = (key: string) => {
    if (choiceModal === 'reminderTime') {
      void updateReminderHour(Number(key));
      return;
    }
    const normalizedQuality = normalizeStoredQuality(key);
    const revision = markPreferenceMutation('VIDEO_QUALITY');
    const previousQuality = quality;
    setQuality(normalizedQuality);
    void enqueuePreferenceWrite(async boundary => {
      const stored = await saveItem(
        await accountScopedStorageKey('VIDEO_QUALITY', boundary),
        normalizedQuality,
      );
      if (!stored) throw new Error('SETTINGS_STORAGE_WRITE_FAILED');
      assertAccountSessionBoundary(boundary);
      if (hasAuthenticatedAccount) {
        await updatePlaybackPreferences(
          {videoQualityPreference: normalizedQuality},
          boundary,
        );
        assertAccountSessionBoundary(boundary);
      }
    }).catch(error => {
      const storageFailure =
        error instanceof Error &&
        error.message === 'SETTINGS_STORAGE_WRITE_FAILED';
      if (
        storageFailure &&
        preferenceRevisionRef.current.VIDEO_QUALITY === revision
      ) {
        setQuality(previousQuality);
      }
      if (storageFailure) {
        Alert.alert(
          'لم يُحفظ التغيير',
          'تعذّر حفظ جودة الفيديو\nحاول مرة أخرى',
        );
      }
    });
    setChoiceModal(null);
  };

  const confirmClearWatchHistory = () =>
    Alert.alert(
      'مسح سجل المشاهدة',
      'سنمسح آخر ما شاهدته فقط\nويبقى تقدمك وشهاداتك محفوظة',
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'مسح السجل',
          style: 'destructive',
          onPress: async () => {
            const boundary = await captureAccountSessionBoundary();
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
            Alert.alert(
              'تم مسح السجل',
              serverSynced
                ? 'بقي تقدمك في الكورسات محفوظًا'
                : 'مسحناه من هذا الجهاز\nوسيكتمل من حسابك عند عودة الاتصال',
            );
          },
        },
      ],
    );

  return {
    choiceModal,
    closeChoiceModal: () => setChoiceModal(null),
    closeNotificationPrimer: () => setNotificationPrimer(false),
    confirmClearWatchHistory,
    confirmNotifications,
    marketingNotifications,
    notificationPrimer,
    notifications,
    openQualityChoice: () => setChoiceModal('quality'),
    openReminderChoice: () => setChoiceModal('reminderTime'),
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
