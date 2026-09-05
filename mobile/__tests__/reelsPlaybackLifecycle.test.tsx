import fs from 'fs';
import path from 'path';
import React from 'react';
import ReactTestRenderer from 'react-test-renderer';

let mockAppActive = true;
let mockWindowFocused = true;

jest.mock('../src/hooks/useAppActiveState', () => ({
  useAppActiveState: () => mockAppActive && mockWindowFocused,
  useAppForegroundState: () => mockAppActive,
}));
jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('react-native-video', () => {
  const ReactModule = require('react');
  const ReactNative = require('react-native');
  return {
    __esModule: true,
    default: ReactModule.forwardRef((props: object, _ref: unknown) =>
      ReactModule.createElement(ReactNative.View, {
        ...props,
        testID: 'native-video',
      }),
    ),
    BufferingStrategyType: {DEPENDING_ON_MEMORY: 'DependingOnMemory'},
    SelectedVideoTrackType: {AUTO: 'auto', RESOLUTION: 'resolution'},
    ViewType: {TEXTURE: 'texture'},
  };
});
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

import VideoComponent from '../src/components/VideoPlayer/VideoComponent';
import type {CourseReel} from '../src/components/VideoPlayer/types';

const reel: CourseReel = {
  id: 'reel-1',
  lessonId: 'lesson-1',
  sectionId: 'section-1',
  moduleId: 'module-1',
  title: 'عنوان المقطع',
  caption: 'شرح المقطع',
  videoUrl: 'https://cdn.example.com/lesson.m3u8',
  availableQualities: ['auto'],
  isPreview: false,
  isLocked: false,
  isCompleted: false,
  reelNumber: 1,
};

describe('reels native playback lifecycle', () => {
  beforeEach(() => {
    mockAppActive = true;
    mockWindowFocused = true;
    jest.useFakeTimers();
  });

  afterEach(() => {
    jest.clearAllTimers();
    jest.useRealTimers();
  });

  it('keeps the same paused decoder when a dialog takes window focus', async () => {
    let renderer!: ReactTestRenderer.ReactTestRenderer;
    const renderVideo = () => (
      <VideoComponent data={reel} width={390} height={844} isVisible />
    );
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(renderVideo());
    });
    const player = renderer.root.findAllByProps({testID: 'native-video'})[0];
    await ReactTestRenderer.act(() => player.props.onLoad({duration: 60}));
    mockWindowFocused = false;
    await ReactTestRenderer.act(() => renderer.update(renderVideo()));
    const obscured = renderer.root.findAllByProps({testID: 'native-video'})[0];
    expect(obscured).toBe(player);
    expect(obscured.props.paused).toBe(true);
    expect(obscured.props.muted).toBe(true);
    mockWindowFocused = true;
    await ReactTestRenderer.act(() => renderer.update(renderVideo()));
    expect(renderer.root.findAllByProps({testID: 'native-video'})[0]).toBe(
      player,
    );
    await ReactTestRenderer.act(() => renderer.unmount());
  });

  it.each([true, false])(
    'only cancels recovery when the native timeline advances (advances: %s)',
    async advances => {
      let renderer!: ReactTestRenderer.ReactTestRenderer;
      await ReactTestRenderer.act(() => {
        renderer = ReactTestRenderer.create(
          <VideoComponent data={reel} width={390} height={844} isVisible />,
        );
      });
      const native = () =>
        renderer.root.findAllByProps({testID: 'native-video'})[0];
      await ReactTestRenderer.act(() => native().props.onLoad({duration: 60}));
      await ReactTestRenderer.act(() =>
        native().props.onProgress({currentTime: 7, seekableDuration: 60}),
      );
      await ReactTestRenderer.act(() =>
        native().props.onError({error: {errorString: 'temporary failure'}}),
      );
      const before = native();
      await ReactTestRenderer.act(() =>
        native().props.onProgress({
          currentTime: advances ? 8 : 7,
          seekableDuration: 60,
        }),
      );
      await ReactTestRenderer.act(() => jest.advanceTimersByTime(120));
      if (advances) expect(native()).toBe(before);
      else expect(native()).not.toBe(before);
      await ReactTestRenderer.act(() => renderer.unmount());
    },
  );

  it('closes a visible playback owner when FlatList removes its row', async () => {
    const onPlaybackEvent = jest.fn();
    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <VideoComponent
          data={reel}
          width={390}
          height={844}
          isVisible
          onPlaybackEvent={onPlaybackEvent}
        />,
      );
    });

    await ReactTestRenderer.act(() => renderer!.unmount());

    expect(onPlaybackEvent).toHaveBeenCalledTimes(1);
    expect(onPlaybackEvent).toHaveBeenCalledWith(
      expect.objectContaining({
        eventType: 'stop',
        endReason: 'lesson_changed',
      }),
    );
  });

  it('closes the previous playback owner when a virtualized row is reused', async () => {
    const oldOwner = jest.fn();
    const newOwner = jest.fn();
    const nextReel: CourseReel = {
      ...reel,
      id: 'reel-2',
      lessonId: 'lesson-2',
      videoUrl: 'https://cdn.example.com/lesson-2.m3u8',
    };
    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <VideoComponent
          data={reel}
          width={390}
          height={844}
          isVisible
          onPlaybackEvent={oldOwner}
        />,
      );
    });

    await ReactTestRenderer.act(() => {
      renderer!.update(
        <VideoComponent
          data={nextReel}
          width={390}
          height={844}
          isVisible
          onPlaybackEvent={newOwner}
        />,
      );
    });

    expect(oldOwner).toHaveBeenCalledWith(
      expect.objectContaining({
        eventType: 'stop',
        endReason: 'lesson_changed',
      }),
    );
    expect(newOwner).not.toHaveBeenCalled();
    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('detaches the native decoder in background without unmounting the reel state', async () => {
    const props = {
      data: reel,
      width: 390,
      height: 844,
      isVisible: true,
    };
    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(<VideoComponent {...props} />);
    });
    try {
      expect(
        renderer!.root.findAllByProps({testID: 'native-video'}).length,
      ).toBeGreaterThan(0);

      mockAppActive = false;
      await ReactTestRenderer.act(() => {
        renderer!.update(<VideoComponent {...props} />);
      });
      expect(
        renderer!.root.findAllByProps({testID: 'native-video'}),
      ).toHaveLength(0);

      mockAppActive = true;
      await ReactTestRenderer.act(() => {
        renderer!.update(<VideoComponent {...props} />);
      });
      expect(
        renderer!.root.findAllByProps({testID: 'native-video'}).length,
      ).toBeGreaterThan(0);
    } finally {
      await ReactTestRenderer.act(() => renderer!.unmount());
    }
  });

  it('does not carry a transient audio-focus pause back to an old row', async () => {
    let renderer: ReactTestRenderer.ReactTestRenderer;
    await ReactTestRenderer.act(() => {
      renderer = ReactTestRenderer.create(
        <VideoComponent data={reel} width={390} height={844} isVisible />,
      );
    });

    const player = renderer!.root.findByProps({testID: 'native-video'});
    await ReactTestRenderer.act(() => {
      player.props.onAudioFocusChanged({hasAudioFocus: false});
    });
    expect(
      renderer!.root.findByProps({testID: 'native-video'}).props.paused,
    ).toBe(true);

    await ReactTestRenderer.act(() => {
      renderer!.update(
        <VideoComponent
          data={reel}
          width={390}
          height={844}
          isVisible={false}
        />,
      );
    });
    await ReactTestRenderer.act(() => {
      renderer!.update(
        <VideoComponent data={reel} width={390} height={844} isVisible />,
      );
    });

    expect(
      renderer!.root.findByProps({testID: 'native-video'}).props.paused,
    ).toBe(false);
    await ReactTestRenderer.act(() => renderer!.unmount());
  });

  it('preloads the next signed source and never retains decoders behind another screen', () => {
    const manifestOwner = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsManifestOwner.ts'),
      'utf8',
    );
    const renderer = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsFeedRenderer.tsx'),
      'utf8',
    );

    expect(manifestOwner).toContain(
      'const nextItem = feedItems[currentIndex + 1]',
    );
    expect(manifestOwner).toContain('void requestPlaybackManifest(');
    expect(renderer).toMatch(
      /shouldMountVideo=\{\s*screenFocused\s*&&\s*Boolean\(reel\?\.videoUrl\.trim\(\)\)/,
    );
  });

  it('does not hold the first manifest behind remote profile reconciliation', () => {
    const preferences = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/usePlaybackPreferences.ts'),
      'utf8',
    );
    const readyFromDevice = preferences.indexOf(
      'setPlaybackPreferencesReady(true)',
    );
    const remoteProfile = preferences.indexOf('await getProfile(boundary)');

    expect(readyFromDevice).toBeGreaterThan(0);
    expect(remoteProfile).toBeGreaterThan(readyFromDevice);
    expect(preferences).toContain("savedQuality === 'توفير البيانات'");
  });

  it('lets real viewability own the active decoder during fast paging', () => {
    const position = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsPosition.ts'),
      'utf8',
    );
    const controller = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsController.tsx'),
      'utf8',
    );
    const surface = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/ReelsSurface.tsx'),
      'utf8',
    );
    const scrollHelper = position.slice(
      position.indexOf('const scrollToIndex = useCallback('),
      position.indexOf('const scrollToKey = useCallback('),
    );

    expect(scrollHelper).not.toContain('setCurrentIndex(index)');
    expect(position).toContain('currentIndexRef.current = visible.index;');
    expect(position).toContain('setCurrentIndex(visible.index);');
    expect(position).toContain('const [paging, setPaging] = useState(false);');
    expect(surface).toContain('onScrollBeginDrag={controller.onPagingStarted}');
    expect(surface).toContain('onMomentumScrollEnd={event =>');
    expect(controller).toContain('interactionLocked || paging');
  });

  it('moves the native list with a same-course resume target', () => {
    const position = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsPosition.ts'),
      'utf8',
    );
    const initialTarget = position.slice(
      position.indexOf('const target = resolvePendingFeedPosition('),
      position.indexOf('const scrollToIndex = useCallback'),
    );

    expect(initialTarget).toContain('if (target === null) return;');
    expect(initialTarget).toContain('setCurrentIndex(target)');
    expect(initialTarget).toContain('listRef.current?.scrollToOffset({');
    expect(initialTarget).toContain('offset: target * layout.height');
  });

  it('keeps position, saved state, and playback runtime behind one owner each', () => {
    const controller = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsController.tsx'),
      'utf8',
    );
    const runtime = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/screens/reels/useReelsPlaybackRuntime.ts',
      ),
      'utf8',
    );
    const lifecycle = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsLifecycle.ts'),
      'utf8',
    );
    const manifest = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/usePlaybackManifest.ts'),
      'utf8',
    );
    const manifestOwner = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsManifestOwner.ts'),
      'utf8',
    );
    const courseState = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsCourseState.ts'),
      'utf8',
    );
    const courseLoader = fs.readFileSync(
      path.resolve(__dirname, '../src/screens/reels/useReelsCourseLoader.ts'),
      'utf8',
    );
    const videoController = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/video/useVideoController.tsx',
      ),
      'utf8',
    );
    const videoEvents = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/video/eventHandlers.ts',
      ),
      'utf8',
    );
    const playbackRecovery = fs.readFileSync(
      path.resolve(
        __dirname,
        '../src/components/VideoPlayer/video/usePlaybackRecovery.ts',
      ),
      'utf8',
    );

    expect(controller).toContain('useReelsPosition({');
    expect(controller).toContain('useReelsSavedLessons({');
    expect(controller).toContain('useReelsPlaybackRuntime({');
    expect(controller).not.toContain('setCurrentIndex(');
    expect(controller).not.toContain('scrollOffsetRef');
    expect(controller).not.toContain('savePendingRef');
    expect(controller).not.toContain('persistLocalPlaybackPosition');
    expect(controller).toContain('useReelsManifestOwner({');
    expect(controller).not.toContain('scheduledManifestRefreshesRef');
    expect(manifestOwner).toContain('scheduledRefreshes.current.has');
    expect(manifestOwner).toContain('scheduledRefreshes.current.size > 64');
    expect(courseState).toContain('loadedCourseRef.current = next;');
    expect(courseLoader).toContain('requestInitialPosition({');
    expect(courseLoader).not.toContain('pendingInitialIndex.current');

    const localResume = runtime.indexOf('persistLocalPlaybackPosition(');
    const optionalTelemetry = runtime.indexOf(
      'if (!reel.playbackSessionId) return;',
    );
    expect(localResume).toBeGreaterThan(0);
    expect(optionalTelemetry).toBeGreaterThan(localResume);
    expect(runtime).toContain('const reportBackground = useCallback(');
    expect(lifecycle).not.toContain('reportPlaybackSessionEvent');
    expect(lifecycle).not.toContain("reportActiveSession(refs, 'stop'");
    expect(manifest).toContain('const flightKey = lessonId;');
    expect(manifest).toContain('MAX_AUTOMATIC_MANIFEST_RETRIES = 3');
    expect(manifest).toContain(
      'refs.versions.current[lessonId] !== requestVersion',
    );
    expect(playbackRecovery).toContain('longBufferTimer.current = timer;');
    expect(videoEvents).not.toContain('setTimeout(');
    const manifestReplacement = videoController.slice(
      videoController.indexOf(
        '// A fresh server manifest is a new authoritative source generation.',
      ),
      videoController.indexOf(
        'if (preferredQualityRef.current === selectedQuality) return;',
      ),
    );
    expect(manifestReplacement).not.toContain(
      'recoveryAttemptsRef.current = 0',
    );
    expect(manifestReplacement).not.toContain(
      'sameSourceRetryUsedRef.current = false',
    );
    expect(playbackRecovery).toContain(
      'const markPlaybackHealthy = useCallback',
    );
  });
});
