import React from 'react';
import {ActivityIndicator, Alert} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockRetryFolders = jest.fn();
let mockFolderReadError = '';
let mockFolderLoading = false;
let mockFolderCreating = false;

jest.mock('@react-navigation/native', () => ({
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(effect, [effect]);
  },
}));

jest.mock('@gorhom/bottom-sheet', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  return {
    BottomSheetBackdrop: View,
    BottomSheetScrollView: View,
    BottomSheetModal: ReactModule.forwardRef(
      ({children}: {children: React.ReactNode}, _ref: unknown) => (
        <View>{children}</View>
      ),
    ),
  };
});
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => false,
}));
jest.mock('../src/components/VideoPlayer/feedSideBar/FeedActions', () => ({
  __esModule: true,
  default: () => null,
  AttachmentIcon: () => null,
}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/CourseIndexModule',
  () => () => null,
);
jest.mock('../src/components/ui/CopyIcon', () => ({CopyIcon: () => null}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/useAttachmentPrompt',
  () => ({
    useAttachmentPrompt: ({course}: {course: {attachments: unknown[]}}) => ({
      attachments: course.attachments,
      markAttachmentsVisible: jest.fn(),
      openAttachments: jest.fn(),
    }),
  }),
);
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/useSavedFolderPicker',
  () => ({
    useSavedFolderPicker: () => ({
      createAndSave: jest.fn(),
      creating: mockFolderCreating,
      error: '',
      loadError: mockFolderReadError,
      folders: [],
      loading: mockFolderLoading,
      retryFolders: mockRetryFolders,
      name: '',
      open: jest.fn(),
      saveInFolder: jest.fn(),
      saveInWatchLater: jest.fn(),
      setName: jest.fn(),
    }),
  }),
);
jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({
  openCourseAttachment: jest.fn(),
}));

import FeedSideBar from '../src/components/VideoPlayer/FeedSideBar';
import {openCourseAttachment} from '../src/components/VideoPlayer/attachmentActions';
import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';

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
const course: CourseLearningData = {
  id: '10',
  title: 'الكورس',
  totalReels: 1,
  modules: [],
  attachments: [
    {
      id: '1',
      title: 'ملف الهاتف',
      url: 'https://example.com/file.pdf',
      platform: 'mobile',
    },
    {
      id: '2',
      title: 'ملف الكمبيوتر',
      url: 'https://example.com/file.zip',
      platform: 'computer',
    },
  ],
};
const deferred = () => {
  let resolve!: (result: {copied: boolean; downloaded: boolean}) => void;
  let reject!: (reason: Error) => void;
  const promise = new Promise<{copied: boolean; downloaded: boolean}>(
    (done, fail) => {
      resolve = done;
      reject = fail;
    },
  );
  return {promise, resolve, reject};
};
const emptyResult = {copied: false, downloaded: false};

describe('attachment row preparation feedback', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  const onOverlayVisibilityChange = jest.fn();
  const render = (selectedCourse = course) => (
    <FeedSideBar
      course={selectedCourse}
      currentReel={reel}
      currentFeedKey="1"
      isSaved={false}
      savePending={false}
      onToggleSave={jest.fn()}
      onBeforeOpenSave={() => true}
      onOpenChat={jest.fn()}
      onOverlayVisibilityChange={onOverlayVisibilityChange}
      onSelectFeedItem={jest.fn()}
      currentTime={0}
    />
  );
  const row = (title: string) =>
    renderer.root.findAll(
      node =>
        typeof node.props.onPress === 'function' &&
        node.props.accessibilityLabel?.endsWith(title),
    )[0];

  beforeEach(async () => {
    jest.clearAllMocks();
    mockFolderReadError = '';
    mockFolderLoading = false;
    mockFolderCreating = false;
    jest.spyOn(Alert, 'alert').mockImplementation(() => undefined);
    await act(async () => {
      renderer = TestRenderer.create(render());
    });
  });
  afterEach(async () => {
    await act(async () => {
      renderer.unmount();
    });
    jest.restoreAllMocks();
  });

  it('immediately marks only the pressed row busy and permits independent actions', async () => {
    const phone = deferred();
    const computer = deferred();
    jest
      .mocked(openCourseAttachment)
      .mockReturnValueOnce(phone.promise)
      .mockReturnValueOnce(computer.promise);
    await act(async () => {
      row('ملف الهاتف').props.onPress();
      row('ملف الهاتف').props.onPress();
    });
    expect(openCourseAttachment).toHaveBeenCalledTimes(1);
    expect(row('ملف الهاتف').props.accessibilityState).toEqual({
      busy: true,
      disabled: true,
    });
    expect(row('ملف الهاتف').props.disabled).toBe(true);
    expect(row('ملف الهاتف').findAllByType(ActivityIndicator)).toHaveLength(1);
    expect(row('ملف الكمبيوتر').props.disabled).toBe(false);
    expect(onOverlayVisibilityChange).not.toHaveBeenCalled();

    await act(async () => {
      row('ملف الكمبيوتر').props.onPress();
    });
    expect(openCourseAttachment).toHaveBeenCalledTimes(2);
    await act(async () => {
      phone.resolve({copied: false, downloaded: true});
    });
    expect(row('ملف الهاتف').props.accessibilityState.busy).toBe(false);
    expect(row('ملف الكمبيوتر').props.accessibilityState.busy).toBe(true);
    await act(async () => {
      computer.resolve({copied: true, downloaded: false});
    });
    expect(row('ملف الكمبيوتر').findAllByType(ActivityIndicator)).toHaveLength(
      0,
    );
    expect(row('ملف الكمبيوتر').props.disabled).toBe(false);
  });

  it('shows one read retry inside the picker and disables it during reload or creation', async () => {
    mockFolderReadError = 'تعذّر تحميل قوائمك';
    await act(async () => renderer.update(render()));
    const retry = () =>
      renderer.root.findAll(
        node =>
          typeof node.props.onPress === 'function' &&
          node.props.accessibilityLabel === 'إعادة تحميل قوائم الحفظ',
      );
    expect(retry()).toHaveLength(1);
    expect(retry()[0].props.disabled).toBe(false);
    act(() => retry()[0].props.onPress());
    expect(mockRetryFolders).toHaveBeenCalledTimes(1);
    mockFolderLoading = true;
    await act(async () => renderer.update(render()));
    expect(retry()[0].props.accessibilityState).toEqual({
      busy: true,
      disabled: true,
    });
    expect(
      retry()[0].findAll(node => node.props.children === 'جارٍ التحديث').length,
    ).toBeGreaterThan(0);
    mockFolderLoading = false;
    mockFolderCreating = true;
    await act(async () => renderer.update(render()));
    expect(retry()[0].props.disabled).toBe(true);
    mockFolderReadError = '';
    await act(async () => renderer.update(render()));
    expect(retry()).toHaveLength(0);
  });

  it.each(['cancel', 'error'])(
    'restores the row after %s without another dialog',
    async outcome => {
      const operation = deferred();
      jest
        .mocked(openCourseAttachment)
        .mockReturnValueOnce(operation.promise)
        .mockResolvedValue(emptyResult);
      await act(async () => {
        row('ملف الهاتف').props.onPress();
      });
      await act(async () => {
        if (outcome === 'error') operation.reject(new Error('failed'));
        else operation.resolve(emptyResult);
      });
      expect(row('ملف الهاتف').props.accessibilityState).toEqual({
        busy: false,
        disabled: false,
      });
      expect(row('ملف الهاتف').findAllByType(ActivityIndicator)).toHaveLength(
        0,
      );
      expect(Alert.alert).not.toHaveBeenCalled();
      await act(async () => {
        row('ملف الهاتف').props.onPress();
      });
      expect(openCourseAttachment).toHaveBeenCalledTimes(2);
    },
  );

  it('does not mark another course busy when its attachment has the same id', async () => {
    const operation = deferred();
    jest.mocked(openCourseAttachment).mockReturnValueOnce(operation.promise);
    await act(async () => {
      row('ملف الهاتف').props.onPress();
    });
    await act(async () => {
      renderer.update(render({...course, id: '20'}));
    });
    expect(row('ملف الهاتف').props.disabled).toBe(false);
    await act(async () => {
      operation.resolve(emptyResult);
    });
    expect(row('ملف الهاتف').props.disabled).toBe(false);
  });
});
