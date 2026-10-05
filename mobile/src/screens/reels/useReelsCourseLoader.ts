import {useCallback, useEffect, useRef} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import {
  applyLocalLearningState,
  getLocalLearningState,
  loadCourseLearningData,
  reconcileServerSavedLessons,
  retryPendingSectionCompletions,
} from '../../components/VideoPlayer/courseLearningApi';
import {learningNavigationHandoff} from '../../components/VideoPlayer/courseLearning/navigationHandoff';
import {
  emptyLocalLearningState,
  overlayLocalLearningState,
} from '../../components/VideoPlayer/courseLearning/persistence';
import {settleWithin} from '../../utils/settleWithin';
import type {CourseLearningData} from '../../components/VideoPlayer/types';
import type {PlaybackRuntimeMetrics} from '../../components/VideoPlayer/playbackTelemetry';
import {
  friendlyNetworkMessage,
  networkFailureKind,
} from '../../services/networkExperience';
import {hasSession} from '../../services/roknApi';
import type {RootNavigation} from '../../navigation/types';
import {
  buildAccessibleFeed,
  buildPreviewFeed,
  resolveReelsFeedAnchor,
  type ReelsRouteParams,
} from './presentation';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';

type CourseLoaderRefs = {
  closedPlaybackSessions: MutableRefObject<Set<string>>;
  loadRequest: MutableRefObject<number>;
  loadAbort: MutableRefObject<AbortController | null>;
  loadedCourse: MutableRefObject<CourseLearningData | null>;
  loadedCourseOwner: MutableRefObject<string>;
  playbackDurations: MutableRefObject<Record<string, number>>;
  playbackRuntime: MutableRefObject<Record<string, PlaybackRuntimeMetrics>>;
  positions: MutableRefObject<Record<string, number>>;
};

export type CourseReloadTarget = {
  lessonId?: string;
  projectId?: string;
  index?: number;
  onResult?: (succeeded: boolean, reason?: 'project_changed') => void;
};

export const useReelsCourseLoader = ({
  navigation,
  identityKey,
  params,
  previewMode,
  refs,
  setConnectionNote,
  setCourse,
  setLoadError,
  setLoading,
  setPreviewGateVisible,
  requestInitialPosition,
  setSavedLessons,
  savedLessonsVersion,
  setServerSession,
}: {
  navigation: Pick<RootNavigation, 'replace'>;
  identityKey: string;
  params: ReelsRouteParams;
  previewMode: boolean;
  refs: CourseLoaderRefs;
  setConnectionNote: Dispatch<SetStateAction<string>>;
  setCourse: Dispatch<SetStateAction<CourseLearningData | null>>;
  setLoadError: Dispatch<SetStateAction<string>>;
  setLoading: Dispatch<SetStateAction<boolean>>;
  setPreviewGateVisible: Dispatch<SetStateAction<boolean>>;
  requestInitialPosition: (request: {key?: string; index?: number}) => void;
  setSavedLessons: Dispatch<SetStateAction<Set<string>>>;
  savedLessonsVersion?: MutableRefObject<number>;
  setServerSession: Dispatch<SetStateAction<boolean | null>>;
}) => {
  const consumedContinueAfterTokenRef = useRef<string | null>(null);
  const load = useCallback(
    async (reloadTarget?: CourseReloadTarget) => {
      refs.loadAbort.current?.abort();
      const controller = new AbortController();
      refs.loadAbort.current = controller;
      const requestId = ++refs.loadRequest.current;
      const requestedCourseId = String(params.courseId || '');
      const savedVersionAtLoadStart = savedLessonsVersion?.current;
      const hasCurrentCourse =
        refs.loadedCourse.current?.id === requestedCourseId &&
        refs.loadedCourseOwner.current === identityKey;
      if (!hasCurrentCourse) {
        refs.closedPlaybackSessions.current.clear();
        refs.playbackRuntime.current = {};
        refs.playbackDurations.current = {};
        refs.loadedCourse.current = null;
        refs.loadedCourseOwner.current = identityKey;
        setCourse(null);
        setLoading(true);
        setLoadError('');
      }
      setPreviewGateVisible(false);
      const boundary = await captureAccountSessionBoundary().catch(() => null);
      const ownsLoadSlot = () =>
        requestId === refs.loadRequest.current &&
        refs.loadedCourseOwner.current === identityKey;
      const isCurrentOwner = () => {
        if (!boundary || !ownsLoadSlot()) return false;
        try {
          assertAccountSessionBoundary(boundary);
          return true;
        } catch {
          return false;
        }
      };
      try {
        if (!boundary) {
          if (!ownsLoadSlot()) return;
          if (hasCurrentCourse) {
            setConnectionNote(
              'تعذّر تحديث محتوى الكورس\nحاول مرة أخرى من زر الفيديو',
            );
          } else {
            setLoadError('تعذّر فتح محتوى الكورس\nمكانك محفوظ\nحاول مرة أخرى');
          }
          if (refs.loadAbort.current === controller) {
            refs.loadAbort.current = null;
          }
          setLoading(false);
          reloadTarget?.onResult?.(false);
          return;
        }
        if (!isCurrentOwner()) return;
        // Optional native state and session restore overlap the metadata read.
        // Read player state once, with one short budget, rather than making a
        // second identical storage read after course details have arrived.
        const rawLocalStateFlight = getLocalLearningState(undefined, boundary);
        const localFallback = emptyLocalLearningState();
        const localStateFlight = settleWithin(
          rawLocalStateFlight,
          localFallback,
          250,
        );
        const sessionFlight = hasSession();
        // Attach a rejection handler now even if metadata takes longer.
        const localContextFlight = Promise.all([
          localStateFlight,
          sessionFlight,
        ]);
        void localContextFlight.catch(() => undefined);
        const handoff =
          !hasCurrentCourse && !reloadTarget
            ? learningNavigationHandoff.take(
                params.learningHandoffKey,
                requestedCourseId,
                boundary,
              )
            : null;
        if (handoff) {
          void retryPendingSectionCompletions().catch(() => undefined);
        }
        const result = handoff
          ? {course: handoff}
          : await loadCourseLearningData(requestedCourseId || undefined, {
              signal: controller.signal,
            });
        if (!isCurrentOwner()) return;
        // A public details payload contains the free samples plus the outline.
        // It is not a learning entitlement. If a stale CTA/deep link opens the
        // player without preview mode, return to the commercial course page
        // instead of turning the first paid reel into a project-style gate.
        const accessType = String(result.course.accessType || '')
          .trim()
          .toLowerCase();
        if (
          !previewMode &&
          (!accessType || accessType === 'none' || accessType === 'preview')
        ) {
          navigation.replace('CourseDetails', {courseId: requestedCourseId});
          reloadTarget?.onResult?.(false);
          return;
        }
        if (
          reloadTarget?.projectId &&
          !result.course.modules.some(module =>
            (module.projects || []).some(
              project => project.id === reloadTarget.projectId,
            ),
          )
        ) {
          // Another publication overtook the prepared draft destination. Keep
          // its source editor until the learner resolves the latest lineage.
          // Gated projects still exist in the outline; only absence blocks this
          // transition, never ordinary access checks or an explicit course load.
          reloadTarget.onResult?.(false, 'project_changed');
          return;
        }
        const [localState, sessionAvailable] = await localContextFlight;
        const withLocalState = await applyLocalLearningState(
          result.course,
          localState,
        );
        if (!isCurrentOwner()) return;
        setServerSession(sessionAvailable);
        // An in-place refresh must not erase the current session while its
        // optional device read is slow, or replace a newer position with an
        // older persisted sample. New course/account entry still resets it.
        refs.positions.current = hasCurrentCourse
          ? {...localState.positions, ...refs.positions.current}
          : localState.positions;
        if (!hasCurrentCourse) {
          setSavedLessons(new Set(localState.savedLessons));
        }
        let savedReadVersion = hasCurrentCourse
          ? savedVersionAtLoadStart
          : savedLessonsVersion?.current;
        let serverSavedResolved = false;
        refs.loadedCourse.current = withLocalState;
        refs.loadedCourseOwner.current = identityKey;
        setCourse(withLocalState);
        setConnectionNote('');
        if (sessionAvailable) {
          const lessonIds = withLocalState.modules.flatMap(module =>
            module.reels.map(reel => reel.lessonId),
          );
          void reconcileServerSavedLessons(lessonIds)
            .then(serverSaved => {
              if (!isCurrentOwner()) return;
              serverSavedResolved = true;
              if (savedLessonsVersion?.current === savedReadVersion) {
                setSavedLessons(new Set(serverSaved));
                savedReadVersion = savedLessonsVersion?.current;
              }
            })
            .catch(() => undefined);
        }
        // Route anchors select the initial destination only. A runtime reload
        // with an explicit index (for example after opening a project gate)
        // must not be dragged back to the reel that originally opened the
        // screen.
        const continueAfterReelId = String(
          params.continueAfterReelId || '',
        ).trim();
        const continueAfterToken = continueAfterReelId
          ? `${requestedCourseId}:${continueAfterReelId}`
          : '';
        const pendingContinueAfterReelId =
          !reloadTarget &&
          continueAfterToken &&
          consumedContinueAfterTokenRef.current !== continueAfterToken
            ? continueAfterReelId
            : undefined;
        const requestedAnchor = reloadTarget
          ? {lessonId: reloadTarget.lessonId, projectId: reloadTarget.projectId}
          : {
              reelId: params.reelId,
              lessonId: params.lessonId,
              projectId: params.projectId,
              continueAfterReelId: pendingContinueAfterReelId,
            };
        const requestedPosition = Number(params.initialPositionSeconds);
        const accessibleItems = previewMode
          ? buildPreviewFeed(withLocalState)
          : buildAccessibleFeed(withLocalState);
        const resolvedAnchor = resolveReelsFeedAnchor(
          accessibleItems,
          requestedAnchor,
        );
        if (
          !reloadTarget &&
          resolvedAnchor?.item.type === 'reel' &&
          Number.isFinite(requestedPosition) &&
          requestedPosition > 0
        ) {
          refs.positions.current[
            `${withLocalState.id}:${resolvedAnchor.item.reel.id}`
          ] = requestedPosition;
        }
        const requestedIndex = Number(
          reloadTarget?.index ?? params.initialReelIndex,
        );
        const firstPendingIndex = accessibleItems.findIndex(item =>
          item.type === 'project'
            ? item.project.status !== 'passed'
            : !item.reel.isCompleted,
        );
        const initialIndex =
          !resolvedAnchor && Number.isFinite(requestedIndex)
            ? Math.max(0, Math.floor(requestedIndex))
            : !resolvedAnchor && accessibleItems.length
            ? firstPendingIndex >= 0
              ? firstPendingIndex
              : accessibleItems.length - 1
            : null;
        requestInitialPosition({
          key: resolvedAnchor?.item.key,
          ...(initialIndex !== null ? {index: initialIndex} : {}),
        });
        if (pendingContinueAfterReelId) {
          consumedContinueAfterTokenRef.current = continueAfterToken;
        }
        if (localState === localFallback) {
          // A slow read is not a lost read. Restore only untouched positions
          // and presentation hints; never reposition the feed or overwrite a
          // newer playback sample, explicit route position or bookmark command.
          void rawLocalStateFlight
            .then(lateState => {
              if (!isCurrentOwner()) return;
              refs.positions.current = {
                ...lateState.positions,
                ...refs.positions.current,
              };
              setCourse(current => {
                if (!isCurrentOwner() || current?.id !== requestedCourseId)
                  return current;
                const hydrated = overlayLocalLearningState(current, lateState);
                refs.loadedCourse.current = hydrated;
                return hydrated;
              });
              if (
                !hasCurrentCourse &&
                !serverSavedResolved &&
                savedLessonsVersion?.current === savedReadVersion
              ) {
                setSavedLessons(new Set(lateState.savedLessons));
                savedReadVersion = savedLessonsVersion?.current;
              }
            })
            .catch(() => undefined);
        }
        reloadTarget?.onResult?.(true);
      } catch (error) {
        if (!isCurrentOwner()) return;
        if (networkFailureKind(error) === 'cancelled') return;
        if (hasCurrentCourse) {
          setConnectionNote(friendlyNetworkMessage(error, 'الفيديو'));
        } else {
          refs.loadedCourse.current = null;
          setCourse(null);
          setLoadError(
            'تعذّر فتح محتوى الكورس\nمكانك محفوظ\nتحقق من الاتصال ثم حاول مرة أخرى',
          );
        }
        reloadTarget?.onResult?.(false);
      } finally {
        if (isCurrentOwner()) {
          if (refs.loadAbort.current === controller)
            refs.loadAbort.current = null;
          setLoading(false);
        }
      }
    },
    [
      navigation,
      identityKey,
      params.courseId,
      params.continueAfterReelId,
      params.initialReelIndex,
      params.learningHandoffKey,
      params.initialPositionSeconds,
      params.lessonId,
      params.projectId,
      params.reelId,
      previewMode,
      refs,
      requestInitialPosition,
      setConnectionNote,
      setCourse,
      setLoadError,
      setLoading,
      setPreviewGateVisible,
      setSavedLessons,
      savedLessonsVersion,
      setServerSession,
    ],
  );

  useEffect(() => {
    void load();
    return () => {
      refs.loadAbort.current?.abort();
      refs.loadRequest.current += 1;
    };
  }, [load, refs]);

  return load;
};
