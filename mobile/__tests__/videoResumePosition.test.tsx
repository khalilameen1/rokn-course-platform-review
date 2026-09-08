import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';
import {
  useVideoController,
  type VideoComponentProps,
} from '../src/components/VideoPlayer/video/useVideoController';
import type {CourseReel} from '../src/components/VideoPlayer/types';

let mockAppActive = true;

jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppActiveState: () => mockAppActive,
  useAppForegroundState: () => mockAppActive,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('react-native-video', () => ({
  SelectedVideoTrackType: {AUTO: 'auto', RESOLUTION: 'resolution'},
}));

const reel: CourseReel = {
  id: 'resume-reel',
  lessonId: 'resume-lesson',
  sectionId: 'resume-section',
  moduleId: 'resume-module',
  title: 'المقطع',
  caption: '',
  videoUrl: 'https://cdn.example.com/resume.m3u8',
  availableQualities: ['auto', '480p'],
  durationSeconds: 20,
  isPreview: true,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};

async function mount(initialPosition = 0) {
  const seek = jest.fn();
  const onComplete = jest.fn();
  const onProgress = jest.fn();
  let props: VideoComponentProps = {
    data: reel,
    height: 844,
    width: 390,
    isVisible: true,
    initialPosition,
    onComplete,
    onProgress,
  };
  let current!: ReturnType<typeof useVideoController>;
  function Harness() {
    current = useVideoController(props, null);
    current.videoRef.current = {seek} as never;
    return null;
  }
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(() => {
    renderer = TestRenderer.create(<Harness />);
  });
  return {
    get current() {
      return current;
    },
    load: (duration = 20) =>
      act(() => current.videoEventHandlers.onLoad?.({duration} as never)),
    progress: (currentTime: number) =>
      act(() =>
        current.videoEventHandlers.onProgress?.({
          currentTime,
          seekableDuration: 20,
        } as never),
      ),
    update: (changes: Partial<VideoComponentProps> = {}) =>
      act(() => {
        props = {...props, ...changes};
        renderer.update(<Harness />);
      }),
    unmount: () => act(() => renderer.unmount()),
    onComplete,
    onProgress,
    seek,
  };
}

describe('fresh replay versus live playback restoration', () => {
  beforeEach(() => {
    mockAppActive = true;
    jest.useFakeTimers();
  });
  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it.each(['background', 'quality', 'manifest'] as const)(
    'restores 17.5 of 20 seconds after a live %s remount',
    async transition => {
      const h = await mount();
      try {
        await h.load();
        await h.progress(17.5);
        h.onProgress.mockClear();
        if (transition === 'background') {
          mockAppActive = false;
          await h.update();
          mockAppActive = true;
          await h.update();
        } else if (transition === 'quality') {
          await h.update({selectedQuality: '480p'});
        } else {
          await h.update({
            data: {...reel, playbackSessionId: 'renewed-session'},
          });
        }
        await h.load();
        expect(h.seek).toHaveBeenLastCalledWith(17.5);
        expect(h.current.currentTime).toBe(17.5);
        expect(h.onProgress).not.toHaveBeenCalled();
        expect(h.onComplete).not.toHaveBeenCalled();
        await h.progress(18.5);
        expect(h.onProgress).toHaveBeenLastCalledWith(18.5, 20);
      } finally {
        await h.unmount();
      }
    },
  );

  it.each([17.5, 20, 25])(
    'still starts a fresh near-end or completed reopen at zero (saved: %s)',
    async initialPosition => {
      const h = await mount(initialPosition);
      try {
        await h.load();
        expect(h.current.currentTime).toBe(0);
        expect(h.seek).not.toHaveBeenCalled();
        expect(h.onComplete).not.toHaveBeenCalled();
      } finally {
        await h.unmount();
      }
    },
  );

  it('keeps a live zero instead of falling back to the saved initial position', async () => {
    const h = await mount(8);
    try {
      await h.load();
      await act(() =>
        h.current.videoEventHandlers.onSeek?.({
          currentTime: 8,
          seekTime: 8,
        } as never),
      );
      await h.progress(0);
      h.seek.mockClear();
      await h.update({selectedQuality: '480p'});
      await h.load();
      expect(h.current.currentTime).toBe(0);
      expect(h.seek).not.toHaveBeenCalled();
    } finally {
      await h.unmount();
    }
  });

  it('bounds a live restore by the new native duration without completing a lesson on load', async () => {
    const h = await mount();
    try {
      await h.load();
      await h.progress(17.5);
      await h.update({selectedQuality: '480p'});
      await h.load(17);
      expect(h.seek).toHaveBeenLastCalledWith(17);
      expect(h.current.currentTime).toBe(17);
      expect(h.onComplete).not.toHaveBeenCalled();
    } finally {
      await h.unmount();
    }
  });
});
