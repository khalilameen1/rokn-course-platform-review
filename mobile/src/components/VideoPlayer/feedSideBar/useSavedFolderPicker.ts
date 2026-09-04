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

export function useSavedFolderPicker({
  dismiss,
  onBeforeOpen,
  onToggleSave,
  present,
}: {
  dismiss: () => void;
  onBeforeOpen: () => boolean;
  onToggleSave: (folder?: SavedFolderOption | null) => void;
  present: () => void;
}) {
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const [folders, setFolders] = useState<SavedFolderOption[]>([]);
  const [loading, setLoading] = useState(false);
  const [name, setName] = useState('');
  const [creating, setCreating] = useState(false);
  const [error, setError] = useState('');
  const generationRef = useRef(0);
  const loadingRef = useRef(false);
  const creatingRef = useRef(false);
  const mountedRef = useRef(true);
  const ownerRef = useRef(identityKey);

  useEffect(() => {
    ownerRef.current = identityKey;
    generationRef.current += 1;
    loadingRef.current = false;
    creatingRef.current = false;
    setFolders([]);
    setLoading(false);
    setName('');
    setCreating(false);
    setError('');
  }, [identityKey]);

  useEffect(
    () => () => {
      mountedRef.current = false;
      generationRef.current += 1;
      loadingRef.current = false;
      creatingRef.current = false;
    },
    [],
  );

  const stillOwned = useCallback(
    (generation: number) =>
      mountedRef.current &&
      generation === generationRef.current &&
      ownerRef.current === identityKey,
    [identityKey],
  );

  const open = useCallback(() => {
    if (!onBeforeOpen()) return;
    present();
    if (loadingRef.current) return;
    loadingRef.current = true;
    const generation = ++generationRef.current;
    setLoading(true);
    setError('');
    void (async () => {
      try {
        const boundary = await captureAccountSessionBoundary();
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
  }, [onBeforeOpen, present, stillOwned]);

  const saveInFolder = useCallback(
    (folder: SavedFolderOption) => {
      onToggleSave(folder);
      dismiss();
    },
    [dismiss, onToggleSave],
  );

  const createAndSave = useCallback(async () => {
    const normalizedName = name.trim();
    if (!normalizedName || creating || creatingRef.current) return;
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
      const created = await createSavedFolderOption(normalizedName);
      assertAccountSessionBoundary(boundary);
      if (!stillOwned(generation)) return;
      setFolders(current => [
        ...current.filter(folder => folder.id !== created.id),
        created,
      ]);
      setName('');
      saveInFolder(created);
    } catch {
      if (stillOwned(generation)) {
        setError('تعذّر إنشاء القائمة\nتحقق من الاتصال ثم حاول مرة أخرى');
      }
    } finally {
      if (generation === generationRef.current) creatingRef.current = false;
      if (stillOwned(generation)) setCreating(false);
    }
  }, [creating, folders, name, saveInFolder, stillOwned]);

  return {
    createAndSave,
    creating,
    error,
    folders,
    loading,
    name,
    open,
    saveInFolder,
    setName,
  };
}
