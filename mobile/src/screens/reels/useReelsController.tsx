import {useIsFocused, useNavigation, useRoute} from '@react-navigation/native';
import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {useSafeAreaInsets} from 'react-native-safe-area-context';
import {goBackOrHome} from '../../navigation/RootNavigationHelper';
import {submitProjectAttempt} from '../../components/VideoPlayer/courseLearningApi';
import {
  CourseReel,
  SelectedProjectFile,
} from '../../components/VideoPlayer/types';
import {
  buildAccessibleFeed,
  buildPreviewFeed,
  resolveReelsFrameWidth,
  type ReelsRouteParams,
  updateProjectStatusOnly,
} from './presentation';
import {usePlaybackPreferences} from './usePlaybackPreferences';
import {useReminderNudge} from './useReminderNudge';
import {useReelsLifecycle} from './useReelsLifecycle';
import {useReelsCourseLoader} from './useReelsCourseLoader';
import {
  useReelsFeedRenderer,
  type ReelsNavigation,
} from './useReelsFeedRenderer';
import {useProjectReview} from './useProjectReview';
import {useReelsProgress} from './useReelsProgress';
import {useReelsPosition} from './useReelsPosition';
import {useReelsSavedLessons} from './useReelsSavedLessons';
import {useReelsPlaybackRuntime} from './useReelsPlaybackRuntime';
import {useReelsManifestOwner} from './useReelsManifestOwner';
import {useReelsCourseState} from './useReelsCourseState';
import {useReelsCourseRevision} from './useReelsCourseRevision';
import {sessionIdentityKey} from '../../constants/helpers';
import {useSelector} from 'react-redux';
import type {RootState} from '../../store/store';
import {hasCourseLearningAccess} from '../../components/VideoPlayer/courseEntitlements';

export const useReelsController = () => {
  const route = useRoute();
  const navigation = useNavigation<ReelsNavigation>();
  const isScreenFocused = useIsFocused();
  const params = (route.params || {}) as ReelsRouteParams;
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const previewMode = params.preview === true;
  const requestedCourseId = String(params.courseId || '');
  const requestedCourseViewKey = `${requestedCourseId}:${
    previewMode ? 'preview' : 'learning'
  }`;
  const insets = useSafeAreaInsets();
  const reviewWatcherRef = useRef(0);
  const watchedProjectRef = useRef<string | null>(null);
  const {
    accountViewGenerationRef,
    connectionNote,
    course,
    courseRevisionPendingRef,
    courseRevisionRefreshing,
    courseRevisionReloadRef,
    learningMapRetryIndex,
    loadAbortRef,
    loadError,
    loading,
    loadRequestRef,
    loadedCourseOwnerRef,
    loadedCourseRef,
    serverSession,
    setConnectionNote,
    setCourse,
    setCourseRevisionRefreshing,
    setLearningMapRetryIndex,
    setLoadError,
    setLoading,
    setServerSession,
  } = useReelsCourseState({
    identityKey,
    requestedCourseId,
    scopeKey: requestedCourseViewKey,
  });
  const {
    autoplay,
    changePlaybackSpeed,
    changeQuality,
    dataSaver,
    getPlaybackSpeed,
    playbackPreferencesReady,
    playbackSpeed,
    selectedQuality,
  } = usePlaybackPreferences(serverSession, identityKey);
  const [chatVisible, setChatVisible] = useState(false);

  useEffect(() => {
    // React Navigation keeps the reels screen mounted under pushed routes.
    // Never leave its native chat Modal interactive above the next screen.
    if (!isScreenFocused) setChatVisible(false);
  }, [isScreenFocused]);

  useEffect(() => {
    if (!isScreenFocused || !course || params.openCourseChatUpgrade !== true)
      return;
    if (hasCourseLearningAccess(course.accessType)) setChatVisible(true);
    navigation.setParams({openCourseChatUpgrade: false});
  }, [course, isScreenFocused, navigation, params.openCourseChatUpgrade]);

  const [contentOverlayVisible, setContentOverlayVisible] = useState(false);
  const contentOverlayScopesRef = useRef(new Set<string>());
  const handleContentOverlayVisibility = useCallback(
    (scopeKey: string, visible: boolean) => {
      if (visible) {
        contentOverlayScopesRef.current.add(scopeKey);
      } else {
        contentOverlayScopesRef.current.delete(scopeKey);
      }
      setContentOverlayVisible(contentOverlayScopesRef.current.size > 0);
    },
    [],
  );
  const [previewGateVisible, setPreviewGateVisible] = useState(false);
  const {
    closeReminderNudge,
    enableRemindersFromNudge,
    maybeOfferReminders,
    reminderNudgeVisible,
  } = useReminderNudge({
    courseId: course?.id,
    courseTitle: course?.title,
  });
  const routeNavigationFlightRef = useRef(false);
  const courseRevisionReloadHandlerRef = useRef<(lessonId?: string) => void>(
    () => undefined,
  );
  const mountedRef = useRef(true);
  const delayedActionsRef = useRef(new Set<ReturnType<typeof setTimeout>>());
  const {savedLessons, savingLessons, setSavedLessons, toggleSaved} =
    useReelsSavedLessons({
      loadedCourse: loadedCourseRef,
      mounted: mountedRef,
      ownerGeneration: accountViewGenerationRef,
      scopeKey: `${identityKey}:${requestedCourseViewKey}`,
      setConnectionNote,
    });

  useEffect(() => {
    // Non-course collaborators own only their local scope cleanup. Course
    // state/request invalidation belongs to useReelsCourseState.
    reviewWatcherRef.current += 1;
    routeNavigationFlightRef.current = false;
    watchedProjectRef.current = null;
    delayedActionsRef.current.forEach(timer => clearTimeout(timer));
    delayedActionsRef.current.clear();
    contentOverlayScopesRef.current.clear();
    setChatVisible(false);
    setContentOverlayVisible(false);
    setPreviewGateVisible(false);
  }, [identityKey, requestedCourseViewKey]);
  const scheduleDelayedAction = useCallback(
    (action: () => void, delayMs: number) => {
      const timer = setTimeout(() => {
        delayedActionsRef.current.delete(timer);
        action();
      }, delayMs);
      delayedActionsRef.current.add(timer);
    },
    [],
  );

  const feedItems = useMemo(
    () =>
      course
        ? previewMode
          ? buildPreviewFeed(course)
          : buildAccessibleFeed(course)
        : [],
    [course, previewMode],
  );
  const {
    currentIndex,
    currentIndexRef,
    feedLengthRef,
    layout,
    listRef,
    onLayout,
    onPagingCancelled,
    onPagingSettled,
    onPagingStarted,
    onScroll,
    onScrollToIndexFailed,
    onViewableItemsChanged,
    paging,
    requestInitialPosition,
    scrollToIndex,
    scrollToKey,
    viewabilityConfig,
  } = useReelsPosition({
    feedItems,
    ownerGeneration: accountViewGenerationRef,
    scopeKey: `${identityKey}:${requestedCourseViewKey}`,
  });
  const currentItem = feedItems[currentIndex] || feedItems[0];
  const currentReel: CourseReel | undefined =
    currentItem?.type === 'reel' ? currentItem.reel : undefined;
  const manifestRefreshHandlerRef = useRef<() => void>(() => undefined);
  const refreshPlaybackSources = useCallback(
    () => manifestRefreshHandlerRef.current(),
    [],
  );
  const playbackRuntime = useReelsPlaybackRuntime({
    courseId: course?.id,
    currentReel,
    getPlaybackSpeed,
    isScreenFocused,
    mounted: mountedRef,
    onSessionClosed: refreshPlaybackSources,
    ownerGeneration: accountViewGenerationRef,
    scopeKey: `${identityKey}:${requestedCourseViewKey}`,
  });
  const {
    activeReel: activeReelRef,
    closedSessions: closedPlaybackSessionsRef,
    completionSent: completionSentRef,
    durations: playbackDurationRef,
    handlePlaybackEvent,
    handlePlaybackMetrics,
    lastPersisted: lastPersistedRef,
    positions: positionsRef,
    reportBackground,
    runtime: playbackRuntimeRef,
  } = playbackRuntime;
  const lifecycleRefs = useMemo(
    () => ({
      delayedActions: delayedActionsRef,
      loadAbort: loadAbortRef,
      loadRequest: loadRequestRef,
      mounted: mountedRef,
      reviewWatcher: reviewWatcherRef,
    }),
    [loadAbortRef, loadRequestRef],
  );
  const appIsActive = useReelsLifecycle(
    lifecycleRefs,
    refreshPlaybackSources,
    reportBackground,
  );
  useEffect(() => {
    if (!isScreenFocused || !appIsActive) onPagingCancelled();
  }, [appIsActive, isScreenFocused, onPagingCancelled]);
  const interactionLocked =
    chatVisible ||
    reminderNudgeVisible ||
    previewGateVisible ||
    contentOverlayVisible ||
    courseRevisionRefreshing;
  const handleCourseRevisionChanged = useCallback(() => {
    courseRevisionReloadHandlerRef.current();
  }, []);
  const manifestOwner = useReelsManifestOwner({
    activeReel: activeReelRef,
    appIsActive,
    course,
    courseRef: loadedCourseRef,
    currentIndex,
    currentItem,
    currentReel,
    dataSaver,
    durations: playbackDurationRef,
    feedItems,
    getPlaybackSpeed,
    interactionLocked,
    isScreenFocused,
    mounted: mountedRef,
    onCourseRevisionChanged: handleCourseRevisionChanged,
    ownerGeneration: accountViewGenerationRef,
    playbackPreferencesReady,
    positions: positionsRef,
    revisionReloadPending: courseRevisionPendingRef,
    runtime: playbackRuntimeRef,
    scheduleDelayedAction,
    scopeKey: `${identityKey}:${requestedCourseViewKey}`,
    selectedQuality,
    serverSession,
    setConnectionNote,
    setCourse,
    sessionsClosed: closedPlaybackSessionsRef,
  });
  manifestRefreshHandlerRef.current = manifestOwner.refreshSources;
  const {
    canPreloadAdjacentVideo,
    invalidateManifests,
    requestPlaybackManifest,
  } = manifestOwner;

  const loaderRefs = useMemo(
    () => ({
      closedPlaybackSessions: closedPlaybackSessionsRef,
      loadAbort: loadAbortRef,
      loadRequest: loadRequestRef,
      loadedCourse: loadedCourseRef,
      loadedCourseOwner: loadedCourseOwnerRef,
      playbackDurations: playbackDurationRef,
      playbackRuntime: playbackRuntimeRef,
      positions: positionsRef,
    }),
    [
      closedPlaybackSessionsRef,
      loadAbortRef,
      loadRequestRef,
      loadedCourseOwnerRef,
      loadedCourseRef,
      playbackDurationRef,
      playbackRuntimeRef,
      positionsRef,
    ],
  );
  const load = useReelsCourseLoader({
    navigation,
    identityKey,
    params,
    previewMode,
    requestInitialPosition,
    refs: loaderRefs,
    setConnectionNote,
    setCourse,
    setLoadError,
    setLoading,
    setPreviewGateVisible,
    setSavedLessons,
    setServerSession,
  });

  const reloadPublishedCourse = useReelsCourseRevision({
    activeReel: activeReelRef,
    closedSessions: closedPlaybackSessionsRef,
    currentIndex: currentIndexRef,
    invalidateManifests,
    load,
    loadedCourse: loadedCourseRef,
    mounted: mountedRef,
    pending: courseRevisionPendingRef,
    reloadFlight: courseRevisionReloadRef,
    setConnectionNote,
    setRefreshing: setCourseRevisionRefreshing,
  });
  courseRevisionReloadHandlerRef.current = reloadPublishedCourse;

  const projectReviewRefs = useMemo(
    () => ({
      loadedCourse: loadedCourseRef,
      mounted: mountedRef,
      ownerGeneration: accountViewGenerationRef,
      reviewWatcher: reviewWatcherRef,
      watchedProject: watchedProjectRef,
    }),
    [accountViewGenerationRef, loadedCourseRef],
  );
  const {refreshProjectState, watchProjectUntilResolved} = useProjectReview({
    active: isScreenFocused,
    course,
    previewMode,
    refs: projectReviewRefs,
    setCourse,
  });

  useEffect(() => {
    if (
      !connectionNote ||
      courseRevisionRefreshing ||
      learningMapRetryIndex !== null
    ) {
      return;
    }
    const timer = setTimeout(() => setConnectionNote(''), 4600);
    return () => clearTimeout(timer);
  }, [
    connectionNote,
    courseRevisionRefreshing,
    learningMapRetryIndex,
    setConnectionNote,
  ]);

  const frameWidth = useMemo(() => resolveReelsFrameWidth(layout), [layout]);

  const progressRefs = useMemo(
    () => ({
      completionSent: completionSentRef,
      feedLength: feedLengthRef,
      lastPersisted: lastPersistedRef,
      ownerGeneration: accountViewGenerationRef,
      playbackDurations: playbackDurationRef,
      playbackRuntime: playbackRuntimeRef,
      positions: positionsRef,
    }),
    [
      completionSentRef,
      feedLengthRef,
      lastPersistedRef,
      accountViewGenerationRef,
      playbackDurationRef,
      playbackRuntimeRef,
      positionsRef,
    ],
  );
  const refreshAfterSectionCompletion = useCallback(
    async (targetIndex: number) => {
      const ownerGeneration = accountViewGenerationRef.current;
      let succeeded = false;
      await load({
        index: targetIndex,
        onResult: result => {
          succeeded = result;
        },
      });
      if (accountViewGenerationRef.current !== ownerGeneration) return false;
      setLearningMapRetryIndex(succeeded ? null : targetIndex);
      return succeeded;
    },
    [accountViewGenerationRef, load, setLearningMapRetryIndex],
  );
  const retryLearningMap = useCallback(async () => {
    const targetIndex = learningMapRetryIndex;
    if (targetIndex === null) return;
    const ownerGeneration = accountViewGenerationRef.current;
    let succeeded = false;
    await load({
      index: targetIndex,
      onResult: result => {
        succeeded = result;
      },
    });
    if (succeeded && accountViewGenerationRef.current === ownerGeneration) {
      setLearningMapRetryIndex(null);
    }
  }, [
    accountViewGenerationRef,
    learningMapRetryIndex,
    load,
    setLearningMapRetryIndex,
  ]);
  const {completeAndAdvance, persistProgress} = useReelsProgress({
    autoplay,
    course,
    currentIndex,
    feedItems,
    maybeOfferReminders,
    playbackSpeed,
    previewMode,
    refs: progressRefs,
    refreshAfterSectionCompletion,
    scheduleDelayedAction,
    scrollToIndex,
    setChatVisible,
    setCourse,
    setPreviewGateVisible,
  });

  const submitProject = useCallback(
    async (projectId: string, files: SelectedProjectFile[], note?: string) => {
      const result = await submitProjectAttempt(projectId, files, note);
      if (result.submissionStatus === 'passed') {
        // Unlock only after refreshed media entitlements arrive.
        const refreshed = await refreshProjectState(projectId);
        if (refreshed?.status === 'passed') {
          return {...result, canContinue: refreshed.canContinue};
        }
        setCourse(current =>
          current
            ? updateProjectStatusOnly(current, projectId, 'passed')
            : current,
        );
        watchProjectUntilResolved(projectId);
        return {...result, accepted: true, canContinue: false};
      }

      if (result.submissionStatus === 'evaluating') {
        setCourse(current =>
          current
            ? updateProjectStatusOnly(current, projectId, 'evaluating')
            : current,
        );
        watchProjectUntilResolved(projectId);
      } else if (result.submissionStatus === 'needs_changes') {
        setCourse(current =>
          current
            ? updateProjectStatusOnly(
                current,
                projectId,
                'needs_changes',
                result.reviewFeedback,
              )
            : current,
        );
      }
      return result;
    },
    [refreshProjectState, setCourse, watchProjectUntilResolved],
  );

  const renderItem = useReelsFeedRenderer({
    bottomInset: insets.bottom,
    changePlaybackSpeed,
    changeQuality,
    completeAndAdvance,
    course,
    currentIndex,
    feedLength: feedItems.length,
    frameWidth,
    handlePlaybackEvent,
    handlePlaybackMetrics,
    layout,
    load,
    navigation,
    persistProgress,
    playbackSpeed,
    playbackBlocked:
      !isScreenFocused || !appIsActive || interactionLocked || paging,
    preloadNext: canPreloadAdjacentVideo && !paging,
    positions: positionsRef,
    preview: params.preview === true,
    previewCount: params.previewCount,
    requestPlaybackManifest,
    screenFocused: isScreenFocused,
    savedLessons,
    savingLessons,
    scheduleDelayedAction,
    scrollToIndex,
    scrollToKey,
    selectedQuality,
    serverSession,
    setChatVisible,
    onContentOverlayVisibilityChange: handleContentOverlayVisibility,
    submitProject,
    toggleSaved,
    topInset: insets.top,
  });

  const showCourseDetails = useCallback(
    (openPurchase: boolean) => {
      if (!course || routeNavigationFlightRef.current) return;
      routeNavigationFlightRef.current = true;
      const currentFeedItem = feedItems[currentIndex];
      const resumeReelId =
        currentFeedItem?.type === 'reel'
          ? String(currentFeedItem.reel.id)
          : undefined;
      navigation.replace('CourseDetails', {
        courseId: params.courseId || course.id,
        openPurchase,
        resumeAfterPreview: openPurchase,
        resumeReelId,
      });
    },
    [course, currentIndex, feedItems, navigation, params.courseId],
  );

  return {
    canOpenCourseAssistant: Boolean(course),
    chatVisible,
    closeChat: () => setChatVisible(false),
    closeReminderNudge,
    connectionNote,
    course,
    courseRevisionRefreshing,
    currentReel,
    enableRemindersFromNudge,
    feedItems,
    identityKey,
    insets,
    layout,
    listRef,
    loadError,
    loading,
    onBack: () => goBackOrHome(navigation),
    onEmptyCourseDetails: () => {
      if (!course) return;
      navigation.replace('CourseDetails', {courseId: course.id});
    },
    onLayout,
    onPagingSettled,
    onPagingStarted,
    onReload: () => void load(),
    onConnectionNotePress: courseRevisionRefreshing
      ? () => reloadPublishedCourse(activeReelRef.current?.lessonId)
      : learningMapRetryIndex !== null
      ? () => void retryLearningMap()
      : undefined,
    onScroll,
    onScrollToIndexFailed,
    onViewableItemsChanged,
    previewGateVisible,
    reminderNudgeVisible,
    renderItem,
    scrollEnabled: !interactionLocked,
    showCourseDetails,
    viewabilityConfig,
  };
};

export type ReelsController = ReturnType<typeof useReelsController>;
