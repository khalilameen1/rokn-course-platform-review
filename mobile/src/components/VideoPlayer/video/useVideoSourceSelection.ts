import {useCallback, useMemo, useState} from 'react';
import {SelectedVideoTrackType} from 'react-native-video';
import type {CourseReel, VideoQuality} from '../types';
import {manifestRefreshDelayMs} from '../playbackTelemetry';
import {selectVideoSource} from './policy';

type VideoSourceSelectionOptions = {
  data: CourseReel;
  isVisible: boolean;
  preferredQuality: VideoQuality;
};

export const useVideoSourceSelection = ({
  data,
  isVisible,
  preferredQuality,
}: VideoSourceSelectionOptions) => {
  const [retryKey, setRetryKey] = useState(0);
  const [usingFallback, setUsingFallback] = useState(false);
  const [effectiveQuality, setEffectiveQuality] =
    useState<VideoQuality>(preferredQuality);

  const selection = useMemo(
    () =>
      selectVideoSource({
        effectiveQuality,
        fallbackVideoUrl: data.fallbackVideoUrl,
        qualitySources: data.qualitySources,
        usingFallback,
        videoUrl: data.videoUrl,
      }),
    [
      data.fallbackVideoUrl,
      data.qualitySources,
      data.videoUrl,
      effectiveQuality,
      usingFallback,
    ],
  );

  const selectedVideoTrack = useMemo(
    () =>
      !isVisible || effectiveQuality === 'auto'
        ? {type: SelectedVideoTrackType.AUTO}
        : {
            type: SelectedVideoTrackType.RESOLUTION,
            value: Number(effectiveQuality.replace('p', '')),
          },
    [effectiveQuality, isVisible],
  );

  const resetSource = useCallback((quality: VideoQuality) => {
    setUsingFallback(false);
    setEffectiveQuality(quality);
  }, []);

  const activateFallback = useCallback(() => setUsingFallback(true), []);
  const activateQuality = useCallback(
    (quality: VideoQuality) => setEffectiveQuality(quality),
    [],
  );
  const remountSource = useCallback(() => setRetryKey(value => value + 1), []);

  return {
    ...selection,
    activateFallback,
    activateQuality,
    effectiveQuality,
    remountSource,
    resetSource,
    retryKey,
    selectedVideoTrack,
    sourceRefreshRequired:
      Boolean(data.playbackSessionId) &&
      manifestRefreshDelayMs(data.playbackExpiresAt) === 0,
    usingFallback,
  };
};
