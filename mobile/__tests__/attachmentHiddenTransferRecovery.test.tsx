import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  Alert,
  AppState,
  Modal,
  Platform,
  type AppStateStatus,
} from 'react-native';
import RNFS from 'react-native-fs';
import Share from 'react-native-share';

jest.mock('@gorhom/bottom-sheet', () => {
  const ReactModule = require('react');
  const {Pressable, View} = require('react-native');
  return {
    BottomSheetBackdrop: View,
    BottomSheetScrollView: View,
    BottomSheetModal: ReactModule.forwardRef(
      (
        {
          children,
          onDismiss,
        }: {children: React.ReactNode; onDismiss?: () => void},
        ref: unknown,
      ) => {
        const [visible, setVisible] = ReactModule.useState(false);
        const dismiss = () => {
          setVisible(false);
          onDismiss?.();
        };
        ReactModule.useImperativeHandle(ref, () => ({
          present: () => setVisible(true),
          dismiss,
        }));
        return visible ? (
          <View>
            <Pressable
              accessibilityLabel="إغلاق نافذة المرفقات"
              onPress={dismiss}
            />
            {children}
          </View>
        ) : null;
      },
    ),
  };
});
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => false,
}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/CourseIndexModule',
  () => () => null,
);
jest.mock('../src/components/VideoPlayer/feedSideBar/FeedActions', () => {
  const {Pressable} = require('react-native');
  return {
    __esModule: true,
    AttachmentIcon: () => null,
    default: ({onOpenAttachments}: {onOpenAttachments: () => void}) => (
      <Pressable
        accessibilityLabel="افتح المرفقات"
        onPress={onOpenAttachments}
      />
    ),
  };
});
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/useAttachmentPrompt',
  () => ({
    useAttachmentPrompt: ({
      course,
      present,
    }: {
      course: {attachments: unknown[]};
      present: () => void;
    }) => ({
      attachments: course.attachments,
      markAttachmentsVisible: jest.fn(),
      openAttachments: present,
    }),
  }),
);
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/useSavedFolderPicker',
  () => ({
    useSavedFolderPicker: () => ({
      close: jest.fn(),
      createAndSave: jest.fn(),
      creating: false,
      error: '',
      folders: [],
      loading: false,
      name: '',
      open: jest.fn(),
      saveInFolder: jest.fn(),
      saveInWatchLater: jest.fn(),
      setName: jest.fn(),
    }),
  }),
);
jest.mock('../src/components/ui/CopyIcon', () => ({CopyIcon: () => null}));
jest.mock('react-native-share', () => ({open: jest.fn()}));
jest.mock('@react-native-clipboard/clipboard', () => ({setString: jest.fn()}));
jest.mock('../src/components/VideoPlayer/courseLearning/mapping', () => ({
  loadCourseLearningData: jest.fn(),
}));
jest.mock('../src/constants/api', () => ({publicRequest: {get: jest.fn()}}));
jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({navigate: jest.fn()}),
  useIsFocused: () => true,
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(effect, [effect]);
  },
}));
jest.mock('react-redux', () => ({
  useSelector: () => ({user: {id: 7, name: 'طالب'}}),
}));
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppForegroundState: () => true,
}));
jest.mock('../src/components/FullTrackUpgradeSheet', () => () => null);
jest.mock('../src/components/ui/QRCode', () => () => null);
jest.mock('react-native-linear-gradient', () => require('react-native').View);
jest.mock(
  '../src/screens/Profile/certificates/CertificateArtifactPreview',
  () => ({CertificateArtifactPreview: () => null}),
);
jest.mock('../src/services/systemActions', () => ({}));
jest.mock('../src/services/roknApi', () => ({
  hasSession: async () => true,
  getCachedCertificates: async () => [],
  getLearningCourses: async () => [],
  getCertificates: async () => [
    {
      publicId: 'credential-1',
      courseId: '10',
      courseName: 'الكورس',
      status: 'ready',
      certificateUrl: 'https://example.com/certificate.png',
      certificatePdfUrl: 'https://example.com/certificate.pdf',
    },
  ],
}));
let mockOwner = {scope: 'user-a', epoch: 1};
let mockAttempt = 0;
jest.mock('../src/constants/helpers', () => ({
  captureAccountSessionBoundary: async () => ({...mockOwner}),
  assertAccountSessionBoundary: (owner: typeof mockOwner) => {
    if (owner.scope !== mockOwner.scope || owner.epoch !== mockOwner.epoch)
      throw new Error('ACCOUNT_CHANGED_DURING_REQUEST');
  },
  extractUserProfile: () => ({id: 7, name: 'طالب'}),
  sessionIdentityKey: () => mockOwner.scope,
}));
jest.mock('../src/utils/secureRandom', () => ({
  secureRandomUuid: () =>
    `11111111-1111-4111-8111-${String(++mockAttempt).padStart(12, '0')}`,
}));

import FeedSideBar from '../src/components/VideoPlayer/FeedSideBar';
import Certificates from '../src/screens/Profile/Certificates';
import {AttachmentDownloadNoticeHost} from '../src/components/VideoPlayer/AttachmentDownloadNoticeHost';
import {quiescePrivateAttachmentDownloads} from '../src/components/VideoPlayer/attachmentActions';
import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';

const course: CourseLearningData = {
  id: '10',
  title: 'الكورس',
  totalReels: 2,
  modules: [],
  attachments: [
    {
      id: '1',
      courseId: '10',
      title: 'ملف الهاتف',
      url: 'https://example.com/file.pdf',
      platform: 'mobile',
      temporary: true,
      expiresAt: '2099-01-01T00:00:00Z',
      fileName: 'file.pdf',
      fileSizeBytes: 10,
      downloadVersion: 'v1',
    },
  ],
};
const reel: CourseReel = {
  id: '1',
  lessonId: '1',
  sectionId: '1',
  moduleId: '1',
  title: 'المقطع',
  caption: '',
  videoUrl: 'https://example.com/video',
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};
const flush = async () => {
  for (let i = 0; i < 40; i += 1) await Promise.resolve();
};
const deferred = <T,>() => {
  let resolve!: (value: T) => void;
  let reject!: (error: Error) => void;
  const promise = new Promise<T>((accept, fail) => {
    resolve = accept;
    reject = fail;
  });
  return {promise, resolve, reject};
};
type TransferReceipt = Awaited<ReturnType<typeof RNFS.downloadFile>['promise']>;
type SaveReceipt = Awaited<ReturnType<typeof Share.open>>;

// Real sidebar, notice host, download action and save coordinator. Only native
// file/presentation receipts and unrelated sidebar features use controlled seams.
// Modal onShow/onDismiss are explicit contract events, not UIKit execution.
describe('return to a hidden iOS attachment transfer', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const transfers: ReturnType<typeof deferred<TransferReceipt>>[] = [];
  const saves: ReturnType<typeof deferred<SaveReceipt>>[] = [];
  const stateListeners = new Set<(state: AppStateStatus) => void>();
  const changeAppState = (state: AppStateStatus) => {
    AppState.currentState = state;
    [...stateListeners].forEach(listener => listener(state));
  };
  const render = (visit: number, selectedCourse = course) => (
    <>
      <AttachmentDownloadNoticeHost />
      <FeedSideBar
        key={visit}
        course={selectedCourse}
        currentReel={reel}
        currentFeedKey={`reel-${visit}`}
        isSaved={false}
        savePending={false}
        onToggleSave={jest.fn()}
        onBeforeOpenSave={() => true}
        onOpenChat={jest.fn()}
        onSelectFeedItem={jest.fn()}
        currentTime={0}
      />
    </>
  );
  const button = (label: string) =>
    renderer.root.findAll(
      node =>
        node.props?.accessibilityLabel === label &&
        typeof node.props.onPress === 'function',
    )[0];
  const press = async (label: string) => {
    await act(async () => {
      const target = button(label);
      if (!target.props.disabled) target.props.onPress();
      await flush();
    });
  };
  const visibleCancellation = (label = 'إلغاء تنزيل ملف الهاتف') =>
    renderer.root.findAll(node => {
      if (
        node.props?.accessibilityLabel !== label ||
        typeof node.props.onPress !== 'function' ||
        node.props.disabled
      )
        return false;
      for (let parent = node.parent; parent; parent = parent.parent) {
        if (parent.type === Modal && !parent.props.visible) return false;
      }
      return true;
    });
  const progressModal = () =>
    renderer.root
      .findAllByType(Modal)
      .find(node => typeof node.props.onShow === 'function')!;
  const finishTransfer = async (index: number) => {
    await act(async () => {
      transfers[index].resolve({
        jobId: 71 + index,
        statusCode: 200,
        bytesWritten: 10,
      });
      await flush();
    });
  };
  const hideProgress = () => {
    act(() => progressModal().props.onShow());
    act(() => progressModal().props.onRequestClose());
    act(() => progressModal().props.onDismiss());
  };

  beforeEach(async () => {
    RNFS.cancelDownload = jest.fn();
    jest.replaceProperty(Platform, 'OS', 'ios');
    AppState.currentState = 'active';
    mockOwner = {scope: 'user-a', epoch: 1};
    mockAttempt = 0;
    stateListeners.clear();
    jest
      .spyOn(AppState, 'addEventListener')
      .mockImplementation((event, listener) => {
        if (event !== 'change')
          throw new Error('Unexpected AppState subscription');
        stateListeners.add(listener);
        return {remove: () => stateListeners.delete(listener)};
      });
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    jest.mocked(RNFS.exists).mockResolvedValue(false);
    jest.mocked(RNFS.readDir).mockResolvedValue([]);
    jest
      .mocked(RNFS.stat)
      .mockResolvedValue({size: 10} as Awaited<ReturnType<typeof RNFS.stat>>);
    jest
      .mocked(RNFS.getFSInfo)
      .mockResolvedValue({freeSpace: 1024 ** 3, totalSpace: 2 * 1024 ** 3});
    await quiescePrivateAttachmentDownloads();
    jest.clearAllMocks();
    transfers.length = 0;
    saves.length = 0;
    jest.mocked(RNFS.downloadFile).mockImplementation(() => {
      const transfer = deferred<TransferReceipt>();
      transfers.push(transfer);
      return {jobId: 70 + transfers.length, promise: transfer.promise};
    });
    jest.mocked(Share.open).mockImplementation(() => {
      const save = deferred<SaveReceipt>();
      saves.push(save);
      return save.promise;
    });
    await act(async () => {
      renderer = TestRenderer.create(render(1));
    });
  });
  afterEach(async () => {
    await act(async () => {
      renderer.unmount();
      await quiescePrivateAttachmentDownloads();
      for (let i = 0; i < 3; i += 1) {
        saves.forEach(save => save.resolve({success: true, message: 'saved'}));
        await flush();
      }
      await flush();
    });
    expect(stateListeners.size).toBe(0);
    jest.restoreAllMocks();
  });

  it.each(['same reel', 'another reel'] as const)(
    'keeps cancellation reachable after Hide and return from %s without another transfer',
    async returningFrom => {
      await press('افتح المرفقات');
      await press('تنزيل ملف الهاتف');
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
      act(() => progressModal().props.onShow());
      expect(visibleCancellation().length).toBeGreaterThan(0);
      act(() => progressModal().props.onRequestClose());
      act(() => progressModal().props.onDismiss());
      if (returningFrom === 'another reel') {
        await act(async () => {
          renderer.update(render(2));
        });
      } else {
        await press('إغلاق نافذة المرفقات');
      }
      await press('افتح المرفقات');
      if (button('تنزيل ملف الهاتف')) await press('تنزيل ملف الهاتف');
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
      expect(Share.open).not.toHaveBeenCalled();
      expect(visibleCancellation().length).toBeGreaterThan(0);
      expect(progressModal().props.visible).toBe(false);
      const cancel = visibleCancellation()[0].props.onPress;
      await act(async () => {
        cancel();
        cancel();
        await flush();
      });
      expect(RNFS.cancelDownload).toHaveBeenCalledTimes(1);
      expect(RNFS.cancelDownload).toHaveBeenCalledWith(71);
      expect(Share.open).not.toHaveBeenCalled();
      expect(button('تنزيل ملف الهاتف').props.disabled).toBe(false);
      await press('تنزيل ملف الهاتف');
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
      act(() => cancel());
      expect(RNFS.cancelDownload).toHaveBeenCalledTimes(1);
    },
  );

  it.each(['details reopen', 'screen remount'])(
    'keeps certificate cancellation reachable after Hide and %s',
    async returningFrom => {
      await act(async () => {
        renderer.update(
          <>
            <AttachmentDownloadNoticeHost />
            <Certificates />
          </>,
        );
        await flush();
      });
      await press('عرض شهادة الكورس');
      await press('حفظ الشهادة');
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
      act(() => progressModal().props.onShow());
      expect(
        visibleCancellation('إلغاء تنزيل شهادة الكورس').length,
      ).toBeGreaterThan(0);
      act(() => progressModal().props.onRequestClose());
      act(() => progressModal().props.onDismiss());
      if (returningFrom === 'details reopen') await press('إغلاق');
      else
        await act(async () => {
          renderer.update(
            <>
              <AttachmentDownloadNoticeHost />
              <Certificates key="return" />
            </>,
          );
          await flush();
        });
      await press('عرض شهادة الكورس');
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(1);
      expect(Share.open).not.toHaveBeenCalled();
      expect(
        visibleCancellation('إلغاء تنزيل شهادة الكورس').length,
      ).toBeGreaterThan(0);
      const cancel = visibleCancellation('إلغاء تنزيل شهادة الكورس')[0].props
        .onPress;
      await act(async () => {
        cancel();
        cancel();
        await flush();
      });
      expect(RNFS.cancelDownload).toHaveBeenCalledTimes(1);
      expect(RNFS.cancelDownload).toHaveBeenCalledWith(71);
      await press('حفظ الشهادة');
      expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
      act(() => cancel());
      expect(RNFS.cancelDownload).toHaveBeenCalledTimes(1);
    },
  );

  it('retires transfer cancellation before modal dismissal and preserves the serialized save barrier', async () => {
    const second = {
      ...course.attachments[0],
      id: '2',
      title: 'ملف ثان',
      url: 'https://example.com/second.pdf',
    };
    await act(async () => {
      renderer.update(
        render(1, {...course, attachments: [...course.attachments, second]}),
      );
    });
    await press('افتح المرفقات');
    await press('تنزيل ملف الهاتف');
    act(() => progressModal().props.onShow());
    const oldCancel = visibleCancellation()[0].props.onPress;
    await press('تنزيل ملف ثان');
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
    await finishTransfer(0);
    expect(visibleCancellation()).toHaveLength(0);
    expect(visibleCancellation('إلغاء تنزيل ملف ثان').length).toBeGreaterThan(
      0,
    );
    act(() => oldCancel());
    expect(RNFS.cancelDownload).not.toHaveBeenCalled();
    expect(Share.open).not.toHaveBeenCalled();
    expect(progressModal().props.visible).toBe(false);
    await act(async () => {
      progressModal().props.onDismiss();
      await flush();
    });
    expect(Share.open).toHaveBeenCalledTimes(1);
    await finishTransfer(1);
    expect(visibleCancellation('إلغاء تنزيل ملف ثان')).toHaveLength(0);
    expect(Share.open).toHaveBeenCalledTimes(1);
    await act(async () => {
      saves[0].resolve({success: true, message: 'saved'});
      await flush();
    });
    expect(Share.open).toHaveBeenCalledTimes(2);
    await act(async () => {
      saves[1].resolve({success: true, message: 'saved'});
      await flush();
    });
    expect(button('تنزيل ملف الهاتف').props.disabled).toBe(false);
    expect(button('تنزيل ملف ثان').props.disabled).toBe(false);
  });

  it('retires failed transfer cancellation before its error presentation and permits retry', async () => {
    await press('افتح المرفقات');
    await press('تنزيل ملف الهاتف');
    act(() => progressModal().props.onShow());
    const oldCancel = visibleCancellation()[0].props.onPress;
    await act(async () => {
      transfers[0].reject(new Error('network disconnected'));
      await flush();
    });
    expect(visibleCancellation()).toHaveLength(0);
    act(() => oldCancel());
    expect(RNFS.cancelDownload).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
    await act(async () => {
      progressModal().props.onDismiss();
      await flush();
    });
    expect(Alert.alert).toHaveBeenCalledWith(
      'تعذّر تنزيل الملف',
      expect.any(String),
    );
    await press('تنزيل ملف الهاتف');
    expect(RNFS.downloadFile).toHaveBeenCalledTimes(2);
  });

  it('cannot route an old account cancel to the same attachment under a new account', async () => {
    await press('افتح المرفقات');
    await press('تنزيل ملف الهاتف');
    hideProgress();
    const oldCancel = visibleCancellation()[0].props.onPress;
    mockOwner = {scope: 'user-b', epoch: 2};
    act(() => oldCancel());
    expect(RNFS.cancelDownload).not.toHaveBeenCalled();
    await act(async () => {
      await quiescePrivateAttachmentDownloads();
      await flush();
      renderer.update(render(2));
    });
    expect(RNFS.cancelDownload).toHaveBeenCalledWith(71);
    await press('افتح المرفقات');
    await press('تنزيل ملف الهاتف');
    act(() => oldCancel());
    expect(RNFS.cancelDownload).toHaveBeenCalledTimes(1);
    hideProgress();
    await act(async () => {
      visibleCancellation()[0].props.onPress();
      await flush();
    });
    expect(RNFS.cancelDownload).toHaveBeenCalledTimes(2);
    expect(RNFS.cancelDownload).toHaveBeenLastCalledWith(72);
    expect(Share.open).not.toHaveBeenCalled();
  });

  it('still aborts a finished transfer waiting for foreground when the root host retires', async () => {
    await press('افتح المرفقات');
    await press('تنزيل ملف الهاتف');
    hideProgress();
    act(() => changeAppState('background'));
    await finishTransfer(0);
    expect(visibleCancellation()).toHaveLength(0);
    expect(Share.open).not.toHaveBeenCalled();
    expect(stateListeners.size).toBe(1);
    await act(async () => {
      renderer.unmount();
      await flush();
    });
    expect(stateListeners.size).toBe(0);
    await act(async () => {
      changeAppState('active');
      await flush();
    });
    expect(Share.open).not.toHaveBeenCalled();
    expect(Alert.alert).not.toHaveBeenCalled();
  });
});
