import {type ForwardedRef, useEffect, useImperativeHandle, useRef} from 'react';
import {VideoRef} from 'react-native-video';
import {CourseReel, VideoQuality} from '../types';
import {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../playbackTelemetry';
import {createVideoEventHandlers} from './eventHandlers';
import {useAppActiveState} from '../../../hooks/useAppActiveState';
import {usePlaybackInterruption} from './usePlaybackInterruption';
import {usePlaybackRecovery} from './usePlaybackRecovery';
import {usePlaybackStatus} from './usePlaybackStatus';
import {usePlaybackTelemetry} from './usePlaybackTelemetry';
import {useVideoSourceSelection} from './useVideoSourceSelection';
import {useVideoTimelineController} from './useVideoTimelineController';

export interface VideoComponentHandle {
  seekTo: (seconds: number) => void;
}

export interface VideoComponentProps {
  data: CourseReel;
  width: number;
  height: number;
  isVisible: boolean;
  playbackBlocked?: boolean;
  playbackSpeed?: number;
  selectedQuality?: VideoQuality;
  initialPosition?: number;
  bottomInset?: number;
  onProgress?: (currentTime: number, duration: number) => void;
  onComplete?: () => void;
  onRefreshSource?: () => void | Promise<void>;
  onPlaybackEvent?: (event: PlaybackPlayerEvent) => void;
  onPlaybackMetrics?: (metrics: PlaybackRuntimeMetrics) => void;
}

export const useVideoController = (
  {
    data,
    width,
    height,
    isVisible,
    playbackBlocked = false,
    playbackSpeed = 1,
    selectedQuality = 'auto',
    initialPosition = 0,
    bottomInset = 0,
    onProgress,
    onComplete,
    onRefreshSource,
    onPlaybackEvent,
    onPlaybackMetrics,
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
    resetTimeline,
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
    resetTelemetry,
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
    isVisible,
    preferredQuality: selectedQuality,
  });
  const appIsActive = useAppActiveState();
  const previousAppActiveRef = useRef(appIsActive);
  const playbackEligible = isVisible && !playbackBlocked && appIsActive;
  const {
    clearTransientInterruption,
    handleAudioBecomingNoisy,
    handleAudioFocusChanged,
    pausedByUser,
    playbackPaused,
    resetInterruption,
    togglePaused,
  } = usePlaybackInterruption({
    emitPlaybackEvent,
    isPlayingRef,
    isVisible,
    playbackEligible,
  });

  if (reelIdentityRef.current !== data.id) {
    reelIdentityRef.current = data.id;
    playbackLifecycleGenerationRef.current += 1;
    reelInitialPositionRef.current = initialPosition;
    declaredDurationRef.current = Number.isFinite(Number(data.durationSeconds))
      ? Math.max(0, Number(data.durationSeconds))
      : 0;
  }

  useImperativeHandle(forwardedRef, () => ({seekTo}), [seekTo]);

  useEffect(() => {
    hasRestoredRef.current = false;
    retryPositionRef.current = null;
    resetInterruption();
    resetTimeline(reelInitialPositionRef.current, declaredDurationRef.current);
    resetStatus();
    if (longBufferTimerRef.current) {
      clearTimeout(longBufferTimerRef.current);
      longBufferTimerRef.current = null;
    }
    if (recoveryTimerRef.current) {
      clearTimeout(recoveryTimerRef.current);
      recoveryTimerRef.current = null;
    }
    resetSource(preferredQualityRef.current);
    recoveryAttemptsRef.current = 0;
    sameSourceRetryUsedRef.current = false;
    deferredPreloadFailureRef.current = false;
    diagnosticRequestRef.current += 1;
    manualRetryFlightRef.current = null;
    previousVisibleRef.current = false;
    previousManifestIdentityRef.current = '';
    resetTelemetry();
    const stopReelOwner = stopOnRemovalRef.current;
    return () => {
      // FlatList normally keys rows by reel, but a recycled component can
      // still receive another reel before unmount. Close the old playback
      // owner here so its session and progress cannot remain live behind
      // the replacement source.
      if (previousVisibleRef.current) {
        previousVisibleRef.current = false;
        stopReelOwner();
      }
    };
  }, [
    data.id,
    resetInterruption,
    resetSource,
    resetStatus,
    resetTelemetry,
    resetTimeline,
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
      playbackLifecycleGenerationRef.current += 1;
      retryPositionRef.current = lastPositionRef.current;
      hasRestoredRef.current = false;
      isPlayingRef.current = false;
      deferredPreloadFailureRef.current = false;
      diagnosticRequestRef.current += 1;
      manualRetryFlightRef.current = null;
      if (longBufferTimerRef.current) {
        clearTimeout(longBufferTimerRef.current);
        longBufferTimerRef.current = null;
      }
      if (recoveryTimerRef.current) {
        clearTimeout(recoveryTimerRef.current);
        recoveryTimerRef.current = null;
      }
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
  ]);

  useEffect(() => {
    if (preferredQualityRef.current === selectedQuality) return;
    preferredQualityRef.current = selectedQuality;
    // A quality change replaces the source generation just like a renewed
    // Bunny manifest. Cancel recovery work owned by the previous variant;
    // otherwise its delayed remount can fire over the learner's new choice
    // and visibly reload the new source a second time.
    playbackLifecycleGenerationRef.current += 1;
    diagnosticRequestRef.current += 1;
    manualRetryFlightRef.current = null;
    retryPositionRef.current = lastPositionRef.current;
    hasRestoredRef.current = false;
    if (longBufferTimerRef.current) {
      clearTimeout(longBufferTimerRef.current);
      longBufferTimerRef.current = null;
    }
    if (recoveryTimerRef.current) {
      clearTimeout(recoveryTimerRef.current);
      recoveryTimerRef.current = null;
    }
    recoveryAttemptsRef.current = 0;
    sameSourceRetryUsedRef.current = false;
    resetSource(selectedQuality);
    resetStatus('جارٍ ضبط الجودة');
  }, [lastPositionRef, resetSource, resetStatus, selectedQuality]);

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
      retryPositionRef.current = lastPositionRef.current;
      hasRestoredRef.current = false;
      playbackLifecycleGenerationRef.current += 1;
      diagnosticRequestRef.current += 1;
      manualRetryFlightRef.current = null;
      activePlayerOwnerRef.current = '';
      setIsLoaded(false);
      setIsBuffering(false);
      setPreviewTime(null);
      if (longBufferTimerRef.current) {
        clearTimeout(longBufferTimerRef.current);
        longBufferTimerRef.current = null;
      }
      if (recoveryTimerRef.current) {
        clearTimeout(recoveryTimerRef.current);
        recoveryTimerRef.current = null;
      }
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
    setError,
    setFailureKind,
    setIsBuffering,
    setIsLoaded,
    setPreviewTime,
    setRecoveryMessage,
  ]);

  useEffect(
    () => () => {
      if (longBufferTimerRef.current) {
        clearTimeout(longBufferTimerRef.current);
      }
      if (recoveryTimerRef.current) {
        clearTimeout(recoveryTimerRef.current);
      }
      diagnosticRequestRef.current += 1;
      playbackLifecycleGenerationRef.current += 1;
      manualRetryFlightRef.current = null;
      activePlayerOwnerRef.current = '';
      if (previousVisibleRef.current) {
        previousVisibleRef.current = false;
        stopOnRemovalRef.current();
      }
    },
    [],
  );

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

  const videoEventHandlers = createVideoEventHandlers({
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
    onComplete,
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
    appIsActive,
    bottomInset,
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
