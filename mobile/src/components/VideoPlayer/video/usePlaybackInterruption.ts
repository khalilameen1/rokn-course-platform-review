import {useCallback, useRef, useState} from 'react';
import type {PlaybackPlayerEvent} from '../playbackTelemetry';

type PlaybackInterruptionOptions = {
  emitPlaybackEvent: (
    eventType: PlaybackPlayerEvent['eventType'],
    options?: Pick<
      PlaybackPlayerEvent,
      'endReason' | 'errorCode' | 'diagnostics'
    >,
  ) => void;
  isPlayingRef: {current: boolean};
  isVisible: boolean;
  playbackEligible: boolean;
};

export const usePlaybackInterruption = ({
  emitPlaybackEvent,
  isPlayingRef,
  isVisible,
  playbackEligible,
}: PlaybackInterruptionOptions) => {
  const [pausedByUser, setPausedByUser] = useState(false);
  const [pausedForInterruption, setPausedForInterruption] = useState(false);
  const resumeAfterFocusLossRef = useRef(false);
  const playbackPaused = pausedByUser || pausedForInterruption;

  const resetInterruption = useCallback(() => {
    resumeAfterFocusLossRef.current = false;
    setPausedByUser(false);
    setPausedForInterruption(false);
  }, []);

  const clearTransientInterruption = useCallback(() => {
    resumeAfterFocusLossRef.current = false;
    setPausedForInterruption(false);
  }, []);

  const handleAudioBecomingNoisy = useCallback(() => {
    if (!isVisible) return;
    resumeAfterFocusLossRef.current = false;
    setPausedForInterruption(false);
    setPausedByUser(true);
    if (!isPlayingRef.current) return;
    isPlayingRef.current = false;
    emitPlaybackEvent('pause');
  }, [emitPlaybackEvent, isPlayingRef, isVisible]);

  const handleAudioFocusChanged = useCallback(
    ({hasAudioFocus}: {hasAudioFocus: boolean}) => {
      if (!isVisible) return;
      if (!hasAudioFocus) {
        if (!playbackEligible || pausedByUser) return;
        resumeAfterFocusLossRef.current = true;
        setPausedForInterruption(true);
        if (isPlayingRef.current) {
          isPlayingRef.current = false;
          emitPlaybackEvent('pause');
        }
        return;
      }
      if (!resumeAfterFocusLossRef.current) return;
      resumeAfterFocusLossRef.current = false;
      setPausedForInterruption(false);
    },
    [
      emitPlaybackEvent,
      isPlayingRef,
      isVisible,
      pausedByUser,
      playbackEligible,
    ],
  );

  const togglePaused = useCallback(() => {
    resumeAfterFocusLossRef.current = false;
    if (!playbackPaused) {
      isPlayingRef.current = false;
      emitPlaybackEvent('pause');
    }
    setPausedForInterruption(false);
    setPausedByUser(!playbackPaused);
  }, [emitPlaybackEvent, isPlayingRef, playbackPaused]);

  return {
    clearTransientInterruption,
    handleAudioBecomingNoisy,
    handleAudioFocusChanged,
    pausedByUser,
    playbackPaused,
    resetInterruption,
    togglePaused,
  };
};
