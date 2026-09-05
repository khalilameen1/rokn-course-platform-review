jest.mock('../src/services/operationalTelemetry', () => ({
  reportClientError: jest.fn(),
}));
jest.mock('react-native-video', () => {
  const React = require('react');
  return {
    __esModule: true,
    default: React.forwardRef(() => null),
    SelectedVideoTrackType: {AUTO: 'auto', RESOLUTION: 'resolution'},
  };
});
jest.mock('react-native-linear-gradient', () => 'LinearGradient');

import VideoComponent from '../src/components/VideoPlayer/VideoComponent';
import {
  createVideoEventHandlers,
  SEEK_ACKNOWLEDGEMENT_TIMEOUT_MS,
} from '../src/components/VideoPlayer/video/eventHandlers';
import {
  formatVideoDuration,
  normalizeVideoUri,
  selectPlaybackErrorCopy,
  selectPlaybackRecoveryStep,
  selectVideoSource,
  selectVideoTimeline,
} from '../src/components/VideoPlayer/video/policy';

describe('VideoComponent facade', () => {
  it('keeps the named forwardRef default export', () => {
    expect(VideoComponent.displayName).toBe('VideoComponent');
    expect(typeof (VideoComponent as unknown as {render: unknown}).render).toBe(
      'function',
    );
  });
});

describe('video component policy', () => {
  it('acknowledges a seek from progress when older Android omits onSeek', () => {
    const pendingSeek = {current: 40 as number | null};
    const onProgressChange = jest.fn();
    const setCurrentTime = jest.fn();
    const handlers = createVideoEventHandlers({
      acceptsLearningEvents: () => true,
      bufferCount: {current: 0},
      bufferDurationMs: {current: 0},
      bufferingStartedAt: {current: null},
      data: {id: 'android-7-reel'},
      diagnosticRequest: {current: 0},
      durationRef: {current: 90},
      emitPlaybackEvent: jest.fn(),
      hasRestored: {current: true},
      hasStarted: {current: true},
      isFallbackSource: false,
      isPlaying: {current: true},
      lastPosition: {current: 0},
      loadStartedAt: {current: null},
      longBufferTimer: {current: null},
      onPlaybackHealthy: jest.fn(),
      onProgressChange,
      ownsPlayback: () => true,
      pendingSeek,
      pendingSeekStartedAt: {current: Date.now()},
      publishRuntimeMetrics: jest.fn(),
      recoverOrFail: jest.fn(),
      reelInitialPosition: {current: 0},
      retryPosition: {current: null},
      setBufferedTime: jest.fn(),
      setCurrentTime,
      setDuration: jest.fn(),
      setError: jest.fn(),
      setIsBuffering: jest.fn(),
      setIsLoaded: jest.fn(),
      setRecoveryMessage: jest.fn(),
      sourceType: 'm3u8',
      sourceUri: 'https://cdn.example.com/android-7.m3u8',
      videoRef: {current: null},
    });

    handlers.onProgress?.({
      currentTime: 40.5,
      playableDuration: 60,
      seekableDuration: 90,
    } as never);
    handlers.onProgress?.({
      currentTime: 44,
      playableDuration: 64,
      seekableDuration: 90,
    } as never);

    expect(pendingSeek.current).toBeNull();
    expect(setCurrentTime).toHaveBeenLastCalledWith(44);
    expect(onProgressChange).toHaveBeenLastCalledWith(44, 90);
  });

  it('recovers when a decoder never acknowledges the requested seek', () => {
    const now = jest.spyOn(Date, 'now').mockReturnValue(1_000);
    const pendingSeek = {current: 70 as number | null};
    const pendingSeekStartedAt = {current: 1_000 as number | null};
    const setCurrentTime = jest.fn();
    const onProgressChange = jest.fn();
    const handlers = createVideoEventHandlers({
      acceptsLearningEvents: () => true,
      bufferCount: {current: 0},
      bufferDurationMs: {current: 0},
      bufferingStartedAt: {current: null},
      data: {id: 'clamped-seek-reel'},
      diagnosticRequest: {current: 0},
      durationRef: {current: 90},
      emitPlaybackEvent: jest.fn(),
      hasRestored: {current: true},
      hasStarted: {current: true},
      isFallbackSource: false,
      isPlaying: {current: true},
      lastPosition: {current: 12},
      loadStartedAt: {current: null},
      longBufferTimer: {current: null},
      onPlaybackHealthy: jest.fn(),
      onProgressChange,
      ownsPlayback: () => true,
      pendingSeek,
      pendingSeekStartedAt,
      publishRuntimeMetrics: jest.fn(),
      recoverOrFail: jest.fn(),
      reelInitialPosition: {current: 0},
      retryPosition: {current: null},
      setBufferedTime: jest.fn(),
      setCurrentTime,
      setDuration: jest.fn(),
      setError: jest.fn(),
      setIsBuffering: jest.fn(),
      setIsLoaded: jest.fn(),
      setRecoveryMessage: jest.fn(),
      sourceType: 'm3u8',
      sourceUri: 'https://cdn.example.com/clamped-seek.m3u8',
      videoRef: {current: null},
    });

    handlers.onProgress?.({currentTime: 12, seekableDuration: 90} as never);
    expect(setCurrentTime).not.toHaveBeenCalled();

    now.mockReturnValue(1_000 + SEEK_ACKNOWLEDGEMENT_TIMEOUT_MS);
    handlers.onProgress?.({currentTime: 13, seekableDuration: 90} as never);

    expect(pendingSeek.current).toBeNull();
    expect(pendingSeekStartedAt.current).toBeNull();
    expect(setCurrentTime).toHaveBeenLastCalledWith(13);
    expect(onProgressChange).toHaveBeenLastCalledWith(13, 90);
    now.mockRestore();
  });

  it('drops a detached decoder seek when a remounted source starts at zero', () => {
    const pendingSeek = {current: 70 as number | null};
    const pendingSeekStartedAt = {current: Date.now() as number | null};
    const seek = jest.fn();
    const handlers = createVideoEventHandlers({
      acceptsLearningEvents: () => true,
      bufferCount: {current: 0},
      bufferDurationMs: {current: 0},
      bufferingStartedAt: {current: null},
      data: {id: 'remounted-reel'},
      diagnosticRequest: {current: 0},
      durationRef: {current: 90},
      emitPlaybackEvent: jest.fn(),
      hasRestored: {current: false},
      hasStarted: {current: false},
      isFallbackSource: false,
      isPlaying: {current: false},
      lastPosition: {current: 70},
      loadStartedAt: {current: null},
      longBufferTimer: {current: null},
      onPlaybackHealthy: jest.fn(),
      ownsPlayback: () => true,
      pendingSeek,
      pendingSeekStartedAt,
      publishRuntimeMetrics: jest.fn(),
      recoverOrFail: jest.fn(),
      reelInitialPosition: {current: 0},
      retryPosition: {current: 0},
      setBufferedTime: jest.fn(),
      setCurrentTime: jest.fn(),
      setDuration: jest.fn(),
      setError: jest.fn(),
      setIsBuffering: jest.fn(),
      setIsLoaded: jest.fn(),
      setRecoveryMessage: jest.fn(),
      sourceType: 'm3u8',
      sourceUri: 'https://cdn.example.com/remounted.m3u8',
      videoRef: {current: {seek} as never},
    });

    handlers.onLoad?.({duration: 90} as never);

    expect(pendingSeek.current).toBeNull();
    expect(pendingSeekStartedAt.current).toBeNull();
    expect(seek).not.toHaveBeenCalled();
  });

  it('does not arm buffer recovery behind chat or another overlay', () => {
    jest.useFakeTimers();
    const recoverOrFail = jest.fn();
    const bufferCount = {current: 0};
    const bufferingStartedAt = {current: null as number | null};
    const longBufferTimer = {
      current: null as ReturnType<typeof setTimeout> | null,
    };
    const handlers = createVideoEventHandlers({
      acceptsLearningEvents: () => false,
      bufferCount,
      bufferDurationMs: {current: 0},
      bufferingStartedAt,
      data: {id: 'covered-reel'},
      diagnosticRequest: {current: 0},
      durationRef: {current: 90},
      emitPlaybackEvent: jest.fn(),
      hasRestored: {current: true},
      hasStarted: {current: true},
      isFallbackSource: false,
      isPlaying: {current: false},
      lastPosition: {current: 18},
      loadStartedAt: {current: null},
      longBufferTimer,
      onPlaybackHealthy: jest.fn(),
      ownsPlayback: () => true,
      pendingSeek: {current: null},
      pendingSeekStartedAt: {current: null},
      publishRuntimeMetrics: jest.fn(),
      recoverOrFail,
      reelInitialPosition: {current: 0},
      retryPosition: {current: null},
      setBufferedTime: jest.fn(),
      setCurrentTime: jest.fn(),
      setDuration: jest.fn(),
      setError: jest.fn(),
      setIsBuffering: jest.fn(),
      setIsLoaded: jest.fn(),
      setRecoveryMessage: jest.fn(),
      sourceType: 'm3u8',
      sourceUri: 'https://cdn.example.com/covered.m3u8',
      videoRef: {current: null},
    });

    handlers.onBuffer?.({isBuffering: true} as never);
    jest.advanceTimersByTime(20_000);

    expect(longBufferTimer.current).toBeNull();
    expect(bufferCount.current).toBe(0);
    expect(bufferingStartedAt.current).toBeNull();
    expect(recoverOrFail).not.toHaveBeenCalled();
    jest.useRealTimers();
  });

  it('drops every native event owned by a replaced player instance', () => {
    const setCurrentTime = jest.fn();
    const onProgressChange = jest.fn();
    const onComplete = jest.fn();
    const recoverOrFail = jest.fn();
    const handlers = createVideoEventHandlers({
      acceptsLearningEvents: () => false,
      bufferCount: {current: 0},
      bufferDurationMs: {current: 0},
      bufferingStartedAt: {current: null},
      data: {id: 'old-reel'},
      diagnosticRequest: {current: 0},
      durationRef: {current: 60},
      emitPlaybackEvent: jest.fn(),
      hasRestored: {current: false},
      hasStarted: {current: false},
      isFallbackSource: false,
      isPlaying: {current: false},
      lastPosition: {current: 0},
      loadStartedAt: {current: null},
      longBufferTimer: {current: null},
      onComplete,
      onPlaybackHealthy: jest.fn(),
      onProgressChange,
      ownsPlayback: () => false,
      pendingSeek: {current: null},
      pendingSeekStartedAt: {current: null},
      publishRuntimeMetrics: jest.fn(),
      recoverOrFail,
      reelInitialPosition: {current: 0},
      retryPosition: {current: null},
      setBufferedTime: jest.fn(),
      setCurrentTime,
      setDuration: jest.fn(),
      setError: jest.fn(),
      setIsBuffering: jest.fn(),
      setIsLoaded: jest.fn(),
      setRecoveryMessage: jest.fn(),
      sourceType: 'm3u8',
      sourceUri: 'https://cdn.example.com/old.m3u8',
      videoRef: {current: null},
    });

    handlers.onLoadStart?.({isNetwork: true} as never);
    handlers.onProgress?.({
      currentTime: 59,
      playableDuration: 60,
      seekableDuration: 60,
    } as never);
    handlers.onError?.({error: {errorCode: 'late'}} as never);
    handlers.onEnd?.();

    expect(setCurrentTime).not.toHaveBeenCalled();
    expect(onProgressChange).not.toHaveBeenCalled();
    expect(recoverOrFail).not.toHaveBeenCalled();
    expect(onComplete).not.toHaveBeenCalled();
  });

  it('drops learning progress and completion from a hidden preloaded player', () => {
    const setCurrentTime = jest.fn();
    const onProgressChange = jest.fn();
    const onComplete = jest.fn();
    const handlers = createVideoEventHandlers({
      acceptsLearningEvents: () => false,
      bufferCount: {current: 0},
      bufferDurationMs: {current: 0},
      bufferingStartedAt: {current: null},
      data: {id: 'preloaded-reel'},
      diagnosticRequest: {current: 0},
      durationRef: {current: 60},
      emitPlaybackEvent: jest.fn(),
      hasRestored: {current: true},
      hasStarted: {current: false},
      isFallbackSource: false,
      isPlaying: {current: false},
      lastPosition: {current: 0},
      loadStartedAt: {current: null},
      longBufferTimer: {current: null},
      onComplete,
      onPlaybackHealthy: jest.fn(),
      onProgressChange,
      ownsPlayback: () => true,
      pendingSeek: {current: null},
      pendingSeekStartedAt: {current: null},
      publishRuntimeMetrics: jest.fn(),
      recoverOrFail: jest.fn(),
      reelInitialPosition: {current: 0},
      retryPosition: {current: null},
      setBufferedTime: jest.fn(),
      setCurrentTime,
      setDuration: jest.fn(),
      setError: jest.fn(),
      setIsBuffering: jest.fn(),
      setIsLoaded: jest.fn(),
      setRecoveryMessage: jest.fn(),
      sourceType: 'm3u8',
      sourceUri: 'https://cdn.example.com/preloaded.m3u8',
      videoRef: {current: null},
    });

    handlers.onProgress?.({currentTime: 60, seekableDuration: 60} as never);
    handlers.onSeek?.({currentTime: 60, seekTime: 60} as never);
    handlers.onPlaybackStateChanged?.({isPlaying: true} as never);
    handlers.onEnd?.();

    expect(setCurrentTime).not.toHaveBeenCalled();
    expect(onProgressChange).not.toHaveBeenCalled();
    expect(onComplete).not.toHaveBeenCalled();
  });

  it('normalizes remote sources without breaking emulator loopback URLs', () => {
    expect(
      normalizeVideoUri(' //cdn.example.com/video.m3u8?a=1&amp;b=2 '),
    ).toBe('https://cdn.example.com/video.m3u8?a=1&b=2');
    expect(normalizeVideoUri('http://cdn.example.com/video.mp4')).toBe(
      'https://cdn.example.com/video.mp4',
    );
    expect(normalizeVideoUri('http://10.0.2.2:8000/video.mp4')).toBe(
      'http://10.0.2.2:8000/video.mp4',
    );
  });

  it('selects quality variants and supported fallbacks with media types', () => {
    const variant = selectVideoSource({
      effectiveQuality: '720p',
      qualitySources: {'720p': 'https://cdn.example.com/video-720.mp4'},
      usingFallback: false,
      videoUrl: 'https://cdn.example.com/master.m3u8',
    });
    expect(variant.source).toEqual({
      uri: 'https://cdn.example.com/video-720.mp4',
      type: 'mp4',
    });
    expect(variant.selectedVariantUri).toContain('video-720.mp4');

    const fallback = selectVideoSource({
      effectiveQuality: 'auto',
      fallbackVideoUrl: 'https://cdn.example.com/fallback.mp4',
      usingFallback: false,
      videoUrl: 'https://youtube.com/watch?v=lesson',
    });
    expect(fallback.isFallbackSource).toBe(true);
    expect(fallback.source).toEqual({
      uri: 'https://cdn.example.com/fallback.mp4',
      type: 'mp4',
    });
    expect(fallback.unsupportedSource).toBe(false);

    const invalidVariant = selectVideoSource({
      effectiveQuality: '720p',
      qualitySources: {'720p': 'https://youtube.com/watch?v=wrong'},
      fallbackVideoUrl: 'https://cdn.example.com/fallback.mp4',
      usingFallback: false,
      videoUrl: 'https://cdn.example.com/master.m3u8',
    });
    expect(invalidVariant.isFallbackSource).toBe(true);
    expect(invalidVariant.source.uri).toBe(
      'https://cdn.example.com/fallback.mp4',
    );

    const duplicateFallback = selectVideoSource({
      effectiveQuality: 'auto',
      fallbackVideoUrl: 'https://cdn.example.com/master.m3u8',
      usingFallback: false,
      videoUrl: 'https://cdn.example.com/master.m3u8',
    });
    expect(duplicateFallback.hasSupportedFallback).toBe(false);
  });

  it('keeps the bounded recovery order', () => {
    const base = {
      adaptiveSource: true,
      availableQualities: ['auto', '1080p', '720p', '480p'] as const,
      effectiveQuality: 'auto' as const,
      hasSelectedVariant: false,
      hasSupportedFallback: true,
      isFallbackSource: false,
      isVisible: true,
      recoveryAttempts: 0,
      recoveryPending: false,
      sameSourceRetryUsed: false,
    };

    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
      }),
    ).toEqual({kind: 'quality', quality: '480p'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        recoveryAttempts: 2,
      }),
    ).toEqual({kind: 'fallback'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        hasSupportedFallback: false,
        recoveryAttempts: 2,
      }),
    ).toEqual({kind: 'retry'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        hasSupportedFallback: false,
        recoveryAttempts: 2,
        sameSourceRetryUsed: true,
      }),
    ).toEqual({kind: 'fail'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        isVisible: false,
      }),
    ).toEqual({kind: 'defer'});
    expect(
      selectPlaybackRecoveryStep({
        ...base,
        availableQualities: [...base.availableQualities],
        sourceRefreshRequired: true,
      }),
    ).toEqual({kind: 'retry'});
  });

  it('preserves timeline bounds and learner-facing failure copy', () => {
    expect(
      selectVideoTimeline({
        bufferedTime: 75,
        currentTime: 30,
        duration: 120,
        previewTime: 60,
      }),
    ).toEqual({
      accessibilityDuration: 120,
      accessibilityPosition: 60,
      bufferedProgress: 0.625,
      displayedTime: 60,
      duration: 120,
      progress: 0.5,
      remaining: 60,
    });
    expect(formatVideoDuration(65)).toBe('١:٠٥');
    expect(selectPlaybackErrorCopy('offline', false).title).toBe(
      'أنت غير متصل بالإنترنت',
    );
  });
});
