import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
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
import {friendlyNetworkMessage} from '../../../services/networkExperience';
import {
  getSavedLessonsPage,
  hasSession,
  type SavedLesson,
} from '../../../services/roknApi';
import type {RootState} from '../../../store/store';

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

  const [saved, setSaved] = useState<SavedLesson[]>([]);
  const [folders, setFolders] = useState<SavedFolderOption[]>([]);
  const [activeFolderId, setActiveFolderId] = useState('all');
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reload, setReload] = useState(0);
  const [nextPage, setNextPage] = useState<number | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [loadMoreError, setLoadMoreError] = useState('');
  const [actionError, setActionError] = useState('');
  const [removingSaved, setRemovingSaved] = useState<Set<string>>(new Set());
  const [showCreateFolder, setShowCreateFolder] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [creatingFolder, setCreatingFolder] = useState(false);
  const [deletingFolder, setDeletingFolder] = useState(false);
  const [folderError, setFolderError] = useState('');
  const [folderLoadError, setFolderLoadError] = useState('');

  const loadGenerationRef = useRef(0);
  const loadingMoreRef = useRef(false);
  const screenActiveRef = useRef(false);
  const createFolderFlightRef = useRef<SavedMutationFlight | null>(null);
  const deleteFolderFlightRef = useRef<SavedMutationFlight | null>(null);
  const removeFlightsRef = useRef(new Map<string, SavedMutationFlight>());
  const dataOwnerRef = useRef(identityKey);
  const savedRef = useRef<SavedLesson[]>([]);

  useEffect(() => {
    loadGenerationRef.current += 1;
    loadingMoreRef.current = false;
    createFolderFlightRef.current = null;
    deleteFolderFlightRef.current = null;
    removeFlightsRef.current.clear();
    savedRef.current = [];
    setSaved([]);
    setFolders([]);
    setActiveFolderId('all');
    setServerSession(null);
    setNextPage(null);
    setError('');
    setLoadMoreError('');
    setActionError('');
    setFolderError('');
    setFolderLoadError('');
    setLoading(true);
    setLoadingMore(false);
    setCreatingFolder(false);
    setDeletingFolder(false);
    setRemovingSaved(new Set());
    dataOwnerRef.current = identityKey;
  }, [identityKey]);

  useFocusEffect(
    useCallback(() => {
      let active = true;
      screenActiveRef.current = true;
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
      const generation = ++loadGenerationRef.current;
      loadingMoreRef.current = false;
      setLoadingMore(false);
      setLoading(true);
      setNextPage(null);
      setLoadMoreError('');
      if (reload > 0) setError('');

      void (async () => {
        try {
          const boundary = await captureAccountSessionBoundary();
          const ownsLoad = () => {
            if (
              !active ||
              generation !== loadGenerationRef.current ||
              dataOwnerRef.current !== identityKey
            ) {
              return false;
            }
            try {
              assertAccountSessionBoundary(boundary);
              return true;
            } catch {
              return false;
            }
          };

          const sessionAvailable = await hasSession();
          if (!ownsLoad()) return;
          setServerSession(sessionAvailable);
          if (!sessionAvailable) {
            savedRef.current = [];
            setSaved([]);
            setFolders([]);
            setFolderLoadError('');
            setError('');
            return;
          }

          const [result, folderResult] = await Promise.all([
            getSavedLessonsPage(1),
            getSavedFolderOptions().then(
              value => ({ok: true as const, value}),
              () => ({ok: false as const}),
            ),
          ]);
          if (!ownsLoad()) return;
          savedRef.current = result.lessons;
          setSaved(result.lessons);
          if (folderResult.ok) {
            setFolders(folderResult.value);
            setFolderLoadError('');
          } else {
            setFolderLoadError('تعذّر تحديث القوائم\nالمحفوظات ما زالت موجودة');
          }
          setNextPage(result.hasMore ? result.page + 1 : null);
          setError(
            result.fromCache
              ? 'نعرض آخر محفوظات متاحة\nأعد المحاولة عند عودة الاتصال'
              : '',
          );
        } catch (requestError) {
          if (
            active &&
            generation === loadGenerationRef.current &&
            dataOwnerRef.current === identityKey
          ) {
            setError(
              `${friendlyNetworkMessage(
                requestError,
                'المحفوظات',
              )}\nمكانك وكل ما حفظته موجود`,
            );
          }
        }
      })().finally(() => {
        if (
          active &&
          generation === loadGenerationRef.current &&
          dataOwnerRef.current === identityKey
        ) {
          setLoading(false);
        }
      });

      return () => {
        active = false;
        screenActiveRef.current = false;
        loadGenerationRef.current += 1;
        loadingMoreRef.current = false;
      };
    }, [identityKey, reload]),
  );

  const retry = useCallback(() => setReload(value => value + 1), []);

  const loadMore = useCallback(async () => {
    if (
      !nextPage ||
      loadingMore ||
      loadingMoreRef.current ||
      serverSession !== true
    ) {
      return;
    }
    loadingMoreRef.current = true;
    const generation = loadGenerationRef.current;
    setLoadingMore(true);
    setLoadMoreError('');
    try {
      const boundary = await captureAccountSessionBoundary();
      const result = await getSavedLessonsPage(nextPage);
      assertAccountSessionBoundary(boundary);
      if (
        !screenActiveRef.current ||
        generation !== loadGenerationRef.current ||
        dataOwnerRef.current !== identityKey
      ) {
        return;
      }
      setSaved(current => {
        const existing = new Set(
          current.map(item => `${item.folderId}:${item.id}`),
        );
        const next = [
          ...current,
          ...result.lessons.filter(
            item => !existing.has(`${item.folderId}:${item.id}`),
          ),
        ];
        savedRef.current = next;
        return next;
      });
      setNextPage(result.hasMore ? result.page + 1 : null);
    } catch {
      if (
        screenActiveRef.current &&
        generation === loadGenerationRef.current &&
        dataOwnerRef.current === identityKey
      ) {
        setLoadMoreError('تعذّر تحميل باقي المحفوظات');
      }
    } finally {
      if (generation === loadGenerationRef.current) {
        loadingMoreRef.current = false;
        setLoadingMore(false);
      }
    }
  }, [identityKey, loadingMore, nextPage, serverSession]);

  const createFolder = useCallback(async () => {
    const name = newFolderName.trim();
    if (!name || creatingFolder || createFolderFlightRef.current) return;
    const existing = folders.find(
      folder =>
        folder.name.trim().toLocaleLowerCase('ar') ===
        name.toLocaleLowerCase('ar'),
    );
    if (existing) {
      setActiveFolderId(existing.id);
      setNewFolderName('');
      setShowCreateFolder(false);
      return;
    }

    const generation = loadGenerationRef.current;
    const flight = {identityKey};
    createFolderFlightRef.current = flight;
    setCreatingFolder(true);
    setFolderError('');
    try {
      const created = await createSavedFolderOption(name);
      if (!screenActiveRef.current || generation !== loadGenerationRef.current)
        return;
      setFolders(current => [
        ...current.filter(folder => folder.id !== created.id),
        created,
      ]);
      setActiveFolderId(created.id);
      setNewFolderName('');
      setShowCreateFolder(false);
    } catch {
      if (screenActiveRef.current && generation === loadGenerationRef.current) {
        setFolderError('تعذّر إنشاء القائمة\nتحقق من الاتصال ثم حاول مرة أخرى');
      }
    } finally {
      if (createFolderFlightRef.current === flight) {
        createFolderFlightRef.current = null;
        if (
          screenActiveRef.current &&
          dataOwnerRef.current === flight.identityKey
        ) {
          setCreatingFolder(false);
          if (generation !== loadGenerationRef.current) {
            setReload(value => value + 1);
          }
        }
      }
    }
  }, [creatingFolder, folders, identityKey, newFolderName]);

  const removeSaved = useCallback(
    async (item: SavedLesson) => {
      const key = `${item.folderId}:${item.id}`;
      if (removingSaved.has(key) || removeFlightsRef.current.has(key)) return;
      const generation = loadGenerationRef.current;
      const flight = {identityKey};
      removeFlightsRef.current.set(key, flight);
      setActionError('');
      setRemovingSaved(current => new Set(current).add(key));
      let optimisticIndex = -1;
      let optimisticApplied = false;
      let boundary: Awaited<
        ReturnType<typeof captureAccountSessionBoundary>
      > | null = null;
      const stillOwned = () => {
        if (
          !boundary ||
          !screenActiveRef.current ||
          generation !== loadGenerationRef.current ||
          dataOwnerRef.current !== identityKey
        ) {
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
        optimisticIndex = savedRef.current.findIndex(
          row => row.id === item.id && row.folderId === item.folderId,
        );
        if (optimisticIndex < 0) return;
        const optimisticRows = savedRef.current.filter(
          row => !(row.id === item.id && row.folderId === item.folderId),
        );
        savedRef.current = optimisticRows;
        setSaved(optimisticRows);
        optimisticApplied = true;

        await removeLessonFromSavedFolder(item.id, item.folderId);
        assertAccountSessionBoundary(boundary);
      } catch {
        if (stillOwned()) {
          if (optimisticApplied) {
            setSaved(current => {
              if (
                current.some(
                  row => row.id === item.id && row.folderId === item.folderId,
                )
              ) {
                return current;
              }
              const restored = [...current];
              restored.splice(
                Math.min(Math.max(0, optimisticIndex), restored.length),
                0,
                item,
              );
              savedRef.current = restored;
              return restored;
            });
          }
          setActionError('تعذّرت إزالة المقطع\nحاول مرة أخرى');
        }
      } finally {
        if (removeFlightsRef.current.get(key) === flight) {
          removeFlightsRef.current.delete(key);
          if (
            screenActiveRef.current &&
            dataOwnerRef.current === flight.identityKey
          ) {
            setRemovingSaved(current => {
              const next = new Set(current);
              next.delete(key);
              return next;
            });
            if (generation !== loadGenerationRef.current) {
              setReload(value => value + 1);
            }
          }
        }
      }
    },
    [identityKey, removingSaved],
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
            const generation = loadGenerationRef.current;
            const flight = {identityKey};
            deleteFolderFlightRef.current = flight;
            setDeletingFolder(true);
            setFolderError('');
            void (async () => {
              let optimisticApplied = false;
              let folderIndex = -1;
              let removedRows: Array<{index: number; item: SavedLesson}> = [];
              let boundary: Awaited<
                ReturnType<typeof captureAccountSessionBoundary>
              > | null = null;
              const stillOwned = () => {
                if (
                  !boundary ||
                  !screenActiveRef.current ||
                  generation !== loadGenerationRef.current ||
                  dataOwnerRef.current !== identityKey
                ) {
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
                folderIndex = folders.findIndex(item => item.id === folder.id);
                removedRows = savedRef.current.flatMap((item, index) =>
                  item.folderId === folder.id ? [{index, item}] : [],
                );
                const remainingRows = savedRef.current.filter(
                  item => item.folderId !== folder.id,
                );
                savedRef.current = remainingRows;
                setSaved(remainingRows);
                setFolders(current =>
                  current.filter(item => item.id !== folder.id),
                );
                setActiveFolderId('all');
                optimisticApplied = true;

                await deleteSavedFolderOption(folder.id);
                assertAccountSessionBoundary(boundary);
              } catch {
                if (stillOwned()) {
                  if (optimisticApplied) {
                    setFolders(current => {
                      if (current.some(item => item.id === folder.id)) {
                        return current;
                      }
                      const restored = [...current];
                      restored.splice(
                        Math.min(Math.max(0, folderIndex), restored.length),
                        0,
                        folder,
                      );
                      return restored;
                    });
                    setSaved(current => {
                      const restored = [...current];
                      removedRows.forEach(({index, item}) => {
                        if (
                          restored.some(
                            row =>
                              row.id === item.id &&
                              row.folderId === item.folderId,
                          )
                        ) {
                          return;
                        }
                        restored.splice(
                          Math.min(Math.max(0, index), restored.length),
                          0,
                          item,
                        );
                      });
                      savedRef.current = restored;
                      return restored;
                    });
                    setActiveFolderId(folder.id);
                  }
                  setFolderError(
                    'تعذّر حذف القائمة\nتحقق من الاتصال وحاول مرة أخرى',
                  );
                }
              } finally {
                if (deleteFolderFlightRef.current === flight) {
                  deleteFolderFlightRef.current = null;
                  if (
                    screenActiveRef.current &&
                    dataOwnerRef.current === flight.identityKey
                  ) {
                    setDeletingFolder(false);
                    if (generation !== loadGenerationRef.current) {
                      setReload(value => value + 1);
                    }
                  }
                }
              }
            })();
          },
        },
      ],
    );
  }, [activeFolderId, deletingFolder, folders, identityKey]);

  const toggleCreateFolder = useCallback(() => {
    setShowCreateFolder(value => !value);
    setFolderError('');
  }, []);

  const derived = useMemo(() => {
    const folderMap = new Map<string, SavedFolderOption>();
    const folderCounts = new Map<string, number>();
    folders.forEach(folder => folderMap.set(folder.id, folder));
    saved.forEach(item => {
      if (!folderMap.has(item.folderId)) {
        folderMap.set(item.folderId, {
          id: item.folderId,
          name: item.folderName,
        });
      }
      folderCounts.set(
        item.folderId,
        (folderCounts.get(item.folderId) ?? 0) + 1,
      );
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
    identityOwned: dataOwnerRef.current === identityKey,
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
    selectFolder: setActiveFolderId,
    serverSession,
    setNewFolderName,
    showCreateFolder,
    toggleCreateFolder,
  };
}
