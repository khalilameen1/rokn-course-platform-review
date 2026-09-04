import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {Platform} from 'react-native';

import {
  manifestRefreshDelayMs,
  scheduledManifestRefreshDelayMs,
  type PlaybackRuntimeMetrics,
} from '../../components/VideoPlayer/playbackTelemetry';
import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import {usePlaybackManifest} from './usePlaybackManifest';

type Params = {
  activeReel: MutableRefObject<CourseReel | undefined>;
  appIsActive: boolean;
  course: CourseLearningData | null;
  courseRef: MutableRefObject<CourseLearningData | null>;
  currentIndex: number;
  currentItem?: CourseFeedItem;
  currentReel?: CourseReel;
  dataSaver: boolean;
  durations: MutableRefObject<Record<string, number>>;
  feedItems: CourseFeedItem[];
  getPlaybackSpeed: () => number;
  interactionLocked: boolean;
  isScreenFocused: boolean;
  mounted: MutableRefObject<boolean>;
  onCourseRevisionChanged: () => void;
  ownerGeneration: MutableRefObject<number>;
  playbackPreferencesReady: boolean;
  positions: MutableRefObject<Record<string, number>>;
  revisionReloadPending: MutableRefObject<boolean>;
  runtime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  scopeKey: string;
  selectedQuality: VideoQuality;
  serverSession: boolean | null;
  setConnectionNote: Dispatch<SetStateAction<string>>;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
  sessionsClosed: MutableRefObject<Set<string>>;
};

/**
 * The single owner of signed playback manifests. Visible acquisition,
 * scheduled renewal and adjacent preloading share one flight/version ledger.
 */
export const useReelsManifestOwner = ({
  activeReel,
  appIsActive,
  course,
  courseRef,
  currentIndex,
  currentItem,
  currentReel,
  dataSaver,
  durations,
  feedItems,
  getPlaybackSpeed,
  interactionLocked,
  isScreenFocused,
  mounted,
  onCourseRevisionChanged,
  ownerGeneration,
  playbackPreferencesReady,
  positions,
  revisionReloadPending,
  runtime,
  scheduleDelayedAction,
  scopeKey,
  selectedQuality,
  serverSession,
  setConnectionNote,
  setCourse,
  sessionsClosed,
}: Params) => {
  const flights = useRef(new Map<string, Promise<void>>());
  const versions = useRef<Record<string, number>>({});
  const retries = useRef<Record<string, number>>({});
  const scheduledRefreshes = useRef(new Set<string>());
  const playbackActive = useRef(false);
  const [refreshNonce, setRefreshNonce] = useState(0);

  useEffect(() => {
    flights.current.clear();
    versions.current = {};
    retries.current = {};
    scheduledRefreshes.current.clear();
    setRefreshNonce(0);
  }, [scopeKey]);

  playbackActive.current = isScreenFocused && appIsActive && !interactionLocked;

  const refs = useMemo(
    () => ({
      activeReel,
      course: courseRef,
      durations,
      flights,
      mounted,
      ownerGeneration,
      positions,
      playbackActive,
      retries,
      revisionReloadPending,
      runtime,
      versions,
    }),
    [
      activeReel,
      courseRef,
      durations,
      mounted,
      ownerGeneration,
      positions,
      revisionReloadPending,
      runtime,
    ],
  );

  const requestPlaybackManifest = usePlaybackManifest({
    courseId: course?.id,
    dataSaver,
    getPlaybackSpeed,
    onCourseRevisionChanged,
    playbackPreferencesReady,
    refs,
    scheduleDelayedAction,
    selectedQuality,
    serverSession,
    setConnectionNote,
    setCourse,
    setManifestRefreshNonce: setRefreshNonce,
  });

  useEffect(() => {
    if (!currentReel || interactionLocked || !isScreenFocused || !appIsActive) {
      return;
    }
    const sessionId = currentReel.playbackSessionId;
    const sessionWasClosed = Boolean(
      sessionId && sessionsClosed.current.has(sessionId),
    );
    const sourceExpired =
      Boolean(sessionId) &&
      manifestRefreshDelayMs(currentReel.playbackExpiresAt) === 0;
    if (!sessionId || sessionWasClosed || sourceExpired) {
      void requestPlaybackManifest(currentReel, sessionId, !sessionWasClosed);
    }
  }, [
    appIsActive,
    currentReel,
    interactionLocked,
    isScreenFocused,
    refreshNonce,
    requestPlaybackManifest,
    sessionsClosed,
  ]);

  useEffect(() => {
    if (!currentReel?.playbackSessionId || !isScreenFocused || !appIsActive) {
      return;
    }
    const delay = scheduledManifestRefreshDelayMs(
      currentReel.playbackRefreshAfter,
      currentReel.playbackExpiresAt,
    );
    if (delay === null) return;
    const expectedSessionId = currentReel.playbackSessionId;
    const refreshKey = [
      expectedSessionId,
      currentReel.playbackRefreshAfter || currentReel.playbackExpiresAt || '',
      refreshNonce,
    ].join(':');
    if (scheduledRefreshes.current.has(refreshKey)) return;
    const timer = setTimeout(() => {
      scheduledRefreshes.current.add(refreshKey);
      while (scheduledRefreshes.current.size > 64) {
        const oldest = scheduledRefreshes.current.values().next().value;
        if (typeof oldest !== 'string') break;
        scheduledRefreshes.current.delete(oldest);
      }
      void requestPlaybackManifest(currentReel, expectedSessionId);
    }, delay);
    return () => clearTimeout(timer);
  }, [
    appIsActive,
    currentReel,
    isScreenFocused,
    refreshNonce,
    requestPlaybackManifest,
  ]);

  const canPreloadAdjacentSource =
    isScreenFocused && appIsActive && !interactionLocked && !dataSaver;
  const androidApiLevel = Number(Platform.Version);
  const canPreloadAdjacentVideo =
    canPreloadAdjacentSource &&
    (Platform.OS !== 'android' ||
      !Number.isFinite(androidApiLevel) ||
      androidApiLevel >= 26);

  useEffect(() => {
    if (!canPreloadAdjacentSource || serverSession !== true) return;
    if (
      currentItem?.type === 'reel' &&
      (!currentItem.reel.playbackSessionId || !currentItem.reel.videoUrl.trim())
    ) {
      return;
    }
    const nextItem = feedItems[currentIndex + 1];
    if (nextItem?.type !== 'reel' || nextItem.reel.isLocked) return;
    const sessionId = nextItem.reel.playbackSessionId;
    const sessionClosed = Boolean(
      sessionId && sessionsClosed.current.has(sessionId),
    );
    const sourceExpired =
      Boolean(sessionId) &&
      manifestRefreshDelayMs(nextItem.reel.playbackExpiresAt) === 0;
    if (
      sessionId &&
      !sessionClosed &&
      !sourceExpired &&
      nextItem.reel.videoUrl.trim()
    ) {
      return;
    }
    void requestPlaybackManifest(nextItem.reel, sessionId, !sessionClosed);
  }, [
    canPreloadAdjacentSource,
    currentIndex,
    currentItem,
    feedItems,
    refreshNonce,
    requestPlaybackManifest,
    serverSession,
    sessionsClosed,
  ]);

  const refreshSources = useCallback(
    () => setRefreshNonce(value => value + 1),
    [],
  );
  const invalidateManifests = useCallback(() => {
    Object.keys(versions.current).forEach(key => {
      versions.current[key] = (versions.current[key] || 0) + 1;
    });
    retries.current = {};
    scheduledRefreshes.current.clear();
    flights.current.clear();
  }, []);

  return {
    canPreloadAdjacentVideo,
    invalidateManifests,
    refreshSources,
    requestPlaybackManifest,
  };
};
