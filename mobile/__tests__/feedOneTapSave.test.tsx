import React from 'react';
import {AccessibilityInfo, Text} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

const mockOpenSheet = jest.fn();
let mockReducedMotion = false;

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
  useReducedMotion: () => mockReducedMotion,
}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/CourseIndexModule',
  () => () => null,
);
jest.mock('../src/components/ui/CopyIcon', () => ({CopyIcon: () => null}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/useAttachmentPrompt',
  () => ({
    useAttachmentPrompt: () => ({
      attachments: [],
      markAttachmentsVisible: jest.fn(),
      openAttachments: jest.fn(),
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
      loadError: '',
      folders: [{id: '8', name: 'أفكار تصميم'}],
      loading: false,
      retryFolders: jest.fn(),
      name: '',
      open: mockOpenSheet,
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
import {
  ActionSnackbar,
  SNACKBAR_DURATION_MS,
} from '../src/components/ui/ActionSnackbar';
import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';

const reel: CourseReel = {
  id: '1',
  lessonId: '44',
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
  attachments: [],
};

const deferred = () => {
  let resolve!: (value: boolean) => void;
  const promise = new Promise<boolean>(done => {
    resolve = done;
  });
  return {promise, resolve};
};

describe('one-tap save from the player', () => {
  let renderer: TestRenderer.ReactTestRenderer;
  let onToggleSave: jest.Mock<Promise<boolean>, unknown[]>;
  let onBeforeOpenSave: jest.Mock<boolean, []>;

  const element = (isSaved: boolean, feedKey = '1') => (
    <FeedSideBar
      course={course}
      currentReel={reel}
      currentFeedKey={feedKey}
      isSaved={isSaved}
      savePending={false}
      onToggleSave={onToggleSave}
      onBeforeOpenSave={onBeforeOpenSave}
      onOpenChat={jest.fn()}
      onSelectFeedItem={jest.fn()}
      currentTime={0}
    />
  );
  const mount = async (isSaved = false) => {
    await act(async () => {
      renderer = TestRenderer.create(element(isSaved));
    });
  };
  const button = (label: string) =>
    renderer.root.findAll(
      node =>
        node.props.accessibilityLabel === label &&
        typeof node.props.onPress === 'function',
    )[0];
  const bars = () => renderer.root.findAllByType(ActionSnackbar);
  const hasText = (text: string) =>
    renderer.root.findAllByType(Text).some(node => node.props.children === text);

  beforeEach(() => {
    jest.clearAllMocks();
    mockReducedMotion = false;
    onToggleSave = jest.fn(async () => true);
    onBeforeOpenSave = jest.fn(() => true);
  });
  afterEach(async () => {
    await act(async () => renderer.unmount());
    jest.useRealTimers();
  });

  it('saves straight to watch later and confirms with a change action', async () => {
    const save = deferred();
    onToggleSave.mockReturnValueOnce(save.promise);
    await mount();

    await act(async () => button('احفظ').props.onPress());
    expect(onBeforeOpenSave).toHaveBeenCalledTimes(1);
    // No folder: the existing default command resolves or creates the list.
    expect(onToggleSave).toHaveBeenCalledWith(undefined);
    expect(mockOpenSheet).not.toHaveBeenCalled();
    // Nothing is claimed before the server confirms.
    expect(bars()).toHaveLength(0);

    await act(async () => {
      save.resolve(true);
      renderer.update(element(true));
    });
    expect(bars()).toHaveLength(1);
    expect(bars()[0].props.message).toBe('اتحفظ في المشاهدة لاحقًا');
    expect(hasText('اتحفظ في المشاهدة لاحقًا')).toBe(true);
    expect(hasText('غيّر')).toBe(true);
    expect(
      renderer.root.findAll(
        node =>
          typeof node.type === 'string' &&
          node.props.accessibilityLiveRegion === 'polite',
      ).length,
    ).toBeGreaterThan(0);
    expect(AccessibilityInfo.announceForAccessibility).toHaveBeenCalledWith(
      'اتحفظ في المشاهدة لاحقًا',
    );
  });

  it('opens the lists from غيّر and closes the bar', async () => {
    await mount();
    await act(async () => button('احفظ').props.onPress());
    await act(async () => renderer.update(element(true)));
    expect(bars()).toHaveLength(1);

    await act(async () => button('غيّر').props.onPress());
    expect(mockOpenSheet).toHaveBeenCalledTimes(1);
    expect(bars()).toHaveLength(0);
    // The default list is marked as already holding the lesson.
    expect(hasText('اتحفظ')).toBe(true);
    expect(hasText('ضيفه')).toBe(true);
  });

  it('opens the lists when the lesson is already saved', async () => {
    await mount(true);
    await act(async () => button('محفوظ').props.onPress());
    expect(mockOpenSheet).toHaveBeenCalledTimes(1);
    expect(onToggleSave).not.toHaveBeenCalled();
    expect(bars()).toHaveLength(0);
  });

  it('opens the lists on a long press before saving', async () => {
    await mount();
    await act(async () => button('احفظ').props.onLongPress());
    expect(mockOpenSheet).toHaveBeenCalledTimes(1);
    expect(onToggleSave).not.toHaveBeenCalled();
  });

  it('shows no success bar when the save fails', async () => {
    onToggleSave.mockResolvedValueOnce(false);
    await mount();
    await act(async () => button('احفظ').props.onPress());
    expect(onToggleSave).toHaveBeenCalledTimes(1);
    expect(bars()).toHaveLength(0);
    expect(hasText('اتحفظ في المشاهدة لاحقًا')).toBe(false);
  });

  it('sends a guest to sign in without saving', async () => {
    onBeforeOpenSave.mockReturnValue(false);
    await mount();
    await act(async () => button('احفظ').props.onPress());
    expect(onToggleSave).not.toHaveBeenCalled();
    expect(bars()).toHaveLength(0);
  });

  it('leaves after four seconds without motion when reduced motion is on', async () => {
    jest.useFakeTimers();
    mockReducedMotion = true;
    await mount();
    await act(async () => button('احفظ').props.onPress());
    await act(async () => renderer.update(element(true)));
    expect(bars()).toHaveLength(1);

    await act(async () => jest.advanceTimersByTime(SNACKBAR_DURATION_MS - 1));
    expect(bars()).toHaveLength(1);
    await act(async () => jest.advanceTimersByTime(1));
    expect(bars()).toHaveLength(0);
  });

  it('keeps one bar and drops it when the lesson changes or is removed', async () => {
    await mount();
    await act(async () => button('احفظ').props.onPress());
    await act(async () => renderer.update(element(true)));
    expect(bars()).toHaveLength(1);

    // Removing the save elsewhere retracts the confirmation.
    await act(async () => renderer.update(element(false)));
    expect(bars()).toHaveLength(0);

    await act(async () => button('احفظ').props.onPress());
    await act(async () => renderer.update(element(true)));
    expect(bars()).toHaveLength(1);
    await act(async () => renderer.update(element(true, '2')));
    expect(bars()).toHaveLength(0);
  });
});
