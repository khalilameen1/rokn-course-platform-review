import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  useSyncExternalStore,
} from 'react';
import {Alert} from 'react-native';
import {useSelector} from 'react-redux';
import {
  createSavedFolderOption,
  deleteSavedFolderOption,
  getSavedFolderOptions,
  removeLessonFromSavedFolder,
  type SavedFolderOption,
} from '../../../components/VideoPlayer/courseLearningApi';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  sessionIdentityKey,
} from '../../../constants/helpers';
import type {SavedLesson} from '../../../services/roknApi';
import type {RootState} from '../../../store/store';
import {SavedLibrarySnapshot} from './SavedLibrarySnapshot';
import {useSavedLibraryRead} from './useSavedLibraryRead';

type SavedGroup = {
  name: string;
  items: SavedLesson[];
};

type SavedMutationFlight = {
  identityKey: string;
};

export function useSavedLibrary() {
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);

  const snapshot = useMemo(() => new SavedLibrarySnapshot(), []);
  const {saved, folders} = useSyncExternalStore(
    snapshot.subscribe,
    snapshot.getSnapshot,
  );
  const [actionError, setActionError] = useState('');
  const [removingSaved, setRemovingSaved] = useState<Set<string>>(new Set());
  const [showCreateFolder, setShowCreateFolder] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [creatingFolder, setCreatingFolder] = useState(false);
  const [deletingFolder, setDeletingFolder] = useState(false);
  const [folderError, setFolderError] = useState('');

  const createFolderFlightRef = useRef<SavedMutationFlight | null>(null);
  const deleteFolderFlightRef = useRef<SavedMutationFlight | null>(null);
  const removeFlightsRef = useRef(new Map<string, SavedMutationFlight>());
  useEffect(() => {
    createFolderFlightRef.current = null;
    deleteFolderFlightRef.current = null;
    removeFlightsRef.current.clear();
    setActionError('');
    setFolderError('');
    setNewFolderName('');
    setShowCreateFolder(false);
    setCreatingFolder(false);
    setDeletingFolder(false);
    setRemovingSaved(new Set());
  }, [identityKey]);

  const restoreMutationActivity = useCallback(() => {
    setCreatingFolder(
      createFolderFlightRef.current?.identityKey === identityKey,
    );
    setDeletingFolder(
      deleteFolderFlightRef.current?.identityKey === identityKey,
    );
    setRemovingSaved(
      new Set(
        [...removeFlightsRef.current]
          .filter(([, flight]) => flight.identityKey === identityKey)
          .map(([key]) => key),
      ),
    );
  }, [identityKey]);

  const {
    activeFolderId,
    captureOperation,
    error,
    folderLoadError,
    identityOwned,
    loadMore,
    loading,
    loadingMore,
    loadMoreError,
    nextPage,
    retry,
    selectFolder,
    serverSession,
    restoreFolderSelection,
    reportReadError,
  } = useSavedLibraryRead(identityKey, snapshot, restoreMutationActivity);

  const createFolder = useCallback(async () => {
    const name = newFolderName.trim();
    if (!name || creatingFolder || createFolderFlightRef.current) return;
    const existing = folders.find(
      folder =>
        folder.name.trim().toLocaleLowerCase('ar') ===
        name.toLocaleLowerCase('ar'),
    );
    if (existing) {
      selectFolder(existing.id);
      setNewFolderName('');
      setShowCreateFolder(false);
      return;
    }

    const operation = captureOperation();
    const flight = {identityKey};
    createFolderFlightRef.current = flight;
    setCreatingFolder(true);
    setFolderError('');
    try {
      const created = await createSavedFolderOption(name);
      if (!operation.isCurrent()) return;
      snapshot.acceptCreatedFolder(created);
      selectFolder(created.id);
      setNewFolderName('');
      setShowCreateFolder(false);
    } catch {
      if (operation.isCurrent()) {
        setFolderError('تعذّر إنشاء القائمة\nتحقق من الاتصال ثم حاول مرة أخرى');
      }
    } finally {
      if (createFolderFlightRef.current === flight) {
        createFolderFlightRef.current = null;
        if (operation.isAccountActive()) {
          setCreatingFolder(false);
          if (!operation.isCurrent()) retry();
        }
      }
    }
  }, [
    captureOperation,
    retry,
    creatingFolder,
    folders,
    identityKey,
    newFolderName,
    selectFolder,
    snapshot,
  ]);

  const removeSaved = useCallback(
    async (item: SavedLesson) => {
      const key = `${item.folderId}:${item.id}`;
      if (removingSaved.has(key) || removeFlightsRef.current.has(key)) return;
      const operation = captureOperation();
      const flight = {identityKey};
      removeFlightsRef.current.set(key, flight);
      setActionError('');
      setRemovingSaved(current => new Set(current).add(key));
      let removal: ReturnType<SavedLibrarySnapshot['beginLessonRemoval']> =
        null;
      let boundary: Awaited<
        ReturnType<typeof captureAccountSessionBoundary>
      > | null = null;
      const stillOwned = () => {
        if (!boundary || !operation.isCurrent()) {
          return false;
        }
        try {
          assertAccountSessionBoundary(boundary);
          return true;
        } catch {
          return false;
        }
      };
      try {
        boundary = await captureAccountSessionBoundary();
        if (!stillOwned()) return;
        removal = snapshot.beginLessonRemoval(item);
        if (!removal) return;

        await removeLessonFromSavedFolder(item.id, item.folderId);
        assertAccountSessionBoundary(boundary);
        // A refresh may have restored its pre-delete snapshot while the write
        // was pending. The confirmed result must also win locally, exactly once.
        if (!stillOwned()) return;
        const countRead = snapshot.confirmLessonRemoval(removal);
        if (countRead) {
          try {
            const latest = await getSavedFolderOptions({requireFresh: true});
            if (!stillOwned()) return;
            snapshot.acceptFolderCount(
              countRead,
              latest.find(folder => folder.id === item.folderId)?.lessonsCount,
            );
          } catch {
            if (stillOwned() && snapshot.isCurrentCountRead(countRead)) {
              // The server delete succeeded; only the total remains unknown.
              reportReadError('تمت إزالة المقطع\nتعذّر تحديث عدد المقاطع');
            }
          }
        }
      } catch {
        if (stillOwned()) {
          if (removal) snapshot.rollbackLessonRemoval(removal);
          setActionError('تعذّرت إزالة المقطع\nحاول مرة أخرى');
        }
      } finally {
        if (removeFlightsRef.current.get(key) === flight) {
          removeFlightsRef.current.delete(key);
          if (operation.isAccountActive()) {
            setRemovingSaved(current => {
              const next = new Set(current);
              next.delete(key);
              return next;
            });
            if (!operation.isCurrent()) retry();
          }
        }
      }
    },
    [
      captureOperation,
      identityKey,
      removingSaved,
      reportReadError,
      retry,
      snapshot,
    ],
  );

  const deleteActiveFolder = useCallback(() => {
    const folder = folders.find(item => item.id === activeFolderId);
    if (!folder || deletingFolder || deleteFolderFlightRef.current) return;
    Alert.alert(
      'حذف القائمة',
      `سنحذف ${folder.name}\nوتبقى المحفوظات في القوائم الأخرى`,
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'حذف',
          style: 'destructive',
          onPress: () => {
            if (deleteFolderFlightRef.current) return;
            const operation = captureOperation();
            const flight = {identityKey};
            deleteFolderFlightRef.current = flight;
            setDeletingFolder(true);
            setFolderError('');
            void (async () => {
              let removal: ReturnType<
                SavedLibrarySnapshot['beginFolderRemoval']
              > | null = null;
              let boundary: Awaited<
                ReturnType<typeof captureAccountSessionBoundary>
              > | null = null;
              const stillOwned = () => {
                if (!boundary || !operation.isCurrent()) {
                  return false;
                }
                try {
                  assertAccountSessionBoundary(boundary);
                  return true;
                } catch {
                  return false;
                }
              };

              try {
                boundary = await captureAccountSessionBoundary();
                if (!stillOwned()) return;
                removal = snapshot.beginFolderRemoval(folder);

                await deleteSavedFolderOption(folder.id);
                assertAccountSessionBoundary(boundary);
                if (stillOwned()) {
                  snapshot.confirmFolderRemoval(removal);
                  selectFolder('all');
                }
              } catch {
                if (stillOwned()) {
                  if (removal) {
                    snapshot.rollbackFolderRemoval(removal);
                    restoreFolderSelection(folder.id);
                  }
                  setFolderError(
                    'تعذّر حذف القائمة\nتحقق من الاتصال وحاول مرة أخرى',
                  );
                }
              } finally {
                if (deleteFolderFlightRef.current === flight) {
                  deleteFolderFlightRef.current = null;
                  if (operation.isAccountActive()) {
                    setDeletingFolder(false);
                    if (!operation.isCurrent()) retry();
                  }
                }
              }
            })();
          },
        },
      ],
    );
  }, [
    captureOperation,
    restoreFolderSelection,
    retry,
    activeFolderId,
    deletingFolder,
    folders,
    identityKey,
    selectFolder,
    snapshot,
  ]);

  const toggleCreateFolder = useCallback(() => {
    setShowCreateFolder(value => !value);
    setFolderError('');
  }, []);

  const derived = useMemo(() => {
    const folderMap = new Map<string, SavedFolderOption>();
    const folderCounts = new Map<string, number>();
    folders.forEach(folder => {
      folderMap.set(folder.id, folder);
      if (folder.lessonsCount !== undefined) {
        folderCounts.set(folder.id, folder.lessonsCount);
      }
    });
    saved.forEach(item => {
      if (!folderMap.has(item.folderId)) {
        folderMap.set(item.folderId, {
          id: item.folderId,
          name: item.folderName,
        });
      }
    });
    const visible =
      activeFolderId === 'all'
        ? saved
        : saved.filter(item => item.folderId === activeFolderId);
    const grouped = Array.from(
      visible.reduce((groups, item) => {
        const group = groups.get(item.folderId) ?? {
          name: item.folderName,
          items: [],
        };
        group.items.push(item);
        groups.set(item.folderId, group);
        return groups;
      }, new Map<string, SavedGroup>()),
    );
    return {
      folderOptions: Array.from(folderMap.values()),
      folderCounts,
      groupedSaved: grouped,
      visibleSaved: visible,
    };
  }, [activeFolderId, folders, saved]);

  return {
    ...derived,
    actionError,
    activeFolderId,
    createFolder,
    creatingFolder,
    deleteActiveFolder,
    deletingFolder,
    error,
    folderError,
    folderLoadError,
    identityOwned,
    loadMore,
    loading,
    loadingMore,
    loadMoreError,
    newFolderName,
    nextPage,
    removeSaved,
    removingSaved,
    retry,
    saved,
    selectFolder,
    serverSession,
    setNewFolderName,
    showCreateFolder,
    toggleCreateFolder,
  };
}
