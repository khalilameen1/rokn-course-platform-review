import React from 'react';
import {BackHandler, View} from 'react-native';
import TestRenderer, {act} from 'react-test-renderer';

type ModalProps = {
  children?: React.ReactNode;
  onAnimate?: (fromIndex: number, toIndex: number) => void;
  onChange?: (index: number) => void;
  onDismiss?: () => void;
};
type ModalRecord = {props: ModalProps; dismiss: jest.Mock; present: jest.Mock};
let mockFocused = true;
let mockModals: ModalRecord[] = [];
const mockCloseSave = jest.fn();

jest.mock('@react-navigation/native', () => ({
  useNavigation: () => ({}),
  useFocusEffect: (effect: () => void | (() => void)) => {
    const ReactModule = require('react') as typeof React;
    ReactModule.useEffect(() => {
      if (mockFocused) return effect();
      return undefined;
    }, [effect, mockFocused]);
  },
}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  goBackOrHome: jest.fn(),
}));
jest.mock('@gorhom/bottom-sheet', () => {
  const ReactModule = require('react') as typeof React;
  const Native = require('react-native');
  return {
    BottomSheetBackdrop: Native.View,
    BottomSheetScrollView: Native.View,
    BottomSheetModal: ReactModule.forwardRef((props: ModalProps, ref) => {
      const record = ReactModule.useMemo(() => {
        const created = {props, dismiss: jest.fn(), present: jest.fn()};
        mockModals.push(created);
        return created;
      }, []);
      record.props = props;
      ReactModule.useImperativeHandle(ref, () => record, [record]);
      return <Native.View>{props.children}</Native.View>;
    }),
  };
});
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('react-native-svg', () => {
  const Native = require('react-native');
  return {
    __esModule: true,
    default: Native.View,
    Circle: Native.View,
    Path: Native.View,
  };
});
jest.mock('../src/hooks/useReducedMotion', () => ({
  useReducedMotion: () => false,
}));
jest.mock('../src/components/ui/CopyIcon', () => ({CopyIcon: () => null}));
jest.mock('../src/components/VideoPlayer/attachmentActions', () => ({
  openCourseAttachment: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/attachmentDownloadNotice', () => ({
  useAttachmentDownloadCancellation: () => () => undefined,
}));
jest.mock(
  '../src/components/VideoPlayer/feedSideBar/CourseIndexModule',
  () => () => null,
);
jest.mock('../src/components/VideoPlayer/feedSideBar/FeedActions', () => ({
  __esModule: true,
  AttachmentIcon: () => null,
  default: () => null,
}));
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
      close: mockCloseSave,
      createAndSave: jest.fn(),
      creating: false,
      error: '',
      loadError: '',
      folders: [],
      loading: false,
      name: '',
      open: jest.fn(),
      retryFolders: jest.fn(),
      saveInFolder: jest.fn(),
      saveInWatchLater: jest.fn(),
      setName: jest.fn(),
    }),
  }),
);

import FeedHeader from '../src/components/VideoPlayer/FeedHeader';
import FeedSideBar from '../src/components/VideoPlayer/FeedSideBar';
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
  attachments: [],
};
const overlayChanged = jest.fn();
const headerChanged = jest.fn();
let callbacks: Array<() => boolean | null | undefined> = [];
let renderer: TestRenderer.ReactTestRenderer;
const sidebar = () => (
  <FeedSideBar
    course={course}
    currentReel={reel}
    currentFeedKey="1"
    isSaved={false}
    savePending={false}
    onToggleSave={jest.fn()}
    onBeforeOpenSave={() => true}
    onOpenChat={jest.fn()}
    onOverlayVisibilityChange={overlayChanged}
    onSelectFeedItem={jest.fn()}
    currentTime={0}
  />
);
const header = () => (
  <FeedHeader
    playbackSpeed={1}
    onPlaybackSpeedChange={jest.fn()}
    selectedQuality="auto"
    qualityOptions={['auto']}
    onQualityChange={jest.fn()}
    onOpenChange={headerChanged}
  />
);
const back = () => {
  for (const callback of [...callbacks].reverse())
    if (callback() === true) return true;
  return false;
};

beforeEach(() => {
  jest.clearAllMocks();
  mockFocused = true;
  mockModals = [];
  callbacks = [];
  jest
    .spyOn(BackHandler, 'addEventListener')
    .mockImplementation((_event, callback) => {
      callbacks.push(callback);
      return {
        remove: () => {
          callbacks = callbacks.filter(active => active !== callback);
        },
      };
    });
});
afterEach(() => {
  act(() => renderer?.unmount());
  jest.restoreAllMocks();
});

it('lets normal navigation handle Back when no player sheet is open', () => {
  act(() => {
    renderer = TestRenderer.create(sidebar());
  });
  expect(back()).toBe(false);
  expect(mockModals.every(modal => modal.dismiss.mock.calls.length === 0)).toBe(
    true,
  );
});

it('owns Back during index opening and dismissal instead of popping the player', () => {
  act(() => {
    renderer = TestRenderer.create(sidebar());
  });
  const index = mockModals[0];
  act(() => index.props.onAnimate?.(-1, 0));
  expect(overlayChanged).toHaveBeenLastCalledWith(true);
  act(() => {
    expect(back()).toBe(true);
  });
  act(() => {
    expect(back()).toBe(true);
  });
  expect(index.dismiss).toHaveBeenCalledTimes(2);
  expect(mockModals[1].dismiss).not.toHaveBeenCalled();
  expect(mockModals[2].dismiss).not.toHaveBeenCalled();
  act(() => index.props.onDismiss?.());
  expect(overlayChanged).toHaveBeenLastCalledWith(false);
  expect(back()).toBe(false);
});

it.each([
  ['attachment', 1],
  ['save', 2],
] as const)(
  'closes the %s sheet through its existing dismissal owner',
  (_name, position) => {
    act(() => {
      renderer = TestRenderer.create(sidebar());
    });
    const selected = mockModals[position];
    act(() => selected.props.onChange?.(0));
    act(() => {
      expect(back()).toBe(true);
    });
    expect(selected.dismiss).toHaveBeenCalledTimes(1);
    expect(mockCloseSave).not.toHaveBeenCalled();
    act(() => selected.props.onDismiss?.());
    expect(mockCloseSave).toHaveBeenCalledTimes(position === 2 ? 1 : 0);
    expect(back()).toBe(false);
  },
);

it('dismisses the top visible sheet, then the remaining underlying sheet', () => {
  act(() => {
    renderer = TestRenderer.create(sidebar());
  });
  act(() => {
    mockModals[0].props.onChange?.(0);
    mockModals[1].props.onChange?.(0);
  });
  act(() => {
    expect(back()).toBe(true);
  });
  expect(mockModals[1].dismiss).toHaveBeenCalledTimes(1);
  expect(mockModals[0].dismiss).not.toHaveBeenCalled();
  act(() => mockModals[1].props.onDismiss?.());
  expect(overlayChanged).toHaveBeenLastCalledWith(true);
  act(() => {
    expect(back()).toBe(true);
  });
  expect(mockModals[0].dismiss).toHaveBeenCalledTimes(1);
});

it('does not swallow another screen Back and cleans up on unmount', () => {
  act(() => {
    renderer = TestRenderer.create(sidebar());
  });
  act(() => mockModals[0].props.onChange?.(0));
  mockFocused = false;
  act(() => renderer.update(sidebar()));
  expect(callbacks).toHaveLength(0);
  expect(back()).toBe(false);
  mockFocused = true;
  act(() => renderer.update(sidebar()));
  act(() => {
    expect(back()).toBe(true);
  });
  act(() => renderer.unmount());
  expect(callbacks).toHaveLength(0);
  expect(overlayChanged).toHaveBeenLastCalledWith(false);
});

it('closes viewing settings before navigation, using the same focused back lifecycle', () => {
  act(() => {
    renderer = TestRenderer.create(header());
  });
  expect(back()).toBe(false);
  const settings = renderer.root.findAll(
    node =>
      node.props.accessibilityLabel === 'إعدادات المشاهدة' &&
      typeof node.props.onPress === 'function',
  )[0];
  expect(settings).toBeDefined();
  act(() => settings.props.onPress());
  expect(headerChanged).toHaveBeenLastCalledWith(true);
  act(() => {
    expect(back()).toBe(true);
  });
  expect(headerChanged).toHaveBeenLastCalledWith(false);
  expect(back()).toBe(false);
  expect(renderer.root.findAllByType(View).length).toBeGreaterThan(0);
});
