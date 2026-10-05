import React from 'react';
import {ActivityIndicator, Alert, Image, Text} from 'react-native';
import AsyncStorage from '@react-native-async-storage/async-storage';
import * as SecureStore from 'expo-secure-store';
import * as Notifications from 'expo-notifications';
import RNFS from 'react-native-fs';
import TestRenderer, {act} from 'react-test-renderer';

const mockDispatch = jest.fn();
const mockGoBack = jest.fn();
const mockGetProfile = jest.fn();
const mockUpdateProfile = jest.fn();
const mockPicker = jest.fn();
const mockCacheFile = jest.fn();
const mockRemoveFile = jest.fn(async (_file?: unknown) => undefined);
let mockFocused = true;
let mockStoredSession: typeof initialSession;

jest.mock('@react-navigation/native', () => {
  const core = jest.requireActual('@react-navigation/core');
  return {
    CommonActions: core.CommonActions,
    createNavigationContainerRef: core.createNavigationContainerRef,
    useIsFocused: () => mockFocused,
    useNavigation: () => ({goBack: mockGoBack, replace: jest.fn()}),
  };
});
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
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
  clearAccountLearnerDraftFiles: jest.fn(
    jest.requireActual('../src/services/learnerDraftFiles')
      .clearAccountLearnerDraftFiles,
  ),
  cacheLearnerDraftFile: (...args: unknown[]) => mockCacheFile(...args),
  removeLearnerDraftFile: (file?: unknown) => mockRemoveFile(file),
}));
jest.mock('../src/services/roknApi', () => ({
  getProfile: (...args: unknown[]) => mockGetProfile(...args),
  hasSession: jest.fn(async () => true),
  updateProfile: (...args: unknown[]) => mockUpdateProfile(...args),
}));
jest.mock('react-native-linear-gradient', () => require('react-native').View);
jest.mock('../src/components/containers/Containers', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {Container: Wrapper, Content: Wrapper};
});
jest.mock('../src/components/ui/PremiumUI', () => {
  const ReactModule = require('react');
  const {Text: NativeText, View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {
    ResponsiveFrame: Wrapper,
    StatusView: ({title}: {title: string}) =>
      ReactModule.createElement(NativeText, null, title),
  };
});
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/ui/DefaultAvatar', () => ({
  DefaultAvatar: () => null,
}));

// Exercise the real screen, native-facing controls, Button and session boundary.
// Picker/cache transports are deliberately controllable; no test is run here.
import EditAccount from '../src/screens/EditAccount';
import * as helpers from '../src/constants/helpers';
import {clearAccountLearnerDraftFiles} from '../src/services/learnerDraftFiles';
import {
  resetSecureSessionForTests,
  saveSecureSession,
} from '../src/services/secureSession';

const initialSession = {
  api_token: 'avatar-preparation-token',
  user: {id: 7, name: 'الاسم الأصلي', profile_revision: 2},
};
const previousAvatar = 'https://cdn.example.test/previous.jpg';
const selectedAsset = {
  uri: 'content://photos/selected',
  type: 'image/jpeg',
  fileName: 'selected.jpg',
  fileSize: 1200,
};
const prepared = {
  uri: 'file:///private/avatar-selected.jpg',
  type: 'image/jpeg',
  fileName: 'selected.jpg',
  size: 1200,
};
const profile = (avatar = previousAvatar, revision = 2) => ({
  id: String(mockStoredSession.user.id),
  name: mockStoredSession.user.name,
  email: 'learner@example.test',
  portfolioHeadline: '',
  avatar,
  profileRevision: revision,
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
const secureValues = new Map<string, string>();

describe('account avatar preparation owns its ready-to-save state', () => {
  let renderer: TestRenderer.ReactTestRenderer | undefined;
  const root = () => renderer!.root;
  const pickerButton = () =>
    root().findByProps({accessibilityLabel: 'اختيار صورة الحساب'});
  const changeButton = () =>
    root().findByProps({accessibilityLabel: 'تغيير صورة الحساب'});
  const saveButton = () =>
    root().findByProps({accessibilityLabel: 'حفظ التغييرات'});
  const avatarUri = () => root().findByType(Image).props.source.uri;
  const removedUris = () =>
    mockRemoveFile.mock.calls
      .map(([file]) => (file as {uri?: string} | undefined)?.uri)
      .filter(Boolean);
  const mount = async () => {
    await act(async () => {
      renderer = TestRenderer.create(<EditAccount />);
    });
  };
  const choose = async () => {
    let completion!: Promise<void>;
    await act(async () => {
      completion = pickerButton().props.onPress();
    });
    return {completion};
  };
  const focus = async (value: boolean) => {
    await act(async () => {
      mockFocused = value;
      renderer!.update(<EditAccount />);
    });
  };

  beforeEach(async () => {
    jest.restoreAllMocks();
    jest.clearAllMocks();
    resetSecureSessionForTests();
    await AsyncStorage.clear();
    secureValues.clear();
    (SecureStore.isAvailableAsync as jest.Mock).mockResolvedValue(true);
    (SecureStore.getItemAsync as jest.Mock).mockImplementation(
      async key => secureValues.get(key) ?? null,
    );
    (SecureStore.setItemAsync as jest.Mock).mockImplementation(
      async (key, value) => {
        secureValues.set(key, value);
      },
    );
    (SecureStore.deleteItemAsync as jest.Mock).mockImplementation(async key => {
      secureValues.delete(key);
    });
    mockStoredSession = initialSession;
    mockFocused = true;
    await saveSecureSession(initialSession);
    mockGetProfile.mockReset().mockImplementation(async boundary => {
      helpers.assertAccountSessionBoundary(boundary);
      return profile();
    });
    mockUpdateProfile
      .mockReset()
      .mockImplementation(async (data: {name: string}, boundary) => {
        helpers.assertAccountSessionBoundary(boundary);
        return {
          ...profile('https://cdn.example.test/saved.jpg', 3),
          name: data.name,
        };
      });
    mockPicker.mockReset().mockResolvedValue({assets: [selectedAsset]});
    mockCacheFile.mockReset().mockResolvedValue(prepared);
    mockRemoveFile.mockReset().mockResolvedValue(undefined);
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
  });

  afterEach(async () => {
    await act(async () => renderer?.unmount());
    renderer = undefined;
    jest.restoreAllMocks();
    resetSecureSessionForTests();
  });

  it('locks both image controls and saving through picker and private copy without losing text edits', async () => {
    const native = deferred<{assets: (typeof selectedAsset)[]}>();
    const copy = deferred<typeof prepared>();
    mockPicker.mockReturnValueOnce(native.promise);
    mockCacheFile.mockReturnValueOnce(copy.promise);
    await mount();
    const retainedPicker = pickerButton().props.onPress;
    const retainedSave = saveButton().props.onPress;
    const selection = await choose();

    expect(pickerButton().props.disabled).toBe(true);
    expect(changeButton().props.disabled).toBe(true);
    expect(saveButton().props.disabled).toBe(true);
    expect(pickerButton().props.accessibilityState).toEqual({
      busy: true,
      disabled: true,
    });
    expect(root().findByType(ActivityIndicator)).toBeTruthy();
    expect(
      root()
        .findAllByType(Text)
        .map(node => node.props.children),
    ).toContain('جارٍ تجهيز الصورة');
    await act(async () => {
      await retainedPicker();
      await retainedSave();
      root()
        .findByProps({accessibilityLabel: 'الاسم الظاهر'})
        .props.onChangeText('الاسم أثناء التجهيز');
      native.resolve({assets: [selectedAsset]});
    });
    expect(mockPicker).toHaveBeenCalledTimes(1);
    expect(mockUpdateProfile).not.toHaveBeenCalled();
    expect(saveButton().props.disabled).toBe(true);
    expect(avatarUri()).toBe(previousAvatar);
    expect(mockCacheFile).toHaveBeenCalledWith(
      'avatar',
      {
        uri: selectedAsset.uri,
        type: selectedAsset.type,
        fileName: selectedAsset.fileName,
        size: selectedAsset.fileSize,
      },
      2 * 1024 * 1024,
      expect.objectContaining({scope: expect.any(String)}),
    );
    await act(async () => {
      copy.resolve(prepared);
      await selection.completion;
    });
    expect(avatarUri()).toBe(prepared.uri);
    expect(saveButton().props.disabled).toBe(false);
    expect(root().findAllByType(ActivityIndicator)).toHaveLength(0);
    await act(async () => saveButton().props.onPress());
    expect(mockUpdateProfile).toHaveBeenCalledWith(
      expect.objectContaining({name: 'الاسم أثناء التجهيز', avatar: prepared}),
      expect.any(Object),
    );
    expect(mockGoBack).toHaveBeenCalledTimes(1);
  });

  it('cancels quietly and unlocks without replacing the previous image', async () => {
    mockPicker.mockResolvedValueOnce({didCancel: true});
    await mount();
    const selection = await choose();
    await act(async () => selection.completion);
    expect(avatarUri()).toBe(previousAvatar);
    expect(saveButton().props.disabled).toBe(false);
    expect(mockCacheFile).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it.each(['picker', 'copy', 'permission', 'size'])(
    'unlocks after %s failure and keeps the last prepared selection for retry',
    async failure => {
      await mount();
      const first = await choose();
      await act(async () => first.completion);
      if (failure === 'picker')
        mockPicker.mockRejectedValueOnce(new Error('native picker failed'));
      if (failure === 'copy')
        mockCacheFile.mockRejectedValueOnce(
          new Error('LEARNER_FILE_COPY_FAILED'),
        );
      if (failure === 'permission')
        mockPicker.mockResolvedValueOnce({errorCode: 'permission'});
      if (failure === 'size')
        mockPicker.mockResolvedValueOnce({
          assets: [{...selectedAsset, fileSize: 2 * 1024 * 1024 + 1}],
        });
      const selection = await choose();
      await act(async () => selection.completion);
      expect(avatarUri()).toBe(prepared.uri);
      expect(saveButton().props.disabled).toBe(false);
      expect(pickerButton().props.accessibilityState.busy).toBe(false);
      expect(removedUris()).not.toContain(prepared.uri);
      expect(Alert.alert).toHaveBeenCalledTimes(1);
      await act(async () => saveButton().props.onPress());
      expect(mockUpdateProfile.mock.calls[0][0].avatar).toEqual(prepared);
    },
  );

  it('does not launch the native picker if its boundary capture finishes after departure', async () => {
    await mount();
    const boundary = await helpers.captureAccountSessionBoundary();
    const gate = deferred<typeof boundary>();
    jest
      .spyOn(helpers, 'captureAccountSessionBoundary')
      .mockReturnValueOnce(gate.promise);
    const selection = await choose();
    await focus(false);
    await act(async () => {
      gate.resolve(boundary);
      await selection.completion;
    });
    expect(mockPicker).not.toHaveBeenCalled();
    expect(mockCacheFile).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });

  it('keeps one native flight across leave/return but rejects the previous visit result', async () => {
    const native = deferred<{assets: (typeof selectedAsset)[]}>();
    mockPicker.mockReturnValueOnce(native.promise);
    await mount();
    const oldPicker = pickerButton().props.onPress;
    const selection = await choose();
    await focus(false);
    await focus(true);
    await act(async () => {
      await pickerButton().props.onPress();
      await oldPicker();
      native.resolve({assets: [selectedAsset]});
      await selection.completion;
    });
    expect(mockPicker).toHaveBeenCalledTimes(1);
    expect(mockCacheFile).not.toHaveBeenCalled();
    expect(avatarUri()).toBe(previousAvatar);
    expect(saveButton().props.disabled).toBe(false);
    const next = await choose();
    await act(async () => next.completion);
    expect(mockPicker).toHaveBeenCalledTimes(2);
    expect(avatarUri()).toBe(prepared.uri);
  });

  it.each(['blur', 'account', 'bearer', 'unmount'])(
    'discards a copied file after %s without adopting it or showing a stale error',
    async departure => {
      const copy = deferred<typeof prepared>();
      mockCacheFile.mockReturnValueOnce(copy.promise);
      await mount();
      const selection = await choose();
      expect(mockCacheFile).toHaveBeenCalledTimes(1);
      if (departure === 'blur') {
        await focus(false);
        await focus(true);
      } else if (departure === 'account' || departure === 'bearer') {
        const previousScope = await helpers.getCurrentAccountStorageScope();
        const previousDirectory = `${RNFS.CachesDirectoryPath}/rokn_learner_drafts/${previousScope}`;
        jest
          .spyOn(RNFS, 'exists')
          .mockImplementation(async path => path === previousDirectory);
        await act(async () => {
          mockStoredSession = {
            api_token: 'avatar-other-token',
            user: {
              id: departure === 'account' ? 8 : 7,
              name: 'الحساب الحالي',
              profile_revision: 2,
            },
          };
          await saveSecureSession(mockStoredSession);
          renderer!.update(<EditAccount />);
        });
        if (departure === 'account') {
          expect(
            Notifications.clearLastNotificationResponseAsync,
          ).toHaveBeenCalledTimes(1);
          expect(
            Notifications.dismissAllNotificationsAsync,
          ).toHaveBeenCalledTimes(1);
          expect(Notifications.setBadgeCountAsync).toHaveBeenCalledWith(0);
          expect(Notifications.setBadgeCountAsync).toHaveBeenCalledTimes(1);
          expect(clearAccountLearnerDraftFiles).toHaveBeenCalledTimes(1);
          expect(clearAccountLearnerDraftFiles).toHaveBeenCalledWith(
            previousScope,
          );
          expect(RNFS.unlink).toHaveBeenCalledWith(previousDirectory);
        } else {
          expect(
            Notifications.clearLastNotificationResponseAsync,
          ).not.toHaveBeenCalled();
          expect(
            Notifications.dismissAllNotificationsAsync,
          ).not.toHaveBeenCalled();
          expect(Notifications.setBadgeCountAsync).not.toHaveBeenCalled();
          expect(clearAccountLearnerDraftFiles).not.toHaveBeenCalled();
          expect(RNFS.unlink).not.toHaveBeenCalledWith(previousDirectory);
        }
      } else {
        await act(async () => renderer!.unmount());
        renderer = undefined;
      }
      await act(async () => {
        copy.resolve(prepared);
        await selection.completion;
      });
      expect(removedUris()).toContain(prepared.uri);
      expect(mockUpdateProfile).not.toHaveBeenCalled();
      expect(Alert.alert).not.toHaveBeenCalled();
      if (renderer) {
        expect(avatarUri()).toBe(previousAvatar);
        expect(saveButton().props.disabled).toBe(false);
      }
    },
  );

  it('silences a late preparation error after leaving and allows a new selection', async () => {
    const copy = deferred<typeof prepared>();
    mockCacheFile.mockReturnValueOnce(copy.promise);
    await mount();
    const selection = await choose();
    await focus(false);
    await focus(true);
    await act(async () => {
      copy.reject(new Error('LEARNER_FILE_COPY_FAILED'));
      await selection.completion;
    });
    expect(Alert.alert).not.toHaveBeenCalled();
    expect(saveButton().props.disabled).toBe(false);
    const next = await choose();
    await act(async () => next.completion);
    expect(avatarUri()).toBe(prepared.uri);
  });

  it('transfers the latest file to cleanup before a replacement render can commit', async () => {
    await mount();
    const first = await choose();
    await act(async () => first.completion);
    const copy = deferred<typeof prepared>();
    const replacement = {...prepared, uri: 'file:///private/replacement.jpg'};
    mockCacheFile.mockReturnValueOnce(copy.promise);
    const selection = await choose();
    await act(async () => {
      copy.resolve(replacement);
      await selection.completion;
      renderer!.unmount();
    });
    renderer = undefined;
    expect(removedUris()).toContain(prepared.uri);
    expect(removedUris()).toContain(replacement.uri);
    expect(mockUpdateProfile).not.toHaveBeenCalled();
  });

  it('freezes the adopted file for an accepted save even before its replacement render commits', async () => {
    await mount();
    const first = await choose();
    await act(async () => first.completion);
    const retainedSave = saveButton().props.onPress;
    const copy = deferred<typeof prepared>();
    const replacement = {...prepared, uri: 'file:///private/replacement.jpg'};
    mockCacheFile.mockReturnValueOnce(copy.promise);
    const selection = await choose();
    await act(async () => {
      copy.resolve(replacement);
      await selection.completion;
      await retainedSave();
    });
    expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
    expect(mockUpdateProfile.mock.calls[0][0].avatar).toEqual(replacement);
    expect(removedUris()).toContain(prepared.uri);
    expect(removedUris()).toContain(replacement.uri);
    expect(mockGoBack).toHaveBeenCalledTimes(1);
  });
});
