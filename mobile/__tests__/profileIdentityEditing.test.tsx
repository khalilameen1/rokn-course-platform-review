import React from 'react';
import {Alert, TextInput} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockDispatch = jest.fn();
const mockGoBack = jest.fn();
const mockReplace = jest.fn();
const mockLaunchImageLibrary = jest.fn();
const mockGetProfile = jest.fn();
const mockUpdateProfile = jest.fn();
const mockCacheLearnerDraftFile = jest.fn();
const mockRemoveLearnerDraftFile = jest.fn(
  async (..._args: unknown[]) => undefined,
);
const mockUpdateSecureSessionForOwner = jest.fn();
let mockCachedSession: unknown;

let mockStoredSession: Record<string, unknown> = {
  api_token: 'token-one',
  user: {
    id: 7,
    name: 'اسم Google',
    email: 'learner@example.test',
    profile_image: 'https://cdn.example.test/old.jpg',
    profile_revision: 2,
  },
};

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({goBack: mockGoBack, replace: mockReplace}),
}));
jest.mock('react-redux', () => ({
  useDispatch: () => mockDispatch,
  useSelector: (selector: (state: unknown) => unknown) =>
    selector({auth: {userData: mockStoredSession}}),
}));
jest.mock('react-native-image-picker', () => ({
  launchImageLibrary: (...args: unknown[]) => mockLaunchImageLibrary(...args),
}));
jest.mock('../src/services/roknApi', () => ({
  getProfile: (...args: unknown[]) => mockGetProfile(...args),
  hasSession: jest.fn(async () => true),
  updateProfile: (...args: unknown[]) => mockUpdateProfile(...args),
}));
jest.mock('../src/services/learnerDraftFiles', () => ({
  cacheLearnerDraftFile: (...args: unknown[]) =>
    mockCacheLearnerDraftFile(...args),
  removeLearnerDraftFile: (...args: unknown[]) =>
    mockRemoveLearnerDraftFile(...args),
}));
jest.mock('../src/services/secureSession', () => ({
  peekSecureSession: () => ({
    ready: true,
    session: mockCachedSession,
    epoch: 1,
  }),
  updateSecureSessionForOwner: (...args: unknown[]) =>
    mockUpdateSecureSessionForOwner(...args),
}));
jest.mock('../src/constants/helpers', () => ({
  AsyncKeys: {USER_DATA: 'USER_DATA'},
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({
    epoch: 1,
    scope: 'user-seven',
  })),
  extractApiToken: (value: Record<string, unknown>) => value.api_token,
  extractUserProfile: (value: Record<string, unknown>) => value.user || {},
  getItem: jest.fn(async () => mockStoredSession),
  sessionIdentityKey: () => 'account-seven',
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () => '11111111-1111-4111-8111-111111111111',
}));
jest.mock('../src/services/mediaPickerErrors', () => ({
  showMediaPickerFailure: jest.fn(),
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
  const {Text, View} = require('react-native');
  const Wrapper = ({children}: {children?: React.ReactNode}) =>
    ReactModule.createElement(View, null, children);
  return {
    PremiumCard: Wrapper,
    ResponsiveFrame: Wrapper,
    StatusView: ({title}: {title: string}) =>
      ReactModule.createElement(Text, null, title),
  };
});
jest.mock('../src/components/view/HeaderWithBack', () => () => null);
jest.mock('../src/components/touchables/Button', () => {
  const ReactModule = require('react');
  const {Pressable: RNPressable, Text} = require('react-native');
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
      RNPressable,
      {accessibilityLabel: title, disabled: disable, onPress},
      ReactModule.createElement(Text, null, title),
    );
});
jest.mock('../src/components/ui/DefaultAvatar', () => ({
  DefaultAvatar: () => null,
}));

import EditAccount from '../src/screens/EditAccount';

const profile = (
  name: string,
  avatar: string,
  profileRevision: number,
  portfolioHeadline = 'مصمم منتجات رقمية',
) => ({
  avatar,
  email: 'learner@example.test',
  id: '7',
  jobTitle: '',
  marketingNotificationsEnabled: false,
  name,
  playbackSpeed: 1,
  portfolioHeadline,
  profileRevision,
  videoQualityPreference: 'auto',
  watchHistoryEnabled: true,
});

describe('profile identity editing', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    mockStoredSession = {
      api_token: 'token-one',
      user: {
        id: 7,
        name: 'اسم Google',
        email: 'learner@example.test',
        profile_image: 'https://cdn.example.test/old.jpg',
        profile_revision: 2,
      },
    };
    mockGetProfile.mockResolvedValue(
      profile('اسم Google', 'https://cdn.example.test/old.jpg', 2),
    );
    mockCachedSession = mockStoredSession;
    mockCacheLearnerDraftFile.mockResolvedValue({
      fileName: 'avatar.jpg',
      size: 1200,
      type: 'image/jpeg',
      uri: 'file:///cached/avatar.jpg',
    });
    mockRemoveLearnerDraftFile.mockReset().mockResolvedValue(undefined);
    mockUpdateProfile.mockResolvedValue(
      profile('الاسم الجديد', 'https://cdn.example.test/new.jpg', 3),
    );
    mockUpdateSecureSessionForOwner.mockImplementation(
      async (_owner: string, update: (session: unknown) => unknown) => {
        mockCachedSession = update(mockCachedSession);
        return mockCachedSession;
      },
    );
  });

  it('lets the learner tap the image, edit a social name, and commits the new identity to the session', async () => {
    mockLaunchImageLibrary.mockResolvedValue({
      assets: [
        {
          fileName: 'picked.jpg',
          fileSize: 1200,
          type: 'image/jpeg',
          uri: 'file:///picker/picked.jpg',
        },
      ],
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<EditAccount />);
    });

    const avatar = renderer.root.findByProps({
      accessibilityLabel: 'اختيار صورة الحساب',
    });
    await act(async () => avatar.props.onPress());
    expect(mockLaunchImageLibrary).toHaveBeenCalledTimes(1);
    expect(mockCacheLearnerDraftFile).toHaveBeenCalledWith(
      'avatar',
      expect.objectContaining({uri: 'file:///picker/picked.jpg'}),
      2 * 1024 * 1024,
      expect.anything(),
    );

    const name = renderer.root.findByProps({
      accessibilityLabel: 'الاسم الظاهر',
    }) as TestRenderer.ReactTestInstance;
    expect(name.type).toBe(TextInput);
    await act(async () => name.props.onChangeText('الاسم الجديد'));
    const save = renderer.root.findByProps({
      accessibilityLabel: 'حفظ التغييرات',
    });
    await act(async () => save.props.onPress());

    expect(mockUpdateProfile).toHaveBeenCalledWith(
      expect.objectContaining({
        avatar: expect.objectContaining({uri: 'file:///cached/avatar.jpg'}),
        expectedProfileRevision: 2,
        name: 'الاسم الجديد',
        portfolioHeadline: 'مصمم منتجات رقمية',
      }),
      expect.anything(),
    );
    expect(mockUpdateProfile.mock.calls[0][0]).not.toHaveProperty('jobTitle');
    expect(mockUpdateSecureSessionForOwner).toHaveBeenCalledWith(
      '7',
      expect.any(Function),
    );
    expect(mockDispatch).toHaveBeenCalledWith(
      expect.objectContaining({
        payload: expect.objectContaining({
          user: expect.objectContaining({
            avatar: 'https://cdn.example.test/new.jpg',
            image: 'https://cdn.example.test/new.jpg',
            name: 'الاسم الجديد',
            portfolio_headline: 'مصمم منتجات رقمية',
            profile_image: 'https://cdn.example.test/new.jpg',
            profile_revision: 3,
          }),
        }),
      }),
    );
    expect(mockGoBack).toHaveBeenCalledTimes(1);
    await act(async () => renderer.unmount());
  });

  it('edits the public portfolio headline without writing the private account job title', async () => {
    mockUpdateProfile.mockResolvedValueOnce(
      profile(
        'اسم Google',
        'https://cdn.example.test/old.jpg',
        3,
        'مصمم واجهات وتجارب',
      ),
    );
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<EditAccount />);
    });

    const headline = renderer.root.findByProps({
      accessibilityLabel: 'العنوان المهني في البورتفوليو',
    });
    expect(headline.props.value).toBe('مصمم منتجات رقمية');
    await act(async () =>
      headline.props.onChangeText('  مصمم   واجهات وتجارب  '),
    );
    await act(async () =>
      renderer.root
        .findByProps({accessibilityLabel: 'حفظ التغييرات'})
        .props.onPress(),
    );

    expect(mockUpdateProfile.mock.calls[0][0]).toEqual(
      expect.objectContaining({portfolioHeadline: 'مصمم واجهات وتجارب'}),
    );
    expect(mockUpdateProfile.mock.calls[0][0]).not.toHaveProperty('jobTitle');
    expect(mockDispatch).toHaveBeenCalledWith(
      expect.objectContaining({
        payload: expect.objectContaining({
          user: expect.objectContaining({
            portfolio_headline: 'مصمم واجهات وتجارب',
          }),
        }),
      }),
    );
    await act(async () => renderer.unmount());
  });

  it('leaves the existing image unchanged when the picker is cancelled', async () => {
    mockLaunchImageLibrary.mockResolvedValue({didCancel: true});
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<EditAccount />);
    });

    const avatar = renderer.root.findByProps({
      accessibilityLabel: 'اختيار صورة الحساب',
    });
    await act(async () => avatar.props.onPress());

    expect(mockCacheLearnerDraftFile).not.toHaveBeenCalled();
    expect(mockUpdateProfile).not.toHaveBeenCalled();
    await act(async () => renderer.unmount());
  });

  it('saves the replacement photo without waiting for the discarded selection to be deleted', async () => {
    jest.useFakeTimers();
    const first = {
      uri: 'file:///cached/first.jpg',
      type: 'image/jpeg',
      size: 1200,
    };
    const second = {...first, uri: 'file:///cached/second.jpg'};
    mockLaunchImageLibrary.mockResolvedValue({
      assets: [
        {uri: 'file:///picker/photo.jpg', type: 'image/jpeg', fileSize: 1200},
      ],
    });
    mockCacheLearnerDraftFile
      .mockResolvedValueOnce(first)
      .mockResolvedValueOnce(second);
    mockRemoveLearnerDraftFile.mockImplementation(async file => {
      if ((file as {uri?: string})?.uri === first.uri)
        await new Promise(() => undefined);
    });
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<EditAccount />);
      });
      const pick = () =>
        renderer.root
          .findByProps({accessibilityLabel: 'اختيار صورة الحساب'})
          .props.onPress();
      await act(async () => {
        await pick();
      });
      let secondPickerFinished = false;
      await act(async () => {
        void pick().then(() => {
          secondPickerFinished = true;
        });
      });
      await act(async () => {
        await jest.advanceTimersByTimeAsync(1500);
      });
      expect(secondPickerFinished).toBe(true);
      await act(async () => {
        await renderer.root
          .findByProps({accessibilityLabel: 'حفظ التغييرات'})
          .props.onPress();
      });
      expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
      expect(mockUpdateProfile.mock.calls[0][0].avatar).toEqual(second);
      expect(mockGoBack).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => renderer.unmount());
      jest.useRealTimers();
    }
  });

  it('keeps the same identity write and selected image available after a failed save', async () => {
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    mockLaunchImageLibrary.mockResolvedValue({
      assets: [
        {
          fileName: 'picked.jpg',
          fileSize: 1200,
          type: 'image/jpeg',
          uri: 'file:///picker/picked.jpg',
        },
      ],
    });
    mockUpdateProfile
      .mockRejectedValueOnce(new Error('temporary failure'))
      .mockResolvedValueOnce(
        profile('الاسم الجديد', 'https://cdn.example.test/new.jpg', 3),
      );
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<EditAccount />);
    });
    await act(async () =>
      renderer.root
        .findByProps({accessibilityLabel: 'اختيار صورة الحساب'})
        .props.onPress(),
    );
    await act(async () =>
      renderer.root
        .findByProps({accessibilityLabel: 'الاسم الظاهر'})
        .props.onChangeText('الاسم الجديد'),
    );
    const save = () =>
      renderer.root
        .findByProps({accessibilityLabel: 'حفظ التغييرات'})
        .props.onPress();

    await act(async () => save());
    expect(mockGoBack).not.toHaveBeenCalled();
    expect(mockRemoveLearnerDraftFile).not.toHaveBeenCalledWith(
      expect.objectContaining({uri: 'file:///cached/avatar.jpg'}),
    );
    const firstRequest = mockUpdateProfile.mock.calls[0][0];

    await act(async () => save());
    const retriedRequest = mockUpdateProfile.mock.calls[1][0];
    expect(retriedRequest.clientRequestId).toBe(firstRequest.clientRequestId);
    expect(retriedRequest.avatar).toEqual(firstRequest.avatar);
    expect(mockGoBack).toHaveBeenCalledTimes(1);
    await act(async () => renderer.unmount());
    jest.restoreAllMocks();
  });

  it('locks text during a pending save and retains an editable draft after failure', async () => {
    const alert = jest
      .spyOn(Alert, 'alert')
      .mockImplementation(() => undefined);
    let rejectSave!: (error: Error) => void;
    mockUpdateProfile.mockReturnValueOnce(
      new Promise((_resolve, reject) => {
        rejectSave = reject;
      }),
    );
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<EditAccount />);
    });
    const nameInput = () =>
      renderer.root.findByProps({accessibilityLabel: 'الاسم الظاهر'});
    const headlineInput = () =>
      renderer.root.findByProps({
        accessibilityLabel: 'العنوان المهني في البورتفوليو',
      });
    const save = () =>
      renderer.root
        .findByProps({accessibilityLabel: 'حفظ التغييرات'})
        .props.onPress();

    try {
      await act(async () => {
        nameInput().props.onChangeText('الاسم المكتوب');
        headlineInput().props.onChangeText('مصمم واجهات');
      });
      let saveFlight!: Promise<void>;
      await act(async () => {
        saveFlight = save();
      });

      expect(nameInput().props.editable).toBe(false);
      expect(headlineInput().props.editable).toBe(false);
      expect(mockUpdateProfile.mock.calls[0][0]).toEqual(
        expect.objectContaining({
          name: 'الاسم المكتوب',
          portfolioHeadline: 'مصمم واجهات',
        }),
      );
      expect(mockGoBack).not.toHaveBeenCalled();

      await act(async () => {
        rejectSave(new Error('temporary failure'));
        await saveFlight;
      });
      expect(nameInput().props.editable).toBe(true);
      expect(headlineInput().props.editable).toBe(true);
      expect(nameInput().props.value).toBe('الاسم المكتوب');
      expect(headlineInput().props.value).toBe('مصمم واجهات');
      expect(mockGoBack).not.toHaveBeenCalled();
      expect(mockUpdateSecureSessionForOwner).not.toHaveBeenCalled();

      await act(async () => {
        nameInput().props.onChangeText('الاسم المصحح');
        headlineInput().props.onChangeText('مصمم منتجات');
      });
      mockUpdateProfile.mockResolvedValueOnce(
        profile(
          'الاسم المصحح',
          'https://cdn.example.test/old.jpg',
          3,
          'مصمم منتجات',
        ),
      );
      await act(async () => save());
      expect(mockUpdateProfile.mock.calls[1][0]).toEqual(
        expect.objectContaining({
          name: 'الاسم المصحح',
          portfolioHeadline: 'مصمم منتجات',
        }),
      );
      expect(mockGoBack).toHaveBeenCalledTimes(1);
    } finally {
      await act(async () => renderer.unmount());
      alert.mockRestore();
    }
  });

  it.each(['session cache', 'temporary image cleanup'])(
    'does not leave a confirmed profile save busy while %s is stalled',
    async stage => {
      jest.useFakeTimers();
      const alert = jest
        .spyOn(Alert, 'alert')
        .mockImplementation(() => undefined);
      const confirmed = profile(
        'الاسم المحفوظ',
        'https://cdn.example.test/new.jpg',
        3,
      );
      mockUpdateProfile.mockImplementationOnce(async () => {
        mockGetProfile.mockResolvedValue(confirmed);
        return confirmed;
      });
      if (stage === 'session cache') {
        mockUpdateSecureSessionForOwner.mockReturnValueOnce(
          new Promise(() => undefined),
        );
      } else {
        mockLaunchImageLibrary.mockResolvedValue({
          assets: [
            {
              uri: 'file:///picker/photo.jpg',
              type: 'image/jpeg',
              fileSize: 1200,
            },
          ],
        });
        mockRemoveLearnerDraftFile.mockImplementation(async file => {
          if ((file as {uri?: string})?.uri === 'file:///cached/avatar.jpg') {
            await new Promise(() => undefined);
          }
        });
      }
      let renderer!: TestRenderer.ReactTestRenderer;
      let finished = false;
      try {
        await act(async () => {
          renderer = TestRenderer.create(<EditAccount />);
        });
        if (stage === 'temporary image cleanup') {
          await act(async () => {
            await renderer.root
              .findByProps({accessibilityLabel: 'اختيار صورة الحساب'})
              .props.onPress();
          });
        }
        await act(async () => {
          renderer.root
            .findByProps({accessibilityLabel: 'الاسم الظاهر'})
            .props.onChangeText('الاسم المحفوظ');
        });
        await act(async () => {
          void renderer.root
            .findByProps({accessibilityLabel: 'حفظ التغييرات'})
            .props.onPress()
            .then(() => {
              finished = true;
            });
        });
        expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
        await act(async () => {
          await jest.advanceTimersByTimeAsync(1500);
        });
        expect(finished).toBe(true);
        expect(
          renderer.root.findByProps({accessibilityLabel: 'حفظ التغييرات'}).props
            .disabled,
        ).toBe(false);
        expect(mockUpdateProfile).toHaveBeenCalledTimes(1);
        if (stage === 'session cache') {
          expect(mockGoBack).not.toHaveBeenCalled();
          expect(mockDispatch).not.toHaveBeenCalled();
          expect(
            renderer.root.findByProps({accessibilityLabel: 'الاسم الظاهر'})
              .props.value,
          ).toBe('الاسم المحفوظ');
          expect(alert).toHaveBeenCalledWith(
            'حُفظت التغييرات',
            expect.any(String),
          );
        } else {
          expect(mockGoBack).toHaveBeenCalledTimes(1);
          expect(mockDispatch).toHaveBeenCalledTimes(1);
        }
      } finally {
        await act(async () => renderer.unmount());
        alert.mockRestore();
        jest.useRealTimers();
      }
    },
  );
});
