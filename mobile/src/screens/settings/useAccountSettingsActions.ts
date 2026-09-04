import {useEffect, useRef, useState} from 'react';
import {Alert, Platform} from 'react-native';
import type {AppDispatch} from '../../store/store';
import {deleteAccount} from '../../store/actions/auth';
import {LogOut, saveLoginData} from '../../store/reducers/auth';
import {
  AsyncKeys,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  clearAccountScopedStorage,
  extractApiToken,
  extractUserProfile,
  getItem,
  rotateGuestStorageScope,
} from '../../constants/helpers';
import {
  cancelLearningReminders,
  setSmartRemindersEnabled,
} from '../../services/smartReminders';
import {
  clearCurrentPushDeviceRegistration,
  getCurrentPushDeviceToken,
} from '../../services/pushNotifications';
import {clearCurrentAccountLearningFiles} from '../../components/VideoPlayer/courseLearningApi';
import {
  accountDeletionCredential,
  socialProviderForSession,
} from '../../services/accountDeletionReauth';
import {
  signInWithSocialProvider,
  type SocialAuthSession,
} from '../../services/socialAuth';
import {toArabicDigits} from '../../constants/arabicFormatting';
import {revokeCurrentDeviceSession} from '../../services/deviceSessions';
import {
  getPublicAppSettings,
  safeDashboardUrl,
} from '../../services/publicAppSettings';
import type {PublicAppSettings} from '../../services/publicAppSettings';
import {accountDeletionUrl} from './settingsData';
import type {SettingsNavigation} from './types';
import {configuredAppStoreUrl} from '../../services/publicLinks';
import {clearTransientChatCache} from '../../utils/fileCache';
import {clearPendingLoginReturnTo} from '../../navigation/authReturn';
import {openExternalUrlOnce} from '../../services/systemActions';
import {revokeReauthenticationSession} from '../../services/accountDeletion';
import {
  deleteSecureSessionIfToken,
  updateSecureSessionForOwner,
} from '../../services/secureSession';

export const useAccountSettingsActions = ({
  dispatch,
  navigation,
  userData,
}: {
  dispatch: AppDispatch;
  navigation: SettingsNavigation;
  userData: unknown;
}) => {
  const [deletingAccount, setDeletingAccount] = useState(false);
  // React state is not synchronous: two alert callbacks can run before the
  // disabled state is painted. One boundary also prevents logout racing an
  // already-confirmed deletion and clearing its reauthentication state.
  const accountExitFlightRef = useRef<'delete' | 'logout' | null>(null);
  const [storeRatingAvailable, setStoreRatingAvailable] = useState(
    Boolean(configuredAppStoreUrl()),
  );

  useEffect(() => {
    let active = true;
    void getPublicAppSettings()
      .then(settings => {
        if (!active) return;
        const configuredUrl =
          Platform.OS === 'android'
            ? safeDashboardUrl(settings.android_app_url)
            : safeDashboardUrl(settings.ios_app_url) ||
              safeDashboardUrl(configuredAppStoreUrl());
        setStoreRatingAvailable(Boolean(configuredUrl));
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, []);

  const openAccountDeletionPage = async () => {
    try {
      await openExternalUrlOnce(accountDeletionUrl);
    } catch {
      Alert.alert('تعذّر فتح الصفحة', 'اطلب حذف الحساب عبر الدعم');
    }
  };

  const openStoreRating = async () => {
    try {
      const settings = await getPublicAppSettings().catch(
        (): PublicAppSettings => ({}),
      );
      if (Platform.OS === 'android') {
        const dashboardUrl = safeDashboardUrl(settings.android_app_url);
        await openExternalUrlOnce(
          dashboardUrl || 'market://details?id=com.rokn',
          'https://play.google.com/store/apps/details?id=com.rokn',
        );
        return;
      }
      const appStoreUrl =
        safeDashboardUrl(settings.ios_app_url) ||
        safeDashboardUrl(configuredAppStoreUrl());
      if (appStoreUrl) {
        await openExternalUrlOnce(appStoreUrl);
        return;
      }
      Alert.alert('تعذّر فتح التقييم', 'حاول مرة أخرى');
    } catch {
      if (Platform.OS === 'android') {
        try {
          await openExternalUrlOnce(
            'https://play.google.com/store/apps/details?id=com.rokn',
          );
          return;
        } catch {
          // Fall through to the same visible recovery as iOS.
        }
      }
      Alert.alert('تعذّر فتح التقييم', 'حاول مرة أخرى');
    }
  };

  const logout = () =>
    Alert.alert('تسجيل الخروج', 'سيخرج حسابك من هذا الجهاز فقط', [
      {text: 'إلغاء', style: 'cancel'},
      {
        text: 'تسجيل الخروج',
        style: 'destructive',
        onPress: async () => {
          if (accountExitFlightRef.current) return;
          accountExitFlightRef.current = 'logout';
          try {
            const sessionToken = extractApiToken(userData);
            const boundary = await captureAccountSessionBoundary();
            if (
              !sessionToken ||
              extractApiToken(await getItem(AsyncKeys.USER_DATA)) !==
                sessionToken
            ) {
              return;
            }
            assertAccountSessionBoundary(boundary);
            const accountScope = boundary.scope;
            cancelLearningReminders();
            await setSmartRemindersEnabled(false, boundary).catch(
              () => undefined,
            );
            let serverSessionRevoked = false;
            if (sessionToken) {
              try {
                const deviceToken = await getCurrentPushDeviceToken(boundary);
                await revokeCurrentDeviceSession(deviceToken, {
                  preservePersistedSessionOnUnauthorized: true,
                  session: {epoch: boundary.epoch, token: sessionToken},
                });
                serverSessionRevoked = true;
              } catch {
                // The local session still closes when the API is unavailable.
              }
            }
            const pushInvalidationDurable =
              await clearCurrentPushDeviceRegistration(boundary)
                .then(() => true)
                .catch(() => false);
            assertAccountSessionBoundary(boundary);
            if (!serverSessionRevoked && !pushInvalidationDurable) {
              Alert.alert('لم يكتمل تسجيل الخروج', 'حاول مرة أخرى');
              return;
            }
            // Secure credential deletion is the durable device-side logout
            // boundary. Do not reset the UI while a bearer or completed OAuth
            // receipt may still be recoverable on the next cold start.
            const secureSessionDeleted = await deleteSecureSessionIfToken(
              sessionToken,
            );
            if (!secureSessionDeleted) {
              // Another account won the session mutation while this logout
              // was in flight. Its UI and bearer must remain untouched.
              return;
            }
            await clearCurrentAccountLearningFiles(accountScope).catch(
              () => undefined,
            );
            await clearTransientChatCache({accountBoundary: true}).catch(
              () => undefined,
            );
            await clearAccountScopedStorage(accountScope, {
              preserveFinancialRecovery: true,
            }).catch(() => undefined);
            await clearPendingLoginReturnTo().catch(() => undefined);
            await rotateGuestStorageScope().catch(() => undefined);
            dispatch(LogOut());
            navigation.reset({index: 0, routes: [{name: 'Home'}]});
          } catch (error) {
            if (
              !(
                error instanceof Error &&
                error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
              )
            ) {
              Alert.alert('لم يكتمل تسجيل الخروج', 'حاول مرة أخرى');
            }
          } finally {
            accountExitFlightRef.current = null;
          }
        },
      },
    ]);

  const deleteCurrentAccount = async () => {
    if (deletingAccount || accountExitFlightRef.current) return;
    const token = extractApiToken(userData);
    if (!token) {
      Alert.alert('سجّل الدخول', 'أكد هويتك أولًا', [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'تسجيل الدخول',
          onPress: () =>
            navigation.navigate('Login', {
              returnTo: {name: 'Settings'},
            }),
        },
      ]);
      return;
    }

    accountExitFlightRef.current = 'delete';
    setDeletingAccount(true);
    let accountDeleted = false;
    let reauthenticationToken = '';
    let reauthenticatedSession: SocialAuthSession | null = null;
    let reauthenticationMatchesCurrentAccount = false;
    let reauthenticationCommitted = false;
    let deletedSessionToken = '';
    const deletionOwner = String(
      extractUserProfile(userData).id ??
        extractUserProfile(userData).user_id ??
        '',
    ).trim();
    try {
      const provider = socialProviderForSession(userData);
      if (!provider) throw new Error('ACCOUNT_REAUTH_PROVIDER_MISSING');
      reauthenticatedSession = await signInWithSocialProvider(
        provider,
        undefined,
        {purpose: 'reauth'},
      );
      reauthenticationToken = reauthenticatedSession.api_token?.trim() || '';
      const reauthToken = accountDeletionCredential(
        userData,
        reauthenticatedSession,
      );
      reauthenticationMatchesCurrentAccount = true;
      // The backend's one-device policy has already retired the old bearer at
      // this point. Commit the verified replacement before any concurrent
      // request can interpret the old token's 401 as a logout.
      if (!deletionOwner) throw new Error('ACCOUNT_REAUTH_IDENTITY_MISMATCH');
      await updateSecureSessionForOwner(
        deletionOwner,
        () => reauthenticatedSession,
      );
      dispatch(saveLoginData(reauthenticatedSession));
      reauthenticationCommitted = true;
      const deletionBoundary = await captureAccountSessionBoundary();
      const accountScope = deletionBoundary.scope;
      const deletion = await dispatch(deleteAccount({reauthToken})).unwrap();
      accountDeleted = true;
      deletedSessionToken = reauthToken;
      cancelLearningReminders();
      await setSmartRemindersEnabled(false, deletionBoundary).catch(
        () => undefined,
      );
      // Once the server confirms deletion, no ancillary cache or notification
      // failure may leave the deleted identity active on this device.
      await clearCurrentPushDeviceRegistration(deletionBoundary).catch(
        () => undefined,
      );
      const secureSessionDeleted = await deleteSecureSessionIfToken(
        deletedSessionToken,
      );
      if (secureSessionDeleted) {
        await clearCurrentAccountLearningFiles(accountScope).catch(
          () => undefined,
        );
        await clearTransientChatCache({accountBoundary: true}).catch(
          () => undefined,
        );
      }
      await clearAccountScopedStorage(accountScope).catch(() => undefined);
      reauthenticationToken = '';
      if (secureSessionDeleted) {
        await clearPendingLoginReturnTo().catch(() => undefined);
        await rotateGuestStorageScope().catch(() => undefined);
        dispatch(LogOut());
        navigation.reset({index: 0, routes: [{name: 'Home'}]});
      }
      Alert.alert(
        deletion.cleanupPending ? 'تم إغلاق الحساب' : 'تم حذف الحساب',
        deletion.cleanupPending
          ? 'أغلقنا حسابك\nلا تحتاج إلى إجراء آخر'
          : 'حذفنا حسابك وبيانات ملفك',
      );
    } catch (error) {
      if (error instanceof Error && error.message === 'LOGIN_CANCELLED') return;
      if (
        reauthenticationToken &&
        reauthenticatedSession &&
        reauthenticationMatchesCurrentAccount &&
        !accountDeleted
      ) {
        // Social reauthentication is a real login at the backend. Under the
        // one-device policy it replaces the previous bearer before deletion is
        // attempted. If deletion then fails, keep that verified replacement as
        // the live session instead of revoking it and stranding the UI on a
        // locally cached token the server has already retired.
        try {
          if (!reauthenticationCommitted) {
            if (!deletionOwner) {
              throw new Error('ACCOUNT_REAUTH_IDENTITY_MISMATCH');
            }
            await updateSecureSessionForOwner(
              deletionOwner,
              () => reauthenticatedSession,
            );
            dispatch(saveLoginData(reauthenticatedSession));
          }
          reauthenticationToken = '';
        } catch {
          await revokeReauthenticationSession(reauthenticationToken).catch(
            () => undefined,
          );
          reauthenticationToken = '';
          const originalSessionDeleted = await deleteSecureSessionIfToken(
            token,
          ).catch(() => false);
          if (originalSessionDeleted) {
            dispatch(LogOut());
            navigation.reset({index: 0, routes: [{name: 'Home'}]});
          }
        }
      } else if (reauthenticationToken) {
        await revokeReauthenticationSession(reauthenticationToken).catch(
          () => undefined,
        );
        reauthenticationToken = '';
      }
      if (accountDeleted) {
        const deletedLocalSession = deletedSessionToken
          ? await deleteSecureSessionIfToken(deletedSessionToken).catch(
              () => false,
            )
          : false;
        if (deletedLocalSession) {
          dispatch(LogOut());
          navigation.reset({index: 0, routes: [{name: 'Home'}]});
        }
        Alert.alert('تم حذف الحساب', 'حُذفت بيانات الحساب من ركن');
      } else {
        const mismatch =
          error instanceof Error &&
          error.message === 'ACCOUNT_REAUTH_IDENTITY_MISMATCH';
        Alert.alert(
          'تعذّر حذف الحساب',
          mismatch
            ? 'اختر حساب ركن نفسه\nثم حاول مرة أخرى'
            : 'أكد هويتك من جديد\nأو استخدم صفحة الحذف',
          [
            {text: 'إلغاء', style: 'cancel'},
            {text: 'صفحة الحذف', onPress: openAccountDeletionPage},
          ],
        );
      }
    } finally {
      accountExitFlightRef.current = null;
      setDeletingAccount(false);
    }
  };

  const confirmDelete = () =>
    Alert.alert(
      'حذف الحساب',
      (() => {
        const paidCoins = Math.max(
          0,
          Number(extractUserProfile(userData)?.wallet_purchased_coins || 0),
        );
        const balanceWarning =
          paidCoins > 0
            ? `\n\nلديك ${toArabicDigits(
                paidCoins,
              )} من الرصيد المدفوع\nاستخدمه قبل حذف الحساب`
            : '';
        return `سيُحذف ملفك وتقدمك ومحفوظاتك\nوستفقد الكورسات والعملات${balanceWarning}`;
      })(),
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'حذف الحساب',
          style: 'destructive',
          onPress: deleteCurrentAccount,
        },
      ],
    );

  return {
    confirmDelete,
    deletingAccount,
    logout,
    openAccountDeletionPage,
    openStoreRating,
    storeRatingAvailable,
  };
};
