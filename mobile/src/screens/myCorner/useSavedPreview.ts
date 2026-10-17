import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useRef, useState} from 'react';

import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {getSavedLessonsPage, type SavedLesson} from '../../services/roknApi';

export const SAVED_PREVIEW_LIMIT = 3;

export type SavedPreviewStatus = 'loading' | 'ready' | 'error';

/**
 * The saved API lists the most recently saved lessons first and expands one
 * row per folder membership. The preview shows each lesson once.
 */
export const latestSavedLessons = (
  lessons: SavedLesson[],
  limit = SAVED_PREVIEW_LIMIT,
) => {
  const seen = new Set<string>();
  const latest: SavedLesson[] = [];
  for (const lesson of lessons) {
    if (seen.has(lesson.id)) continue;
    seen.add(lesson.id);
    latest.push(lesson);
    if (latest.length === limit) break;
  }
  return latest;
};

/**
 * First page of the saved library for ركني. It reads through the same
 * account-scoped request and offline cache as the full library, so both
 * screens agree and no second cache exists.
 */
export const useSavedPreview = (identityKey: string, enabled: boolean) => {
  const appIsActive = useAppForegroundState();
  const ownerRef = useRef(identityKey);
  const [lessons, setLessons] = useState<SavedLesson[]>([]);
  const [status, setStatus] = useState<SavedPreviewStatus>('loading');
  const reloadRef = useRef<(() => void) | null>(null);
  const reload = useCallback(() => reloadRef.current?.(), []);

  useEffect(() => {
    ownerRef.current = identityKey;
    setLessons([]);
    setStatus('loading');
  }, [identityKey]);

  useFocusEffect(
    useCallback(() => {
      if (!enabled || !appIsActive) return undefined;
      let active = true;
      let readGeneration = 0;
      // Focus, foreground and pull to refresh share one screen-owned read.
      const refresh = () => {
        const generation = ++readGeneration;
        const owns = () =>
          active &&
          generation === readGeneration &&
          ownerRef.current === identityKey;
        void (async () => {
          try {
            const boundary = await captureAccountSessionBoundary();
            const page = await getSavedLessonsPage(1);
            assertAccountSessionBoundary(boundary);
            if (!owns()) return;
            setLessons(latestSavedLessons(page.lessons));
            setStatus('ready');
          } catch {
            if (!owns()) return;
            // Keep rows already shown; a failed refresh does not empty them.
            setStatus(current => (current === 'ready' ? current : 'error'));
          }
        })();
      };
      reloadRef.current = refresh;
      refresh();
      return () => {
        active = false;
        if (reloadRef.current === refresh) reloadRef.current = null;
      };
    }, [appIsActive, enabled, identityKey]),
  );

  return {lessons, reload, status};
};
