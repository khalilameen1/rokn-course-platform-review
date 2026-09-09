import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

const mockSeek = jest.fn();
jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppActiveState: () => true,
  useAppForegroundState: () => true,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('react-native-video', () => {
  const ReactModule = require('react');
  const {View} = require('react-native');
  return {
    __esModule: true,
    default: ReactModule.forwardRef((props: object, ref: unknown) => {
      ReactModule.useImperativeHandle(ref, () => ({seek: mockSeek}));
      return ReactModule.createElement(View, {
        ...props,
        testID: 'native-video',
      });
    }),
    BufferingStrategyType: {DEPENDING_ON_MEMORY: 'DependingOnMemory'},
    SelectedVideoTrackType: {AUTO: 'auto', RESOLUTION: 'resolution'},
    ViewType: {TEXTURE: 'texture'},
  };
});
jest.mock('react-native-linear-gradient', () => 'LinearGradient');
jest.mock('../src/components/VideoPlayer/courseLearningApi', () => ({
  flushPendingPlaybackPositions: jest.fn(async () => undefined),
  markSectionComplete: jest.fn(async () => true),
  savePlaybackPosition: jest.fn(async () => undefined),
}));
jest.mock('../src/services/smartReminders', () => ({
  scheduleNextLearningReminder: jest.fn(async () => undefined),
}));

import VideoComponent from '../src/components/VideoPlayer/VideoComponent';
import type {
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';
import {
  markSectionComplete,
  savePlaybackPosition,
} from '../src/components/VideoPlayer/courseLearningApi';
import {useReelsProgress} from '../src/screens/reels/useReelsProgress';
import {
  buildAccessibleFeed,
  buildPreviewFeed,
} from '../src/screens/reels/presentation';

const makeReel = (number: number): CourseReel => ({
  id: `reel-${number}`,
  lessonId: `lesson-${number}`,
  sectionId: `section-${number}`,
  sectionOrder: number,
  moduleId: 'module-1',
  title: `المقطع ${number}`,
  caption: '',
  videoUrl: `https://cdn.example.com/${number}.m3u8`,
  durationSeconds: 20,
  availableQualities: ['auto'],
  isPreview: true,
  isLocked: false,
  isCompleted: false,
  reelNumber: number,
});

async function mount({
  preview = false,
  next = 'reel',
}: {
  preview?: boolean;
  next?: 'reel' | 'locked-reel' | 'project' | 'none';
} = {}) {
  const first = makeReel(1);
  const second = {
    ...makeReel(2),
    ...(next === 'locked-reel'
      ? {isLocked: true, lockReason: 'previous_section_incomplete'}
      : {}),
  };
  const original: CourseLearningData = {
    id: 'course-1',
    title: 'كورس',
    accessType: preview ? 'preview' : 'paid',
    totalReels: next === 'reel' || next === 'locked-reel' ? 2 : 1,
    attachments: [],
    modules: [
      {
        id: 'module-1',
        title: 'الوحدة',
        order: 1,
        isLocked: false,
        reels:
          next === 'reel' || next === 'locked-reel' ? [first, second] : [first],
        projects:
          next === 'project'
            ? [
                {
                  id: 'project-1',
                  sectionId: 'section-project',
                  sectionOrder: 2,
                  moduleId: 'module-1',
                  title: 'مشروع العبور',
                  requirements: '',
                  status: 'draft',
                  isGraduationProject: false,
                  isLocked: true,
                  lockReason: 'previous_section_incomplete',
                },
              ]
            : [],
      },
    ],
  };
  const ownerGeneration = {current: 0};
  const scroll = jest.fn();
  const refresh = jest.fn(async () => true);
  let currentIndex = 0;
  let gate = false;
  const Harness = () => {
    const [course, setCourse] = React.useState<CourseLearningData | null>(
      original,
    );
    const [index, setIndex] = React.useState(0);
    const [purchaseGate, setPurchaseGate] = React.useState(false);
    currentIndex = index;
    gate = purchaseGate;
    const feedItems = preview
      ? buildPreviewFeed(course!)
      : buildAccessibleFeed(course!);
    const refs = {
      completionSent: React.useRef(new Set<string>()),
      feedLength: React.useRef(feedItems.length),
      lastPersisted: React.useRef<Record<string, number>>({}),
      ownerGeneration,
      playbackDurations: React.useRef<Record<string, number>>({}),
      playbackRuntime: React.useRef({}),
      positions: React.useRef<Record<string, number>>({}),
    };
    const progress = useReelsProgress({
      autoplay: true,
      course,
      currentIndex: index,
      feedItems,
      maybeOfferReminders: () => undefined,
      playbackSpeed: 1,
      previewMode: preview,
      refs,
      refreshAfterSectionCompletion: refresh,
      scheduleDelayedAction: (action, delay) => {
        setTimeout(action, delay);
      },
      scrollToIndex: target => {
        scroll(target);
        setIndex(target);
      },
      setChatVisible: () => undefined,
      setCourse,
      setPreviewGateVisible: setPurchaseGate,
    });
    const item = feedItems[index];
    if (item?.type !== 'reel') return null;
    return (
      <VideoComponent
        key={item.key}
        data={item.reel}
        width={390}
        height={844}
        isVisible
        onProgress={(time, duration) =>
          progress.persistProgress(item.reel, time, duration)
        }
        onComplete={() => progress.completeAndAdvance(item.reel)}
      />
    );
  };
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(() => {
    renderer = TestRenderer.create(<Harness />);
  });
  const native = () => renderer.root.findByProps({testID: 'native-video'});
  await act(() => native().props.onLoad({duration: 20}));
  return {
    native,
    scroll,
    refresh,
    ownerGeneration,
    index: () => currentIndex,
    gate: () => gate,
    end: () =>
      act(async () => {
        native().props.onEnd();
        await jest.advanceTimersByTimeAsync(300);
      }),
    unmount: () => act(() => renderer.unmount()),
  };
}

describe('native reel end to the next permitted learning step', () => {
  beforeEach(() => {
    jest.useFakeTimers();
    jest.clearAllMocks();
    mockSeek.mockReset();
    jest.mocked(markSectionComplete).mockReset().mockResolvedValue(true);
  });
  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it.each(['pending', 'failed'] as const)(
    'autoplays an already permitted paid next reel while completion is %s',
    async outcome => {
      let settle!: (completed: boolean) => void;
      const confirmation = new Promise<boolean>(resolve => {
        settle = resolve;
      });
      jest.mocked(markSectionComplete).mockReturnValueOnce(confirmation);
      const h = await mount();
      try {
        if (outcome === 'failed') settle(false);
        await h.end();
        expect(h.scroll).toHaveBeenCalledTimes(1);
        expect(h.index()).toBe(1);
        expect(h.native().props.source.uri).toContain('/2.m3u8');
        expect(h.native().props.repeat).toBe(false);
        expect(h.native().props.paused).toBe(false);
        expect(mockSeek).not.toHaveBeenCalled();
        expect(savePlaybackPosition).toHaveBeenCalledTimes(1);
        expect(markSectionComplete).toHaveBeenCalledTimes(1);
        await act(async () => {
          settle(true);
          await jest.advanceTimersByTimeAsync(300);
        });
        expect(h.scroll).toHaveBeenCalledTimes(1);
      } finally {
        await h.unmount();
      }
    },
  );

  it('autoplays the next preview without repeating the ended video', async () => {
    const h = await mount({preview: true});
    try {
      await h.end();
      expect(h.index()).toBe(1);
      expect(h.native().props.paused).toBe(false);
      expect(h.native().props.repeat).toBe(false);
      expect(mockSeek).not.toHaveBeenCalled();
    } finally {
      await h.unmount();
    }
  });

  it('shows the purchase gate at the final preview and keeps that video ended', async () => {
    const h = await mount({preview: true, next: 'none'});
    try {
      await h.end();
      expect(h.gate()).toBe(true);
      expect(h.scroll).not.toHaveBeenCalled();
      expect(h.native().props.paused).toBe(true);
      expect(mockSeek).not.toHaveBeenCalled();
    } finally {
      await h.unmount();
    }
  });

  it.each(['project', 'locked-reel'] as const)(
    'still waits for confirmed completion before the %s boundary',
    async next => {
      let settle!: (completed: boolean) => void;
      jest.mocked(markSectionComplete).mockReturnValueOnce(
        new Promise(resolve => {
          settle = resolve;
        }),
      );
      const h = await mount({next});
      try {
        await h.end();
        expect(h.scroll).not.toHaveBeenCalled();
        expect(h.refresh).not.toHaveBeenCalled();
        expect(h.native().props.paused).toBe(true);
        await act(async () => {
          settle(true);
          await jest.advanceTimersByTimeAsync(300);
        });
        if (next === 'project') expect(h.refresh).toHaveBeenCalledWith(1);
        else expect(h.scroll).toHaveBeenCalledWith(1);
      } finally {
        await h.unmount();
      }
    },
  );

  it.each(['project', 'locked-reel'] as const)(
    'does not cross the %s boundary when completion is rejected',
    async next => {
      jest.mocked(markSectionComplete).mockResolvedValueOnce(false);
      const h = await mount({next});
      try {
        await h.end();
        expect(h.scroll).not.toHaveBeenCalled();
        expect(h.refresh).not.toHaveBeenCalled();
        expect(h.native().props.paused).toBe(true);
        expect(h.native().props.repeat).toBe(false);
        expect(mockSeek).not.toHaveBeenCalled();
      } finally {
        await h.unmount();
      }
    },
  );

  it('drops the queued autoplay when its account/course owner retires', async () => {
    const h = await mount();
    try {
      await act(async () => {
        h.native().props.onEnd();
        h.ownerGeneration.current += 1;
        await jest.advanceTimersByTimeAsync(300);
      });
      expect(h.scroll).not.toHaveBeenCalled();
      expect(mockSeek).not.toHaveBeenCalled();
    } finally {
      await h.unmount();
    }
  });
});
