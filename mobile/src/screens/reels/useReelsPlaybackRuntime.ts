import {useCallback, useEffect, useRef} from 'react';
import type {MutableRefObject} from 'react';
import {
  persistLocalPlaybackPosition,
  reportPlaybackSessionEvent,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../../components/VideoPlayer/playbackTelemetry';
import type {CourseReel} from '../../components/VideoPlayer/types';

type Params = {
  courseId?: string;
  currentReel?: CourseReel;
  getPlaybackSpeed: () => number;
  isScreenFocused: boolean;
  mounted: MutableRefObject<boolean>;
  onSessionClosed: () => void;
  ownerGeneration: MutableRefObject<number>;
  scopeKey: string;
};

export const useReelsPlaybackRuntime = ({
  courseId,
  currentReel,
  getPlaybackSpeed,
  isScreenFocused,
  mounted,
  onSessionClosed,
  ownerGeneration,
  scopeKey,
}: Params) => {
  const positions = useRef<Record<string, number>>({});
  const lastPersisted = useRef<Record<string, number>>({});
  const completionSent = useRef(new Set<string>());
  const durations = useRef<Record<string, number>>({});
  const runtime = useRef<Record<string, PlaybackRuntimeMetrics>>({});
  const closedSessions = useRef(new Set<string>());
  const activeReel = useRef<CourseReel | undefined>(currentReel);
  activeReel.current = currentReel;

  useEffect(() => {
    positions.current = {};
    lastPersisted.current = {};
    completionSent.current.clear();
    durations.current = {};
    runtime.current = {};
    closedSessions.current.clear();
  }, [scopeKey]);

  const renderedOwnerGeneration = ownerGeneration.current;
  const handlePlaybackEvent = useCallback(
    (reel: CourseReel, event: PlaybackPlayerEvent) => {
      if (ownerGeneration.current !== renderedOwnerGeneration) return;

      if (event.durationSeconds && event.durationSeconds > 0) {
        durations.current[reel.id] = event.durationSeconds;
      }
      if (courseId) {
        positions.current[`${courseId}:${reel.id}`] = event.positionSeconds;
        if (event.eventType === 'stop') {
          void persistLocalPlaybackPosition(
            courseId,
            reel.id,
            event.positionSeconds,
          ).catch(() => undefined);
        }
      }
      runtime.current[reel.id] = {
        effectiveQuality: event.effectiveQuality,
        effectiveBitrateKbps: event.effectiveBitrateKbps,
        recoveryCount: event.recoveryCount,
        bufferCount: event.bufferCount,
        bufferDurationMs: event.bufferDurationMs,
        startupLatencyMs: event.startupLatencyMs,
        diagnostics: event.diagnostics,
      };

      // Position and local resume do not depend on a telemetry session. Public
      // previews can still be resumed even when no signed session was issued.
      if (!reel.playbackSessionId) return;
      if (
        event.eventType === 'stop' ||
        (event.eventType === 'error' && event.endReason)
      ) {
        const wasAlreadyClosed = closedSessions.current.has(
          reel.playbackSessionId,
        );
        closedSessions.current.add(reel.playbackSessionId);
        while (closedSessions.current.size > 64) {
          const oldest = closedSessions.current.values().next().value;
          if (typeof oldest !== 'string') break;
          closedSessions.current.delete(oldest);
        }
        if (!wasAlreadyClosed && mounted.current && isScreenFocused) {
          onSessionClosed();
        }
      }
      void reportPlaybackSessionEvent({
        lessonId: reel.lessonId,
        playbackSessionId: reel.playbackSessionId,
        playbackRate: getPlaybackSpeed(),
        ...event,
      });
    },
    [
      courseId,
      getPlaybackSpeed,
      isScreenFocused,
      mounted,
      onSessionClosed,
      ownerGeneration,
      renderedOwnerGeneration,
    ],
  );

  const handlePlaybackMetrics = useCallback(
    (reel: CourseReel, metrics: PlaybackRuntimeMetrics) => {
      if (ownerGeneration.current !== renderedOwnerGeneration) return;
      runtime.current[reel.id] = metrics;
    },
    [ownerGeneration, renderedOwnerGeneration],
  );

  const reportBackground = useCallback(() => {
    if (ownerGeneration.current !== renderedOwnerGeneration) return;
    const reel = activeReel.current;
    if (!reel) return;
    const positionSeconds = courseId
      ? positions.current[`${courseId}:${reel.id}`] || 0
      : 0;
    if (courseId) {
      void persistLocalPlaybackPosition(
        courseId,
        reel.id,
        positionSeconds,
      ).catch(() => undefined);
    }
    if (
      !reel.playbackSessionId ||
      closedSessions.current.has(reel.playbackSessionId)
    ) {
      return;
    }
    void reportPlaybackSessionEvent({
      lessonId: reel.lessonId,
      playbackSessionId: reel.playbackSessionId,
      eventType: 'background',
      endReason: 'app_closed',
      positionSeconds,
      durationSeconds: durations.current[reel.id],
      playbackRate: getPlaybackSpeed(),
      ...runtime.current[reel.id],
    });
  }, [courseId, getPlaybackSpeed, ownerGeneration, renderedOwnerGeneration]);

  return {
    activeReel,
    closedSessions,
    completionSent,
    durations,
    handlePlaybackEvent,
    handlePlaybackMetrics,
    lastPersisted,
    positions,
    reportBackground,
    runtime,
  };
};
