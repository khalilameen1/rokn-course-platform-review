import {useCallback, useEffect, useRef, useState} from 'react';
import type {SavedFolderOption} from '../courseLearningApi';

/**
 * Instagram-style save: one tap stores the lesson in the default list and
 * offers a short confirmation. The save itself is the existing command with
 * no folder (toggleWatchLater, which resolves or creates "المشاهدة لاحقًا").
 */
export function useOneTapSave({
  isSaved,
  onBeforeSave,
  onToggleSave,
  scopeKey,
}: {
  isSaved: boolean;
  onBeforeSave: () => boolean;
  onToggleSave: (folder?: SavedFolderOption | null) => Promise<boolean>;
  scopeKey: string;
}) {
  // One slot: a new confirmation replaces the previous one instead of stacking.
  const [confirmationKey, setConfirmationKey] = useState<number | null>(null);
  // Set only from a confirmed one-tap save on this lesson.
  const [savedToWatchLater, setSavedToWatchLater] = useState(false);
  const sequenceRef = useRef(0);
  const flightRef = useRef(false);
  const mountedRef = useRef(true);
  const scopeRef = useRef(scopeKey);
  scopeRef.current = scopeKey;

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
    };
  }, []);

  useEffect(() => {
    setConfirmationKey(null);
    setSavedToWatchLater(false);
  }, [scopeKey]);

  useEffect(() => {
    if (isSaved) return;
    setConfirmationKey(null);
    setSavedToWatchLater(false);
  }, [isSaved]);

  const saveToWatchLater = useCallback(async () => {
    if (flightRef.current || !onBeforeSave()) return;
    flightRef.current = true;
    const scope = scopeRef.current;
    setConfirmationKey(null);
    try {
      const confirmed = await onToggleSave(undefined);
      if (confirmed && mountedRef.current && scopeRef.current === scope) {
        setSavedToWatchLater(true);
        sequenceRef.current += 1;
        setConfirmationKey(sequenceRef.current);
      }
    } catch {
      // The save owner already rolled back and showed its error; no success bar.
    } finally {
      flightRef.current = false;
    }
  }, [onBeforeSave, onToggleSave]);

  const dismissConfirmation = useCallback(() => setConfirmationKey(null), []);

  return {
    confirmationKey,
    dismissConfirmation,
    saveToWatchLater,
    savedToWatchLater,
  };
}
