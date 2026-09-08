import React, {useState} from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import {useAccountSettingsActions} from '../src/screens/settings/useAccountSettingsActions';
import {
  loadSecureSession,
  peekSecureSession,
  resetSecureSessionForTests,
  saveSecureSession,
} from '../src/services/secureSession';
import {clearCurrentAccountLearningFiles} from '../src/components/VideoPlayer/courseLearningApi';
import {revokeCurrentDeviceSession} from '../src/services/deviceSessions';
import {signInWithSocialProvider} from '../src/services/socialAuth';
import {clearPendingLoginReturnTo} from '../src/navigation/authReturn';
import {accountScopedStorageKey} from '../src/constants/helpers';

jest.mock('../src/store/actions/auth', () => ({
  deleteAccount: () => ({type: 'test/deleteAccount'}),
}));
jest.mock('../src/store/reducers/auth', () => ({
  LogOut: () => ({type: 'test/logout'}),
  saveLoginData: (payload: unknown) => ({type: 'test/login', payload}),
}));
jest.mock('../src/services/smartReminders', () => ({
  cancelLearningReminders: jest.fn(),
  setSmartRemindersEnabled: jest.fn(async () => undefined),
}));
jest.mock('../src/services/pushNotifications', () => ({
  getCurrentPushDeviceToken: jest.fn(async () => null),
  clearCurrentPushDeviceRegistration: jest.fn(async () => undefined),
}));
jest.mock('../src/services/deviceSessions', () => ({
  revokeCurrentDeviceSession: jest.fn(async () => undefined),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  clearCurrentAccountLearningFiles: jest.fn(async () => undefined),
}));
jest.mock('../src/utils/fileCache', () => ({
  clearTransientChatCache: jest.fn(async () => undefined),
}));
jest.mock('../src/services/socialAuth', () => ({
  signInWithSocialProvider: jest.fn(),
}));
jest.mock('../src/services/accountDeletion', () => ({
  revokeReauthenticationSession: jest.fn(async () => undefined),
}));
jest.mock('../src/services/publicAppSettings', () => ({
  getPublicAppSettings: jest.fn(async () => ({})),
  safeDashboardUrl: jest.fn(() => null),
}));
jest.mock('../src/services/publicLinks', () => ({
  configuredAppStoreUrl: () => null,
}));
jest.mock('../src/screens/settings/settingsData', () => ({
  accountDeletionUrl: 'https://example.test/delete',
}));
jest.mock('../src/services/systemActions', () => ({
  openExternalUrlOnce: jest.fn(),
}));
jest.mock('../src/navigation/authReturn', () => ({
  clearPendingLoginReturnTo: jest.fn(async () => undefined),
}));

const secureValues = new Map<string, string>();
const secureDelete = jest.mocked(SecureStore.deleteItemAsync);
const initialSession = {
  api_token: 'original-token',
  user: {
    id: 7,
    name: 'Student',
    email: null,
    social_provider: 'google' as const,
  },
};
const mockDeleteOnServer = jest.fn(async () => ({cleanupPending: false}));
let renderer: TestRenderer.ReactTestRenderer | undefined;

const mountActions = async () => {
  let actions!: ReturnType<typeof useAccountSettingsActions>;
  let setSession!: (session: unknown) => void;
  const navigation = {navigate: jest.fn(), reset: jest.fn()};
  const dispatch = jest.fn((action: {type: string; payload?: unknown}) => {
    if (action.type === 'test/deleteAccount')
      return {unwrap: mockDeleteOnServer};
    if (action.type === 'test/login') setSession(action.payload);
    return action;
  });
  const Probe = () => {
    const [session, updateSession] = useState<unknown>(initialSession);
    setSession = updateSession;
    actions = useAccountSettingsActions({
      dispatch: dispatch as never,
      navigation: navigation as never,
      userData: session,
    });
    return null;
  };
  await act(async () => {
    renderer = TestRenderer.create(<Probe />);
  });
  return {actions: () => actions, dispatch, navigation};
};

const pressAlertAction = async (text: string) => {
  const alerts = jest.mocked(Alert.alert).mock.calls;
  const buttons = alerts[alerts.length - 1]?.[2];
  const button = buttons?.find(item => item.text === text);
  expect(button?.onPress).toBeDefined();
  await act(async () => {
    await button!.onPress!();
  });
};

describe('settings durable local logout', () => {
  beforeEach(async () => {
    jest.restoreAllMocks();
    jest.clearAllMocks();
    resetSecureSessionForTests();
    secureValues.clear();
    await AsyncStorage.clear();
    jest
      .mocked(SecureStore.getItemAsync)
      .mockImplementation(async key => secureValues.get(key) ?? null);
    jest
      .mocked(SecureStore.setItemAsync)
      .mockImplementation(async (key, value) => {
        secureValues.set(key, value);
      });
    secureDelete.mockImplementation(async key => {
      secureValues.delete(key);
    });
    jest.mocked(clearCurrentAccountLearningFiles).mockResolvedValue(undefined);
    jest.mocked(revokeCurrentDeviceSession).mockResolvedValue(undefined);
    jest.mocked(signInWithSocialProvider).mockResolvedValue({
      ...initialSession,
      api_token: 'reauth-token',
    });
    mockDeleteOnServer.mockResolvedValue({cleanupPending: false});
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await saveSecureSession(initialSession);
  });

  afterEach(() => {
    if (renderer) act(() => renderer!.unmount());
    renderer = undefined;
  });

  it.each([
    'rokn.auth.pending-social.v1',
    'rokn.auth.api-token.v2',
    'USER_DATA',
  ])(
    'shows retry after %s deletion fails and the next logout really finishes',
    async key => {
      const hook = await mountActions();
      secureValues.set('rokn.auth.pending-social.v1', 'completed-journal');
      let fail = true;
      if (key === 'USER_DATA') {
        jest
          .spyOn(AsyncStorage, 'removeItem')
          .mockRejectedValueOnce(new Error('profile delete failed'));
      } else {
        secureDelete.mockImplementation(async deletingKey => {
          if (fail && deletingKey === key)
            throw new Error('native delete failed');
          secureValues.delete(deletingKey);
        });
      }
      hook.actions().logout();
      await pressAlertAction('تسجيل الخروج');

      expect(Alert.alert).toHaveBeenLastCalledWith(
        'لم يكتمل تسجيل الخروج',
        'حاول مرة أخرى',
      );
      expect(hook.dispatch).not.toHaveBeenCalledWith({type: 'test/logout'});
      expect(hook.navigation.reset).not.toHaveBeenCalled();
      expect(await loadSecureSession()).toEqual(initialSession);
      const deletesAfterFailure = secureDelete.mock.calls.length;
      fail = false;
      hook.actions().logout();
      await pressAlertAction('تسجيل الخروج');

      expect(secureDelete.mock.calls.length).toBeGreaterThan(
        deletesAfterFailure,
      );
      expect(secureValues.size).toBe(0);
      expect(await AsyncStorage.getItem('USER_DATA')).toBeNull();
      expect(hook.dispatch).toHaveBeenCalledWith({type: 'test/logout'});
      expect(hook.navigation.reset).toHaveBeenCalledTimes(1);
    },
  );

  it.each([7, 8])(
    'keeps replacement account %s when login commits during cleanup',
    async id => {
      const hook = await mountActions();
      let replacementCacheKey = '';
      jest
        .mocked(clearCurrentAccountLearningFiles)
        .mockImplementationOnce(async () => {
          await saveSecureSession({api_token: 'replacement-token', user: {id}});
          replacementCacheKey = await accountScopedStorageKey(
            'replacement-draft',
          );
          await AsyncStorage.setItem(replacementCacheKey, 'new-account-state');
        });
      hook.actions().logout();
      await pressAlertAction('تسجيل الخروج');

      expect(peekSecureSession().session).toMatchObject({
        api_token: 'replacement-token',
        user: {id},
      });
      expect(hook.dispatch).not.toHaveBeenCalledWith({type: 'test/logout'});
      expect(hook.navigation.reset).not.toHaveBeenCalled();
      expect(clearPendingLoginReturnTo).not.toHaveBeenCalled();
      expect(await AsyncStorage.getItem(replacementCacheKey)).toBe(
        'new-account-state',
      );
    },
  );

  it('does not report logout or erase a replacement that wins before token deletion', async () => {
    const hook = await mountActions();
    jest.mocked(revokeCurrentDeviceSession).mockImplementationOnce(async () => {
      await saveSecureSession({api_token: 'replacement-token', user: {id: 8}});
    });
    hook.actions().logout();
    await pressAlertAction('تسجيل الخروج');

    expect(peekSecureSession().session).toMatchObject({
      api_token: 'replacement-token',
    });
    expect(secureDelete).not.toHaveBeenCalled();
    expect(hook.navigation.reset).not.toHaveBeenCalled();
  });

  it('keeps server deletion truthful and allows local logout retry without deleting the account again', async () => {
    const hook = await mountActions();
    let fail = true;
    secureDelete.mockImplementation(async key => {
      if (fail && key === 'rokn.auth.pending-social.v1')
        throw new Error('journal delete failed');
      secureValues.delete(key);
    });
    hook.actions().confirmDelete();
    await pressAlertAction('حذف الحساب');

    expect(mockDeleteOnServer).toHaveBeenCalledTimes(1);
    expect(Alert.alert).toHaveBeenLastCalledWith(
      'تم حذف الحساب',
      'لم يكتمل تسجيل الخروج\nحاول تسجيل الخروج مرة أخرى',
    );
    expect(hook.navigation.reset).not.toHaveBeenCalled();
    expect(peekSecureSession().session).toMatchObject({
      api_token: 'reauth-token',
    });

    fail = false;
    hook.actions().logout();
    await pressAlertAction('تسجيل الخروج');

    expect(mockDeleteOnServer).toHaveBeenCalledTimes(1);
    expect(signInWithSocialProvider).toHaveBeenCalledTimes(1);
    expect(hook.navigation.reset).toHaveBeenCalledTimes(1);
    expect(peekSecureSession().session).toBeNull();
  });
});
