import React from 'react';
import {Alert} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import TestRenderer, {act} from 'react-test-renderer';

const mockDispatch = jest.fn();
const mockGoBack = jest.fn();
const mockGetProfile = jest.fn();
const mockUpdateProfile = jest.fn();
let mockStoredSession: unknown;

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({goBack: mockGoBack, replace: jest.fn()}),
}));
jest.mock('react-redux', () => ({
  useDispatch: () => mockDispatch,
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({auth: {userData: mockStoredSession}}),
}));
jest.mock('react-native-image-picker', () => ({
  launchImageLibrary: jest.fn(async () => ({didCancel: true})),
}));
jest.mock('../src/services/roknApi', () => ({
  getProfile: (...args: unknown[]) => mockGetProfile(...args),
  hasSession: jest.fn(async () => true),
  updateProfile: (...args: unknown[]) => mockUpdateProfile(...args),
}));
jest.mock('../src/components/containers/Containers', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {Container: Wrapper, Content: Wrapper};
});
jest.mock('../src/components/ui/PremiumUI', () => {
  const ReactModule = require('react');
  const {Pressable, Text, View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {
    PremiumCard: Wrapper,
    ResponsiveFrame: Wrapper,
    StatusView: ({
      title,
      actionLabel,
      onAction,
    }: {
      title: string;
      actionLabel?: string;
      onAction?: () => void;
    }) =>
      ReactModule.createElement(
        View,
        {testID: 'profile-status'},
        ReactModule.createElement(Text, null, title),
        actionLabel
          ? ReactModule.createElement(
              Pressable,
              {
                accessibilityLabel: actionLabel,
                onPress: onAction,
              },
              ReactModule.createElement(Text, null, actionLabel),
            )
          : null,
      ),
  };
});
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/touchables/Button', () => {
  const ReactModule = require('react');
  const {Pressable, Text} = require('react-native');
  return ({
    disable,
    onPress,
    title,
  }: {
    disable?: boolean;
    onPress: () => void;
    title: string;
  }) =>
    ReactModule.createElement(
      Pressable,
      {
        accessibilityLabel: title,
        disabled: disable,
        onPress,
      },
      ReactModule.createElement(Text, null, title),
    );
});
jest.mock('../src/components/ui/DefaultAvatar', () => ({
  DefaultAvatar: () => null,
}));

import EditAccount from '../src/screens/EditAccount';
import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../src/constants/helpers';
import {
  deleteSecureSession,
  loadSecureSession,
  peekSecureSession,
  resetSecureSessionForTests,
  saveSecureSession,
} from '../src/services/secureSession';
import {serializeSecureSessionMutation} from '../src/services/secureSessionMutation';

const secureValues = new Map<string, string>();
const secureGet = SecureStore.getItemAsync as jest.MockedFunction<
  typeof SecureStore.getItemAsync
>;
const secureSet = SecureStore.setItemAsync as jest.MockedFunction<
  typeof SecureStore.setItemAsync
>;
const secureDelete = SecureStore.deleteItemAsync as jest.MockedFunction<
  typeof SecureStore.deleteItemAsync
>;
const secureIsAvailable = SecureStore.isAvailableAsync as jest.MockedFunction<
  typeof SecureStore.isAvailableAsync
>;
const writeProfile = (
  AsyncStorage.setItem as jest.Mock
).getMockImplementation()!;
const initialSession = {
  api_token: 'profile-lifecycle-original-token',
  user: {id: 7, name: 'الاسم الأصلي', profile_revision: 2},
};
const profile = (name = 'الاسم المحفوظ', profileRevision = 3) => ({
  avatar: '',
  email: 'learner@example.test',
  id: '7',
  name,
  portfolioHeadline: '',
  profileRevision,
});
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  const promise = new Promise<T>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('confirmed profile saves through the real session owner', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;

  beforeEach(async () => {
    jest.restoreAllMocks();
    jest.clearAllMocks();
    resetSecureSessionForTests();
    secureValues.clear();
    (AsyncStorage.setItem as jest.Mock).mockImplementation(writeProfile);
    await AsyncStorage.clear();
    secureIsAvailable.mockResolvedValue(true);
    secureGet.mockImplementation(async key => secureValues.get(key) ?? null);
    secureSet.mockImplementation(async (key, value) => {
      secureValues.set(key, value);
    });
    secureDelete.mockImplementation(async key => {
      secureValues.delete(key);
    });
    await saveSecureSession(initialSession);
    mockStoredSession = initialSession;
    mockGetProfile
      .mockReset()
      .mockImplementation(async (boundary: AccountSessionBoundary) => {
        assertAccountSessionBoundary(boundary);
        return profile(initialSession.user.name, 2);
      });
    mockUpdateProfile
      .mockReset()
      .mockImplementation(
        async (_payload: unknown, boundary: AccountSessionBoundary) => {
          assertAccountSessionBoundary(boundary);
          return profile();
        },
      );
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    jest.useFakeTimers();
  });

  afterEach(async () => {
    await act(async () => {
      renderer?.unmount();
    });
    renderer = undefined;
    jest.useRealTimers();
    jest.restoreAllMocks();
    (AsyncStorage.setItem as jest.Mock).mockImplementation(writeProfile);
    resetSecureSessionForTests();
  });

  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<EditAccount />);
    });
  };
  const input = () =>
    renderer!.root.findByProps({accessibilityLabel: 'الاسم الظاهر'});
  const edit = async (name: string) => {
    await act(async () => {
      input().props.onChangeText(name);
    });
  };
  const beginSave = async () => {
    let result!: Promise<void>;
    await act(async () => {
      result = renderer!.root
        .findByProps({accessibilityLabel: 'حفظ التغييرات'})
        .props.onPress();
    });
    return {result};
  };
  const pauseProfileWrite = () => {
    const gate = deferred<void>();
    const started = deferred<void>();
    jest
      .spyOn(AsyncStorage, 'setItem')
      .mockImplementation(async (key, value) => {
        if (
          key === 'USER_DATA' &&
          JSON.parse(value).user.name === 'الاسم المحفوظ'
        ) {
          started.resolve();
          await gate.promise;
        }
        await writeProfile(key, value);
      });
    return {gate, started};
  };

  it('accepts its own durable epoch advance and dispatches the committed snapshot', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const startingEpoch = peekSecureSession().epoch;
    const save = await beginSave();
    await act(async () => {
      await save.result;
    });

    const current = peekSecureSession();
    expect(current.epoch).toBe(startingEpoch + 1);
    expect(current.session).toMatchObject({
      api_token: initialSession.api_token,
      user: {id: 7, name: 'الاسم المحفوظ', profile_revision: 3},
    });
    expect(mockDispatch).toHaveBeenCalledWith({
      type: 'auth/saveLoginData',
      payload: current.session,
    });
    expect(mockGoBack).toHaveBeenCalledTimes(1);
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
    expect(Alert.alert).not.toHaveBeenCalled();
    resetSecureSessionForTests();
    await expect(loadSecureSession()).resolves.toEqual(current.session);
  });

  it('offers retry when a late durable commit overtakes the recovery GET instead of staying loading', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const {gate, started} = pauseProfileWrite();
    const recovery = deferred<ReturnType<typeof profile>>();
    mockGetProfile.mockImplementationOnce(
      async (boundary: AccountSessionBoundary) => {
        const result = await recovery.promise;
        assertAccountSessionBoundary(boundary);
        return result;
      },
    );
    const startingEpoch = peekSecureSession().epoch;
    const save = await beginSave();
    await started.promise;
    await act(async () => {
      await jest.advanceTimersByTimeAsync(750);
    });
    await save.result;
    expect(mockGetProfile).toHaveBeenCalledTimes(2);
    expect(peekSecureSession().epoch).toBe(startingEpoch);
    expect(mockDispatch).not.toHaveBeenCalled();
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).toHaveBeenCalledWith(
      'حُفظت التغييرات',
      expect.any(String),
    );

    await act(async () => {
      gate.resolve();
      await serializeSecureSessionMutation(async () => undefined);
      recovery.resolve(profile());
    });
    expect(peekSecureSession().epoch).toBe(startingEpoch + 1);
    const retry = renderer!.root.findByProps({
      accessibilityLabel: 'إعادة المحاولة',
    });
    expect(
      renderer!.root.findAllByProps({accessibilityLabel: 'حفظ التغييرات'}),
    ).toHaveLength(0);
    mockGetProfile.mockResolvedValueOnce(profile());
    await act(async () => {
      retry.props.onPress();
    });
    expect(input().props.value).toBe('الاسم المحفوظ');
    expect(
      renderer!.root.findByProps({accessibilityLabel: 'حفظ التغييرات'}).props
        .disabled,
    ).toBe(false);
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
    expect(mockDispatch).not.toHaveBeenCalled();
  });

  it('rejects the old queued updater after the same account signs in with a replacement bearer', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const gate = deferred<void>();
    const blocker = serializeSecureSessionMutation(() => gate.promise);
    const logout = deleteSecureSession();
    const replacement = {
      ...initialSession,
      api_token: 'profile-lifecycle-new-token',
      user: {...initialSession.user, name: 'الاسم بعد الدخول'},
    };
    const login = saveSecureSession(replacement);
    const save = await beginSave();
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
    expect(peekSecureSession().session).toEqual(initialSession);

    await act(async () => {
      gate.resolve();
      await blocker;
      await logout;
      await login;
      await save.result;
    });
    expect(peekSecureSession().session).toEqual(replacement);
    expect(
      JSON.parse((await AsyncStorage.getItem('USER_DATA'))!).user.name,
    ).toBe(replacement.user.name);
    expect(mockDispatch).not.toHaveBeenCalled();
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(mockGetProfile).toHaveBeenCalledTimes(1);
  });

  it('does not replace a newer visible edit when its timed-out cache write eventually completes', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const {gate, started} = pauseProfileWrite();
    mockGetProfile.mockResolvedValueOnce(profile());
    const save = await beginSave();
    await started.promise;
    await act(async () => {
      await jest.advanceTimersByTimeAsync(750);
    });
    await save.result;
    await edit('التعديل التالي');

    await act(async () => {
      gate.resolve();
      await serializeSecureSessionMutation(async () => undefined);
    });
    expect(input().props.value).toBe('التعديل التالي');
    expect(input().props.editable).toBe(true);
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
    expect(mockDispatch).not.toHaveBeenCalled();
    expect(mockGoBack).not.toHaveBeenCalled();
  });
});
