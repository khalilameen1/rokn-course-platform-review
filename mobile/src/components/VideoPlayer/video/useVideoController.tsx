import {
  type ForwardedRef,
  useCallback,
  useEffect,
  useImperativeHandle,
  useMemo,
  useRef,
  useState,
} from 'react';
import {VideoRef} from 'react-native-video';
import {CourseReel, VideoQuality} from '../types';
import {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../playbackTelemetry';
import {createVideoEventHandlers} from './eventHandlers';
import {
  useAppActiveState,
  useAppForegroundState,
} from '../../../hooks/useAppActiveState';
import {usePlaybackInterruption} from './usePlaybackInterruption';
import {usePlaybackRecovery} from './usePlaybackRecovery';
import {usePlaybackStatus} from './usePlaybackStatus';
import {usePlaybackTelemetry} from './usePlaybackTelemetry';
import {useVideoSourceSelection} from './useVideoSourceSelection';
import {useVideoTimelineController} from './useVideoTimelineController';
import {useVideoZoom} from './useVideoZoom';
import {
  captionStyle,
  normalizeReportedTextTracks,
  selectCaptionTrack,
  setCaptionsEnabled,
  toSideloadedTextTracks,
  useCaptionsEnabled,
  type ReportedTextTrack,
} from './captions';
import {resolveVideoPresentation, type VideoContentFrame} from './policy';

export interface VideoComponentHandle {
  seekTo: (seconds: number) => void;
}

export interface VideoComponentProps {
  data: CourseReel;
  nativePreloadOwner?: string;
  width: number;
  height: number;
  isVisible: boolean;
  playbackBlocked?: boolean;
  controlsHidden?: boolean;
  playbackSpeed?: number;
  selectedQuality?: VideoQuality;
  initialPosition?: number;
  bottomInset?: number;
  onProgress?: (currentTime: number, duration: number) => void;
  onComplete?: () => void;
  onRefreshSource?: () => void | Promise<void>;
  onPlaybackEvent?: (event: PlaybackPlayerEvent) => void;
  onPlaybackMetrics?: (metrics: PlaybackRuntimeMetrics) => void;
  /** The picture's band inside the frame when a landscape source is letterboxed. */
  onContentFrameChange?: (frame: VideoContentFrame | null) => void;
}

export const useVideoController = (
  {
    data,
    nativePreloadOwner,
    width,
    height,
    isVisible,
    playbackBlocked = false,
    controlsHidden = false,
    playbackSpeed = 1,
    selectedQuality = 'auto',
    initialPosition = 0,
    bottomInset = 0,
    onProgress,
    onComplete,
    onRefreshSource,
    onPlaybackEvent,
    onPlaybackMetrics,
    onContentFrameChange,
  }: VideoComponentProps,
  forwardedRef: ForwardedRef<VideoComponentHandle>,
) => {
  const videoRef = useRef<VideoRef>(null);
  const declaredDurationRef = useRef(
    Number.isFinite(Number(data.durationSeconds))
      ? Math.max(0, Number(data.durationSeconds))
      : 0,
  );
  const reelIdentityRef = useRef(data.id);
  const reelInitialPositionRef = useRef(initialPosition);
  const hasRestoredRef = useRef(false);
  const retryPositionRef = useRef<number | null>(null);
  const preferredQualityRef = useRef(selectedQuality);
  const recoveryAttemptsRef = useRef(0);
  const sameSourceRetryUsedRef = useRef(false);
  const deferredPreloadFailureRef = useRef(false);
  const diagnosticRequestRef = useRef(0);
  const playbackLifecycleGenerationRef = useRef(0);
  const manualRetryFlightRef = useRef<symbol | null>(null);
  const activePlayerOwnerRef = useRef('');
  const acceptsLearningEventsRef = useRef(false);
  const previousVisibleRef = useRef(isVisible);
  const previousManifestIdentityRef = useRef(
    `${data.playbackSessionId || 'local'}:${
      data.playbackManifestRevision || 0
    }`,
  );
  const stopOnRemovalRef = useRef<() => void>(() => undefined);
  const {
    currentTime,
    durationRef,
    lastPositionRef,
    panHandlers,
    pendingSeekRef,
    pendingSeekStartedAtRef,
    previewTime,
    seekBy,
    seekTo,
    setBufferedTime,
    setCurrentTime,
    setDuration,
    setPreviewTime,
    setTrackWidth,
    timeline,
    trackWidth,
  } = useVideoTimelineController({
    initialDuration: declaredDurationRef.current,
    initialPosition,
    videoRef,
  });
  const {
    bufferCountRef,
    bufferDurationMsRef,
    bufferingStartedAtRef,
    emitPlaybackEvent,
    hasStartedRef,
    isPlayingRef,
    loadStartedAtRef,
    publishRuntimeMetrics,
  } = usePlaybackTelemetry({
    durationRef,
    onPlaybackEvent,
    onPlaybackMetrics,
    positionRef: lastPositionRef,
    recoveryAttemptsRef,
  });
  const {
    error,
    failPlayback,
    failureKind,
    isBuffering,
    isLoaded,
    markStatusHealthy,
    recoveryMessage,
    resetStatus,
    setError,
    setFailureKind,
    setIsBuffering,
    setIsLoaded,
    setRecoveryMessage,
  } = usePlaybackStatus();
  const longBufferTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const recoveryTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const {
    activateFallback,
    activateQuality,
    adaptiveSource,
    effectiveQuality,
    hasSupportedFallback,
    isFallbackSource,
    remountSource,
    resetSource,
    retryKey,
    selectedVariantUri,
    selectedVideoTrack,
    source,
    sourceRefreshRequired,
    sourceType,
    unsupportedSource,
    usingFallback,
  } = useVideoSourceSelection({
    data,
    preferredQuality: selectedQuality,
  });
  const windowIsActive = useAppActiveState();
  const appIsActive = useAppForegroundState();
  const previousAppActiveRef = useRef(appIsActive);
  const playbackEligible = isVisible && !playbackBlocked && windowIsActive;
  const {
    clearTransientInterruption,
    handleAudioBecomingNoisy,
    handleAudioFocusChanged,
    pauseAtEnd,
    pausedByUser,
    playbackPaused,
    togglePaused: togglePlaybackPaused,
  } = usePlaybackInterruption({
    emitPlaybackEvent,
    isPlayingRef,
    isVisible,
    playbackEligible,
  });

  const togglePaused = useCallback(() => {
    // An ended decoder does not restart from changing `paused` alone. Keep
    // autoplay/navigation owned by completion; only an explicit play tap
    // rewinds, and respect a seek the learner made after the reel ended.
    if (
      playbackPaused &&
      durationRef.current > 0 &&
      lastPositionRef.current >= durationRef.current
    ) {
      seekTo(0);
      setCurrentTime(0);
    }
    togglePlaybackPaused();
  }, [
    durationRef,
    lastPositionRef,
    playbackPaused,
    seekTo,
    setCurrentTime,
    togglePlaybackPaused,
  ]);

  useImperativeHandle(forwardedRef, () => ({seekTo}), [seekTo]);

  const clearPlaybackTimers = useCallback(() => {
    if (longBufferTimerRef.current) {
      clearTimeout(longBufferTimerRef.current);
      longBufferTimerRef.current = null;
    }
    if (recoveryTimerRef.current) {
      clearTimeout(recoveryTimerRef.current);
      recoveryTimerRef.current = null;
    }
  }, []);

  /**
   * Retires the current source generation: delayed recovery, diagnostics and
   * manual retries owned by it can no longer act, and the next generation
   * restores `resumeAt` once it loads. A controller owns exactly one reel:
   * VideoComponent keys it by reel id, so React itself resets reel-owned
   * state and refs when a recycled row receives another reel.
   */
  const retireSourceGeneration = useCallback(
    (resumeAt: number | null) => {
      playbackLifecycleGenerationRef.current += 1;
      diagnosticRequestRef.current += 1;
      manualRetryFlightRef.current = null;
      hasRestoredRef.current = false;
      retryPositionRef.current = resumeAt;
      clearPlaybackTimers();
    },
    [clearPlaybackTimers],
  );

  useEffect(() => {
    // Optional device state can finish after a paused adjacent decoder loads.
    // Adopt its resume hint before playback starts, not by rewinding a reel
    // that has played or replacing a seek already owned by the learner.
    if (
      hasStartedRef.current ||
      pendingSeekRef.current !== null ||
      lastPositionRef.current > 0 ||
      !Number.isFinite(initialPosition) ||
      initialPosition <= 0
    )
      return;
    reelInitialPositionRef.current = initialPosition;
    if (hasRestoredRef.current) seekTo(initialPosition);
  }, [
    data.id,
    initialPosition,
    hasStartedRef,
    lastPositionRef,
    pendingSeekRef,
    seekTo,
  ]);

  useEffect(() => {
    const manifestIdentity = `${data.playbackSessionId || 'local'}:${
      data.playbackManifestRevision || 0
    }`;
    const previousIdentity = previousManifestIdentityRef.current;
    if (previousIdentity && previousIdentity !== manifestIdentity) {
      // A fresh server manifest is a new authoritative source generation.
      // Do not carry a previous generation's emergency fallback, lowered
      // quality or delayed retry into it; doing so can leave a recovered
      // lesson permanently degraded even though the primary source is
      // healthy again. Preserve only the learner's playback position.
      retireSourceGeneration(lastPositionRef.current);
      isPlayingRef.current = false;
      deferredPreloadFailureRef.current = false;
      resetSource(preferredQualityRef.current);
      resetStatus();
    }
    previousManifestIdentityRef.current = manifestIdentity;
  }, [
    data.playbackManifestRevision,
    data.playbackSessionId,
    isPlayingRef,
    lastPositionRef,
    resetSource,
    resetStatus,
    retireSourceGeneration,
  ]);

  useEffect(() => {
    if (preferredQualityRef.current === selectedQuality) return;
    preferredQualityRef.current = selectedQuality;
    // A quality change replaces the source generation just like a renewed
    // Bunny manifest. Cancel recovery work owned by the previous variant;
    // otherwise its delayed remount can fire over the learner's new choice
    // and visibly reload the new source a second time.
    retireSourceGeneration(lastPositionRef.current);
    recoveryAttemptsRef.current = 0;
    sameSourceRetryUsedRef.current = false;
    resetSource(selectedQuality);
    resetStatus('جارٍ ضبط الجودة');
  }, [
    lastPositionRef,
    resetSource,
    resetStatus,
    retireSourceGeneration,
    selectedQuality,
  ]);

  acceptsLearningEventsRef.current = playbackEligible;

  useEffect(() => {
    if (!playbackEligible) {
      setPreviewTime(null);
      if (longBufferTimerRef.current) {
        clearTimeout(longBufferTimerRef.current);
        longBufferTimerRef.current = null;
      }
      if (bufferingStartedAtRef.current !== null) {
        bufferDurationMsRef.current += Math.max(
          0,
          Date.now() - bufferingStartedAtRef.current,
        );
        bufferingStartedAtRef.current = null;
      }
    } else if (deferredPreloadFailureRef.current) {
      deferredPreloadFailureRef.current = false;
      hasRestoredRef.current = false;
      retryPositionRef.current = lastPositionRef.current;
      setError(false);
      setIsBuffering(true);
      remountSource();
    }
  }, [
    bufferDurationMsRef,
    bufferingStartedAtRef,
    lastPositionRef,
    playbackEligible,
    remountSource,
    setError,
    setIsBuffering,
    setPreviewTime,
  ]);

  useEffect(() => {
    if (!appIsActive) {
      clearTransientInterruption();
      retireSourceGeneration(lastPositionRef.current);
      activePlayerOwnerRef.current = '';
      setIsLoaded(false);
      setIsBuffering(false);
      setPreviewTime(null);
    } else if (!previousAppActiveRef.current) {
      // The native decoder is deliberately detached in the background on
      // low-memory Android devices. Its fresh instance restores this exact
      // position instead of replaying the reel from the beginning.
      setIsBuffering(true);
      setError(false);
      setFailureKind('source');
      setRecoveryMessage('');
    }
    previousAppActiveRef.current = appIsActive;
  }, [
    appIsActive,
    clearTransientInterruption,
    lastPositionRef,
    retireSourceGeneration,
    setError,
    setFailureKind,
    setIsBuffering,
    setIsLoaded,
    setPreviewTime,
    setRecoveryMessage,
  ]);

  useEffect(
    () => () => {
      retireSourceGeneration(null);
      activePlayerOwnerRef.current = '';
      // Removal, including another reel arriving in a recycled row (which
      // remounts this keyed controller), closes the owner it left visible.
      if (previousVisibleRef.current) {
        previousVisibleRef.current = false;
        stopOnRemovalRef.current();
      }
    },
    [retireSourceGeneration],
  );

  const [naturalSize, setNaturalSize] = useState<{
    width: number;
    height: number;
    orientation?: string;
  } | null>(null);
  const [reportedTextTracks, setReportedTextTracks] = useState<
    ReportedTextTrack[]
  >([]);
  const captionsEnabled = useCaptionsEnabled();
  const textTracks = useMemo(
    () => toSideloadedTextTracks(data.captionTracks),
    [data.captionTracks],
  );
  const selectedTextTrack = useMemo(
    () =>
      selectCaptionTrack({
        enabled: captionsEnabled,
        reported: reportedTextTracks,
        sideloaded: data.captionTracks,
      }),
    [captionsEnabled, data.captionTracks, reportedTextTracks],
  );
  const captionsAvailable = selectedTextTrack !== undefined;
  // Keep one source object per real change: RNV re-resolves the native source
  // whenever this prop's identity changes.
  const playerSource = useMemo(
    () =>
      textTracks || nativePreloadOwner
        ? {
            ...source,
            ...(textTracks ? {textTracks} : {}),
            ...(nativePreloadOwner
              ? {metadata: {title: data.title, description: nativePreloadOwner}}
              : {}),
          }
        : source,
    [data.title, nativePreloadOwner, source, textTracks],
  );
  const subtitleStyle = useMemo(() => captionStyle(bottomInset), [bottomInset]);
  const toggleCaptions = useCallback(
    () => setCaptionsEnabled(!captionsEnabled),
    [captionsEnabled],
  );
  const presentation = useMemo(
    () =>
      resolveVideoPresentation({
        width,
        height,
        naturalHeight: naturalSize?.height,
        naturalOrientation: naturalSize?.orientation,
        naturalWidth: naturalSize?.width,
      }),
    [height, naturalSize, width],
  );
  const contentTop = presentation.contentFrame?.top;
  const contentBottom = presentation.contentFrame?.bottom;
  useEffect(() => {
    if (!onContentFrameChange) return;
    onContentFrameChange(
      contentTop === undefined || contentBottom === undefined
        ? null
        : {top: contentTop, bottom: contentBottom},
    );
  }, [contentBottom, contentTop, onContentFrameChange]);
  useEffect(() => () => onContentFrameChange?.(null), [onContentFrameChange]);
  const zoom = useVideoZoom({active: isVisible, height, width});

  const sourceFailed = unsupportedSource || error;
  const playerOwner = `${data.id}:${data.playbackSessionId || 'local'}:${
    data.playbackManifestRevision || 0
  }:${effectiveQuality}:${usingFallback ? 'fallback' : 'primary'}:${retryKey}`;
  activePlayerOwnerRef.current = playerOwner;

  stopOnRemovalRef.current = () => {
    isPlayingRef.current = false;
    emitPlaybackEvent('stop', {endReason: 'lesson_changed'});
  };

  useEffect(() => {
    const wasVisible = previousVisibleRef.current;
    previousVisibleRef.current = isVisible;
    if (wasVisible && !isVisible) {
      // Audio focus interruptions belong to the currently visible owner.
      // If that row leaves the viewport before focus returns, carrying the
      // transient pause back to it later leaves a healthy reel frozen.
      clearTransientInterruption();
      isPlayingRef.current = false;
      emitPlaybackEvent('stop', {endReason: 'lesson_changed'});
    }
  }, [clearTransientInterruption, emitPlaybackEvent, isPlayingRef, isVisible]);

  useEffect(() => {
    if (selectedVariantUri && effectiveQuality !== 'auto') {
      publishRuntimeMetrics({effectiveQuality});
    }
  }, [effectiveQuality, publishRuntimeMetrics, selectedVariantUri]);

  const {markPlaybackHealthy, recoverOrFail, retryPlayback} =
    usePlaybackRecovery({
      activateFallback,
      activateQuality,
      activePlayerOwner: activePlayerOwnerRef,
      adaptiveSource,
      bufferCount: bufferCountRef,
      bufferDurationMs: bufferDurationMsRef,
      bufferingStartedAt: bufferingStartedAtRef,
      data,
      deferredPreloadFailure: deferredPreloadFailureRef,
      diagnosticRequest: diagnosticRequestRef,
      effectiveQuality,
      emitPlaybackEvent,
      failPlayback,
      hasRestored: hasRestoredRef,
      hasStarted: hasStartedRef,
      hasSupportedFallback,
      isBuffering,
      isFallbackSource,
      lastPosition: lastPositionRef,
      lifecycleGeneration: playbackLifecycleGenerationRef,
      longBufferTimer: longBufferTimerRef,
      manualRetryFlight: manualRetryFlightRef,
      markStatusHealthy,
      onRefreshSource,
      playbackEligible,
      playerOwner,
      publishRuntimeMetrics,
      recoveryAttempts: recoveryAttemptsRef,
      recoveryTimer: recoveryTimerRef,
      reelIdentity: reelIdentityRef,
      remountSource,
      resetSource,
      resetStatus,
      retryPosition: retryPositionRef,
      sameSourceRetryUsed: sameSourceRetryUsedRef,
      selectedQuality,
      selectedVariantUri,
      setFailureKind,
      setIsBuffering,
      setRecoveryMessage,
      sourceRefreshRequired,
      sourceType,
      sourceUri: source.uri,
      unsupportedSource,
    });

  // Rebuild native callbacks only when their inputs change, not on every
  // playback-clock render.
  const videoEventHandlers = useMemo(() => {
    const handlers = createVideoEventHandlers({
      acceptsLearningEvents: () =>
        acceptsLearningEventsRef.current &&
        activePlayerOwnerRef.current === playerOwner,
      bufferCount: bufferCountRef,
      bufferDurationMs: bufferDurationMsRef,
      bufferingStartedAt: bufferingStartedAtRef,
      data,
      diagnosticRequest: diagnosticRequestRef,
      durationRef,
      emitPlaybackEvent,
      hasRestored: hasRestoredRef,
      hasStarted: hasStartedRef,
      isFallbackSource,
      isPlaying: isPlayingRef,
      lastPosition: lastPositionRef,
      loadStartedAt: loadStartedAtRef,
      longBufferTimer: longBufferTimerRef,
      onComplete: () => {
        pauseAtEnd();
        onComplete?.();
      },
      onPlaybackHealthy: markPlaybackHealthy,
      onProgressChange: onProgress,
      ownsPlayback: () => activePlayerOwnerRef.current === playerOwner,
      pendingSeek: pendingSeekRef,
      pendingSeekStartedAt: pendingSeekStartedAtRef,
      publishRuntimeMetrics,
      recoverOrFail,
      reelInitialPosition: reelInitialPositionRef,
      retryPosition: retryPositionRef,
      setBufferedTime,
      setCurrentTime,
      setDuration,
      setError,
      setIsBuffering,
      setIsLoaded,
      setRecoveryMessage,
      sourceType,
      sourceUri: source.uri,
      videoRef,
    });
    return {
      ...handlers,
      onLoad: (event: Parameters<NonNullable<typeof handlers.onLoad>>[0]) => {
        const size = event.naturalSize;
        if (size?.width > 0 && size?.height > 0) {
          const orientation = size.orientation ?? undefined;
          setNaturalSize(current =>
            current?.width === size.width &&
            current?.height === size.height &&
            current?.orientation === orientation
              ? current
              : {width: size.width, height: size.height, orientation},
          );
        }
        setReportedTextTracks(normalizeReportedTextTracks(event.textTracks));
        handlers.onLoad?.(event);
      },
      onTextTracks: (event: {
        textTracks?: ReadonlyArray<{
          index: number;
          title?: string;
          language?: string;
        }>;
      }) =>
        setReportedTextTracks(normalizeReportedTextTracks(event.textTracks)),
    };
  }, [
    bufferCountRef,
    bufferDurationMsRef,
    bufferingStartedAtRef,
    data,
    durationRef,
    emitPlaybackEvent,
    hasStartedRef,
    isFallbackSource,
    isPlayingRef,
    lastPositionRef,
    loadStartedAtRef,
    markPlaybackHealthy,
    onComplete,
    onProgress,
    pauseAtEnd,
    pendingSeekRef,
    pendingSeekStartedAtRef,
    playerOwner,
    publishRuntimeMetrics,
    recoverOrFail,
    setBufferedTime,
    setCurrentTime,
    setDuration,
    setError,
    setIsBuffering,
    setIsLoaded,
    setRecoveryMessage,
    source.uri,
    sourceType,
  ]);

  return {
    appIsActive,
    captionsAvailable,
    captionsEnabled,
    playerSource,
    resizeMode: presentation.resizeMode,
    selectedTextTrack,
    subtitleStyle,
    textTracks,
    toggleCaptions,
    zoom,
    nativePreloadOwner,
    bottomInset,
    controlsHidden,
    currentTime,
    data,
    effectiveQuality,
    failureKind,
    handleAudioBecomingNoisy,
    handleAudioFocusChanged,
    height,
    isBuffering,
    isLoaded,
    panHandlers,
    pausedByUser,
    playbackEligible,
    playbackPaused,
    playbackSpeed,
    previewTime,
    recoveryMessage,
    retryKey,
    retryPlayback,
    seekBy,
    selectedVideoTrack,
    setTrackWidth,
    source,
    sourceFailed,
    timeline,
    togglePaused,
    trackWidth,
    unsupportedSource,
    usingFallback,
    videoEventHandlers,
    videoRef,
    width,
  };
};

export type VideoController = ReturnType<typeof useVideoController>;
