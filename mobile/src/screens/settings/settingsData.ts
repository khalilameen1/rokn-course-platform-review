import type {ComponentType} from 'react';
import type {SvgProps} from 'react-native-svg';
import {
  FullNameIcon,
  MoreBellIcon,
  MoreDeleteAccountIcon,
  MoreEditAccountIcon,
  MoreInfoIcon,
  MoreLogoutIcon,
  MoreRateAppIcon,
  SettingsClearHistoryIcon,
  SettingsDevicesIcon,
  SettingsFeedbackIcon,
  SettingsHistoryIcon,
  SettingsMarketingIcon,
  SettingsPortfolioIcon,
  SettingsPrivacyIcon,
  SettingsQualityIcon,
  SettingsReminderIcon,
  SettingsTermsIcon,
} from '../../assets/SVG';
import {toArabicDigits} from '../../constants/arabicFormatting';
import appConfig from '../../../app.json';
import {accountDeletionUrl} from '../../services/publicLinks';
import {PENDING_WATCH_HISTORY_CLEAR_KEY} from '../../services/pendingAccountWrites';

export {accountDeletionUrl};

export {PENDING_WATCH_HISTORY_CLEAR_KEY};
export const settingsAppVersion = toArabicDigits(appConfig.expo.version);

export const qualityLabel = (value: string) => {
  if (value === 'auto' || value === 'تلقائي') return 'تلقائي';
  if (value === 'data_saver' || value === 'توفير البيانات') {
    return 'توفير البيانات';
  }
  return toArabicDigits(value);
};

export const reminderTimeLabel = (hour: number) => {
  if (hour === 10) return 'صباحًا · ١٠:٠٠';
  if (hour === 15) return 'بعد الظهر · ٣:٠٠';
  return 'مساءً · ٨:٠٠';
};

export type SettingRowModel = {
  id: string;
  title: string;
  subtitle?: string;
  value?: string;
  onPress?: () => void;
  destructive?: boolean;
  toggle?: {value: boolean; onChange: (value: boolean) => void};
  isLast?: boolean;
  icon: ComponentType<SvgProps>;
};

export type SettingsSectionModel = {
  id: 'account' | 'learning' | 'privacy' | 'about';
  title: string;
  rows: SettingRowModel[];
};

export type SettingsSectionsProps = {
  authenticated: boolean;
  canRateApp?: boolean;
  deletingAccount: boolean;
  marketingNotifications: boolean;
  notifications: boolean;
  quality: string;
  reminderHour: number;
  watchHistory: boolean;
  onAbout: () => void;
  onClearWatchHistory: () => void;
  onDeleteAccount: () => void;
  onDevices: () => void;
  onEditAccount: () => void;
  onFeedback: () => void;
  onLogin: () => void;
  onLogout: () => void;
  onOpenQuality: () => void;
  onOpenReminderTime: () => void;
  onPortfolio: () => void;
  onPrivacyPolicy: () => void;
  onAiConsent?: () => void;
  onRateApp: () => void;
  onTermsOfUse: () => void;
  onToggleMarketing: (value: boolean) => void;
  onToggleNotifications: (value: boolean) => void;
  onToggleWatchHistory: (value: boolean) => void;
};

export const buildSettingsSections = (
  props: SettingsSectionsProps,
): SettingsSectionModel[] => {
  const accountRows: SettingRowModel[] = props.authenticated
    ? [
        {
          id: 'account.edit',
          icon: MoreEditAccountIcon,
          onPress: props.onEditAccount,
          title: 'بيانات الحساب',
        },
        {
          id: 'account.portfolio',
          icon: SettingsPortfolioIcon,
          onPress: props.onPortfolio,
          title: 'البورتفوليو',
        },
        {
          id: 'account.devices',
          icon: SettingsDevicesIcon,
          onPress: props.onDevices,
          title: 'الأجهزة المسجّل عليها',
        },
        {
          id: 'account.logout',
          icon: MoreLogoutIcon,
          onPress: props.onLogout,
          title: 'تسجيل الخروج',
        },
        {
          id: 'account.delete',
          destructive: true,
          icon: MoreDeleteAccountIcon,
          isLast: true,
          onPress: props.onDeleteAccount,
          title: props.deletingAccount ? 'جارٍ حذف الحساب' : 'حذف الحساب',
        },
      ]
    : [
        {
          id: 'account.login',
          icon: FullNameIcon,
          isLast: true,
          onPress: props.onLogin,
          subtitle: 'احفظ تقدمك ومحفوظاتك',
          title: 'تسجيل الدخول',
        },
      ];

  const learningRows: SettingRowModel[] = [
    {
      id: 'learning.notifications',
      icon: MoreBellIcon,
      title: 'إشعارات التعلّم',
      toggle: {
        value: props.notifications,
        onChange: props.onToggleNotifications,
      },
    },
    ...(props.notifications
      ? [
          {
            id: 'learning.reminder-time',
            icon: SettingsReminderIcon,
            onPress: props.onOpenReminderTime,
            title: 'وقت التذكير',
            value: reminderTimeLabel(props.reminderHour),
          } satisfies SettingRowModel,
        ]
      : []),
    {
      id: 'learning.quality',
      icon: SettingsQualityIcon,
      onPress: props.onOpenQuality,
      title: 'جودة الفيديو',
      value: qualityLabel(props.quality),
    },
    {
      id: 'learning.history',
      icon: SettingsHistoryIcon,
      subtitle: 'أكمل من آخر موضع شاهدته',
      title: 'سجل المشاهدة',
      toggle: {value: props.watchHistory, onChange: props.onToggleWatchHistory},
    },
    {
      id: 'learning.clear-history',
      destructive: true,
      icon: SettingsClearHistoryIcon,
      isLast: true,
      onPress: props.onClearWatchHistory,
      title: 'مسح سجل المشاهدة',
    },
  ];

  const privacyRows: SettingRowModel[] = [
    ...(props.authenticated && props.onAiConsent
      ? [{
          id: 'privacy.ai-consent', icon: SettingsPrivacyIcon,
          title: 'مشاركة البيانات للذكاء الاصطناعي', onPress: props.onAiConsent,
        } satisfies SettingRowModel]
      : []),
    ...(props.authenticated
      ? [
          {
            id: 'privacy.marketing',
            icon: SettingsMarketingIcon,
            title: 'العروض والأخبار',
            toggle: {
              value: props.marketingNotifications,
              onChange: props.onToggleMarketing,
            },
          } satisfies SettingRowModel,
        ]
      : []),
    {
      id: 'privacy.policy',
      icon: SettingsPrivacyIcon,
      onPress: props.onPrivacyPolicy,
      title: 'سياسة الخصوصية',
    },
    {
      id: 'privacy.terms',
      icon: SettingsTermsIcon,
      onPress: props.onTermsOfUse,
      title: 'شروط الاستخدام',
    },
    {
      id: 'privacy.support',
      icon: SettingsFeedbackIcon,
      isLast: true,
      onPress: props.onFeedback,
      title: 'تواصل معنا',
    },
  ];

  const aboutRows: SettingRowModel[] = [
    ...(props.canRateApp !== false
      ? [
          {
            id: 'about.rate',
            icon: MoreRateAppIcon,
            onPress: props.onRateApp,
            title: 'قيّم ركن',
          } satisfies SettingRowModel,
        ]
      : []),
    {
      id: 'about.info',
      icon: MoreInfoIcon,
      isLast: true,
      onPress: props.onAbout,
      title: 'عن ركن',
      value: settingsAppVersion,
    },
  ];

  return [
    {id: 'account', title: 'الحساب', rows: accountRows},
    {id: 'learning', title: 'التعلّم والمشاهدة', rows: learningRows},
    {id: 'privacy', title: 'الخصوصية والتواصل', rows: privacyRows},
    {id: 'about', title: 'عن التطبيق', rows: aboutRows},
  ];
};
