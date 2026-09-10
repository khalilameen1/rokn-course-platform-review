import React from 'react';
import {
  Pressable,
  StyleSheet,
  Text,
  View,
  type GestureResponderHandlers,
} from 'react-native';
import {Gesture, GestureDetector} from 'react-native-gesture-handler';
import LinearGradient from 'react-native-linear-gradient';
import {Fonts} from '../../../constants/styleConstants';
import {Palette, textDirection} from '../../../constants/designSystem';
import {SkeletonBlock} from '../../ui/Skeleton';
import {
  formatVideoDuration,
  selectPlaybackErrorCopy,
  type PlaybackFailure,
  type VideoTimelinePresentation,
} from './policy';

type VideoChromeProps = {
  bottomInset: number;
  currentTime: number;
  failureKind: PlaybackFailure;
  isBuffering: boolean;
  isLoaded: boolean;
  onRetry: () => void;
  onSeekBy: (seconds: number) => void;
  onTogglePaused: () => void;
  onTrackWidth: (width: number) => void;
  panHandlers: GestureResponderHandlers;
  pausedByUser: boolean;
  previewTime: number | null;
  recoveryMessage: string;
  sourceFailed: boolean;
  timeline: VideoTimelinePresentation;
  trackWidth: number;
  unsupportedSource: boolean;
};

export const VideoChrome = ({
  bottomInset,
  currentTime,
  failureKind,
  isBuffering,
  isLoaded,
  onRetry,
  onSeekBy,
  onTogglePaused,
  onTrackWidth,
  panHandlers,
  pausedByUser,
  previewTime,
  recoveryMessage,
  sourceFailed,
  timeline,
  trackWidth,
  unsupportedSource,
}: VideoChromeProps) => {
  const errorCopy = selectPlaybackErrorCopy(failureKind, unsupportedSource);
  const surfaceTap = React.useMemo(
    () =>
      Gesture.Tap()
        .maxDistance(12)
        .runOnJS(true)
        .onEnd((_event, success) => {
          if (success) onTogglePaused();
        }),
    [onTogglePaused],
  );

  return (
    <>
      <GestureDetector gesture={surfaceTap}>
        <View
          accessible
          accessibilityRole="button"
          accessibilityLabel={pausedByUser ? 'تشغيل الفيديو' : 'إيقاف الفيديو'}
          accessibilityActions={[{name: 'activate'}]}
          onAccessibilityAction={event => {
            if (event.nativeEvent.actionName === 'activate') onTogglePaused();
          }}
          style={styles.tapLayer}>
          {pausedByUser && (
            <View pointerEvents="none" style={styles.playButton}>
              <Text style={styles.playSymbol} maxFontSizeMultiplier={1.1}>
                ▶
              </Text>
            </View>
          )}
        </View>
      </GestureDetector>

      <LinearGradient
        pointerEvents="none"
        colors={['rgba(0,0,0,.70)', 'rgba(0,0,0,0)', 'rgba(0,0,0,.88)']}
        locations={[0, 0.36, 1]}
        style={StyleSheet.absoluteFill}
      />

      {!sourceFailed && (isBuffering || !isLoaded) && (
        <View
          accessibilityLiveRegion="polite"
          accessibilityLabel={recoveryMessage || 'جارٍ تجهيز الفيديو'}
          pointerEvents="none"
          style={styles.centerState}>
          <SkeletonBlock height={54} radius={27} width={54} />
          <Text style={styles.stateText}>
            {recoveryMessage || 'جارٍ تجهيز الفيديو'}
          </Text>
        </View>
      )}

      {sourceFailed && (
        <View accessibilityLiveRegion="assertive" style={styles.errorCard}>
          <Text accessibilityRole="header" style={styles.errorTitle}>
            {errorCopy.title}
          </Text>
          <Text style={styles.errorText}>
            {errorCopy.message}
            {'\n'}
            {currentTime > 2
              ? `مكانك محفوظ عند ${formatVideoDuration(currentTime)}`
              : 'مكانك محفوظ'}
          </Text>
          <Pressable
            accessibilityRole="button"
            style={styles.retryButton}
            onPress={onRetry}>
            <Text style={styles.retryText}> حاول مرة أخرى</Text>
          </Pressable>
        </View>
      )}

      <View style={[styles.timelineArea, {bottom: bottomInset + 5}]}>
        <Text style={styles.remainingText} maxFontSizeMultiplier={1.15}>
          {timeline.duration
            ? `−${formatVideoDuration(timeline.remaining)}`
            : '—:—'}
        </Text>
        <View
          accessible
          accessibilityRole="adjustable"
          accessibilityLabel="موضع الفيديو"
          accessibilityHint={
            timeline.duration
              ? 'اسحب للتقديم أو التأخير، أو استخدم أوامر الزيادة والنقصان'
              : 'يتاح التقديم بعد تحديد مدة الفيديو'
          }
          accessibilityState={{disabled: !timeline.duration}}
          accessibilityValue={
            timeline.duration
              ? {
                  min: 0,
                  max: timeline.accessibilityDuration,
                  now: timeline.accessibilityPosition,
                  text: `${formatVideoDuration(
                    timeline.accessibilityPosition,
                  )} من ${formatVideoDuration(timeline.duration)}`,
                }
              : {text: 'جارٍ تحديد مدة الفيديو'}
          }
          accessibilityActions={
            timeline.duration
              ? [
                  {name: 'increment', label: 'تقديم عشر ثوانٍ'},
                  {name: 'decrement', label: 'تأخير عشر ثوانٍ'},
                ]
              : []
          }
          testID="video-timeline-touch-track"
          onAccessibilityAction={event => {
            if (event.nativeEvent.actionName === 'increment') {
              onSeekBy(10);
            } else if (event.nativeEvent.actionName === 'decrement') {
              onSeekBy(-10);
            }
          }}
          style={styles.touchTrack}
          onLayout={event => onTrackWidth(event.nativeEvent.layout.width)}
          {...panHandlers}>
          <View
            pointerEvents="none"
            style={styles.track}
            testID="video-timeline-track">
            <View
              testID="video-timeline-buffered"
              style={[
                styles.bufferedTrack,
                {width: `${timeline.bufferedProgress * 100}%`},
              ]}
            />
            <View
              testID="video-timeline-played"
              style={[
                styles.playedTrack,
                {width: `${timeline.progress * 100}%`},
              ]}
            />
          </View>
          {previewTime !== null && (
            <View
              pointerEvents="none"
              testID="video-timeline-scrubber"
              style={[
                styles.scrubber,
                {start: Math.max(0, timeline.progress * trackWidth - 6)},
              ]}
            />
          )}
        </View>
      </View>
    </>
  );
};

const styles = StyleSheet.create({
  tapLayer: {
    ...StyleSheet.absoluteFillObject,
    alignItems: 'center',
    justifyContent: 'center',
    zIndex: 2,
  },
  playButton: {
    width: 68,
    height: 68,
    borderRadius: 34,
    alignItems: 'center',
    justifyContent: 'center',
    backgroundColor: 'rgba(7,11,18,.86)',
  },
  playSymbol: {
    color: '#FFFFFF',
    fontSize: 27,
    marginLeft: 4,
  },
  centerState: {
    position: 'absolute',
    top: '42%',
    alignSelf: 'center',
    alignItems: 'center',
    gap: 12,
    zIndex: 4,
    maxWidth: '86%',
  },
  stateText: {
    ...textDirection,
    color: 'rgba(255,255,255,.82)',
    fontFamily: Fonts.medium,
    fontSize: 13,
    textAlign: 'center',
    paddingHorizontal: 12,
    paddingVertical: 8,
    borderRadius: 12,
    backgroundColor: 'rgba(7,11,18,.86)',
  },
  errorCard: {
    position: 'absolute',
    left: 24,
    right: 24,
    top: '36%',
    borderRadius: 20,
    padding: 20,
    backgroundColor: Palette.surface,
    alignItems: 'center',
    zIndex: 6,
  },
  errorTitle: {
    ...textDirection,
    color: '#FFFFFF',
    fontFamily: Fonts.bold,
    fontSize: 17,
    textAlign: 'center',
  },
  errorText: {
    ...textDirection,
    color: 'rgba(255,255,255,.68)',
    fontFamily: Fonts.regular,
    fontSize: 13,
    lineHeight: 21,
    textAlign: 'center',
    marginTop: 6,
  },
  retryButton: {
    minHeight: 48,
    paddingHorizontal: 22,
    paddingVertical: 12,
    borderRadius: 14,
    backgroundColor: Palette.primary,
    alignItems: 'center',
    justifyContent: 'center',
    marginTop: 16,
  },
  retryText: {
    ...textDirection,
    textAlign: 'center',
    color: '#FFFFFF',
    fontFamily: Fonts.semiBold,
    fontSize: 13,
  },
  timelineArea: {
    position: 'absolute',
    left: 12,
    right: 12,
    zIndex: 12,
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
  },
  remainingText: {
    minWidth: 44,
    color: 'rgba(255,255,255,.88)',
    fontFamily: Fonts.medium,
    fontSize: 11,
    fontVariant: ['tabular-nums'],
  },
  touchTrack: {
    flex: 1,
    minHeight: 48,
    justifyContent: 'center',
    // Playback time follows the physical screen: start on the left and end
    // on the right. Do not let the application's Arabic layout mirror it.
    direction: 'ltr',
  },
  track: {
    height: 3,
    borderRadius: 2,
    overflow: 'hidden',
    backgroundColor: 'rgba(255,255,255,.28)',
  },
  bufferedTrack: {
    position: 'absolute',
    start: 0,
    top: 0,
    height: '100%',
    borderRadius: 2,
    backgroundColor: 'rgba(255,255,255,.48)',
  },
  playedTrack: {
    position: 'absolute',
    start: 0,
    top: 0,
    height: '100%',
    borderRadius: 2,
    backgroundColor: '#FFFFFF',
  },
  scrubber: {
    position: 'absolute',
    top: 18,
    width: 12,
    height: 12,
    borderRadius: 6,
    backgroundColor: '#FFFFFF',
  },
});
