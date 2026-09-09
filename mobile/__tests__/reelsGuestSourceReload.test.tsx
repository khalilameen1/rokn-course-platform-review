import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

jest.mock('../src/components/VideoPlayer/FeedRow', () => ({
  __esModule: true,
  default: () => null,
}));
jest.mock('../src/navigation/journeyNavigation', () => ({
  openGuestLogin: jest.fn(),
}));
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  applyLocalLearningState: jest.fn(async course => course),
  getLocalLearningState: jest.fn(async () => ({
    positions: {'course-1:reel-2': 30},
    savedLessons: [],
  })),
  loadCourseLearningData: jest.fn(),
  reconcileServerSavedLessons: jest.fn(async () => []),
}));
jest.mock('../src/constants/helpers', () => ({
  assertAccountSessionBoundary: jest.fn(),
  captureAccountSessionBoundary: jest.fn(async () => ({scope: 'guest-1'})),
}));
jest.mock('../src/services/roknApi', () => ({hasSession: jest.fn()}));

import {loadCourseLearningData} from '../src/components/VideoPlayer/courseLearningApi';
import type {CourseLearningData} from '../src/components/VideoPlayer/types';
import type {RootNavigation} from '../src/navigation/types';
import {hasSession} from '../src/services/roknApi';
import {buildPreviewFeed} from '../src/screens/reels/presentation';
import {useReelsCourseLoader} from '../src/screens/reels/useReelsCourseLoader';
import {useReelsFeedRenderer} from '../src/screens/reels/useReelsFeedRenderer';

const course: CourseLearningData = {
  id: 'course-1',
  title: 'كورس',
  totalReels: 2,
  accessType: 'preview',
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'وحدة',
      order: 1,
      isLocked: false,
      reels: [1, 2].map(index => ({
        id: `reel-${index}`,
        lessonId: `lesson-${index}`,
        sectionId: `section-${index}`,
        moduleId: 'module-1',
        title: 'مقطع',
        caption: '',
        videoUrl: `https://cdn.example/reel-${index}.m3u8`,
        availableQualities: ['auto'],
        isPreview: true,
        isLocked: false,
        isCompleted: false,
        reelNumber: index,
      })),
    },
  ],
};

it.each([
  {serverSession: false, disappeared: false},
  {serverSession: null, disappeared: false},
  {serverSession: true, disappeared: false},
  {serverSession: false, disappeared: true},
])(
  'retries the current preview (session: $serverSession, removed: $disappeared)',
  async ({serverSession, disappeared}) => {
    jest.clearAllMocks();
    jest.mocked(loadCourseLearningData).mockResolvedValue({course} as never);
    jest.mocked(hasSession).mockResolvedValue(serverSession === true);
    const refs = {
      closedPlaybackSessions: {current: new Set<string>()},
      loadRequest: {current: 0},
      loadAbort: {current: null as AbortController | null},
      loadedCourse: {current: null as CourseLearningData | null},
      loadedCourseOwner: {current: 'guest-1'},
      playbackDurations: {current: {}},
      playbackRuntime: {current: {}},
      positions: {current: {} as Record<string, number>},
    };
    const navigation = {replace: jest.fn()} as unknown as RootNavigation;
    const requestInitialPosition = jest.fn();
    const requestPlaybackManifest = jest.fn();
    const noop = jest.fn();
    let refresh!: () => Promise<void>;
    const Harness = () => {
      const load = useReelsCourseLoader({
        navigation,
        identityKey: 'guest-1',
        params: {
          courseId: course.id,
          reelId: 'reel-1',
          initialPositionSeconds: 7,
        },
        previewMode: true,
        refs,
        requestInitialPosition,
        setConnectionNote: noop,
        setCourse: noop,
        setLoadError: noop,
        setLoading: noop,
        setPreviewGateVisible: noop,
        setSavedLessons: noop,
        setServerSession: noop,
      });
      const renderItem = useReelsFeedRenderer({
        bottomInset: 0,
        changePlaybackSpeed: noop,
        changeQuality: noop,
        completeAndAdvance: noop,
        course,
        currentIndex: 1,
        feedLength: 2,
        frameWidth: 390,
        handlePlaybackEvent: noop,
        handlePlaybackMetrics: noop,
        layout: {width: 390, height: 844},
        load,
        navigation,
        persistProgress: noop,
        playbackSpeed: 1,
        playbackBlocked: false,
        preloadNext: false,
        positions: refs.positions,
        preview: true,
        requestPlaybackManifest,
        screenFocused: true,
        savedLessons: new Set(),
        savingLessons: new Set(),
        scheduleDelayedAction: noop,
        scrollToIndex: noop,
        scrollToKey: noop,
        selectedQuality: 'auto',
        serverSession,
        setChatVisible: noop,
        onContentOverlayVisibilityChange: noop,
        submitProject: jest.fn(),
        toggleSaved: jest.fn(),
        topInset: 0,
      });
      const row = renderItem({item: buildPreviewFeed(course)[1], index: 1});
      refresh = row!.props.onRefreshVideo;
      return null;
    };
    let renderer!: TestRenderer.ReactTestRenderer;
    try {
      await act(async () => {
        renderer = TestRenderer.create(<Harness />);
      });
      expect(requestInitialPosition).toHaveBeenLastCalledWith({
        key: 'reel-reel-1',
      });
      requestInitialPosition.mockClear();

      if (disappeared) {
        jest.mocked(loadCourseLearningData).mockResolvedValueOnce({
          course: {
            ...course,
            totalReels: 1,
            modules: [
              {...course.modules[0], reels: [course.modules[0].reels[0]]},
            ],
          },
        } as never);
      }

      await act(async () => {
        await refresh();
      });

      if (serverSession) {
        expect(requestPlaybackManifest).toHaveBeenCalledWith(
          course.modules[0].reels[1],
          undefined,
        );
        expect(loadCourseLearningData).toHaveBeenCalledTimes(1);
        expect(requestInitialPosition).not.toHaveBeenCalled();
      } else {
        expect(requestPlaybackManifest).not.toHaveBeenCalled();
        expect(loadCourseLearningData).toHaveBeenCalledTimes(2);
        expect(requestInitialPosition).toHaveBeenLastCalledWith(
          disappeared ? {index: 0} : {key: 'reel-reel-2'},
        );
        expect(refs.positions.current['course-1:reel-2']).toBe(30);
      }
    } finally {
      if (renderer)
        await act(async () => {
          renderer.unmount();
        });
    }
  },
);
