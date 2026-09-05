import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';
import {useReelsProgress} from '../src/screens/reels/useReelsProgress';
import {
  flushPendingPlaybackPositions,
  markSectionComplete,
  savePlaybackPosition,
} from '../src/components/VideoPlayer/courseLearningApi';

jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  flushPendingPlaybackPositions: jest.fn(async () => undefined),
  markSectionComplete: jest.fn(async () => true),
  savePlaybackPosition: jest.fn(async () => undefined),
}));

jest.mock('../src/services/smartReminders', () => ({
  scheduleNextLearningReminder: jest.fn(async () => undefined),
}));

const firstReel = (): CourseReel => ({
  id: 'reel-1',
  lessonId: 'lesson-1',
  sectionId: 'section-1',
  moduleId: 'module-1',
  title: 'المقطع الأول',
  caption: '',
  videoUrl: 'https://cdn.example/1.m3u8',
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
  sectionOrder: 1,
});

const courseWith = (
  reels: CourseReel[],
  projects: CourseLearningData['modules'][number]['projects'] = [],
): CourseLearningData => ({
  id: 'course-1',
  title: 'كورس',
  totalReels: reels.length,
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels,
      projects,
    },
  ],
});

type ProgressApi = ReturnType<typeof useReelsProgress>;

const renderProgress = async ({
  course,
  feedItems,
  previewMode = false,
}: {
  course: CourseLearningData;
  feedItems: CourseFeedItem[];
  previewMode?: boolean;
}) => {
  const scrollToIndex = jest.fn();
  const refreshAfterSectionCompletion = jest.fn(async () => true);
  const setPreviewGateVisible = jest.fn();
  let api: ProgressApi | null = null;

  const Harness = () => {
    api = useReelsProgress({
      autoplay: true,
      course,
      currentIndex: 0,
      feedItems,
      maybeOfferReminders: jest.fn(),
      playbackSpeed: 1,
      previewMode,
      refs: {
        completionSent: React.useRef(new Set<string>()),
        feedLength: React.useRef(feedItems.length),
        lastPersisted: React.useRef<Record<string, number>>({}),
        ownerGeneration: React.useRef(0),
        playbackDurations: React.useRef<Record<string, number>>({}),
        playbackRuntime: React.useRef({}),
        positions: React.useRef<Record<string, number>>({}),
      },
      refreshAfterSectionCompletion,
      scheduleDelayedAction: (action, delay) => setTimeout(action, delay),
      scrollToIndex,
      setChatVisible: jest.fn(),
      setCourse: jest.fn(),
      setPreviewGateVisible,
    });
    return null;
  };

  let renderer: TestRenderer.ReactTestRenderer;
  await act(async () => {
    renderer = TestRenderer.create(<Harness />);
  });

  return {
    api: () => api!,
    refreshAfterSectionCompletion,
    renderer: renderer!,
    scrollToIndex,
    setPreviewGateVisible,
  };
};

describe('reel completion navigation', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
  });

  afterEach(() => {
    jest.useRealTimers();
  });

  it('joins the final progress save and opens the next ordinary reel once', async () => {
    const first = firstReel();
    const second = {...firstReel(), id: 'reel-2', lessonId: 'lesson-2', sectionId: 'section-2', title: 'المقطع الثاني', reelNumber: 2, sectionOrder: 2};
    const course = courseWith([first, second]);
    const feedItems: CourseFeedItem[] = [first, second].map(reel => ({
      key: `reel-${reel.id}`,
      type: 'reel',
      moduleId: 'module-1',
      reel,
    }));
    const harness = await renderProgress({course, feedItems});

    await act(async () => {
      // Native onEnd emits its final progress sample immediately before the
      // completion callback. Both callbacks must share one confirmation.
      harness.api().persistProgress(first, 100, 100);
      harness.api().completeAndAdvance(first);
      await Promise.resolve();
      await jest.runAllTimersAsync();
    });

    expect(savePlaybackPosition).toHaveBeenCalledTimes(1);
    expect(flushPendingPlaybackPositions).toHaveBeenCalledTimes(1);
    expect(markSectionComplete).toHaveBeenCalledTimes(1);
    expect(harness.scrollToIndex).toHaveBeenCalledWith(1);
    expect(harness.refreshAfterSectionCompletion).not.toHaveBeenCalled();

    await act(async () => harness.renderer.unmount());
  });

  it('opens the authored project boundary through a fresh learning map', async () => {
    const first = firstReel();
    const project = {
      id: 'project-1',
      sectionId: 'section-project-1',
      moduleId: 'module-1',
      title: 'مشروع العبور',
      requirements: '',
      status: 'draft' as const,
      isGraduationProject: false,
      isLocked: false,
      sectionOrder: 2,
    };
    const course = courseWith([first], [project]);
    const feedItems: CourseFeedItem[] = [
      {key: 'reel-reel-1', type: 'reel', moduleId: 'module-1', reel: first},
      {key: 'project-project-1', type: 'project', moduleId: 'module-1', project},
    ];
    const harness = await renderProgress({course, feedItems});

    await act(async () => {
      harness.api().completeAndAdvance(first);
      await Promise.resolve();
      await jest.runAllTimersAsync();
    });

    expect(harness.refreshAfterSectionCompletion).toHaveBeenCalledWith(1);
    expect(harness.scrollToIndex).not.toHaveBeenCalled();

    await act(async () => harness.renderer.unmount());
  });

  it('shows the purchase gate after the last free preview without replaying it', async () => {
    const preview = {...firstReel(), isPreview: true};
    const course = courseWith([preview]);
    const feedItems: CourseFeedItem[] = [
      {key: 'reel-reel-1', type: 'reel', moduleId: 'module-1', reel: preview},
    ];
    const harness = await renderProgress({course, feedItems, previewMode: true});

    await act(async () => {
      harness.api().completeAndAdvance(preview);
      await Promise.resolve();
      await jest.runAllTimersAsync();
    });

    expect(harness.setPreviewGateVisible).toHaveBeenCalledWith(true);
    expect(harness.scrollToIndex).not.toHaveBeenCalled();

    await act(async () => harness.renderer.unmount());
  });
});
