import {useEffect, useRef} from 'react';
import type {MutableRefObject} from 'react';
import {flushPendingPlaybackPositions} from '../../components/VideoPlayer/courseLearningApi';
import {useAppActiveState} from '../../hooks/useAppActiveState';

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
  const previouslyActiveRef = useRef(appIsActive);

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
    const wasActive = previouslyActiveRef.current;
    previouslyActiveRef.current = appIsActive;
    if (appIsActive) {
      if (!wasActive) onForeground();
      return;
    }
    if (wasActive) {
      // Autoplay advances, manifest retries and transition callbacks are only
      // meaningful while the learner can see this screen. Let foreground
      // reconciliation recreate current work instead of allowing an old timer
      // to move the feed or touch an expired source behind the lock screen.
      refs.delayedActions.current.forEach(clearTimeout);
      refs.delayedActions.current.clear();
      onBackground();
      void flushPendingPlaybackPositions();
    }
  }, [appIsActive, onBackground, onForeground, refs]);

  return appIsActive;
};
