import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockNoop = () => undefined;
const mockRef = <T,>(current: T) => ({current});
let mockPaging = false;
let mockIndex = 0;
const mockReels = [1, 2].map(index => ({
  id: `reel-${index}`,
  lessonId: `lesson-${index}`,
  sectionId: `section-${index}`,
  sectionOrder: index,
  moduleId: 'module-1',
  title: 'مقطع',
  caption: '',
  videoUrl: `https://cdn.example/reel-${index}.m3u8`,
  isLocked: false,
  isPreview: true,
  isCompleted: false,
  reelNumber: index,
}));
const mockCourse = {
  id: 'course-1',
  title: 'كورس',
  totalReels: 2,
  accessType: 'paid',
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'وحدة',
      order: 1,
      isLocked: false,
      reels: mockReels,
    },
  ],
};
const mockCourseState = {
  accountViewGenerationRef: mockRef(0),
  course: mockCourse,
  loading: false,
  loadError: '',
  connectionNote: '',
  courseRevisionPendingRef: mockRef(false),
  courseRevisionRefreshing: false,
  courseRevisionReloadRef: mockRef(null),
  learningMapRetryIndex: null,
  loadAbortRef: mockRef(null),
  loadRequestRef: mockRef(0),
  loadedCourseOwnerRef: mockRef('user:course-1'),
  loadedCourseRef: mockRef(mockCourse),
  serverSession: true,
  setConnectionNote: mockNoop,
  setCourse: mockNoop,
  setCourseRevisionRefreshing: mockNoop,
  setLearningMapRetryIndex: mockNoop,
  setLoadError: mockNoop,
  setLoading: mockNoop,
  setServerSession: mockNoop,
};
const mockRuntime = {
  activeReel: mockRef(mockReels[0]),
  closedSessions: mockRef(new Set()),
  completionSent: mockRef(new Set()),
  durations: mockRef({}),
  positions: mockRef({}),
  runtime: mockRef({}),
  lastPersisted: mockRef({}),
  handlePlaybackEvent: mockNoop,
  handlePlaybackMetrics: mockNoop,
  reportBackground: mockNoop,
};

jest.mock('@react-navigation/native', () => ({
  useIsFocused: () => true,
  useRoute: () => ({params: {courseId: 'course-1'}}),
  useNavigation: () => ({}),
}));
jest.mock('react-redux', () => ({useSelector: () => ({id: 1})}));
jest.mock('react-native-safe-area-context', () => ({
  useSafeAreaInsets: () => ({top: 0, bottom: 0, left: 0, right: 0}),
}));
jest.mock('../src/constants/helpers', () => ({
  sessionIdentityKey: () => 'user-1',
}));
jest.mock('../src/navigation/RootNavigationHelper', () => ({
  goBackOrHome: jest.fn(),
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  submitProjectAttempt: jest.fn(),
}));
jest.mock('../src/screens/reels/useReelsCourseState', () => ({
  useReelsCourseState: () => mockCourseState,
}));
jest.mock('../src/screens/reels/usePlaybackPreferences', () => ({
  usePlaybackPreferences: () => ({
    autoplay: true,
    changePlaybackSpeed: mockNoop,
    changeQuality: mockNoop,
    dataSaver: false,
    getPlaybackSpeed: () => 1,
    playbackPreferencesReady: true,
    playbackSpeed: 1,
    selectedQuality: 'auto',
  }),
}));
jest.mock('../src/screens/reels/useReminderNudge', () => ({
  useReminderNudge: () => ({
    closeReminderNudge: mockNoop,
    enableRemindersFromNudge: mockNoop,
    maybeOfferReminders: mockNoop,
    reminderNudgeVisible: false,
  }),
}));
jest.mock('../src/screens/reels/useReelsSavedLessons', () => ({
  useReelsSavedLessons: () => ({
    savedLessons: new Set(),
    savingLessons: new Set(),
    setSavedLessons: mockNoop,
    toggleSaved: mockNoop,
  }),
}));
jest.mock('../src/screens/reels/useReelsPosition', () => ({
  useReelsPosition: () => ({
    currentIndex: mockIndex,
    currentIndexRef: mockRef(mockIndex),
    feedLengthRef: mockRef(2),
    layout: {width: 390, height: 844},
    listRef: mockRef(null),
    onLayout: mockNoop,
    onPagingCancelled: mockNoop,
    onPagingSettled: mockNoop,
    onPagingStarted: mockNoop,
    onScroll: mockNoop,
    onScrollToIndexFailed: mockNoop,
    onViewableItemsChanged: mockNoop,
    paging: mockPaging,
    requestInitialPosition: mockNoop,
    scrollToIndex: mockNoop,
    scrollToKey: mockNoop,
    viewabilityConfig: {},
  }),
}));
jest.mock('../src/screens/reels/useReelsPlaybackRuntime', () => ({
  useReelsPlaybackRuntime: () => mockRuntime,
}));
jest.mock('../src/screens/reels/useReelsLifecycle', () => ({
  useReelsLifecycle: () => true,
}));
jest.mock('../src/screens/reels/useReelsManifestOwner', () => ({
  useReelsManifestOwner: () => ({
    refreshSources: mockNoop,
    invalidateManifests: mockNoop,
    requestPlaybackManifest: mockNoop,
    canPreloadAdjacentVideo: true,
  }),
}));
jest.mock('../src/screens/reels/useReelsCourseLoader', () => ({
  useReelsCourseLoader: () => mockNoop,
}));
jest.mock('../src/screens/reels/useReelsCourseRevision', () => ({
  useReelsCourseRevision: () => mockNoop,
}));
jest.mock('../src/screens/reels/useProjectReview', () => ({
  useProjectReview: () => ({
    refreshProjectState: mockNoop,
    watchProjectUntilResolved: mockNoop,
    applyReviewResolution: mockNoop,
  }),
}));
jest.mock('../src/screens/reels/useReelsProgress', () => ({
  useReelsProgress: () => ({
    completeAndAdvance: mockNoop,
    persistProgress: mockNoop,
  }),
}));
jest.mock('../src/components/VideoPlayer/FeedRow', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  return {
    __esModule: true,
    default: (props: {
      shouldMountVideo: boolean;
      playbackBlocked: boolean;
      item: {key: string};
    }) =>
      props.shouldMountVideo
        ? ReactModule.createElement(View, {
            testID: props.item.key,
            playbackBlocked: props.playbackBlocked,
          })
        : null,
  };
});

import {useReelsController} from '../src/screens/reels/useReelsController';

it('keeps the preloaded next row mounted through paging while playback stays blocked', async () => {
  mockPaging = false;
  mockIndex = 0;
  let controller!: ReturnType<typeof useReelsController>;
  function Harness() {
    controller = useReelsController();
    return (
      <>
        {controller.feedItems.map((item, index) => (
          <React.Fragment key={item.key}>
            {controller.renderItem({item, index})}
          </React.Fragment>
        ))}
      </>
    );
  }
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(() => {
    renderer = TestRenderer.create(<Harness />);
  });
  const nextKey = controller.feedItems[1].key;
  const preloaded = renderer.root.findByProps({testID: nextKey});

  mockPaging = true;
  await act(() => renderer.update(<Harness />));
  expect(renderer.root.findAllByProps({testID: nextKey})).toContain(preloaded);
  expect(renderer.root.findByProps({testID: nextKey})).toBe(preloaded);
  expect(preloaded.props.playbackBlocked).toBe(true);

  mockIndex = 1;
  await act(() => renderer.update(<Harness />));
  expect(renderer.root.findByProps({testID: nextKey})).toBe(preloaded);
  mockPaging = false;
  await act(() => renderer.update(<Harness />));
  expect(renderer.root.findByProps({testID: nextKey})).toBe(preloaded);
  expect(preloaded.props.playbackBlocked).toBe(false);
  await act(() => renderer.unmount());
});
