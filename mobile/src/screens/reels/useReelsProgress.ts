import {
  useCallback,
  useRef,
  type Dispatch,
  type MutableRefObject,
  type SetStateAction,
} from 'react';
import {
  flushPendingPlaybackPositions,
  markSectionComplete,
  savePlaybackPosition,
} from '../../components/VideoPlayer/courseLearningApi';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
} from '../../components/VideoPlayer/types';
import {scheduleNextLearningReminder} from '../../services/smartReminders';
import {
  buildPlaybackEvidence,
  markReelCompleted,
  nextLearningTitle,
  reelCompletionNeedsLearningMapRefresh,
} from './progress';

type ReelsProgressRefs = {
  completionSent: MutableRefObject<Set<string>>;
  feedLength: MutableRefObject<number>;
  lastPersisted: MutableRefObject<Record<string, number>>;
  ownerGeneration: MutableRefObject<number>;
  playbackDurations: MutableRefObject<Record<string, number>>;
  playbackRuntime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  positions: MutableRefObject<Record<string, number>>;
};

export const useReelsProgress = ({
  autoplay,
  course,
  currentIndex,
  feedItems,
  maybeOfferReminders,
  playbackSpeed,
  previewMode,
  refs,
  refreshAfterSectionCompletion,
  scheduleDelayedAction,
  scrollToIndex,
  setChatVisible,
  setCourse,
  setPreviewGateVisible,
}: {
  autoplay: boolean;
  course: CourseLearningData | null;
  currentIndex: number;
  feedItems: CourseFeedItem[];
  maybeOfferReminders: () => void;
  playbackSpeed: number;
  previewMode: boolean;
  refs: ReelsProgressRefs;
  refreshAfterSectionCompletion: (targetIndex: number) => Promise<boolean>;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  scrollToIndex: (index: number, animated?: boolean) => void;
  setChatVisible: Dispatch<SetStateAction<boolean>>;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
  setPreviewGateVisible: Dispatch<SetStateAction<boolean>>;
}) => {
  const renderOwnerGeneration = refs.ownerGeneration.current;
  const activeContextRef = useRef({courseId: '', feedKey: ''});
  const completionFlightsRef = useRef(new Map<string, Promise<boolean>>());
  activeContextRef.current = {
    courseId: course?.id || '',
    feedKey: feedItems[currentIndex]?.key || '',
  };

  const ownsCourse = useCallback(
    (courseId: string) =>
      refs.ownerGeneration.current === renderOwnerGeneration &&
      activeContextRef.current.courseId === courseId,
    [refs.ownerGeneration, renderOwnerGeneration],
  );
  const ownsActiveReel = useCallback(
    (courseId: string, reel: CourseReel) =>
      ownsCourse(courseId) &&
      activeContextRef.current.feedKey === `reel-${reel.id}`,
    [ownsCourse],
  );

  const updateReelCompletion = useCallback(
    (courseId: string, reel: CourseReel) => {
      setCourse(current =>
        current?.id === courseId
          ? markReelCompleted(
              current,
              reel,
              !reelCompletionNeedsLearningMapRefresh(current, reel),
            )
          : current,
      );
    },
    [setCourse],
  );

  const confirmReelCompletion = useCallback(
    (reel: CourseReel, evidenceSave: Promise<void>): Promise<boolean> => {
      if (!course) return Promise.resolve(false);
      const courseId = course.id;
      const flightKey = `${renderOwnerGeneration}:${courseId}:${reel.sectionId}`;
      const existing = completionFlightsRef.current.get(flightKey);
      if (existing) return existing;
      const flight = (async () => {
        try {
          await evidenceSave;
          if (!ownsCourse(courseId)) return false;
          await flushPendingPlaybackPositions();
          if (!ownsCourse(courseId)) return false;
          const completed = await markSectionComplete(courseId, reel.sectionId);
          if (!ownsCourse(courseId)) return false;
          if (!completed) {
            refs.completionSent.current.delete(reel.sectionId);
            return false;
          }
          updateReelCompletion(courseId, reel);
          return true;
        } catch {
          refs.completionSent.current.delete(reel.sectionId);
          return false;
        }
      })();
      completionFlightsRef.current.set(flightKey, flight);
      void flight.finally(() => {
        if (completionFlightsRef.current.get(flightKey) === flight) {
          completionFlightsRef.current.delete(flightKey);
        }
      });
      return flight;
    },
    [
      course,
      ownsCourse,
      refs.completionSent,
      renderOwnerGeneration,
      updateReelCompletion,
    ],
  );

  const persistProgress = useCallback(
    (reel: CourseReel, currentTime: number, duration: number) => {
      if (!course || !ownsCourse(course.id)) return;
      refs.positions.current[`${course.id}:${reel.id}`] = currentTime;
      if (duration > 0) refs.playbackDurations.current[reel.id] = duration;
      const runtime = refs.playbackRuntime.current[reel.id];
      const hasSavedSample = Object.prototype.hasOwnProperty.call(
        refs.lastPersisted.current,
        reel.id,
      );
      const lastSaved = refs.lastPersisted.current[reel.id] || 0;
      const reachedCompletion = duration > 0 && currentTime / duration >= 0.95;
      const evidence = buildPlaybackEvidence(reel, runtime, playbackSpeed);
      let progressSave: Promise<void> | null = null;
      if (
        !hasSavedSample ||
        Math.abs(currentTime - lastSaved) >= 15 ||
        reachedCompletion
      ) {
        refs.lastPersisted.current[reel.id] = currentTime;
        progressSave = savePlaybackPosition(
          course.id,
          reel.id,
          currentTime,
          reel.lessonId,
          duration,
          reachedCompletion,
          evidence,
        );
        if (!reachedCompletion) progressSave.catch(() => undefined);
      }
      if (
        reachedCompletion &&
        !refs.completionSent.current.has(reel.sectionId)
      ) {
        refs.completionSent.current.add(reel.sectionId);
        const finalEvidenceSave =
          progressSave ??
          savePlaybackPosition(
            course.id,
            reel.id,
            currentTime,
            reel.lessonId,
            duration,
            true,
            evidence,
          );
        const completeLocally = previewMode;
        if (completeLocally) {
          updateReelCompletion(course.id, reel);
        }
        void confirmReelCompletion(reel, finalEvidenceSave).then(completed => {
          if (!completed && !completeLocally) return;
          if (!ownsActiveReel(course.id, reel)) return;
          maybeOfferReminders();
          const nextTitle = nextLearningTitle(course, reel);
          const lastPreviewItem = feedItems[feedItems.length - 1];
          const isLastPreviewReel =
            previewMode &&
            lastPreviewItem?.type === 'reel' &&
            lastPreviewItem.reel.id === reel.id;
          if (nextTitle && !isLastPreviewReel) {
            scheduleNextLearningReminder({
              nextReelTitle: nextTitle,
              courseTitle: course.title,
              courseId: course.id,
            }).catch(() => undefined);
          }
        });
      }
    },
    [
      course,
      feedItems,
      maybeOfferReminders,
      ownsActiveReel,
      ownsCourse,
      playbackSpeed,
      previewMode,
      refs,
      confirmReelCompletion,
      updateReelCompletion,
    ],
  );

  const completeAndAdvance = useCallback(
    (reel: CourseReel) => {
      if (!course || !ownsCourse(course.id)) return;
      const completeLocally = previewMode;
      if (completeLocally) {
        updateReelCompletion(course.id, reel);
      }
      const advance = async () => {
        if (!ownsActiveReel(course.id, reel)) return;
        maybeOfferReminders();
        const isLastPreviewReel =
          previewMode && currentIndex >= refs.feedLength.current - 1;
        const nextTitle = nextLearningTitle(course, reel);
        if (nextTitle && !isLastPreviewReel) {
          scheduleNextLearningReminder({
            nextReelTitle: nextTitle,
            courseTitle: course.title,
            courseId: course.id,
          }).catch(() => undefined);
        }
        if (isLastPreviewReel) {
          setChatVisible(false);
          setPreviewGateVisible(true);
          return;
        }
        if (
          !previewMode &&
          reelCompletionNeedsLearningMapRefresh(course, reel)
        ) {
          // The fresh contract owns project requirements, report tier and the
          // newly-opened gate. On failure, stay on the completed reel so the
          // learner never lands on an empty or incorrectly locked transition.
          // Autoplay controls reel-to-reel motion. A crossing project is the
          // next authored step itself, so it must open after completion even
          // when automatic video playback is disabled.
          await refreshAfterSectionCompletion(currentIndex + 1);
          return;
        }
        if (autoplay) {
          scheduleDelayedAction(() => {
            if (ownsActiveReel(course.id, reel)) {
              scrollToIndex(currentIndex + 1);
            }
          }, 280);
        }
      };
      if (!refs.completionSent.current.has(reel.sectionId)) {
        refs.completionSent.current.add(reel.sectionId);
        const position = Math.max(
          0,
          refs.positions.current[`${course.id}:${reel.id}`] || 0,
        );
        const duration = Math.max(position, reel.durationSeconds || 0);
        const runtime = refs.playbackRuntime.current[reel.id];
        const evidenceSave = savePlaybackPosition(
          course.id,
          reel.id,
          position,
          reel.lessonId,
          duration || undefined,
          true,
          buildPlaybackEvidence(reel, runtime, playbackSpeed),
        );
        void confirmReelCompletion(reel, evidenceSave).then(completed => {
          if (completed || completeLocally) void advance();
        });
        return;
      }
      if (completeLocally || reel.isCompleted) {
        void advance();
        return;
      }
      // persistProgress may have crossed the completion threshold immediately
      // before the native onEnd callback. Join that same request instead of
      // dropping autoplay merely because the server is still confirming it.
      void confirmReelCompletion(reel, Promise.resolve()).then(completed => {
        if (completed) void advance();
      });
    },
    [
      autoplay,
      course,
      confirmReelCompletion,
      currentIndex,
      maybeOfferReminders,
      ownsActiveReel,
      ownsCourse,
      playbackSpeed,
      previewMode,
      refs,
      refreshAfterSectionCompletion,
      scheduleDelayedAction,
      scrollToIndex,
      setChatVisible,
      setPreviewGateVisible,
      updateReelCompletion,
    ],
  );

  return {completeAndAdvance, persistProgress};
};
