import {useCallback, useMemo, useRef, useState} from 'react';
import {PanResponder} from 'react-native';
import type {VideoRef} from 'react-native-video';
import {selectVideoTimeline} from './policy';

type TimelineOptions = {
  initialDuration: number;
  initialPosition: number;
  videoRef: React.RefObject<VideoRef | null>;
};

export const useVideoTimelineController = ({
  initialDuration,
  initialPosition,
  videoRef,
}: TimelineOptions) => {
  const lastPositionRef = useRef(initialPosition);
  const durationRef = useRef(initialDuration);
  const pendingSeekRef = useRef<number | null>(null);
  const pendingSeekStartedAtRef = useRef<number | null>(null);
  const [duration, setDuration] = useState(initialDuration);
  const [bufferedTime, setBufferedTime] = useState(0);
  const [currentTime, setCurrentTime] = useState(initialPosition);
  const [previewTime, setPreviewTime] = useState<number | null>(null);
  const [trackWidth, setTrackWidth] = useState(0);
  const dragStartXRef = useRef(0);

  const seekTo = useCallback(
    (seconds: number) => {
      const target = Math.max(0, seconds);
      pendingSeekRef.current = target;
      pendingSeekStartedAtRef.current = Date.now();
      lastPositionRef.current = target;
      videoRef.current?.seek(target);
    },
    [videoRef],
  );

  const resetTimeline = useCallback(
    (position: number, declaredDuration: number) => {
      lastPositionRef.current = position;
      durationRef.current = declaredDuration;
      pendingSeekRef.current = null;
      pendingSeekStartedAtRef.current = null;
      setCurrentTime(position);
      setDuration(declaredDuration);
      setBufferedTime(0);
      setPreviewTime(null);
    },
    [],
  );

  const seekFromX = useCallback(
    (x: number, commit: boolean) => {
      if (!trackWidth || !duration) return;
      const seconds = Math.max(0, Math.min(1, x / trackWidth)) * duration;
      setPreviewTime(seconds);
      if (!commit) return;

      pendingSeekRef.current = seconds;
      pendingSeekStartedAtRef.current = Date.now();
      lastPositionRef.current = seconds;
      videoRef.current?.seek(seconds);
      setCurrentTime(seconds);
      setPreviewTime(null);
    },
    [duration, trackWidth, videoRef],
  );

  const panResponder = useMemo(
    () =>
      PanResponder.create({
        onStartShouldSetPanResponder: () => trackWidth > 0 && duration > 0,
        onMoveShouldSetPanResponder: () => trackWidth > 0 && duration > 0,
        // This touch began on the scrub target, not the surrounding pager.
        onPanResponderTerminationRequest: () => false,
        onPanResponderGrant: event => {
          dragStartXRef.current = event.nativeEvent.locationX;
          seekFromX(dragStartXRef.current, false);
        },
        onPanResponderMove: (_event, gesture) =>
          seekFromX(dragStartXRef.current + gesture.dx, false),
        onPanResponderRelease: (_event, gesture) =>
          seekFromX(dragStartXRef.current + gesture.dx, true),
        onPanResponderTerminate: () => setPreviewTime(null),
      }),
    [duration, seekFromX, trackWidth],
  );

  const timeline = selectVideoTimeline({
    bufferedTime,
    currentTime,
    duration,
    previewTime,
  });

  const seekBy = useCallback(
    (offsetSeconds: number) => {
      if (!duration) return;
      const seconds = Math.max(
        0,
        Math.min(duration, timeline.displayedTime + offsetSeconds),
      );
      pendingSeekRef.current = seconds;
      pendingSeekStartedAtRef.current = Date.now();
      lastPositionRef.current = seconds;
      videoRef.current?.seek(seconds);
      setCurrentTime(seconds);
      setPreviewTime(null);
    },
    [duration, timeline.displayedTime, videoRef],
  );

  return {
    currentTime,
    duration,
    durationRef,
    lastPositionRef,
    panHandlers: panResponder.panHandlers,
    pendingSeekRef,
    pendingSeekStartedAtRef,
    previewTime,
    resetTimeline,
    seekBy,
    seekTo,
    setBufferedTime,
    setCurrentTime,
    setDuration,
    setPreviewTime,
    setTrackWidth,
    timeline,
    trackWidth,
  };
};
