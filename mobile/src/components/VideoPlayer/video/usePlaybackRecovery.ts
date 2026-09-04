import {useCallback, useEffect, type MutableRefObject} from 'react';
import type {CourseReel, VideoQuality} from '../types';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../playbackTelemetry';
import {probeVideoSource} from '../videoSourcePolicy';
import {reportClientError} from '../../../services/operationalTelemetry';
import {selectPlaybackRecoveryStep, type PlaybackFailure} from './policy';

type MutableValue<T> = MutableRefObject<T>;

type PlaybackRecoveryOptions = {
  activateFallback: () => void;
  activateQuality: (quality: VideoQuality) => void;
  activePlayerOwner: MutableValue<string>;
  adaptiveSource: boolean;
  bufferCount: MutableValue<number>;
  bufferDurationMs: MutableValue<number>;
  bufferingStartedAt: MutableValue<number | null>;
  data: Pick<CourseReel, 'availableQualities' | 'id'>;
  deferredPreloadFailure: MutableValue<boolean>;
  diagnosticRequest: MutableValue<number>;
  effectiveQuality: VideoQuality;
  emitPlaybackEvent: (
    eventType: PlaybackPlayerEvent['eventType'],
    options?: Pick<
      PlaybackPlayerEvent,
      'endReason' | 'errorCode' | 'diagnostics'
    >,
  ) => void;
  hasRestored: MutableValue<boolean>;
  hasStarted: MutableValue<boolean>;
  hasSupportedFallback: boolean;
  failPlayback: (failure: PlaybackFailure) => void;
  isBuffering: boolean;
  isFallbackSource: boolean;
  lastPosition: MutableValue<number>;
  lifecycleGeneration: MutableValue<number>;
  longBufferTimer: MutableValue<ReturnType<typeof setTimeout> | null>;
  manualRetryFlight: MutableValue<symbol | null>;
  onRefreshSource?: () => void | Promise<void>;
  playbackEligible: boolean;
  playerOwner: string;
  publishRuntimeMetrics: (updates: Partial<PlaybackRuntimeMetrics>) => void;
  recoveryAttempts: MutableValue<number>;
  recoveryTimer: MutableValue<ReturnType<typeof setTimeout> | null>;
  reelIdentity: MutableValue<CourseReel['id']>;
  remountSource: () => void;
  resetSource: (quality: VideoQuality) => void;
  resetStatus: (message?: string) => void;
  retryPosition: MutableValue<number | null>;
  sameSourceRetryUsed: MutableValue<boolean>;
  selectedQuality: VideoQuality;
  selectedVariantUri: string;
  setFailureKind: (failure: PlaybackFailure) => void;
  setIsBuffering: (buffering: boolean) => void;
  setRecoveryMessage: (message: string) => void;
  sourceRefreshRequired: boolean;
  sourceType?: string;
  sourceUri: string;
  unsupportedSource: boolean;
};

export const usePlaybackRecovery = ({
  activateFallback,
  activateQuality,
  activePlayerOwner,
  adaptiveSource,
  bufferCount,
  bufferDurationMs,
  bufferingStartedAt,
  data,
  deferredPreloadFailure,
  diagnosticRequest,
  effectiveQuality,
  emitPlaybackEvent,
  hasRestored,
  hasStarted,
  hasSupportedFallback,
  failPlayback,
  isBuffering,
  isFallbackSource,
  lastPosition,
  lifecycleGeneration,
  longBufferTimer,
  manualRetryFlight,
  onRefreshSource,
  playbackEligible,
  playerOwner,
  publishRuntimeMetrics,
  recoveryAttempts,
  recoveryTimer,
  reelIdentity,
  remountSource,
  resetSource,
  resetStatus,
  retryPosition,
  sameSourceRetryUsed,
  selectedQuality,
  selectedVariantUri,
  setFailureKind,
  setIsBuffering,
  setRecoveryMessage,
  sourceRefreshRequired,
  sourceType,
  sourceUri,
  unsupportedSource,
}: PlaybackRecoveryOptions) => {
  const restartPlayback = useCallback(
    (message: string, delayMs = 650) => {
      const generation = lifecycleGeneration.current;
      retryPosition.current = lastPosition.current;
      hasRestored.current = false;
      resetStatus(message);
      if (recoveryTimer.current) clearTimeout(recoveryTimer.current);
      recoveryTimer.current = setTimeout(() => {
        if (generation !== lifecycleGeneration.current) return;
        recoveryTimer.current = null;
        remountSource();
      }, delayMs);
    },
    [
      hasRestored,
      lastPosition,
      lifecycleGeneration,
      recoveryTimer,
      remountSource,
      resetStatus,
      retryPosition,
    ],
  );

  const finishWithDiagnostic = useCallback(
    (initialFailure: PlaybackFailure) => {
      failPlayback(initialFailure);
      const request = diagnosticRequest.current + 1;
      diagnosticRequest.current = request;
      if (initialFailure === 'unsupported') return;
      const generation = lifecycleGeneration.current;
      const owner = activePlayerOwner.current;
      void probeVideoSource(sourceUri).then(result => {
        if (
          diagnosticRequest.current !== request ||
          lifecycleGeneration.current !== generation ||
          activePlayerOwner.current !== owner
        ) {
          return;
        }
        setFailureKind(result === 'reachable' ? initialFailure : result);
      });
    },
    [
      activePlayerOwner,
      diagnosticRequest,
      failPlayback,
      lifecycleGeneration,
      setFailureKind,
      sourceUri,
    ],
  );

  const recoverOrFail = useCallback(
    (reason: 'source' | 'timeout') => {
      const step = selectPlaybackRecoveryStep({
        adaptiveSource,
        availableQualities: data.availableQualities,
        effectiveQuality,
        hasSelectedVariant: Boolean(selectedVariantUri),
        hasSupportedFallback,
        isFallbackSource,
        isVisible: playbackEligible,
        recoveryAttempts: recoveryAttempts.current,
        recoveryPending: Boolean(recoveryTimer.current),
        sameSourceRetryUsed: sameSourceRetryUsed.current,
        sourceRefreshRequired,
      });
      if (step.kind === 'pending') return true;
      if (step.kind === 'defer') {
        deferredPreloadFailure.current = true;
        setIsBuffering(false);
        return true;
      }
      if (step.kind === 'quality') {
        recoveryAttempts.current += 1;
        publishRuntimeMetrics({});
        activateQuality(step.quality);
        restartPlayback('الاتصال بطيء\nنضبط الجودة');
        return true;
      }
      if (step.kind === 'fallback') {
        recoveryAttempts.current += 1;
        publishRuntimeMetrics({});
        activateFallback();
        restartPlayback('جارٍ استعادة المقطع\nمكانك محفوظ');
        return true;
      }
      if (step.kind === 'retry') {
        sameSourceRetryUsed.current = true;
        recoveryAttempts.current += 1;
        publishRuntimeMetrics({});
        setRecoveryMessage('نحاول الوصول إلى الفيديو');
        const generation = lifecycleGeneration.current;
        const reelId = data.id;
        void Promise.resolve(onRefreshSource?.())
          .catch(() => undefined)
          .then(() => {
            if (
              generation !== lifecycleGeneration.current ||
              reelIdentity.current !== reelId
            ) {
              return;
            }
            restartPlayback('نحاول الوصول إلى الفيديو', 120);
          });
        return true;
      }

      finishWithDiagnostic(reason);
      return false;
    },
    [
      activateFallback,
      activateQuality,
      adaptiveSource,
      data.availableQualities,
      data.id,
      deferredPreloadFailure,
      effectiveQuality,
      finishWithDiagnostic,
      hasSupportedFallback,
      isFallbackSource,
      lifecycleGeneration,
      onRefreshSource,
      playbackEligible,
      publishRuntimeMetrics,
      recoveryAttempts,
      recoveryTimer,
      reelIdentity,
      restartPlayback,
      sameSourceRetryUsed,
      selectedVariantUri,
      setIsBuffering,
      setRecoveryMessage,
      sourceRefreshRequired,
    ],
  );

  useEffect(() => {
    if (
      !playbackEligible ||
      !isBuffering ||
      unsupportedSource ||
      longBufferTimer.current
    ) {
      return;
    }

    if (hasStarted.current && bufferingStartedAt.current === null) {
      bufferingStartedAt.current = Date.now();
      bufferCount.current += 1;
      publishRuntimeMetrics({bufferCount: bufferCount.current});
    }
    const generation = lifecycleGeneration.current;
    const timeoutMs = recoveryAttempts.current ? 7000 : 12_000;
    const timer = setTimeout(() => {
      if (
        longBufferTimer.current !== timer ||
        generation !== lifecycleGeneration.current ||
        activePlayerOwner.current !== playerOwner
      ) {
        return;
      }
      longBufferTimer.current = null;
      if (bufferingStartedAt.current !== null) {
        bufferDurationMs.current += Math.max(
          0,
          Date.now() - bufferingStartedAt.current,
        );
        bufferingStartedAt.current = null;
        publishRuntimeMetrics({
          bufferCount: bufferCount.current,
          bufferDurationMs: bufferDurationMs.current,
        });
      }
      reportClientError(new Error(`video_buffer_timeout:${data.id}`), {
        source: 'video_player',
      });
      const willRecover = recoverOrFail('timeout');
      emitPlaybackEvent('error', {
        errorCode: 'buffer_timeout',
        ...(willRecover ? {} : {endReason: 'playback_error'}),
        diagnostics: {
          source_type: sourceType || 'unknown',
          stage: isFallbackSource ? 'fallback' : 'primary',
          reason: 'buffer_timeout',
          retry_stage: willRecover ? 'automatic_recovery' : 'exhausted',
        },
      });
    }, timeoutMs);
    longBufferTimer.current = timer;

    return () => {
      if (longBufferTimer.current === timer) {
        clearTimeout(timer);
        longBufferTimer.current = null;
      }
    };
  }, [
    activePlayerOwner,
    bufferCount,
    bufferDurationMs,
    bufferingStartedAt,
    data.id,
    emitPlaybackEvent,
    hasStarted,
    isBuffering,
    isFallbackSource,
    lifecycleGeneration,
    longBufferTimer,
    playbackEligible,
    playerOwner,
    publishRuntimeMetrics,
    recoverOrFail,
    recoveryAttempts,
    sourceType,
    unsupportedSource,
  ]);

  const retryPlayback = useCallback(() => {
    if (manualRetryFlight.current) return;
    const flight = Symbol('manual-playback-retry');
    manualRetryFlight.current = flight;
    const generation = lifecycleGeneration.current;
    const reelId = data.id;
    retryPosition.current = lastPosition.current;
    hasRestored.current = false;
    recoveryAttempts.current = 0;
    sameSourceRetryUsed.current = false;
    diagnosticRequest.current += 1;
    resetStatus('نحاول الوصول إلى الفيديو');
    resetSource(selectedQuality);
    void Promise.resolve()
      .then(() => onRefreshSource?.())
      .catch(() => undefined)
      .then(() => {
        if (
          generation !== lifecycleGeneration.current ||
          reelIdentity.current !== reelId
        ) {
          return;
        }
        remountSource();
      })
      .finally(() => {
        if (manualRetryFlight.current === flight) {
          manualRetryFlight.current = null;
        }
      });
  }, [
    data.id,
    diagnosticRequest,
    hasRestored,
    lastPosition,
    lifecycleGeneration,
    manualRetryFlight,
    onRefreshSource,
    recoveryAttempts,
    reelIdentity,
    remountSource,
    resetSource,
    resetStatus,
    retryPosition,
    sameSourceRetryUsed,
    selectedQuality,
  ]);

  const markPlaybackHealthy = useCallback(() => {
    if (recoveryAttempts.current === 0 && !sameSourceRetryUsed.current) return;
    recoveryAttempts.current = 0;
    sameSourceRetryUsed.current = false;
    deferredPreloadFailure.current = false;
    publishRuntimeMetrics({});
  }, [
    deferredPreloadFailure,
    publishRuntimeMetrics,
    recoveryAttempts,
    sameSourceRetryUsed,
  ]);

  return {markPlaybackHealthy, recoverOrFail, retryPlayback};
};
