import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';

const mockRequestManifest = jest.fn();
jest.mock('../src/screens/reels/usePlaybackManifest', () => ({
  usePlaybackManifest: () => mockRequestManifest,
}));

import {useReelsManifestOwner} from '../src/screens/reels/useReelsManifestOwner';

const reels: CourseReel[] = [1, 2, 3].map(index => ({
  id: `reel-${index}`,
  lessonId: `lesson-${index}`,
  sectionId: `section-${index}`,
  moduleId: 'module-1',
  title: 'مقطع',
  caption: '',
  videoUrl: '',
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: index,
}));
const course: CourseLearningData = {
  id: 'course-1',
  title: 'كورس',
  totalReels: reels.length,
  accessType: 'paid',
  attachments: [],
  modules: [{id: 'module-1', title: 'وحدة', order: 1, isLocked: false, reels}],
};
const feedItems: CourseFeedItem[] = reels.map(reel => ({
  key: `reel-${reel.id}`,
  type: 'reel',
  moduleId: reel.moduleId,
  reel,
}));
type Params = Parameters<typeof useReelsManifestOwner>[0];
const createParams = (): Params => ({
  activeReel: {current: reels[0]},
  appIsActive: true,
  course,
  courseRef: {current: course},
  currentIndex: 0,
  currentReel: reels[0],
  dataSaver: false,
  durations: {current: {}},
  feedItems,
  getPlaybackSpeed: () => 1,
  interactionLocked: false,
  isScreenFocused: true,
  mounted: {current: true},
  onCourseRevisionChanged: jest.fn(),
  ownerGeneration: {current: 1},
  playbackPreferencesReady: true,
  positions: {current: {}},
  revisionReloadPending: {current: false},
  runtime: {current: {}},
  scheduleDelayedAction: jest.fn(),
  scopeKey: 'user-1:course-1',
  selectedQuality: 'auto',
  serverSession: true,
  setConnectionNote: jest.fn(),
  setCourse: jest.fn(),
  sessionsClosed: {current: new Set()},
});
const Harness = ({params}: {params: Params}) => {
  useReelsManifestOwner(params);
  return null;
};

describe('bounded adjacent-reel manifest preloading', () => {
  beforeEach(() => {
    mockRequestManifest.mockReset();
    // No response: the next request must not depend on the current response.
    mockRequestManifest.mockImplementation(() => new Promise(() => {}));
  });

  const render = async (params: Params) => {
    let renderer!: TestRenderer.ReactTestRenderer;
    await act(async () => {
      renderer = TestRenderer.create(<Harness params={params} />);
    });
    return renderer;
  };

  it('requests the current and next source immediately on a cold opening, not the third', async () => {
    const renderer = await render(createParams());
    expect(mockRequestManifest.mock.calls.map(([reel]) => reel.id)).toEqual([
      'reel-1',
      'reel-2',
    ]);
    act(() => renderer.unmount());
  });

  it('requests both actual neighbours when entering a middle reel', async () => {
    const params = createParams();
    params.currentIndex = 1;
    params.currentReel = reels[1];
    params.activeReel = {current: reels[1]};
    const renderer = await render(params);
    expect(mockRequestManifest.mock.calls.map(([reel]) => reel.id)).toEqual([
      'reel-2',
      'reel-3',
      'reel-1',
    ]);
    act(() => renderer.unmount());
  });

  it.each(['dataSaver', 'interactionLocked'] as const)(
    'does not preload when %s is enabled',
    async flag => {
      const params = {...createParams(), [flag]: true};
      const renderer = await render(params);
      expect(
        mockRequestManifest.mock.calls.some(([reel]) => reel.id === 'reel-2'),
      ).toBe(false);
      act(() => renderer.unmount());
    },
  );

  it.each(['appIsActive', 'isScreenFocused'] as const)(
    'does not request any source when %s is false',
    async flag => {
      const renderer = await render({...createParams(), [flag]: false});
      expect(mockRequestManifest).not.toHaveBeenCalled();
      act(() => renderer.unmount());
    },
  );

  it('does not cross a locked next reel to preload a later one', async () => {
    const params = createParams();
    params.feedItems = [
      feedItems[0],
      {...feedItems[1], reel: {...reels[1], isLocked: true}} as CourseFeedItem,
      feedItems[2],
    ];
    const renderer = await render(params);
    expect(mockRequestManifest.mock.calls.map(([reel]) => reel.id)).toEqual([
      'reel-1',
    ]);
    act(() => renderer.unmount());
  });

  it('reuses an unexpired next manifest rather than issuing another session', async () => {
    const params = createParams();
    params.feedItems = [
      feedItems[0],
      {
        ...feedItems[1],
        reel: {
          ...reels[1],
          playbackSessionId: 'next-session',
          playbackExpiresAt: new Date(Date.now() + 300_000).toISOString(),
          videoUrl: 'https://cdn.example/next.m3u8',
        },
      } as CourseFeedItem,
    ];
    const renderer = await render(params);
    expect(mockRequestManifest.mock.calls.map(([reel]) => reel.id)).toEqual([
      'reel-1',
    ]);
    act(() => renderer.unmount());
  });
});
