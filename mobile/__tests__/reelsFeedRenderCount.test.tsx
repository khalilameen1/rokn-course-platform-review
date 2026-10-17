import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockNoop = () => undefined;
const mockRef = <T,>(current: T) => ({current});
let mockPaging = false;
let mockIndex = 0;
const mockReels = [1, 2, 3].map(index => ({
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
  totalReels: 3,
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
    feedLengthRef: mockRef(3),
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
const mockVideoRenders: Record<string, number> = {};
const mockVideoProps: Record<
  string,
  {isVisible: boolean; playbackBlocked?: boolean}
> = {};
const mockChromeRenders: Record<string, number> = {};
jest.mock('../src/components/VideoPlayer/VideoComponent', () => ({
  __esModule: true,
  default: (props: {
    data: {id: string};
    isVisible: boolean;
    playbackBlocked?: boolean;
  }) => {
    mockVideoRenders[props.data.id] =
      (mockVideoRenders[props.data.id] || 0) + 1;
    mockVideoProps[props.data.id] = props;
    return null;
  },
}));
jest.mock('../src/components/VideoPlayer/FeedSideBar', () => ({
  __esModule: true,
  default: (props: {currentReel: {id: string}}) => {
    const id = props.currentReel.id;
    mockChromeRenders[id] = (mockChromeRenders[id] || 0) + 1;
    return null;
  },
}));
jest.mock('../src/components/VideoPlayer/FeedHeader', () => ({
  __esModule: true,
  default: () => null,
}));
jest.mock('../src/components/VideoPlayer/FeedFooter', () => ({
  __esModule: true,
  default: () => null,
}));
jest.mock('../src/components/VideoPlayer/ProjectTransition', () => ({
  __esModule: true,
  default: () => null,
}));

import {useReelsController} from '../src/screens/reels/useReelsController';
import {ReelsFeedContext} from '../src/screens/reels/useReelsFeedRenderer';

// Reference: React docs, "Skipping re-rendering when props are unchanged"
// (memo) and "Separating events from effects". A swipe may re-render only
// the rows whose own visibility/blocking/mount state changed.
it('re-renders only the rows whose state changes across one swipe', async () => {
  mockPaging = false;
  mockIndex = 0;
  let controller!: ReturnType<typeof useReelsController>;
  function Harness() {
    controller = useReelsController();
    return (
      <ReelsFeedContext.Provider value={controller.feedState}>
        {controller.feedItems.map((item, index) => (
          <React.Fragment key={item.key}>
            {controller.renderItem({item, index})}
          </React.Fragment>
        ))}
      </ReelsFeedContext.Provider>
    );
  }
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(() => {
    renderer = TestRenderer.create(<Harness />);
  });
  const renderItem = controller.renderItem;
  const snapshot = () => ({
    video: {...mockVideoRenders},
    chrome: {...mockChromeRenders},
  });
  expect(snapshot().video).toEqual({'reel-1': 1, 'reel-2': 1});

  // Unrelated controller re-render (progress tick, connection note, ...).
  let before = snapshot();
  await act(() => renderer.update(<Harness />));
  expect(snapshot()).toEqual(before);
  expect(controller.renderItem).toBe(renderItem);

  // Drag starts: only the visible row pauses; the preloaded neighbour stays.
  before = snapshot();
  mockPaging = true;
  await act(() => renderer.update(<Harness />));
  expect(mockVideoRenders['reel-1']).toBe(before.video['reel-1'] + 1);
  expect(mockVideoRenders['reel-2']).toBe(before.video['reel-2']);
  expect(mockVideoProps['reel-1'].playbackBlocked).toBe(true);

  // Page settles on the next reel while the drag is still blocking playback.
  before = snapshot();
  mockIndex = 1;
  await act(() => renderer.update(<Harness />));
  expect(mockVideoRenders['reel-2']).toBe(before.video['reel-2'] + 1);
  expect(mockVideoRenders['reel-3']).toBe(1);
  expect(mockVideoProps['reel-2']).toMatchObject({
    isVisible: true,
    playbackBlocked: true,
  });

  // Playback unblocks: only the new visible row renders again.
  before = snapshot();
  mockPaging = false;
  await act(() => renderer.update(<Harness />));
  expect(mockVideoRenders['reel-2']).toBe(before.video['reel-2'] + 1);
  expect(mockVideoRenders['reel-3']).toBe(before.video['reel-3']);
  expect(mockVideoRenders['reel-1']).toBe(before.video['reel-1']);
  expect(mockVideoProps['reel-2'].playbackBlocked).toBe(false);
  expect(mockChromeRenders['reel-3']).toBeUndefined();
  await act(() => renderer.unmount());
});
