import React, {
  createContext,
  memo,
  useCallback,
  useContext,
  useLayoutEffect,
  useMemo,
  useRef,
} from 'react';
import type {Dispatch, MutableRefObject, SetStateAction} from 'react';
import FeedRow from '../../components/VideoPlayer/FeedRow';
import type {ProjectResolution} from '../../components/VideoPlayer/courseLearning/projectRemote';
import type {
  ProjectSubmissionOutcome,
  SavedFolderOption,
} from '../../components/VideoPlayer/courseLearningApi';
import type {
  CourseFeedItem,
  CourseLearningData,
  CourseReel,
  SelectedProjectFile,
  VideoQuality,
} from '../../components/VideoPlayer/types';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from '../../components/VideoPlayer/playbackTelemetry';
import {manifestRefreshDelayMs} from '../../components/VideoPlayer/playbackTelemetry';
import type {RootNavigation} from '../../navigation/types';
import {openGuestLogin} from '../../navigation/journeyNavigation';
import type {CourseReloadTarget} from './useReelsCourseLoader';

export type ReelsNavigation = RootNavigation;

/** Latest learner actions. Rows call through this ref instead of capturing them. */
type ReelsFeedActions = {
  applyReviewResolution?: (
    projectId: string,
    resolution: ProjectResolution,
  ) => void;
  changePlaybackSpeed: (speed: number) => void;
  changeQuality: (quality: VideoQuality) => void;
  completeAndAdvance: (reel: CourseReel) => void | Promise<void>;
  courseId?: string;
  handlePlaybackEvent: (reel: CourseReel, event: PlaybackPlayerEvent) => void;
  handlePlaybackMetrics: (
    reel: CourseReel,
    metrics: PlaybackRuntimeMetrics,
  ) => void;
  load: (target?: CourseReloadTarget) => Promise<void>;
  navigation: ReelsNavigation;
  onContentOverlayVisibilityChange: (
    scopeKey: string,
    visible: boolean,
  ) => void;
  persistProgress: (
    reel: CourseReel,
    currentTime: number,
    duration: number,
  ) => void;
  preview: boolean;
  previewCount?: number;
  refreshCourseEntitlements?: () => void | Promise<void>;
  requestPlaybackManifest: (
    reel: CourseReel,
    expectedSessionId?: string,
  ) => void | Promise<void>;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  scrollToIndex: (index: number) => void;
  scrollToKey: (key: string) => void;
  serverSession: boolean | null;
  setChatVisible: Dispatch<SetStateAction<boolean>>;
  submitProject: (
    projectId: string,
    files: SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  removeFromList: (
    reel: CourseReel,
    folder: SavedFolderOption,
    stillSavedElsewhere: boolean,
  ) => Promise<boolean>;
  toggleSaved: (
    reel: CourseReel,
    folder?: SavedFolderOption | null,
  ) => Promise<boolean>;
};

/**
 * Feed-wide values that change while paging. Rows read them from context and
 * pass only their own derived primitives to the memoized FeedRow, so a swipe
 * re-renders the rows whose visibility or blocking actually changed.
 */
export type ReelsFeedState = {
  actions: MutableRefObject<ReelsFeedActions>;
  controlsHidden: boolean;
  bottomInset: number;
  course: CourseLearningData | null;
  currentIndex: number;
  feedLength: number;
  frameWidth: number;
  nativePreloadMode: boolean;
  nativePreloadOwner?: string;
  nativePreloadPending: boolean;
  pageHeight: number;
  pageWidth: number;
  playbackBlocked: boolean;
  playbackSpeed: number;
  positions: MutableRefObject<Record<string, number>>;
  preloadNext: boolean;
  savedLessons: Set<string>;
  savingLessons: Set<string>;
  screenFocused: boolean;
  selectedQuality: VideoQuality;
  topInset: number;
};

export const ReelsFeedContext = createContext<ReelsFeedState | null>(null);

const ReelsFeedRow = memo(
  ({item, index}: {item: CourseFeedItem; index: number}) => {
    const feed = useContext(ReelsFeedContext);
    const actions = feed?.actions;
    const reel = item.type === 'reel' ? item.reel : undefined;
    const call = useCallback(
      <T,>(run: (latest: ReelsFeedActions) => T) =>
        actions ? run(actions.current) : undefined,
      [actions],
    );

    // Stable per feed item: these callbacks read the latest controller
    // actions at call time (React's "effect event" pattern) instead of
    // capturing per-render closures that defeat FeedRow's memo.
    // The controller owns failure feedback; the row only learns whether the
    // command was confirmed so it can show a success confirmation.
    const onToggleSave = useCallback(
      (folder?: SavedFolderOption | null) =>
        call(latest =>
          reel
            ? latest.toggleSaved(reel, folder).catch(() => false)
            : undefined,
        ) ?? Promise.resolve(false),
      [call, reel],
    );
    const onRemoveFromList = useCallback(
      (folder: SavedFolderOption, stillSavedElsewhere: boolean) =>
        call(latest =>
          reel
            ? latest
                .removeFromList(reel, folder, stillSavedElsewhere)
                .catch(() => false)
            : undefined,
        ) ?? Promise.resolve(false),
      [call, reel],
    );
    const onBeforeOpenSave = useCallback(
      () =>
        call(latest => {
          if (latest.serverSession === true) return true;
          if (!latest.courseId) return false;
          openGuestLogin(latest.navigation, {
            name: 'Reels',
            params: {
              courseId: latest.courseId,
              reelId: reel?.id,
              preview: latest.preview,
              previewCount: latest.previewCount,
            },
          });
          return false;
        }) === true,
      [call, reel],
    );
    const onOpenChat = useCallback(
      () => call(latest => latest.setChatVisible(true)),
      [call],
    );
    const onOverlayVisibilityChange = useCallback(
      (scopeKey: string, visible: boolean) =>
        call(latest =>
          latest.onContentOverlayVisibilityChange(scopeKey, visible),
        ),
      [call],
    );
    const onSelectFeedItem = useCallback(
      (key: string) => call(latest => latest.scrollToKey(key)),
      [call],
    );
    const onPlaybackSpeedChange = useCallback(
      (speed: number) => call(latest => latest.changePlaybackSpeed(speed)),
      [call],
    );
    const onQualityChange = useCallback(
      (quality: VideoQuality) => call(latest => latest.changeQuality(quality)),
      [call],
    );
    const onProgress = useCallback(
      (time: number, duration: number) =>
        call(latest => reel && latest.persistProgress(reel, time, duration)),
      [call, reel],
    );
    const onPlaybackEvent = useCallback(
      (event: PlaybackPlayerEvent) =>
        call(latest => reel && latest.handlePlaybackEvent(reel, event)),
      [call, reel],
    );
    const onPlaybackMetrics = useCallback(
      (metrics: PlaybackRuntimeMetrics) =>
        call(latest => reel && latest.handlePlaybackMetrics(reel, metrics)),
      [call, reel],
    );
    const onComplete = useCallback(
      () => call(latest => reel && latest.completeAndAdvance(reel)),
      [call, reel],
    );
    const onRefreshVideo = useCallback(
      () =>
        call(latest => {
          if (reel && latest.serverSession) {
            return latest.requestPlaybackManifest(reel, reel.playbackSessionId);
          }
          return latest.load({lessonId: reel?.lessonId});
        }),
      [call, reel],
    );
    const onSubmitProject = useCallback(
      (files: SelectedProjectFile[], note?: string) =>
        call(latest =>
          item.type === 'project'
            ? latest.submitProject(item.project.id, files, note)
            : undefined,
        ) ||
        Promise.resolve({
          submissionStatus: 'draft' as const,
          accepted: false,
          canContinue: false,
        }),
      [call, item],
    );
    const continueAfterProject = useCallback(
      () =>
        call(latest =>
          latest.scheduleDelayedAction(
            () => latest.scrollToIndex(index + 1),
            80,
          ),
        ),
      [call, index],
    );
    const onReviewResolution = useCallback(
      (resolution: ProjectResolution) =>
        call(latest => {
          if (item.type === 'project')
            latest.applyReviewResolution?.(item.project.id, resolution);
        }),
      [call, item],
    );
    const onEntitlementChanged = useCallback(
      () => call(latest => latest.refreshCourseEntitlements?.()),
      [call],
    );

    if (!feed?.course || !feed.pageHeight || !feed.frameWidth) return null;
    const {
      currentIndex,
      nativePreloadMode,
      nativePreloadPending,
      preloadNext,
      screenFocused,
    } = feed;
    const isActive = index === currentIndex;
    const isVisible = isActive && screenFocused;
    const signedSourceExpired =
      Boolean(reel?.playbackSessionId) &&
      manifestRefreshDelayMs(reel?.playbackExpiresAt) === 0;

    return (
      <FeedRow
        onEntitlementChanged={onEntitlementChanged}
        item={item}
        course={feed.course}
        pageWidth={feed.pageWidth}
        pageHeight={feed.pageHeight}
        frameWidth={feed.frameWidth}
        isVisible={isVisible}
        controlsHidden={feed.controlsHidden}
        // A hidden row can never play. Keeping it blocked avoids re-rendering
        // the paused neighbour on every drag start/settle.
        playbackBlocked={isVisible ? feed.playbackBlocked : true}
        nativePreloadOwner={feed.nativePreloadOwner}
        shouldMountVideo={
          screenFocused &&
          !nativePreloadPending &&
          Boolean(reel?.videoUrl.trim()) &&
          !signedSourceExpired &&
          (isActive ||
            (preloadNext && !nativePreloadMode && index === currentIndex + 1))
        }
        playbackSpeed={feed.playbackSpeed}
        selectedQuality={feed.selectedQuality}
        saved={reel ? feed.savedLessons.has(reel.lessonId) : false}
        savePending={reel ? feed.savingLessons.has(reel.lessonId) : false}
        initialPosition={
          reel ? feed.positions.current[`${feed.course.id}:${reel.id}`] || 0 : 0
        }
        topInset={feed.topInset}
        bottomInset={feed.bottomInset}
        onPlaybackSpeedChange={onPlaybackSpeedChange}
        onQualityChange={onQualityChange}
        onToggleSave={onToggleSave}
        onRemoveFromList={onRemoveFromList}
        onBeforeOpenSave={onBeforeOpenSave}
        onOpenChat={onOpenChat}
        onOverlayVisibilityChange={onOverlayVisibilityChange}
        onSelectFeedItem={onSelectFeedItem}
        onProgress={onProgress}
        onPlaybackEvent={onPlaybackEvent}
        onPlaybackMetrics={onPlaybackMetrics}
        onComplete={onComplete}
        onRefreshVideo={onRefreshVideo}
        onSubmitProject={onSubmitProject}
        onContinueAfterProject={
          index < feed.feedLength - 1 ? continueAfterProject : undefined
        }
        onReviewResolution={onReviewResolution}
      />
    );
  },
);
ReelsFeedRow.displayName = 'ReelsFeedRow';

const renderReelsFeedItem = ({
  item,
  index,
}: {
  item: CourseFeedItem;
  index: number;
}) => <ReelsFeedRow item={item} index={index} />;

export const useReelsFeedRenderer = ({
  controlsHidden = false,
  bottomInset,
  changePlaybackSpeed,
  changeQuality,
  completeAndAdvance,
  course,
  currentIndex,
  feedLength,
  frameWidth,
  handlePlaybackEvent,
  handlePlaybackMetrics,
  layout,
  load,
  navigation,
  persistProgress,
  playbackSpeed,
  playbackBlocked,
  preloadNext,
  nativePreloadMode = false,
  nativePreloadPending = false,
  nativePreloadOwner,
  positions,
  preview,
  previewCount,
  requestPlaybackManifest,
  screenFocused,
  savedLessons,
  savingLessons,
  scheduleDelayedAction,
  scrollToIndex,
  scrollToKey,
  selectedQuality,
  serverSession,
  setChatVisible,
  onContentOverlayVisibilityChange,
  submitProject,
  applyReviewResolution,
  refreshCourseEntitlements,
  removeFromList,
  toggleSaved,
  topInset,
}: {
  controlsHidden?: boolean;
  bottomInset: number;
  changePlaybackSpeed: (speed: number) => void;
  changeQuality: (quality: VideoQuality) => void;
  completeAndAdvance: (reel: CourseReel) => void | Promise<void>;
  course: CourseLearningData | null;
  currentIndex: number;
  feedLength: number;
  frameWidth: number;
  handlePlaybackEvent: (reel: CourseReel, event: PlaybackPlayerEvent) => void;
  handlePlaybackMetrics: (
    reel: CourseReel,
    metrics: PlaybackRuntimeMetrics,
  ) => void;
  layout: {width: number; height: number};
  load: (target?: CourseReloadTarget) => Promise<void>;
  refreshCourseEntitlements?: () => void | Promise<void>;
  navigation: ReelsNavigation;
  persistProgress: (
    reel: CourseReel,
    currentTime: number,
    duration: number,
  ) => void;
  playbackSpeed: number;
  playbackBlocked: boolean;
  preloadNext: boolean;
  nativePreloadMode?: boolean;
  nativePreloadPending?: boolean;
  nativePreloadOwner?: string;
  positions: MutableRefObject<Record<string, number>>;
  preview: boolean;
  previewCount?: number;
  requestPlaybackManifest: (
    reel: CourseReel,
    expectedSessionId?: string,
  ) => void | Promise<void>;
  screenFocused: boolean;
  savedLessons: Set<string>;
  savingLessons: Set<string>;
  scheduleDelayedAction: (action: () => void, delayMs: number) => void;
  scrollToIndex: (index: number) => void;
  scrollToKey: (key: string) => void;
  selectedQuality: VideoQuality;
  serverSession: boolean | null;
  setChatVisible: Dispatch<SetStateAction<boolean>>;
  onContentOverlayVisibilityChange: (
    scopeKey: string,
    visible: boolean,
  ) => void;
  submitProject: (
    projectId: string,
    files: SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  applyReviewResolution?: (
    projectId: string,
    resolution: ProjectResolution,
  ) => void;
  removeFromList: (
    reel: CourseReel,
    folder: SavedFolderOption,
    stillSavedElsewhere: boolean,
  ) => Promise<boolean>;
  toggleSaved: (
    reel: CourseReel,
    folder?: SavedFolderOption | null,
  ) => Promise<boolean>;
  topInset: number;
}) => {
  const latestActions: ReelsFeedActions = {
    applyReviewResolution,
    changePlaybackSpeed,
    changeQuality,
    completeAndAdvance,
    courseId: course?.id,
    handlePlaybackEvent,
    handlePlaybackMetrics,
    load,
    navigation,
    onContentOverlayVisibilityChange,
    persistProgress,
    preview,
    previewCount,
    refreshCourseEntitlements,
    removeFromList,
    requestPlaybackManifest,
    scheduleDelayedAction,
    scrollToIndex,
    scrollToKey,
    serverSession,
    setChatVisible,
    submitProject,
    toggleSaved,
  };
  const actions = useRef(latestActions);
  // Publish after commit, before any row effect or native event can call it.
  useLayoutEffect(() => {
    actions.current = latestActions;
  });

  const feedState = useMemo<ReelsFeedState>(
    () => ({
      actions,
      controlsHidden,
      bottomInset,
      course,
      currentIndex,
      feedLength,
      frameWidth,
      nativePreloadMode,
      nativePreloadOwner,
      nativePreloadPending,
      pageHeight: layout.height,
      pageWidth: layout.width,
      playbackBlocked,
      playbackSpeed,
      positions,
      preloadNext,
      savedLessons,
      savingLessons,
      screenFocused,
      selectedQuality,
      topInset,
    }),
    [
      controlsHidden,
      bottomInset,
      course,
      currentIndex,
      feedLength,
      frameWidth,
      nativePreloadMode,
      nativePreloadOwner,
      nativePreloadPending,
      layout.height,
      layout.width,
      playbackBlocked,
      playbackSpeed,
      positions,
      preloadNext,
      savedLessons,
      savingLessons,
      screenFocused,
      selectedQuality,
      topInset,
    ],
  );

  // FlatList re-renders every mounted cell when renderItem changes. Keep it
  // module-stable; rows subscribe to ReelsFeedContext for paging state.
  return {feedState, renderItem: renderReelsFeedItem};
};
