import {useCallback, useEffect, useRef, useState} from 'react';
import {useSelector} from 'react-redux';
import {
  createSavedFolderOption,
  getSavedFolderOptions,
  type SavedFolderOption,
} from '../courseLearningApi';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  sessionIdentityKey,
} from '../../../constants/helpers';
import type {RootState} from '../../../store/store';

const watchLaterName = (value: string) =>
  value.trim().toLocaleLowerCase('ar') === 'المشاهدة لاحقًا';

export function useSavedFolderPicker({
  dismiss,
  onBeforeOpen,
  onToggleSave,
  present,
  scopeKey = '',
}: {
  dismiss: () => void;
  onBeforeOpen: () => boolean;
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
    if (mountedRef.current) {
      setLoading(false);
      setCreating(false);
    }
  }, []);

  useEffect(() => {
    close();
    setFolders([]);
    setLoading(false);
    setName('');
    setCreating(false);
    setError('');
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

  const open = useCallback(() => {
    if (!mountedRef.current || ownerRef.current !== ownerKey) return;
    if (!onBeforeOpen()) return;
    present();
    if (loadingRef.current) return;
    visitOpenRef.current = true;
    loadingRef.current = true;
    const generation = ++generationRef.current;
    setLoading(true);
    setError('');
    void (async () => {
      try {
        const boundary = await captureAccountSessionBoundary();
        if (!stillOwned(generation)) return;
        assertAccountSessionBoundary(boundary);
        const nextFolders = await getSavedFolderOptions();
        assertAccountSessionBoundary(boundary);
        if (stillOwned(generation)) setFolders(nextFolders);
      } catch {
        if (stillOwned(generation)) {
          setError('تعذّر تحميل قوائمك الآن\nحاول مرة أخرى');
        }
      } finally {
        if (generation === generationRef.current) loadingRef.current = false;
        if (stillOwned(generation)) setLoading(false);
      }
    })();
  }, [onBeforeOpen, ownerKey, present, stillOwned]);

  const visitGeneration = generationRef.current;
  const onDismiss = useCallback(() => {
    if (stillOwned(visitGeneration)) close();
  }, [close, stillOwned, visitGeneration]);
  const saveInFolder = useCallback(
    (folder?: SavedFolderOption | null) => {
      if (!stillOwned(visitGeneration)) return;
      close();
      onToggleSave(folder);
      dismiss();
    },
    [close, dismiss, onToggleSave, stillOwned, visitGeneration],
  );

  const watchLaterFolder = folders.find(folder => watchLaterName(folder.name));
  const visibleFolders = folders.filter(folder => !watchLaterName(folder.name));
  const saveInWatchLater = useCallback(() => {
    saveInFolder(watchLaterFolder);
  }, [saveInFolder, watchLaterFolder]);

  const createAndSave = useCallback(async () => {
    const normalizedName = name.trim();
    if (
      !normalizedName ||
      creating ||
      creatingRef.current ||
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
        setError('تعذّر إنشاء القائمة\nتحقق من الاتصال ثم حاول مرة أخرى');
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
    name,
    open,
    saveInFolder,
    saveInWatchLater,
    setName,
  };
}
