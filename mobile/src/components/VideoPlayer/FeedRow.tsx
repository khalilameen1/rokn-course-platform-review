import React, {useCallback, useEffect, useRef, useState} from 'react';
import {
  ActivityIndicator,
  Image,
  Pressable,
  StyleSheet,
  Text,
  View,
} from 'react-native';
import {CourseLearningData, CourseFeedItem, VideoQuality} from './types';
import VideoComponent from './VideoComponent';
import FeedFooter from './FeedFooter';
import FeedHeader from './FeedHeader';
import FeedSideBar from './FeedSideBar';
import ProjectTransition from './ProjectTransition';
import type {
  ProjectSubmissionOutcome,
  SavedFolderOption,
} from './courseLearningApi';
import type {
  PlaybackPlayerEvent,
  PlaybackRuntimeMetrics,
} from './playbackTelemetry';
import {Fonts} from '../../constants/styleConstants';

export const SOURCE_PENDING_RETRY_DELAY_MS = 9_000;

interface FeedRowProps {
  item: CourseFeedItem;
  course: CourseLearningData;
  pageWidth: number;
  pageHeight: number;
  frameWidth: number;
  isVisible: boolean;
  playbackBlocked: boolean;
  shouldMountVideo: boolean;
  playbackSpeed: number;
  selectedQuality: VideoQuality;
  saved: boolean;
  savePending: boolean;
  initialPosition: number;
  topInset: number;
  bottomInset: number;
  onPlaybackSpeedChange: (speed: number) => void;
  onQualityChange: (quality: VideoQuality) => void;
  onToggleSave: (folder?: SavedFolderOption | null) => void;
  onBeforeOpenSave: () => boolean;
  onOpenChat: () => void;
  onOverlayVisibilityChange?: (scopeKey: string, visible: boolean) => void;
  onSelectFeedItem: (key: string) => void;
  onProgress: (currentTime: number, duration: number) => void;
  onComplete: () => void;
  onRefreshVideo: () => void | Promise<void>;
  onPlaybackEvent: (event: PlaybackPlayerEvent) => void;
  onPlaybackMetrics: (metrics: PlaybackRuntimeMetrics) => void;
  onSubmitProject: (
    files: import('./types').SelectedProjectFile[],
    note?: string,
  ) => Promise<ProjectSubmissionOutcome>;
  onContinueAfterProject?: () => void;
}

const FeedRow = ({
  item,
  course,
  pageWidth,
  pageHeight,
  frameWidth,
  isVisible,
  playbackBlocked,
  shouldMountVideo,
  playbackSpeed,
  selectedQuality,
  saved,
  savePending,
  initialPosition,
  topInset,
  bottomInset,
  onPlaybackSpeedChange,
  onQualityChange,
  onToggleSave,
  onBeforeOpenSave,
  onOpenChat,
  onOverlayVisibilityChange,
  onSelectFeedItem,
  onProgress,
  onComplete,
  onRefreshVideo,
  onPlaybackEvent,
  onPlaybackMetrics,
  onSubmitProject,
  onContinueAfterProject,
}: FeedRowProps) => {
  const [currentTime, setCurrentTime] = useState(0);
  const attachmentClockRef = useRef(0);
  const attachmentClockOwnerRef = useRef(item.key);
  const [headerOverlayVisible, setHeaderOverlayVisible] = useState(false);
  const [sidebarOverlayVisible, setSidebarOverlayVisible] = useState(false);
  const [sourceWaitExpired, setSourceWaitExpired] = useState(false);
  const [sourceAttempt, setSourceAttempt] = useState(0);
  const sourceRefreshFlightRef = useRef<Promise<void> | null>(null);
  const sourceRefreshGenerationRef = useRef(0);
  const [sourceRetrying, setSourceRetrying] = useState(false);
  const localOverlayVisible = headerOverlayVisible || sidebarOverlayVisible;
  const attachmentCurrentTime =
    attachmentClockOwnerRef.current === item.key ? currentTime : 0;
  const awaitingVisibleSource =
    item.type === 'reel' &&
    isVisible &&
    !item.reel.isLocked &&
    (!item.reel.videoUrl.trim() || !shouldMountVideo);

  useEffect(() => {
    setSourceWaitExpired(false);
    if (!awaitingVisibleSource || sourceRetrying) return;
    const timer = setTimeout(
      () => setSourceWaitExpired(true),
      SOURCE_PENDING_RETRY_DELAY_MS,
    );
    return () => clearTimeout(timer);
  }, [awaitingVisibleSource, item.key, sourceAttempt, sourceRetrying]);

  useEffect(() => {
    // A manifest arriving through either the foreground request or an
    // adjacent preload owns the result. Clear the row-level retry state as
    // soon as this exact reel has a playable source.
    if (!item.type || item.type !== 'reel' || !item.reel.videoUrl.trim())
      return;
    sourceRefreshFlightRef.current = null;
    setSourceRetrying(false);
    setSourceWaitExpired(false);
  }, [item]);

  useEffect(() => {
    // Virtualized rows may be reused. A refresh flight and its visual state
    // belong to one feed item, not to the component instance that happens to
    // render it after a fast swipe or course revision.
    sourceRefreshGenerationRef.current += 1;
    sourceRefreshFlightRef.current = null;
    setSourceAttempt(0);
    setSourceRetrying(false);
    setSourceWaitExpired(false);
    attachmentClockOwnerRef.current = item.key;
    attachmentClockRef.current = 0;
    setCurrentTime(0);
    return () => {
      sourceRefreshGenerationRef.current += 1;
      sourceRefreshFlightRef.current = null;
    };
  }, [item.key]);

  useEffect(() => {
    onOverlayVisibilityChange?.(item.key, isVisible && localOverlayVisible);
    return () => onOverlayVisibilityChange?.(item.key, false);
  }, [isVisible, item.key, localOverlayVisible, onOverlayVisibilityChange]);
  const attachmentPromptAt = course.attachmentPrompt?.enabled
    ? Math.max(0, Number(course.attachmentPrompt.atSeconds || 0))
    : null;
  const handleProgress = useCallback(
    (time: number, duration: number) => {
      // VideoComponent owns the frame-by-frame playback clock. FeedRow only
      // needs a coarse clock until the one-time attachment prompt threshold;
      // mirroring every progress event here rerendered the full side rail and
      // bottom sheets throughout every reel on low-end phones.
      if (
        attachmentPromptAt !== null &&
        attachmentClockRef.current <= attachmentPromptAt
      ) {
        if (attachmentClockOwnerRef.current !== item.key) {
          attachmentClockOwnerRef.current = item.key;
          attachmentClockRef.current = 0;
        }
        const next = Math.min(
          attachmentPromptAt,
          Math.max(0, Math.floor(time)),
        );
        if (next !== attachmentClockRef.current) {
          attachmentClockRef.current = next;
          setCurrentTime(next);
        }
      }
      onProgress(time, duration);
    },
    [attachmentPromptAt, item.key, onProgress],
  );
  const retryMissingSource = useCallback(() => {
    if (sourceRefreshFlightRef.current) return;
    setSourceAttempt(value => value + 1);
    setSourceWaitExpired(false);
    setSourceRetrying(true);
    const generation = sourceRefreshGenerationRef.current;
    const flight = Promise.resolve(onRefreshVideo())
      .catch(() => undefined)
      .then(() => undefined)
      .finally(() => {
        if (
          sourceRefreshGenerationRef.current !== generation ||
          sourceRefreshFlightRef.current !== flight
        ) {
          return;
        }
        sourceRefreshFlightRef.current = null;
        setSourceRetrying(false);
      });
    sourceRefreshFlightRef.current = flight;
  }, [onRefreshVideo]);

  if (item.type === 'project') {
    const module = course.modules.find(entry => entry.id === item.moduleId);
    return (
      <View style={[styles.page, {width: pageWidth, height: pageHeight}]}>
        <ProjectTransition
          key={item.project.id}
          active={isVisible}
          project={item.project}
          moduleTitle={module?.title || ''}
          width={pageWidth}
          height={pageHeight}
          topInset={topInset}
          bottomInset={bottomInset}
          onSubmit={onSubmitProject}
          onContinue={onContinueAfterProject}
        />
      </View>
    );
  }

  const availableQualities = item.reel.availableQualities?.length
    ? item.reel.availableQualities
    : (['auto'] as VideoQuality[]);
  const effectiveQuality = availableQualities.includes(selectedQuality)
    ? selectedQuality
    : 'auto';

  return (
    <View style={[styles.page, {width: pageWidth, height: pageHeight}]}>
      <View
        style={[styles.videoFrame, {width: frameWidth, height: pageHeight}]}>
        {shouldMountVideo ? (
          <VideoComponent
            data={item.reel}
            width={frameWidth}
            height={pageHeight}
            isVisible={isVisible}
            playbackBlocked={playbackBlocked || localOverlayVisible}
            playbackSpeed={playbackSpeed}
            selectedQuality={effectiveQuality}
            initialPosition={initialPosition}
            bottomInset={bottomInset}
            onProgress={handleProgress}
            onComplete={onComplete}
            onRefreshSource={onRefreshVideo}
            onPlaybackEvent={onPlaybackEvent}
            onPlaybackMetrics={onPlaybackMetrics}
          />
        ) : (
          <View style={[StyleSheet.absoluteFill, styles.sourcePending]}>
            {!!item.reel.thumbnailUrl && (
              <Image
                accessibilityElementsHidden
                accessibilityIgnoresInvertColors
                importantForAccessibility="no"
                blurRadius={3}
                source={{uri: item.reel.thumbnailUrl}}
                style={StyleSheet.absoluteFill}
              />
            )}
            {awaitingVisibleSource &&
              (sourceWaitExpired ? (
                <View
                  accessibilityLiveRegion="assertive"
                  style={styles.sourceRetry}>
                  <Text style={styles.sourceRetryTitle}>
                    تعذّر تجهيز الفيديو
                  </Text>
                  <Pressable
                    accessibilityRole="button"
                    accessibilityLabel="إعادة محاولة تشغيل الفيديو"
                    onPress={retryMissingSource}
                    style={styles.sourceRetryButton}>
                    <Text style={styles.sourceRetryButtonText}>
                      إعادة المحاولة
                    </Text>
                  </Pressable>
                </View>
              ) : (
                <View
                  accessibilityLiveRegion="polite"
                  accessibilityLabel="جارٍ تجهيز الفيديو"
                  style={styles.sourceLoader}>
                  <ActivityIndicator color="#FFFFFF" size="small" />
                  <Text style={styles.sourceLoaderText}>
                    جارٍ تجهيز الفيديو
                  </Text>
                </View>
              ))}
          </View>
        )}
        {isVisible && (
          <>
            <FeedHeader
              playbackSpeed={playbackSpeed}
              onPlaybackSpeedChange={onPlaybackSpeedChange}
              selectedQuality={effectiveQuality}
              qualityOptions={availableQualities}
              onQualityChange={onQualityChange}
              onOpenChange={setHeaderOverlayVisible}
              topInset={topInset}
            />
            <FeedSideBar
              course={course}
              currentReel={item.reel}
              currentFeedKey={item.key}
              isSaved={saved}
              savePending={savePending}
              bottomInset={bottomInset}
              onToggleSave={onToggleSave}
              onBeforeOpenSave={onBeforeOpenSave}
              onOpenChat={onOpenChat}
              onOverlayVisibilityChange={setSidebarOverlayVisible}
              onSelectFeedItem={onSelectFeedItem}
              currentTime={attachmentCurrentTime}
            />
            <FeedFooter data={item.reel} bottomInset={bottomInset} />
          </>
        )}
      </View>
    </View>
  );
};

export default React.memo(FeedRow);

const styles = StyleSheet.create({
  page: {
    backgroundColor: '#000000',
    alignItems: 'center',
    justifyContent: 'center',
  },
  videoFrame: {
    position: 'relative',
    overflow: 'hidden',
    backgroundColor: '#030507',
  },
  sourcePending: {
    alignItems: 'center',
    justifyContent: 'center',
  },
  sourceLoader: {
    alignItems: 'center',
    gap: 10,
  },
  sourceLoaderText: {
    color: 'rgba(255,255,255,.75)',
    fontFamily: Fonts.medium,
    fontSize: 12,
  },
  sourceRetry: {
    alignItems: 'center',
    padding: 16,
  },
  sourceRetryTitle: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 14,
  },
  sourceRetryButton: {
    minHeight: 48,
    minWidth: 150,
    borderRadius: 20,
    alignItems: 'center',
    justifyContent: 'center',
    paddingHorizontal: 18,
    backgroundColor: '#236FE8',
    marginTop: 12,
  },
  sourceRetryButtonText: {
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
});
