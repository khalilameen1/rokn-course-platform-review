import {useCallback, useRef} from 'react';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../playbackTelemetry';

type MutableNumberRef = {current: number};

type PlaybackTelemetryOptions = {
  durationRef: MutableNumberRef;
  onPlaybackEvent?: (event: PlaybackPlayerEvent) => void;
  onPlaybackMetrics?: (metrics: PlaybackRuntimeMetrics) => void;
  positionRef: MutableNumberRef;
  recoveryAttemptsRef: MutableNumberRef;
};

export const usePlaybackTelemetry = ({
  durationRef,
  onPlaybackEvent,
  onPlaybackMetrics,
  positionRef,
  recoveryAttemptsRef,
}: PlaybackTelemetryOptions) => {
  const isPlayingRef = useRef(false);
  const hasStartedRef = useRef(false);
  const loadStartedAtRef = useRef<number | null>(null);
  const bufferingStartedAtRef = useRef<number | null>(null);
  const bufferCountRef = useRef(0);
  const bufferDurationMsRef = useRef(0);
  const runtimeMetricsRef = useRef<PlaybackRuntimeMetrics>({
    recoveryCount: 0,
  });

  const resetTelemetry = useCallback(() => {
    isPlayingRef.current = false;
    hasStartedRef.current = false;
    loadStartedAtRef.current = null;
    bufferingStartedAtRef.current = null;
    bufferCountRef.current = 0;
    bufferDurationMsRef.current = 0;
    runtimeMetricsRef.current = {recoveryCount: 0};
  }, []);

  const publishRuntimeMetrics = useCallback(
    (updates: Partial<PlaybackRuntimeMetrics>) => {
      const previous = runtimeMetricsRef.current;
      const next: PlaybackRuntimeMetrics = {
        ...previous,
        ...updates,
        recoveryCount: recoveryAttemptsRef.current,
      };
      runtimeMetricsRef.current = next;
      if (
        previous.effectiveQuality !== next.effectiveQuality ||
        previous.effectiveBitrateKbps !== next.effectiveBitrateKbps ||
        previous.recoveryCount !== next.recoveryCount ||
        previous.bufferCount !== next.bufferCount ||
        previous.bufferDurationMs !== next.bufferDurationMs ||
        previous.startupLatencyMs !== next.startupLatencyMs ||
        previous.diagnostics?.stage !== next.diagnostics?.stage ||
        previous.diagnostics?.source_type !== next.diagnostics?.source_type
      ) {
        onPlaybackMetrics?.(next);
      }
    },
    [onPlaybackMetrics, recoveryAttemptsRef],
  );

  const emitPlaybackEvent = useCallback(
    (
      eventType: PlaybackPlayerEvent['eventType'],
      options: Pick<
        PlaybackPlayerEvent,
        'endReason' | 'errorCode' | 'diagnostics'
      > = {},
    ) => {
      onPlaybackEvent?.({
        eventType,
        positionSeconds: Math.max(0, positionRef.current),
        ...(durationRef.current > 0
          ? {durationSeconds: durationRef.current}
          : {}),
        ...runtimeMetricsRef.current,
        recoveryCount: recoveryAttemptsRef.current,
        ...options,
      });
    },
    [durationRef, onPlaybackEvent, positionRef, recoveryAttemptsRef],
  );

  return {
    bufferCountRef,
    bufferDurationMsRef,
    bufferingStartedAtRef,
    emitPlaybackEvent,
    hasStartedRef,
    isPlayingRef,
    loadStartedAtRef,
    publishRuntimeMetrics,
    resetTelemetry,
    runtimeMetricsRef,
  };
};
