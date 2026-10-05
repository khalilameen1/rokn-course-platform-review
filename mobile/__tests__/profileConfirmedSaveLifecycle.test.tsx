import React from 'react';
import {Alert, Image} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import TestRenderer, {act} from 'react-test-renderer';

const mockDispatch = jest.fn();
const mockGoBack = jest.fn();
const mockGetProfile = jest.fn();
const mockHasSession = jest.fn();
const mockUpdateProfile = jest.fn();
const mockPicker = jest.fn();
const mockCacheFile = jest.fn();
const mockRemoveFile = jest.fn(async (_file?: unknown) => undefined);
let mockFocused = true;
let mockForeground = true;
let mockStoredSession: unknown;

jest.mock('@react-navigation/native', () => ({
  useIsFocused: () => mockFocused,
  useNavigation: () => ({goBack: mockGoBack, replace: jest.fn()}),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => mockForeground,
}));
jest.mock('react-redux', () => ({
  useDispatch: () => mockDispatch,
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({auth: {userData: mockStoredSession}}),
}));
jest.mock('react-native-image-picker', () => ({
  launchImageLibrary: (...args: unknown[]) => mockPicker(...args),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: (...args: unknown[]) => mockCacheFile(...args),
  removeLearnerDraftFile: (file?: unknown) => mockRemoveFile(file),
}));
jest.mock('../src/services/roknApi', () => ({
  getProfile: (...args: unknown[]) => mockGetProfile(...args),
  hasSession: () => mockHasSession(),
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
  let reject!: (error: unknown) => void;
  const promise = new Promise<T>((done, fail) => {
    resolve = done;
    reject = fail;
  });
  return {promise, resolve, reject};
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
    mockFocused = true;
    mockForeground = true;
    mockPicker.mockReset().mockResolvedValue({didCancel: true});
    mockCacheFile.mockReset();
    mockHasSession.mockReset().mockResolvedValue(true);
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
  const focus = async (value: boolean) => {
    await act(async () => {
      mockFocused = value;
      renderer!.update(<EditAccount />);
    });
  };
  const foreground = async (value: boolean) => {
    await act(async () => {
      mockForeground = value;
      renderer!.update(<EditAccount />);
    });
  };
  const deliverCommittedReduxSession = async () => {
    const action =
      mockDispatch.mock.calls[mockDispatch.mock.calls.length - 1][0];
    expect(action).toEqual({
      type: 'auth/saveLoginData',
      payload: peekSecureSession().session,
    });
    await act(async () => {
      mockStoredSession = action.payload;
      renderer!.update(<EditAccount />);
    });
  };
  const delayRemoteSave = () => {
    const reply = deferred<ReturnType<typeof profile>>();
    mockUpdateProfile.mockImplementationOnce(
      async (_payload: unknown, boundary: AccountSessionBoundary) => {
        const response = await reply.promise;
        assertAccountSessionBoundary(boundary);
        return response;
      },
    );
    return reply;
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

  it.each([false, true])(
    'commits an accepted save after departure without taking over navigation when returned=%s',
    async returned => {
      await mount();
      await edit('اسم قبل التسوية');
      const reply = delayRemoteSave();
      const save = await beginSave();
      await focus(false);
      if (returned) await focus(true);
      expect(input().props.editable).toBe(false);
      expect(
        renderer!.root.findByProps({accessibilityLabel: 'حفظ التغييرات'}).props
          .disabled,
      ).toBe(true);

      await act(async () => {
        reply.resolve(profile('الاسم المعتمد من السيرفر', 3));
        await save.result;
      });
      expect(peekSecureSession().session).toMatchObject({
        user: {name: 'الاسم المعتمد من السيرفر', profile_revision: 3},
      });
      expect(mockDispatch).toHaveBeenCalledWith({
        type: 'auth/saveLoginData',
        payload: peekSecureSession().session,
      });
      expect(mockGoBack).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
      if (!returned) await focus(true);
      expect(input().props.value).toBe('الاسم المعتمد من السيرفر');
      expect(input().props.editable).toBe(true);

      await edit('التعديل التالي');
      mockUpdateProfile.mockResolvedValueOnce(profile('التعديل التالي', 4));
      const next = await beginSave();
      await act(async () => {
        await next.result;
      });
      expect(mockUpdateProfile.mock.calls[1][0]).toMatchObject({
        name: 'التعديل التالي',
        expectedProfileRevision: 3,
      });
      expect(mockGoBack).toHaveBeenCalledTimes(1);
    },
  );

  it('settles a saved avatar to the canonical URL instead of leaving the deleted private selection in the editor', async () => {
    const selected = {
      uri: 'file:///private/profile-save-selected.jpg',
      type: 'image/jpeg',
      fileName: 'selected.jpg',
      size: 1200,
    };
    mockPicker.mockResolvedValueOnce({
      assets: [{uri: 'content://photos/selected', fileSize: 1200}],
    });
    mockCacheFile.mockResolvedValueOnce(selected);
    await mount();
    await act(async () => {
      await renderer!.root
        .findByProps({accessibilityLabel: 'اختيار صورة الحساب'})
        .props.onPress();
    });
    expect(renderer!.root.findByType(Image).props.source.uri).toBe(
      selected.uri,
    );
    const reply = delayRemoteSave();
    const save = await beginSave();
    await focus(false);
    await focus(true);
    const canonicalAvatar = 'https://cdn.example.test/saved-avatar.jpg';
    await act(async () => {
      reply.resolve({...profile(), avatar: canonicalAvatar});
      await save.result;
    });
    expect(mockUpdateProfile.mock.calls[0][0].avatar).toBe(selected);
    expect(mockRemoveFile).toHaveBeenCalledWith(selected);
    expect(renderer!.root.findByType(Image).props.source.uri).toBe(
      canonicalAvatar,
    );
    expect(peekSecureSession().session).toMatchObject({
      user: {avatar: canonicalAvatar, profile_image: canonicalAvatar},
    });
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    mockUpdateProfile.mockResolvedValueOnce({
      ...profile('تعديل جديد', 4),
      avatar: canonicalAvatar,
    });
    await edit('تعديل جديد');
    const next = await beginSave();
    await act(async () => {
      await next.result;
    });
    expect(mockUpdateProfile.mock.calls[1][0]).toMatchObject({
      avatar: undefined,
      expectedProfileRevision: 3,
    });
  });

  it.each([false, true])(
    'keeps a failed draft retryable without an old alert when returned=%s',
    async returned => {
      await mount();
      await edit('تعديل لم يُحفظ');
      const reply = delayRemoteSave();
      const save = await beginSave();
      const request = mockUpdateProfile.mock.calls[0][0];
      await focus(false);
      if (returned) await focus(true);
      await act(async () => {
        reply.reject(new Error('NETWORK_UNAVAILABLE'));
        await save.result;
      });
      expect(Alert.alert).not.toHaveBeenCalled();
      expect(mockGoBack).not.toHaveBeenCalled();
      expect(mockDispatch).not.toHaveBeenCalled();
      expect(peekSecureSession().session).toEqual(initialSession);
      if (!returned) await focus(true);
      expect(input().props.value).toBe('تعديل لم يُحفظ');
      expect(input().props.editable).toBe(true);
      const retry = await beginSave();
      await act(async () => {
        await retry.result;
      });
      expect(mockUpdateProfile.mock.calls[1][0]).toEqual(request);
      expect(mockGoBack).toHaveBeenCalledTimes(1);
    },
  );

  it('commits a slow local mirror after departure without reverting the active route', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const {gate, started} = pauseProfileWrite();
    const save = await beginSave();
    await started.promise;
    await focus(false);
    await act(async () => {
      gate.resolve();
      await save.result;
    });
    expect(peekSecureSession().session).toMatchObject({
      user: {name: 'الاسم المحفوظ', profile_revision: 3},
    });
    expect(mockDispatch).toHaveBeenCalledTimes(1);
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    await focus(true);
    expect(input().props.value).toBe('الاسم المحفوظ');
  });

  it('recovers a timed-out mirror quietly after departure and does not replay its completion into a newer edit', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const {gate, started} = pauseProfileWrite();
    mockGetProfile.mockResolvedValueOnce(profile());
    const save = await beginSave();
    await started.promise;
    await focus(false);
    await act(async () => {
      await jest.advanceTimersByTimeAsync(750);
      await save.result;
    });
    expect(mockGetProfile).toHaveBeenCalledTimes(2);
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(mockGoBack).not.toHaveBeenCalled();
    await focus(true);
    expect(input().props.value).toBe('الاسم المحفوظ');
    await edit('التعديل التالي');
    await act(async () => {
      gate.resolve();
      await serializeSecureSessionMutation(async () => undefined);
    });
    expect(input().props.value).toBe('التعديل التالي');
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
    expect(mockDispatch).not.toHaveBeenCalled();
  });

  it('rejects a retained save callback from a previous focus visit', async () => {
    await mount();
    const retainedSave = renderer!.root.findByProps({
      accessibilityLabel: 'حفظ التغييرات',
    }).props.onPress;
    await focus(false);
    await focus(true);
    await act(async () => {
      await retainedSave();
    });
    expect(mockUpdateProfile).not.toHaveBeenCalled();
    expect(mockDispatch).not.toHaveBeenCalled();
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('does not let a retained old-account save capture a replacement secure session before Redux renders it', async () => {
    await mount();
    const retainedSave = renderer!.root.findByProps({
      accessibilityLabel: 'حفظ التغييرات',
    }).props.onPress;
    await act(async () => {
      await deleteSecureSession();
      await saveSecureSession({
        api_token: 'replacement-profile-save-token',
        user: {id: 8, name: 'حساب آخر', profile_revision: 5},
      });
      await retainedSave();
    });
    expect(mockUpdateProfile).not.toHaveBeenCalled();
    expect(mockDispatch).not.toHaveBeenCalled();
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(peekSecureSession().session).toMatchObject({user: {id: 8}});
  });

  it('finishes an accepted same-account save after unmount without navigation or a late dialog', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const reply = delayRemoteSave();
    const save = await beginSave();
    await act(async () => {
      renderer!.unmount();
    });
    renderer = undefined;
    await act(async () => {
      reply.resolve(profile());
      await save.result;
    });
    expect(peekSecureSession().session).toMatchObject({
      user: {name: 'الاسم المحفوظ', profile_revision: 3},
    });
    expect(mockDispatch).toHaveBeenCalledTimes(1);
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it.each([
    {returned: false, succeeds: true},
    {returned: true, succeeds: true},
    {returned: false, succeeds: false},
    {returned: true, succeeds: false},
  ])(
    'does not present a save outcome across a foreground visit returned=$returned succeeds=$succeeds',
    async ({returned, succeeds}) => {
      await mount();
      await edit('الاسم المحفوظ');
      const reply = delayRemoteSave();
      const save = await beginSave();
      await foreground(false);
      if (returned) await foreground(true);
      await act(async () => {
        if (succeeds) reply.resolve(profile());
        else reply.reject(new Error('NETWORK_UNAVAILABLE'));
        await save.result;
      });
      expect(mockGoBack).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
      expect(mockDispatch).toHaveBeenCalledTimes(succeeds ? 1 : 0);
      if (!returned) await foreground(true);
      expect(input().props.editable).toBe(true);
      expect(input().props.value).toBe('الاسم المحفوظ');
    },
  );

  it('keeps the native gallery result across its own foreground interruption', async () => {
    await mount();
    const selection = deferred<{assets: {uri: string; fileSize: number}[]}>();
    mockPicker.mockImplementationOnce(() => selection.promise);
    const file = {
      uri: 'file:///private/native-gallery-result.jpg',
      type: 'image/jpeg',
      fileName: 'gallery.jpg',
      size: 1200,
    };
    mockCacheFile.mockResolvedValueOnce(file);
    let picking!: Promise<void>;
    await act(async () => {
      picking = renderer!.root
        .findByProps({accessibilityLabel: 'اختيار صورة الحساب'})
        .props.onPress();
    });
    await foreground(false);
    await foreground(true);
    await act(async () => {
      selection.resolve({
        assets: [{uri: 'content://gallery/photo', fileSize: 1200}],
      });
      await picking;
    });
    expect(renderer!.root.findByType(Image).props.source.uri).toBe(file.uri);
    expect(mockRemoveFile).not.toHaveBeenCalledWith(file);
    const savedAvatar = 'https://cdn.example.test/gallery-saved.jpg';
    mockUpdateProfile.mockResolvedValueOnce({
      ...profile(),
      avatar: savedAvatar,
    });
    const save = await beginSave();
    await act(async () => {
      await save.result;
    });
    expect(mockUpdateProfile.mock.calls[0][0].avatar).toBe(file);
    expect(peekSecureSession().session).toMatchObject({
      user: {avatar: savedAvatar, profile_revision: 3},
    });
    expect(mockRemoveFile).toHaveBeenCalledWith(file);
    expect(mockGoBack).toHaveBeenCalledTimes(1);
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it.each([false, true])(
    'settles a reopened editor through the committed Redux snapshot while preserving a newer draft=%s',
    async edited => {
      await mount();
      await edit('اسم الحفظ القديم');
      const reply = delayRemoteSave();
      const save = await beginSave();
      await act(async () => renderer!.unmount());
      renderer = undefined;
      await mount();
      expect(input().props.value).toBe(initialSession.user.name);
      const nextFile = {
        uri: 'file:///private/reopened-avatar.jpg',
        type: 'image/jpeg',
        fileName: 'new.jpg',
        size: 1200,
      };
      if (edited) {
        await edit('تعديل المحرر الجديد');
        mockPicker.mockResolvedValueOnce({
          assets: [{uri: 'content://gallery/new', fileSize: 1200}],
        });
        mockCacheFile.mockResolvedValueOnce(nextFile);
        await act(async () => {
          renderer!.root
            .findByProps({accessibilityLabel: 'العنوان المهني في البورتفوليو'})
            .props.onChangeText('عنوان جديد');
          await renderer!.root
            .findByProps({accessibilityLabel: 'اختيار صورة الحساب'})
            .props.onPress();
        });
      }
      const canonical = {
        ...profile('اسم معتمد', 3),
        portfolioHeadline: 'عنوان معتمد',
        avatar: 'https://cdn.example.test/old-save-accepted.jpg',
      };
      await act(async () => {
        reply.resolve(canonical);
        await save.result;
      });
      await deliverCommittedReduxSession();
      expect(input().props.value).toBe(
        edited ? 'تعديل المحرر الجديد' : canonical.name,
      );
      expect(
        renderer!.root.findByProps({
          accessibilityLabel: 'العنوان المهني في البورتفوليو',
        }).props.value,
      ).toBe(edited ? 'عنوان جديد' : canonical.portfolioHeadline);
      expect(renderer!.root.findByType(Image).props.source.uri).toBe(
        edited ? nextFile.uri : canonical.avatar,
      );
      expect(mockRemoveFile).not.toHaveBeenCalledWith(nextFile);
      expect(mockGoBack).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
      mockUpdateProfile.mockResolvedValueOnce({
        ...canonical,
        profileRevision: 4,
      });
      const next = await beginSave();
      await act(async () => {
        await next.result;
      });
      expect(mockUpdateProfile.mock.calls[1][0]).toMatchObject({
        name: edited ? 'تعديل المحرر الجديد' : canonical.name,
        portfolioHeadline: edited ? 'عنوان جديد' : canonical.portfolioHeadline,
        avatar: edited ? nextFile : undefined,
        expectedProfileRevision: 3,
      });
      expect(mockGoBack).toHaveBeenCalledTimes(1);
    },
  );

  it('rejects a retained save callback from an earlier foreground visit', async () => {
    await mount();
    const retainedSave = renderer!.root.findByProps({
      accessibilityLabel: 'حفظ التغييرات',
    }).props.onPress;
    await foreground(false);
    await foreground(true);
    await act(async () => {
      await retainedSave();
    });
    expect(mockUpdateProfile).not.toHaveBeenCalled();
    expect(mockDispatch).not.toHaveBeenCalled();
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('ignores a reopened editor hydration overtaken by the preceding save commit', async () => {
    await mount();
    await edit('الاسم المحفوظ');
    const reply = delayRemoteSave();
    const save = await beginSave();
    await act(async () => renderer!.unmount());
    renderer = undefined;
    const oldRead = deferred<ReturnType<typeof profile>>();
    mockGetProfile.mockImplementationOnce(
      async (boundary: AccountSessionBoundary) => {
        const response = await oldRead.promise;
        assertAccountSessionBoundary(boundary);
        return response;
      },
    );
    await mount();
    expect(
      renderer!.root.findAllByProps({accessibilityLabel: 'حفظ التغييرات'}),
    ).toHaveLength(0);
    await act(async () => {
      reply.resolve(profile());
      await save.result;
    });
    await deliverCommittedReduxSession();
    expect(input().props.value).toBe('الاسم المحفوظ');
    await edit('تعديل بعد التسوية');
    await act(async () => {
      oldRead.resolve(profile(initialSession.user.name, 2));
    });
    expect(input().props.value).toBe('تعديل بعد التسوية');
    expect(
      renderer!.root.findByProps({accessibilityLabel: 'حفظ التغييرات'}).props
        .disabled,
    ).toBe(false);
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it.each(['success', 'failure'])(
    'does not replay an overtaken hydration using the current boundary and equal revision outcome=%s',
    async outcome => {
      await mount();
      await edit('الاسم المحفوظ');
      const reply = delayRemoteSave();
      const save = await beginSave();
      await act(async () => renderer!.unmount());
      renderer = undefined;
      const sessionCheck = deferred<boolean>();
      mockHasSession.mockImplementationOnce(() => sessionCheck.promise);
      const read = deferred<ReturnType<typeof profile>>();
      let currentBoundary!: AccountSessionBoundary;
      mockGetProfile.mockImplementationOnce(
        async (boundary: AccountSessionBoundary) => {
          currentBoundary = boundary;
          const response = await read.promise;
          assertAccountSessionBoundary(boundary);
          return response;
        },
      );
      await mount();
      await act(async () => {
        reply.resolve(profile());
        await save.result;
        // The next GET captures the already committed epoch, but Redux has not
        // delivered that snapshot to the reopened editor yet.
        sessionCheck.resolve(true);
      });
      expect(currentBoundary.epoch).toBe(peekSecureSession().epoch);
      await deliverCommittedReduxSession();
      expect(input().props.value).toBe('الاسم المحفوظ');
      await edit('تعديل أحدث من القراءة');
      const file = {
        uri: 'file:///private/after-canonical-commit.jpg',
        type: 'image/jpeg',
        fileName: 'newer.jpg',
        size: 1200,
      };
      mockPicker.mockResolvedValueOnce({
        assets: [{uri: 'content://gallery/newer', fileSize: 1200}],
      });
      mockCacheFile.mockResolvedValueOnce(file);
      await act(async () => {
        renderer!.root
          .findByProps({accessibilityLabel: 'العنوان المهني في البورتفوليو'})
          .props.onChangeText('عنوان أحدث');
        await renderer!.root
          .findByProps({accessibilityLabel: 'اختيار صورة الحساب'})
          .props.onPress();
      });
      await act(async () => {
        if (outcome === 'success') read.resolve(profile());
        else read.reject(new Error('NETWORK_UNAVAILABLE'));
      });
      expect(() => assertAccountSessionBoundary(currentBoundary)).not.toThrow();
      expect(input().props.value).toBe('تعديل أحدث من القراءة');
      expect(
        renderer!.root.findByProps({
          accessibilityLabel: 'العنوان المهني في البورتفوليو',
        }).props.value,
      ).toBe('عنوان أحدث');
      expect(renderer!.root.findByType(Image).props.source.uri).toBe(file.uri);
      expect(mockRemoveFile).not.toHaveBeenCalledWith(file);
      expect(
        renderer!.root.findByProps({accessibilityLabel: 'حفظ التغييرات'}).props
          .disabled,
      ).toBe(false);
      mockUpdateProfile.mockResolvedValueOnce({
        ...profile('تعديل أحدث من القراءة', 4),
        portfolioHeadline: 'عنوان أحدث',
        avatar: 'https://cdn.example.test/newer-persisted.jpg',
      });
      const next = await beginSave();
      await act(async () => {
        await next.result;
      });
      expect(mockUpdateProfile.mock.calls[1][0]).toMatchObject({
        name: 'تعديل أحدث من القراءة',
        portfolioHeadline: 'عنوان أحدث',
        avatar: file,
        expectedProfileRevision: 3,
      });
    },
  );
});
