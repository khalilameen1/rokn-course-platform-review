import {useCallback, useEffect, useRef, useState} from 'react';
import {useSelector} from 'react-redux';
import {
  createSavedFolderOption,
  getSavedFolderOptions,
  getSavedLessonFolderMembership,
  type SavedFolderOption,
} from '../courseLearningApi';
import {isWatchLaterFolderName} from '../courseLearning/savedFolderNames';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  sessionIdentityKey,
} from '../../../constants/helpers';
import type {RootState} from '../../../store/store';
import {learnerCopy} from '../../../constants/learnerCopy';

const REMOVE_FAILED = `معرفناش نحدّث المحفوظات\n${learnerCopy.networkRetry}`;

/**
 * Facebook-style "Save to collection" sheet: each list row shows whether it
 * holds the lesson and toggles that one membership. Adding keeps the existing
 * save command (and closes the sheet); removing leaves the other lists alone.
 */
export function useSavedFolderPicker({
  dismiss,
  lessonId,
  onBeforeOpen,
  onRemoveFromList,
  onToggleSave,
  present,
  scopeKey = '',
}: {
  dismiss: () => void;
  /** With onRemoveFromList, the sheet reads which lists hold this lesson. */
  lessonId?: string;
  onBeforeOpen: () => boolean;
  onRemoveFromList?: (
    folder: SavedFolderOption,
    stillSavedElsewhere: boolean,
  ) => Promise<boolean>;
  onToggleSave: (folder?: SavedFolderOption | null) => void;
  present: () => void;
  scopeKey?: string;
}) {
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const ownerKey = `${identityKey}:${scopeKey}`;
  const [folders, setFolders] = useState<SavedFolderOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [name, setName] = useState('');
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState('');
  const [loadError, setLoadError] = useState('');
  // Folder ids holding the lesson; null while unknown (rows stay add-only).
  const [membership, setMembership] = useState<ReadonlySet<string> | null>(
    null,
  );
  const [removingFolderId, setRemovingFolderId] = useState<string | null>(
    null,
  );
  const removingRef = useRef(false);
  // Bumped by every membership command so an older read cannot undo it.
  const membershipRevisionRef = useRef(0);
  const membershipEnabled = Boolean(lessonId && onRemoveFromList);
  const generationRef = useRef(0);
  const loadingRef = useRef(false);
  const creatingRef = useRef(false);
  const mountedRef = useRef(true);
  const ownerRef = useRef(ownerKey);
  ownerRef.current = ownerKey;
  const visitOpenRef = useRef(false);

  const close = useCallback(() => {
    visitOpenRef.current = false;
    generationRef.current += 1;
    loadingRef.current = false;
    creatingRef.current = false;
    removingRef.current = false;
    if (mountedRef.current) {
      setLoading(false);
      setCreating(false);
      setRemovingFolderId(null);
    }
  }, []);

  useEffect(() => {
    close();
    setFolders([]);
    setLoading(false);
    setName('');
    setCreating(false);
    setError('');
    setLoadError('');
    setMembership(null);
  }, [close, ownerKey]);

  useEffect(() => {
    mountedRef.current = true;
    return () => {
      mountedRef.current = false;
      close();
    };
  }, [close]);

  const stillOwned = useCallback(
    (generation: number) =>
      mountedRef.current &&
      visitOpenRef.current &&
      generation === generationRef.current &&
      ownerRef.current === ownerKey,
    [ownerKey],
  );

  const loadFolders = useCallback(
    (generation: number, requireFresh = false) => {
      if (!stillOwned(generation) || loadingRef.current) return;
      loadingRef.current = true;
      setLoading(true);
      void (async () => {
        try {
          const boundary = await captureAccountSessionBoundary();
          if (!stillOwned(generation)) return;
          assertAccountSessionBoundary(boundary);
          const membershipRevision = membershipRevisionRef.current;
          const [nextFolders, nextMembership] = await Promise.all([
            requireFresh
              ? getSavedFolderOptions({requireFresh: true})
              : getSavedFolderOptions(),
            // Membership is an enhancement: when it cannot be read the rows
            // keep their add-only behaviour instead of blocking the sheet.
            membershipEnabled && lessonId
              ? getSavedLessonFolderMembership(lessonId).catch(() => null)
              : null,
          ]);
          assertAccountSessionBoundary(boundary);
          if (stillOwned(generation)) {
            setFolders(nextFolders);
            setLoadError('');
            if (membershipRevision === membershipRevisionRef.current) {
              setMembership(
                nextMembership ? new Set(nextMembership.folderIds) : null,
              );
            }
          }
        } catch {
          if (stillOwned(generation)) {
            setLoadError('معرفناش نحمّل قوائمك');
          }
        } finally {
          if (generation === generationRef.current) loadingRef.current = false;
          if (stillOwned(generation)) setLoading(false);
        }
      })();
    },
    [lessonId, membershipEnabled, stillOwned],
  );

  const open = useCallback(() => {
    if (!mountedRef.current || ownerRef.current !== ownerKey) return;
    if (!onBeforeOpen()) return;
    present();
    if (loadingRef.current) return;
    visitOpenRef.current = true;
    const generation = ++generationRef.current;
    setError('');
    setLoadError('');
    setMembership(null);
    loadFolders(generation);
  }, [loadFolders, onBeforeOpen, ownerKey, present]);

  const visitGeneration = generationRef.current;
  const onDismiss = useCallback(() => {
    if (stillOwned(visitGeneration)) close();
  }, [close, stillOwned, visitGeneration]);
  const retryFolders = useCallback(() => {
    // Retry the read in this visit; do not re-present the modal, retire a
    // pending creation, or use a stale callback from a closed lesson.
    if (!loadError || creatingRef.current || !stillOwned(visitGeneration))
      return;
    loadFolders(visitGeneration, true);
  }, [loadError, loadFolders, stillOwned, visitGeneration]);
  const saveInFolder = useCallback(
    (folder?: SavedFolderOption | null) => {
      if (!stillOwned(visitGeneration)) return;
      close();
      onToggleSave(folder);
      dismiss();
    },
    [close, dismiss, onToggleSave, stillOwned, visitGeneration],
  );

  const watchLaterFolder = folders.find(folder =>
    isWatchLaterFolderName(folder.name),
  );
  const visibleFolders = folders.filter(
    folder => !isWatchLaterFolderName(folder.name),
  );
  const saveInWatchLater = useCallback(() => {
    saveInFolder(watchLaterFolder);
  }, [saveInFolder, watchLaterFolder]);

  const removeFromFolder = useCallback(
    async (folder: SavedFolderOption) => {
      const generation = visitGeneration;
      if (
        !onRemoveFromList ||
        !membership?.has(folder.id) ||
        removingRef.current ||
        creatingRef.current ||
        !stillOwned(generation)
      )
        return;
      removingRef.current = true;
      membershipRevisionRef.current += 1;
      const remaining = new Set(membership);
      remaining.delete(folder.id);
      // Optimistic: the row flips now and is restored if the server refuses.
      setMembership(remaining);
      setRemovingFolderId(folder.id);
      setError('');
      let confirmed = false;
      try {
        confirmed = await onRemoveFromList(folder, remaining.size > 0);
      } catch {
        confirmed = false;
      } finally {
        if (generation === generationRef.current) removingRef.current = false;
        if (stillOwned(generation)) {
          setRemovingFolderId(null);
          if (!confirmed) {
            membershipRevisionRef.current += 1;
            setMembership(current => new Set(current ?? []).add(folder.id));
            setError(REMOVE_FAILED);
          }
        }
      }
    },
    [membership, onRemoveFromList, stillOwned, visitGeneration],
  );

  /** A row that holds the lesson leaves that list; any other row adds to it. */
  const toggleFolder = useCallback(
    (folder: SavedFolderOption) => {
      if (membership?.has(folder.id)) void removeFromFolder(folder);
      else saveInFolder(folder);
    },
    [membership, removeFromFolder, saveInFolder],
  );
  const toggleWatchLater = useCallback(() => {
    if (watchLaterFolder && membership?.has(watchLaterFolder.id)) {
      void removeFromFolder(watchLaterFolder);
    } else saveInWatchLater();
  }, [membership, removeFromFolder, saveInWatchLater, watchLaterFolder]);

  const createAndSave = useCallback(async () => {
    const normalizedName = name.trim();
    if (
      !normalizedName ||
      creating ||
      creatingRef.current ||
      removingRef.current ||
      !stillOwned(visitGeneration)
    )
      return;
    const existing = folders.find(
      folder =>
        folder.name.trim().toLocaleLowerCase('ar') ===
        normalizedName.toLocaleLowerCase('ar'),
    );
    if (existing) {
      setName('');
      saveInFolder(existing);
      return;
    }

    const generation = generationRef.current;
    creatingRef.current = true;
    setCreating(true);
    setError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      if (!stillOwned(generation)) return;
      assertAccountSessionBoundary(boundary);
      const created = await createSavedFolderOption(normalizedName);
      assertAccountSessionBoundary(boundary);
      if (!stillOwned(generation)) return;
      setFolders(current => [
        ...current.filter(folder => folder.id !== created.id),
        created,
      ]);
      setName(current => (current.trim() === normalizedName ? '' : current));
      saveInFolder(created);
    } catch {
      if (stillOwned(generation)) {
        setError(`معرفناش نعمل القائمة\n${learnerCopy.networkRetry}`);
      }
    } finally {
      if (generation === generationRef.current) creatingRef.current = false;
      if (stillOwned(generation)) setCreating(false);
    }
  }, [creating, folders, name, saveInFolder, stillOwned, visitGeneration]);

  return {
    createAndSave,
    close: onDismiss,
    creating,
    error,
    folders: visibleFolders,
    loading,
    loadError,
    /** Folder ids holding the lesson; null when unknown. */
    membership,
    name,
    open,
    removingFolderId,
    retryFolders,
    saveInFolder,
    saveInWatchLater,
    setName,
    toggleFolder,
    toggleWatchLater,
    watchLaterFolder,
  };
}
