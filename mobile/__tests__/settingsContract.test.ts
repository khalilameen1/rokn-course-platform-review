import {
  accountDeletionUrl,
  buildSettingsSections,
  type SettingsSectionsProps,
} from '../src/screens/settings/settingsData';
import {mainUrl} from '../src/constants/api';
import {returnsPolicyUrl} from '../src/services/publicLinks';
import fs from 'fs';
import path from 'path';

const callback = jest.fn;

const createProps = (
  overrides: Partial<SettingsSectionsProps> = {},
): SettingsSectionsProps => ({
  authenticated: true,
  deletingAccount: false,
  marketingNotifications: false,
  notifications: true,
  quality: 'auto',
  reminderHour: 20,
  watchHistory: true,
  onAbout: callback(),
  onClearWatchHistory: callback(),
  onDeleteAccount: callback(),
  onDevices: callback(),
  onEditAccount: callback(),
  onFeedback: callback(),
  onLogin: callback(),
  onLogout: callback(),
  onOpenQuality: callback(),
  onOpenReminderTime: callback(),
  onPortfolio: callback(),
  onPrivacyPolicy: callback(),
  onRateApp: callback(),
  onTermsOfUse: callback(),
  onToggleMarketing: callback(),
  onToggleNotifications: callback(),
  onToggleWatchHistory: callback(),
  ...overrides,
});

const authenticatedRows = [
  'account.edit',
  'account.portfolio',
  'account.devices',
  'account.logout',
  'account.delete',
  'learning.notifications',
  'learning.reminder-time',
  'learning.quality',
  'learning.history',
  'learning.clear-history',
  'privacy.marketing',
  'privacy.policy',
  'privacy.terms',
  'privacy.support',
  'about.rate',
  'about.info',
];

const guestRows = [
  'account.login',
  'learning.notifications',
  'learning.quality',
  'learning.history',
  'learning.clear-history',
  'privacy.policy',
  'privacy.terms',
  'privacy.support',
  'about.rate',
  'about.info',
];

const flattenRows = (props: SettingsSectionsProps) =>
  buildSettingsSections(props).flatMap(section => section.rows);

describe('settings screen contract', () => {
  it('opens public legal pages outside both legacy and versioned API prefixes', () => {
    const expectedOrigin = new URL(mainUrl).origin;
    const accountDeletion = new URL(accountDeletionUrl);
    const returnsPolicy = new URL(returnsPolicyUrl);
    expect(accountDeletion.origin).toBe(expectedOrigin);
    expect(accountDeletion.pathname).toBe('/account-deletion');
    expect(returnsPolicy.origin).toBe(expectedOrigin);
    expect(returnsPolicy.pathname).toBe('/returns-policy');
    expect(accountDeletion.search).toBe('');
    expect(accountDeletion.hash).toBe('');
    expect(returnsPolicy.search).toBe('');
    expect(returnsPolicy.hash).toBe('');
  });

  it('keeps every authenticated setting in its established order', () => {
    const rows = flattenRows(createProps());
    expect(rows.map(row => row.id)).toEqual(authenticatedRows);
    expect(new Set(rows.map(row => row.id)).size).toBe(rows.length);
  });

  it('keeps the guest settings and only hides the disabled reminder row', () => {
    const rows = flattenRows(
      createProps({authenticated: false, notifications: false}),
    );
    expect(rows.map(row => row.id)).toEqual(guestRows);
    expect(new Set(rows.map(row => row.id)).size).toBe(rows.length);
  });

  it('assigns a distinct icon and an interaction contract to all visible rows', () => {
    const authenticated = flattenRows(createProps());
    const guest = flattenRows(
      createProps({authenticated: false, notifications: false}),
    );
    const rowsById = new Map(
      [...authenticated, ...guest].map(row => [row.id, row]),
    );

    expect(rowsById.size).toBe(17);
    expect(new Set([...rowsById.values()].map(row => row.icon)).size).toBe(17);
    expect(rowsById.has('learning.display')).toBe(false);
    expect(rowsById.has('about.open-source')).toBe(false);
    expect(rowsById.has('privacy.refunds')).toBe(false);
    expect(rowsById.has('learning.autoplay')).toBe(false);

    for (const row of rowsById.values()) {
      expect(Boolean(row.onPress || row.toggle)).toBe(true);
    }
  });

  it('uses one contact entry for problems and suggestions', () => {
    const onFeedback = jest.fn();
    const rows = flattenRows(createProps({onFeedback}));
    const contact = rows.filter(row => row.id === 'privacy.support');

    expect(contact).toHaveLength(1);
    expect(rows.some(row => row.id === 'privacy.feedback')).toBe(false);
    expect(contact[0].title).toBe('تواصل معنا');
    contact[0].onPress?.();
    expect(onFeedback).toHaveBeenCalledTimes(1);
  });

  it('keeps implementation notes and retired choices out of the settings UI', () => {
    const rows = flattenRows(createProps());
    const visibleCopy = rows
      .flatMap(row => [row.title, row.subtitle, row.value])
      .filter(Boolean)
      .join('\n');

    expect(visibleCopy).not.toMatch(
      /مكتبات مفتوحة المصدر|سياسة الاسترداد|طريقة عرض الفيديو|متاح (?:حتى )?(?:دون|بدون) تسجيل/,
    );
  });

  it('uses the same flat avatar fallback in profile and account editing', () => {
    const profile = fs.readFileSync(
      path.join(__dirname, '../src/screens/Profile/index.tsx'),
      'utf8',
    );
    const editAccount = fs.readFileSync(
      path.join(__dirname, '../src/screens/EditAccount.tsx'),
      'utf8',
    );
    const avatar = fs.readFileSync(
      path.join(__dirname, '../src/components/ui/DefaultAvatar.tsx'),
      'utf8',
    );

    expect(profile).toContain('<DefaultAvatar');
    expect(editAccount).toContain('<DefaultAvatar');
    expect(profile).not.toContain('default-avatar.png');
    expect(editAccount).not.toContain('default-avatar.png');
    expect(avatar).toContain('<Circle');
    expect(avatar).toContain('<Path');
    expect(avatar).not.toMatch(/LinearGradient|RadialGradient|Mask|filter=/);
  });

  it('routes privacy questions through the same in-app support journey', () => {
    const source = fs.readFileSync(
      path.join(__dirname, '../src/screens/Informations/PrivacyPolicy.tsx'),
      'utf8',
    );

    expect(source).toContain(
      "navigation.navigate('Feedback', {sourceScreen: 'privacy'})",
    );
    expect(source).not.toContain('openSupportWhatsApp');
  });

  it('keeps one in-app support operation instead of a second WhatsApp fallback', () => {
    const source = fs.readFileSync(
      path.join(__dirname, '../src/screens/Feedback.tsx'),
      'utf8',
    );

    expect(source).toContain('<HeaderWithBack title="تواصل معنا" />');
    expect(source).not.toMatch(
      /openSupportWhatsApp|getSupportWhatsAppUrl|إرسالها للدعم على واتساب/,
    );
  });

  it('binds support drafts to the canonical account identity', () => {
    const feedback = fs.readFileSync(
      path.join(__dirname, '../src/screens/Feedback.tsx'),
      'utf8',
    );
    const preferences = fs.readFileSync(
      path.join(__dirname, '../src/screens/settings/useSettingsPreferences.ts'),
      'utf8',
    );

    expect(feedback).toContain('sessionIdentityKey(storedUser)');
    expect(preferences).toContain('sessionIdentityKey(userData)');
    expect(feedback).not.toMatch(/\?\s*String\([^)]*storedToken/);
  });

  it('keeps guest reminders local and device rows free of build diagnostics', () => {
    const preferences = fs.readFileSync(
      path.join(__dirname, '../src/screens/settings/useSettingsPreferences.ts'),
      'utf8',
    );
    const devices = fs.readFileSync(
      path.join(__dirname, '../src/screens/DeviceSessions.tsx'),
      'utf8',
    );

    expect(preferences).toMatch(
      /if \(!hasAuthenticatedAccount\) return true;[\s\S]{0,180}registerPushDeviceIfEligible/,
    );
    expect(devices).not.toContain('session.app_build');
    expect(devices).not.toContain('session.app_version');
    expect(devices).not.toMatch(/كمبيوتر|حاسوب|لابتوب/);
    expect(devices).toContain(
      "openGuestLogin(navigation, {name: 'DeviceSessions'})",
    );
    expect(devices).toContain('if (authenticated)');
  });

  it('does not expose the account editor while identity is unresolved', () => {
    const editAccount = fs.readFileSync(
      path.join(__dirname, '../src/screens/EditAccount.tsx'),
      'utf8',
    );

    expect(editAccount).toContain('if (!hasStoredToken)');
    expect(editAccount).toContain("hydrationState === 'loading'");
    expect(editAccount).toContain('جارٍ تحميل بيانات الحساب');
    expect(editAccount).toContain('Apple');
  });

  it('does not promise support recovery for coins discarded by account deletion', () => {
    const actions = fs.readFileSync(
      path.join(
        __dirname,
        '../src/screens/settings/useAccountSettingsActions.ts',
      ),
      'utf8',
    );

    expect(actions).toContain('استخدمه قبل حذف الحساب');
    expect(actions).not.toContain('راجع الدعم قبل الحذف إذا أردت استعادته');
  });
});
