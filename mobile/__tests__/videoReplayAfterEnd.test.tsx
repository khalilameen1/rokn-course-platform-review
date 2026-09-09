import React from 'react';
import TestRenderer, {act} from 'react-test-renderer';

let mockAppActive = true;
const mockSeek = jest.fn();

jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppActiveState: () => mockAppActive,
  useAppForegroundState: () => mockAppActive,
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

import VideoComponent from '../src/components/VideoPlayer/VideoComponent';
import type {CourseReel} from '../src/components/VideoPlayer/types';

const reel: CourseReel = {
  id: 'replay-reel',
  lessonId: 'replay-lesson',
  sectionId: 'replay-section',
  moduleId: 'replay-module',
  title: 'المقطع',
  caption: '',
  videoUrl: 'https://cdn.example.com/replay.m3u8',
  durationSeconds: 20,
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};

async function mount() {
  const onComplete = jest.fn();
  const onProgress = jest.fn();
  let props: React.ComponentProps<typeof VideoComponent> = {
    data: reel,
    width: 390,
    height: 844,
    isVisible: true,
    onComplete,
    onProgress,
  };
  const render = () => <VideoComponent {...props} />;
  let renderer!: TestRenderer.ReactTestRenderer;
  await act(() => {
    renderer = TestRenderer.create(render());
  });
  const native = () => renderer.root.findByProps({testID: 'native-video'});
  await act(() => native().props.onLoad({duration: 20}));
  return {
    native,
    onComplete,
    onProgress,
    renderer,
    tap: () =>
      act(() => {
        const button = renderer.root.findAll(
          node =>
            node.props.accessibilityRole === 'button' &&
            typeof node.props.onAccessibilityAction === 'function',
        )[0];
        button.props.onAccessibilityAction({
          nativeEvent: {actionName: 'activate'},
        });
      }),
    update: (changes: Partial<typeof props> = {}) =>
      act(() => {
        props = {...props, ...changes};
        renderer.update(render());
      }),
    unmount: () => act(() => renderer.unmount()),
  };
}

describe('explicit replay after a reel finishes on screen', () => {
  beforeEach(() => {
    mockAppActive = true;
    mockSeek.mockReset();
    jest.useFakeTimers();
  });
  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('restarts the ended decoder on the next explicit play tap without an automatic replay', async () => {
    const h = await mount();
    try {
      await act(() => h.native().props.onEnd());
      expect(h.onComplete).toHaveBeenCalledTimes(1);
      expect(h.onProgress).toHaveBeenLastCalledWith(20, 20);
      expect(mockSeek).not.toHaveBeenCalled();
      expect(h.native().props.paused).toBe(true);
      expect(
        h.renderer.root.findAllByProps({accessibilityLabel: 'تشغيل الفيديو'})
          .length,
      ).toBeGreaterThan(0);

      await h.tap();
      // The installed Android resumePlayback only sets playWhenReady. A
      // decoder in STATE_ENDED needs an explicit seek to play this reel again.
      expect(mockSeek).toHaveBeenCalledWith(0);
      expect(h.native().props.paused).toBe(false);
      expect(h.onComplete).toHaveBeenCalledTimes(1);
      await act(() =>
        h.native().props.onProgress({currentTime: 1, seekableDuration: 20}),
      );
      expect(h.onProgress).toHaveBeenLastCalledWith(1, 20);
    } finally {
      await h.unmount();
    }
  });

  it('continues a deliberate mid-reel pause without rewinding', async () => {
    const h = await mount();
    try {
      await act(() =>
        h.native().props.onProgress({currentTime: 9, seekableDuration: 20}),
      );
      await h.tap();
      expect(h.native().props.paused).toBe(true);
      await h.tap();
      expect(h.native().props.paused).toBe(false);
      expect(mockSeek).not.toHaveBeenCalled();
      expect(h.onComplete).not.toHaveBeenCalled();
    } finally {
      await h.unmount();
    }
  });

  it("plays from the learner's new seek after completion instead of forcing zero", async () => {
    const h = await mount();
    try {
      await act(() => h.native().props.onEnd());
      await act(() =>
        h.renderer.root
          .findByProps({testID: 'video-timeline-touch-track'})
          .props.onAccessibilityAction({
            nativeEvent: {actionName: 'decrement'},
          }),
      );
      expect(mockSeek).toHaveBeenCalledTimes(1);
      expect(mockSeek).toHaveBeenCalledWith(10);
      expect(h.native().props.paused).toBe(true);
      await h.tap();
      expect(h.native().props.paused).toBe(false);
      expect(mockSeek).toHaveBeenCalledTimes(1);
      await act(() =>
        h.native().props.onProgress({currentTime: 11, seekableDuration: 20}),
      );
      expect(h.onProgress).toHaveBeenLastCalledWith(11, 20);
      expect(h.onComplete).toHaveBeenCalledTimes(1);
    } finally {
      await h.unmount();
    }
  });

  it('keeps an ended reel paused through background and restores explicit replay on return', async () => {
    const h = await mount();
    try {
      await act(() => h.native().props.onEnd());
      mockAppActive = false;
      await h.update();
      expect(
        h.renderer.root.findAllByProps({testID: 'native-video'}),
      ).toHaveLength(0);
      mockAppActive = true;
      await h.update();
      await act(() => h.native().props.onLoad({duration: 20}));
      expect(mockSeek).toHaveBeenLastCalledWith(20);
      expect(h.native().props.paused).toBe(true);
      await h.tap();
      expect(mockSeek).toHaveBeenLastCalledWith(0);
      expect(h.native().props.paused).toBe(false);
      expect(h.onComplete).toHaveBeenCalledTimes(1);
    } finally {
      await h.unmount();
    }
  });

  it('does not let an old decoder end event pause the replacement reel', async () => {
    const h = await mount();
    try {
      const oldEnd = h.native().props.onEnd;
      await act(() => oldEnd());
      await h.update({
        data: {...reel, id: 'next-reel', lessonId: 'next-lesson'},
      });
      await act(() => h.native().props.onLoad({duration: 20}));
      expect(h.native().props.paused).toBe(false);
      await act(() => oldEnd());
      expect(h.native().props.paused).toBe(false);
      expect(h.onComplete).toHaveBeenCalledTimes(1);
      expect(mockSeek).not.toHaveBeenCalled();
    } finally {
      await h.unmount();
    }
  });
});
