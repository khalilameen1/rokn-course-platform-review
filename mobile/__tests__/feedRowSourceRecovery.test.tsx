import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

jest.mock('../src/components/VideoPlayer/VideoComponent', () =>
  jest.fn(() => null),
);
jest.mock('../src/components/VideoPlayer/FeedFooter', () =>
  jest.fn(() => null),
);
jest.mock('../src/components/VideoPlayer/FeedHeader', () =>
  jest.fn(() => null),
);
jest.mock('../src/components/VideoPlayer/FeedSideBar', () =>
  jest.fn(() => null),
);
jest.mock('../src/components/VideoPlayer/ProjectTransition', () =>
  jest.fn(() => null),
);

import FeedRow, {
  SOURCE_PENDING_RETRY_DELAY_MS,
} from '../src/components/VideoPlayer/FeedRow';
import VideoComponent from '../src/components/VideoPlayer/VideoComponent';
import FeedSideBar from '../src/components/VideoPlayer/FeedSideBar';
import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
} from '../src/components/VideoPlayer/types';

const reel: CourseReel = {
  id: 'reel-1',
  lessonId: 'lesson-1',
  sectionId: 'section-1',
  moduleId: 'module-1',
  title: 'المقطع',
  caption: '',
  videoUrl: '',
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};

const item: CourseFeedItem = {
  key: 'reel-1',
  type: 'reel',
  moduleId: 'module-1',
  reel,
};

const course: CourseLearningData = {
  id: 'course-1',
  title: 'الكورس',
  totalReels: 1,
  attachments: [],
  modules: [
    {
      id: 'module-1',
      title: 'الوحدة',
      order: 1,
      isLocked: false,
      reels: [reel],
    },
  ],
};

describe('feed row source recovery', () => {
  beforeEach(() => {
    jest.clearAllMocks();
    jest.useFakeTimers();
  });
  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('replaces an endless source spinner with an owned retry action', async () => {
    const onRefreshVideo = jest.fn(async () => undefined);
    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <FeedRow
          item={item}
          course={course}
          pageWidth={390}
          pageHeight={844}
          frameWidth={390}
          isVisible
          playbackBlocked={false}
          shouldMountVideo={false}
          playbackSpeed={1}
          selectedQuality="auto"
          saved={false}
          savePending={false}
          initialPosition={0}
          topInset={0}
          bottomInset={0}
          onPlaybackSpeedChange={jest.fn()}
          onQualityChange={jest.fn()}
          onToggleSave={jest.fn()}
          onBeforeOpenSave={() => true}
          onOpenChat={jest.fn()}
          onSelectFeedItem={jest.fn()}
          onProgress={jest.fn()}
          onComplete={jest.fn()}
          onRefreshVideo={onRefreshVideo}
          onPlaybackEvent={jest.fn()}
          onPlaybackMetrics={jest.fn()}
          onSubmitProject={jest.fn()}
        />,
      );
    });

    expect(
      renderer!.root.findAllByProps({
        accessibilityLabel: 'إعادة محاولة تشغيل الفيديو',
      }),
    ).toHaveLength(0);

    await ReactTestRenderer.act(() => {
      jest.advanceTimersByTime(SOURCE_PENDING_RETRY_DELAY_MS);
    });
    const retry = renderer!.root.findByProps({
      accessibilityLabel: 'إعادة محاولة تشغيل الفيديو',
    });

    await ReactTestRenderer.act(() => retry.props.onPress());
    expect(onRefreshVideo).toHaveBeenCalledTimes(1);
    expect(
      renderer!.root.findAllByProps({
        accessibilityLabel: 'إعادة محاولة تشغيل الفيديو',
      }),
    ).toHaveLength(0);

    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('keeps a missing-source retry single-flight while the manifest is loading', async () => {
    let finishRefresh: (() => void) | undefined;
    const onRefreshVideo = jest.fn(
      () =>
        new Promise<void>(resolve => {
          finishRefresh = resolve;
        }),
    );
    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <FeedRow
          item={item}
          course={course}
          pageWidth={390}
          pageHeight={844}
          frameWidth={390}
          isVisible
          playbackBlocked={false}
          shouldMountVideo={false}
          playbackSpeed={1}
          selectedQuality="auto"
          saved={false}
          savePending={false}
          initialPosition={0}
          topInset={0}
          bottomInset={0}
          onPlaybackSpeedChange={jest.fn()}
          onQualityChange={jest.fn()}
          onToggleSave={jest.fn()}
          onBeforeOpenSave={() => true}
          onOpenChat={jest.fn()}
          onSelectFeedItem={jest.fn()}
          onProgress={jest.fn()}
          onComplete={jest.fn()}
          onRefreshVideo={onRefreshVideo}
          onPlaybackEvent={jest.fn()}
          onPlaybackMetrics={jest.fn()}
          onSubmitProject={jest.fn()}
        />,
      );
    });
    await ReactTestRenderer.act(() => {
      jest.advanceTimersByTime(SOURCE_PENDING_RETRY_DELAY_MS);
    });
    const retry = renderer!.root.findByProps({
      accessibilityLabel: 'إعادة محاولة تشغيل الفيديو',
    });

    await ReactTestRenderer.act(() => {
      retry.props.onPress();
      retry.props.onPress();
    });
    expect(onRefreshVideo).toHaveBeenCalledTimes(1);

    await ReactTestRenderer.act(async () => {
      finishRefresh?.();
      await Promise.resolve();
    });
    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('does not carry an attachment clock into a recycled reel row', async () => {
    const playableReel = {
      ...reel,
      videoUrl: 'https://cdn.example.com/reel-1.m3u8',
    };
    const playableItem = {...item, reel: playableReel};
    const nextReel = {
      ...playableReel,
      id: 'reel-2',
      lessonId: 'lesson-2',
      sectionId: 'section-2',
      videoUrl: 'https://cdn.example.com/reel-2.m3u8',
    };
    const nextItem: CourseFeedItem = {
      key: 'reel-2',
      type: 'reel',
      moduleId: 'module-1',
      reel: nextReel,
    };
    const courseWithPrompt: CourseLearningData = {
      ...course,
      attachments: [
        {
          id: 'attachment-1',
          title: 'ملف الكورس',
          url: 'https://cdn.example.com/course.pdf',
          platform: 'mobile',
        },
      ],
      attachmentPrompt: {
        enabled: true,
        atSeconds: 5,
        title: 'مرفقات الكورس',
        body: 'حمّل الملفات',
        buttonText: 'تحميل',
        frequency: 'once_per_course',
      },
      modules: [{...course.modules[0], reels: [playableReel, nextReel]}],
    };
    const commonProps = {
      course: courseWithPrompt,
      pageWidth: 390,
      pageHeight: 844,
      frameWidth: 390,
      isVisible: true,
      playbackBlocked: false,
      shouldMountVideo: true,
      playbackSpeed: 1,
      selectedQuality: 'auto' as const,
      saved: false,
      savePending: false,
      initialPosition: 0,
      topInset: 0,
      bottomInset: 0,
      onPlaybackSpeedChange: jest.fn(),
      onQualityChange: jest.fn(),
      onToggleSave: jest.fn(),
      onBeforeOpenSave: () => true,
      onOpenChat: jest.fn(),
      onSelectFeedItem: jest.fn(),
      onProgress: jest.fn(),
      onComplete: jest.fn(),
      onRefreshVideo: jest.fn(),
      onPlaybackEvent: jest.fn(),
      onPlaybackMetrics: jest.fn(),
      onSubmitProject: jest.fn(),
    };
    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <FeedRow item={playableItem} {...commonProps} />,
      );
    });

    const videoProps = jest.mocked(VideoComponent).mock.calls.at(-1)?.[0];
    await ReactTestRenderer.act(() => videoProps?.onProgress?.(8, 30));
    expect(
      jest.mocked(FeedSideBar).mock.calls.at(-1)?.[0].currentTime,
    ).toBe(5);

    await ReactTestRenderer.act(() => {
      renderer!.update(<FeedRow item={nextItem} {...commonProps} />);
    });

    expect(
      jest.mocked(FeedSideBar).mock.calls.at(-1)?.[0].currentTime,
    ).toBe(0);
    await ReactTestRenderer.act(() => renderer!.unmount());
  });
});
