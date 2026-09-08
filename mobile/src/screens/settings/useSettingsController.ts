import {useNavigation} from '@react-navigation/native';
import {useDispatch, useSelector} from 'react-redux';
import {extractApiToken, extractUserProfile} from '../../constants/helpers';
import type {AppDispatch, RootState} from '../../store/store';
import type {SettingsSectionsProps} from './settingsData';
import type {SettingsNavigation} from './types';
import {useAccountSettingsActions} from './useAccountSettingsActions';
import {useSettingsPreferences} from './useSettingsPreferences';
import {openGuestLogin} from '../../navigation/journeyNavigation';

export const useSettingsController = () => {
  const navigation = useNavigation<SettingsNavigation>();
  const dispatch = useDispatch<AppDispatch>();
  const userData = useSelector((state: RootState) => state.auth.userData);
  const authenticated = Boolean(extractApiToken(userData));
  const user = extractUserProfile(userData);
  const accountId = user.id ?? user.user_id;
  const accountName = typeof user.name === 'string' ? user.name.trim() : '';
  const validAccountId =
    /^[1-9]\d*$/.test(String(accountId ?? '')) &&
    (typeof accountId !== 'number' || Number.isSafeInteger(accountId));
  const accountIdentity =
    authenticated && validAccountId
      ? {id: String(accountId), name: accountName || 'حسابي'}
      : null;
  const preferences = useSettingsPreferences({
    hasAuthenticatedAccount: authenticated,
    userData,
  });
  const account = useAccountSettingsActions({dispatch, navigation, userData});

  const sectionsProps: SettingsSectionsProps = {
    authenticated,
    canRateApp: account.storeRatingAvailable,
    deletingAccount: account.deletingAccount,
    marketingNotifications: preferences.marketingNotifications,
    notifications: preferences.notifications,
    quality: preferences.quality,
    reminderHour: preferences.reminderHour,
    watchHistory: preferences.watchHistory,
    onAbout: () => navigation.navigate('AboutUs'),
    onClearWatchHistory: preferences.confirmClearWatchHistory,
    onDeleteAccount: account.confirmDelete,
    onDevices: () => navigation.navigate('DeviceSessions'),
    onEditAccount: () => navigation.navigate('EditAccount'),
    onFeedback: () =>
      navigation.navigate('Feedback', {sourceScreen: 'settings'}),
    onLogin: () => openGuestLogin(navigation, {name: 'Settings'}),
    onLogout: account.logout,
    onOpenQuality: preferences.openQualityChoice,
    onOpenReminderTime: preferences.openReminderChoice,
    onPortfolio: () => navigation.navigate('Profile'),
    onPrivacyPolicy: () => navigation.navigate('PrivacyPolicy'),
    onRateApp: account.openStoreRating,
    onTermsOfUse: () => navigation.navigate('TermsOfUse'),
    onToggleMarketing: preferences.toggleMarketing,
    onToggleNotifications: preferences.toggleNotifications,
    onToggleWatchHistory: preferences.toggleWatchHistory,
  };

  return {
    accountIdentity,
    choiceModal: preferences.choiceModal,
    closeChoiceModal: preferences.closeChoiceModal,
    closeNotificationPrimer: preferences.closeNotificationPrimer,
    confirmNotifications: preferences.confirmNotifications,
    notificationPrimer: preferences.notificationPrimer,
    quality: preferences.quality,
    reminderHour: preferences.reminderHour,
    sectionsProps,
    selectChoice: preferences.selectChoice,
  };
};
