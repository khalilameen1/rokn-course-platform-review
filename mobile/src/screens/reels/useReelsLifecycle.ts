import {useEffect, useRef} from 'react';
import type {MutableRefObject} from 'react';
import {flushPendingPlaybackPositions} from '../../components/VideoPlayer/courseLearningApi';
import {
  useAppActiveState,
  useAppForegroundState,
} from '../../hooks/useAppActiveState';

type ReelsLifecycleRefs = {
  delayedActions: MutableRefObject<Set<ReturnType<typeof setTimeout>>>;
  loadAbort: MutableRefObject<AbortController | null>;
  loadRequest: MutableRefObject<number>;
  mounted: MutableRefObject<boolean>;
  reviewWatcher: MutableRefObject<number>;
};

export const useReelsLifecycle = (
  refs: ReelsLifecycleRefs,
  onForeground: () => void,
  onBackground: () => void,
) => {
  const appIsActive = useAppActiveState();
  const appIsForeground = useAppForegroundState();
  const previouslyActiveRef = useRef(appIsForeground);

  useEffect(() => {
    const delayedActions = refs.delayedActions.current;
    refs.mounted.current = true;
    return () => {
      refs.mounted.current = false;
      refs.loadAbort.current?.abort();
      refs.loadAbort.current = null;
      refs.loadRequest.current += 1;
      refs.reviewWatcher.current += 1;
      delayedActions.forEach(clearTimeout);
      delayedActions.clear();
      void flushPendingPlaybackPositions();
    };
  }, [refs]);

  useEffect(() => {
    if (appIsActive) return;
    refs.delayedActions.current.forEach(clearTimeout);
    refs.delayedActions.current.clear();
  }, [appIsActive, refs]);

  useEffect(() => {
    const wasActive = previouslyActiveRef.current;
    previouslyActiveRef.current = appIsForeground;
    if (appIsForeground) {
      if (!wasActive) onForeground();
      return;
    }
    if (wasActive) {
      // A dialog only pauses playback. Refresh manifests and report app
      // transitions when the app actually leaves or returns to foreground.
      onBackground();
      void flushPendingPlaybackPositions();
    }
  }, [appIsForeground, onBackground, onForeground, refs]);

  return appIsActive;
};
