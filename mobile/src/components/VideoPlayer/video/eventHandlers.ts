import type {Dispatch, SetStateAction} from 'react';
import type Video from 'react-native-video';
import type {VideoRef} from 'react-native-video';
import {reportClientError} from '../../../services/operationalTelemetry';
import type {CourseReel} from '../types';
import {
  qualityForTrackHeight,
  type PlaybackPlayerEvent,
  type PlaybackRuntimeMetrics,
} from '../playbackTelemetry';
import {sourceHostForLog} from './policy';

type VideoProps = React.ComponentProps<typeof Video>;
type VideoEventHandlers = Pick<
  VideoProps,
  | 'onBandwidthUpdate'
  | 'onBuffer'
  | 'onEnd'
  | 'onError'
  | 'onLoad'
  | 'onLoadStart'
  | 'onPlaybackStateChanged'
  | 'onProgress'
  | 'onSeek'
  | 'onVideoTracks'
>;

type MutableValue<T> = {current: T};
type SetValue<T> = Dispatch<SetStateAction<T>>;

type VideoEventContext = {
  bufferCount: MutableValue<number>;
  bufferDurationMs: MutableValue<number>;
  bufferingStartedAt: MutableValue<number | null>;
  data: Pick<CourseReel, 'id'>;
  diagnosticRequest: MutableValue<number>;
  durationRef: MutableValue<number>;
  emitPlaybackEvent: (
    eventType: PlaybackPlayerEvent['eventType'],
    options?: Pick<
      PlaybackPlayerEvent,
      'endReason' | 'errorCode' | 'diagnostics'
    >,
  ) => void;
  hasRestored: MutableValue<boolean>;
  hasStarted: MutableValue<boolean>;
  isFallbackSource: boolean;
  isPlaying: MutableValue<boolean>;
  lastPosition: MutableValue<number>;
  loadStartedAt: MutableValue<number | null>;
  longBufferTimer: MutableValue<ReturnType<typeof setTimeout> | null>;
  onComplete?: () => void;
  onPlaybackHealthy: () => void;
  onProgressChange?: (currentTime: number, duration: number) => void;
  acceptsLearningEvents: () => boolean;
  ownsPlayback: () => boolean;
  pendingSeek: MutableValue<number | null>;
  pendingSeekStartedAt: MutableValue<number | null>;
  publishRuntimeMetrics: (updates: Partial<PlaybackRuntimeMetrics>) => void;
  recoverOrFail: (reason: 'source' | 'timeout') => boolean;
  reelInitialPosition: MutableValue<number>;
  retryPosition: MutableValue<number | null>;
  setBufferedTime: SetValue<number>;
  setCurrentTime: SetValue<number>;
  setError: SetValue<boolean>;
  setIsBuffering: SetValue<boolean>;
  setIsLoaded: SetValue<boolean>;
  setRecoveryMessage: SetValue<string>;
  setDuration: SetValue<number>;
  sourceType?: string;
  sourceUri: string;
  videoRef: MutableValue<VideoRef | null>;
};

type NativeErrorRecord = Record<string, unknown>;

export const SEEK_ACKNOWLEDGEMENT_TIMEOUT_MS = 4_000;

const asErrorRecord = (value: unknown): NativeErrorRecord =>
  typeof value === 'object' && value !== null
    ? (value as NativeErrorRecord)
    : {};

export const nativeVideoErrorCode = (event: unknown): string => {
  const root = asErrorRecord(event);
  const error = asErrorRecord(root.error || event);
  return String(
    error.errorCode || error.code || error.errorString || 'unknown',
  ).slice(0, 120);
};

export const createVideoEventHandlers = (
  context: VideoEventContext,
): VideoEventHandlers => ({
  onLoadStart: () => {
    if (!context.ownsPlayback()) return;
    if (!context.hasStarted.current && context.loadStartedAt.current === null) {
      context.loadStartedAt.current = Date.now();
    }
    context.setError(false);
    context.setIsBuffering(true);
  },
  onLoad: event => {
    if (!context.ownsPlayback()) return;
    if (context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
    const rawLoadedDuration = Number(event.duration || 0);
    const loadedDuration =
      Number.isFinite(rawLoadedDuration) && rawLoadedDuration > 0
        ? rawLoadedDuration
        : Math.max(0, context.durationRef.current);
    context.setDuration(loadedDuration);
    context.durationRef.current = loadedDuration;
    context.setBufferedTime(0);
    context.setIsLoaded(true);
    context.setIsBuffering(false);
    context.setRecoveryMessage('');
    context.diagnosticRequest.current += 1;
    if (!context.hasRestored.current) {
      const requestedPosition =
        context.retryPosition.current ?? context.reelInitialPosition.current;
      const resumeAt =
        loadedDuration > 0 && requestedPosition >= loadedDuration - 3
          ? 0
          : Math.max(0, requestedPosition);
      // A native source remount starts a new decoder generation. A seek that
      // belonged to the detached decoder cannot remain authoritative when
      // the new source intentionally resumes from the beginning.
      context.pendingSeek.current = null;
      context.pendingSeekStartedAt.current = null;
      if (resumeAt > 0) {
        context.pendingSeek.current = resumeAt;
        context.pendingSeekStartedAt.current = Date.now();
        context.videoRef.current?.seek(resumeAt);
      }
      context.setCurrentTime(resumeAt);
      context.lastPosition.current = resumeAt;
      context.hasRestored.current = true;
      context.retryPosition.current = null;
    }
  },
  onProgress: event => {
    if (!context.ownsPlayback() || !context.acceptsLearningEvents()) return;
    const rawTime = Number(event.currentTime || 0);
    const nextTime = Number.isFinite(rawTime) ? Math.max(0, rawTime) : 0;
    // onLoad updates the ref synchronously while the state render follows.
    // Reading rendered duration here can therefore persist one early sample
    // against stale author metadata and mark a reel complete too soon.
    const knownDuration = Number(context.durationRef.current || 0);
    const rawDuration =
      (Number.isFinite(knownDuration) && knownDuration > 0
        ? knownDuration
        : 0) || Number(event.seekableDuration || 0);
    const nextDuration = Number.isFinite(rawDuration)
      ? Math.max(0, rawDuration)
      : 0;
    const rawPlayableDuration = Number(event.playableDuration || 0);
    context.setBufferedTime(
      Math.max(
        nextTime,
        Number.isFinite(rawPlayableDuration) ? rawPlayableDuration : 0,
      ),
    );
    if (nextDuration && !context.durationRef.current) {
      context.setDuration(nextDuration);
      context.durationRef.current = nextDuration;
    }
    const pendingSeek = context.pendingSeek.current;
    if (pendingSeek !== null) {
      const pendingSince = context.pendingSeekStartedAt.current;
      const stillAwaitingSeek =
        pendingSince !== null &&
        Date.now() - pendingSince < SEEK_ACKNOWLEDGEMENT_TIMEOUT_MS;
      if (Math.abs(nextTime - pendingSeek) > 2 && stillAwaitingSeek) {
        return;
      }
      // Some Android decoders (especially older ExoPlayer builds/devices)
      // reach the requested position through onProgress without emitting a
      // matching onSeek callback. Treat the first close progress sample as
      // the acknowledgement; otherwise every later sample is rejected once
      // playback moves more than two seconds past the stale seek target.
      context.pendingSeek.current = null;
      context.pendingSeekStartedAt.current = null;
    }
    if (nextTime > context.lastPosition.current + 0.25) {
      // A manifest response or metadata load is not proof that playback was
      // repaired. Reset the bounded recovery streak only after the decoder
      // advances real media, otherwise a bad signed source can refresh and
      // retry forever without ever showing a frame.
      context.onPlaybackHealthy();
    }
    context.lastPosition.current = nextTime;
    context.setCurrentTime(nextTime);
    context.onProgressChange?.(nextTime, nextDuration);
  },
  onSeek: event => {
    if (!context.ownsPlayback() || !context.acceptsLearningEvents()) return;
    const pendingTarget = context.pendingSeek.current;
    const acknowledgedTarget = Number(event.seekTime);
    if (
      pendingTarget !== null &&
      Number.isFinite(acknowledgedTarget) &&
      Math.abs(acknowledgedTarget - pendingTarget) > 2 &&
      context.pendingSeekStartedAt.current !== null &&
      Date.now() - context.pendingSeekStartedAt.current <
        SEEK_ACKNOWLEDGEMENT_TIMEOUT_MS
    ) {
      return;
    }
    const nextTime = Math.max(
      0,
      Number(event.currentTime ?? event.seekTime ?? pendingTarget) || 0,
    );
    context.pendingSeek.current = null;
    context.pendingSeekStartedAt.current = null;
    context.lastPosition.current = nextTime;
    context.setCurrentTime(nextTime);
    context.onProgressChange?.(nextTime, context.durationRef.current);
  },
  onBandwidthUpdate: event => {
    if (!context.ownsPlayback()) return;
    const bitrate = Number(event.bitrate || 0);
    const trackHeightPx = Number(event.height || 0);
    const effectiveQuality = qualityForTrackHeight(trackHeightPx);
    context.publishRuntimeMetrics({
      ...(effectiveQuality ? {effectiveQuality} : {}),
      ...(bitrate > 0
        ? {effectiveBitrateKbps: Math.max(1, Math.round(bitrate / 1000))}
        : {}),
      diagnostics: {
        source_type: context.sourceType || 'unknown',
        stage: context.isFallbackSource ? 'fallback' : 'primary',
      },
    });
  },
  onVideoTracks: event => {
    if (!context.ownsPlayback()) return;
    const selected = event.videoTracks.find(track => track.selected);
    if (!selected) return;
    const bitrate = Number(selected.bitrate || 0);
    const effectiveQuality = qualityForTrackHeight(
      Number(selected.height || 0),
    );
    context.publishRuntimeMetrics({
      ...(effectiveQuality ? {effectiveQuality} : {}),
      ...(bitrate > 0
        ? {effectiveBitrateKbps: Math.max(1, Math.round(bitrate / 1000))}
        : {}),
    });
  },
  onPlaybackStateChanged: event => {
    if (!context.ownsPlayback()) return;
    if (!context.acceptsLearningEvents()) {
      context.isPlaying.current = false;
      return;
    }
    if (event.isPlaying && !context.isPlaying.current) {
      if (
        !context.hasStarted.current &&
        context.loadStartedAt.current !== null
      ) {
        context.publishRuntimeMetrics({
          startupLatencyMs: Math.max(
            0,
            Date.now() - context.loadStartedAt.current,
          ),
        });
      }
      context.emitPlaybackEvent('start', {
        diagnostics: {
          stage: context.hasStarted.current ? 'resume' : 'initial',
        },
      });
      context.hasStarted.current = true;
    }
    context.isPlaying.current = event.isPlaying;
  },
  onBuffer: event => {
    if (!context.ownsPlayback()) return;
    context.setIsBuffering(event.isBuffering);
    if (
      event.isBuffering &&
      context.acceptsLearningEvents() &&
      context.hasStarted.current &&
      context.bufferingStartedAt.current === null
    ) {
      context.bufferingStartedAt.current = Date.now();
      context.bufferCount.current += 1;
      context.publishRuntimeMetrics({
        bufferCount: context.bufferCount.current,
      });
    } else if (
      !event.isBuffering &&
      context.bufferingStartedAt.current !== null
    ) {
      context.bufferDurationMs.current += Math.max(
        0,
        Date.now() - context.bufferingStartedAt.current,
      );
      context.bufferingStartedAt.current = null;
      context.publishRuntimeMetrics({
        bufferCount: context.bufferCount.current,
        bufferDurationMs: context.bufferDurationMs.current,
      });
    }
    // The controller effect is the only owner of the buffering watchdog.
    // Repeated native `onBuffer(true)` callbacks must not clear that timer
    // without changing React state, otherwise the reel can spin forever.
    if (!event.isBuffering && context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
  },
  onError: event => {
    if (!context.ownsPlayback()) return;
    if (context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
    context.setIsBuffering(false);
    if (context.bufferingStartedAt.current !== null) {
      context.bufferDurationMs.current += Math.max(
        0,
        Date.now() - context.bufferingStartedAt.current,
      );
      context.bufferingStartedAt.current = null;
      context.publishRuntimeMetrics({
        bufferCount: context.bufferCount.current,
        bufferDurationMs: context.bufferDurationMs.current,
      });
    }
    const errorCode = nativeVideoErrorCode(event);
    if (__DEV__) {
      console.warn('[RoknVideo] playback failed', {
        reelId: context.data.id,
        host: sourceHostForLog(context.sourceUri),
        code: errorCode,
        fallback: context.isFallbackSource,
      });
    }
    reportClientError(
      new Error(`video_playback:${errorCode}:reel:${context.data.id}`),
      {
        source: context.isFallbackSource ? 'video_fallback' : 'video_primary',
      },
    );
    const willRecover = context.recoverOrFail('source');
    context.emitPlaybackEvent('error', {
      errorCode,
      ...(willRecover ? {} : {endReason: 'playback_error'}),
      diagnostics: {
        source_type: context.sourceType || 'unknown',
        stage: context.isFallbackSource ? 'fallback' : 'primary',
        reason: 'native_error',
        player_error: errorCode,
        retry_stage: willRecover ? 'automatic_recovery' : 'exhausted',
      },
    });
  },
  onEnd: () => {
    if (!context.ownsPlayback() || !context.acceptsLearningEvents()) return;
    if (context.longBufferTimer.current) {
      clearTimeout(context.longBufferTimer.current);
      context.longBufferTimer.current = null;
    }
    const finalDuration = Math.max(
      0,
      context.durationRef.current,
      context.lastPosition.current,
    );
    context.pendingSeek.current = null;
    context.pendingSeekStartedAt.current = null;
    context.durationRef.current = finalDuration;
    context.setDuration(finalDuration);
    context.lastPosition.current = finalDuration;
    context.setCurrentTime(finalDuration);
    context.onProgressChange?.(finalDuration, finalDuration);
    context.onComplete?.();
  },
});
