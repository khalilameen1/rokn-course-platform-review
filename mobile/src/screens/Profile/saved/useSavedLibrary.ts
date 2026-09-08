import {useFocusEffect} from '@react-navigation/native';
import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type SetStateAction,
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
import {friendlyNetworkMessage} from '../../../services/networkExperience';
import {
  getSavedFolderLessonsPage,
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
  const [folders, setFoldersState] = useState<SavedFolderOption[]>([]);
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
  const foldersRef = useRef<SavedFolderOption[]>([]);
  // Row pagination and server folder totals are independent snapshots.
  const folderCountSnapshotsRef = useRef(new Map<string, object>());
  const setFolders = useCallback(
    (
      next: SetStateAction<SavedFolderOption[]>,
      options?: {replaceCountSnapshots?: string[]},
    ) => {
      options?.replaceCountSnapshots?.forEach(id => {
        folderCountSnapshotsRef.current.set(id, {});
      });
      foldersRef.current =
        typeof next === 'function' ? next(foldersRef.current) : next;
      setFoldersState(foldersRef.current);
    },
    [],
  );

  useEffect(() => {
    loadGenerationRef.current += 1;
    loadingMoreRef.current = false;
    createFolderFlightRef.current = null;
    deleteFolderFlightRef.current = null;
    removeFlightsRef.current.clear();
    folderCountSnapshotsRef.current.clear();
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
  }, [identityKey, setFolders]);

  const selectFolder = useCallback(
    (folderId: string) => {
      if (folderId === activeFolderId) return;
      // Invalidate immediately: an earlier page must not land in the new scope.
      loadGenerationRef.current += 1;
      loadingMoreRef.current = false;
      savedRef.current = [];
      setSaved([]);
      setNextPage(null);
      setLoading(true);
      setLoadingMore(false);
      setError('');
      setLoadMoreError('');
      setActiveFolderId(folderId);
    },
    [activeFolderId],
  );

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

          const [lessonResult, folderResult] = await Promise.all([
            (activeFolderId === 'all'
              ? getSavedLessonsPage(1)
              : getSavedFolderLessonsPage(activeFolderId, 1)
            ).then(
              value => ({ok: true as const, value}),
              reason => ({ok: false as const, reason}),
            ),
            getSavedFolderOptions().then(
              value => ({ok: true as const, value}),
              () => ({ok: false as const}),
            ),
          ]);
          if (!ownsLoad()) return;
          if (folderResult.ok) {
            setFolders(
              current => {
                // The index may be an offline cache. A successful folder read is
                // stronger evidence than its absence from that older index.
                const selected = current.find(
                  folder => folder.id === activeFolderId,
                );
                return lessonResult.ok &&
                  selected &&
                  !folderResult.value.some(folder => folder.id === selected.id)
                  ? [...folderResult.value, selected]
                  : folderResult.value;
              },
              {
                replaceCountSnapshots: folderResult.value.map(
                  folder => folder.id,
                ),
              },
            );
            setFolderLoadError('');
          } else {
            setFolderLoadError('تعذّر تحديث القوائم\nالمحفوظات ما زالت موجودة');
          }
          if (
            activeFolderId !== 'all' &&
            !lessonResult.ok &&
            lessonResult.reason?.response?.status === 404
          ) {
            setFolders(current =>
              current.filter(folder => folder.id !== activeFolderId),
            );
            selectFolder('all');
            return;
          }
          if (!lessonResult.ok) throw lessonResult.reason;
          const result = lessonResult.value;
          savedRef.current = result.lessons;
          setSaved(result.lessons);
          if (activeFolderId !== 'all') {
            setFolders(
              current =>
                current.map(folder =>
                  folder.id === activeFolderId
                    ? {...folder, lessonsCount: result.total}
                    : folder,
                ),
              {replaceCountSnapshots: [activeFolderId]},
            );
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
    }, [activeFolderId, identityKey, reload, selectFolder, setFolders]),
  );

  const retry = useCallback(() => setReload(value => value + 1), []);

  const loadMore = useCallback(async () => {
    if (
      !nextPage ||
      loading ||
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
      const result = await (activeFolderId === 'all'
        ? getSavedLessonsPage(nextPage)
        : getSavedFolderLessonsPage(activeFolderId, nextPage));
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
    } catch (requestError) {
      if (
        screenActiveRef.current &&
        generation === loadGenerationRef.current &&
        dataOwnerRef.current === identityKey
      ) {
        if (
          activeFolderId !== 'all' &&
          (requestError as {response?: {status?: number}})?.response?.status ===
            404
        ) {
          setFolders(current =>
            current.filter(folder => folder.id !== activeFolderId),
          );
          selectFolder('all');
        } else {
          setLoadMoreError('تعذّر تحميل باقي المحفوظات');
        }
      }
    } finally {
      if (generation === loadGenerationRef.current) {
        loadingMoreRef.current = false;
        setLoadingMore(false);
      }
    }
  }, [
    activeFolderId,
    identityKey,
    loading,
    loadingMore,
    nextPage,
    selectFolder,
    serverSession,
    setFolders,
  ]);

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

    const generation = loadGenerationRef.current;
    const flight = {identityKey};
    createFolderFlightRef.current = flight;
    setCreatingFolder(true);
    setFolderError('');
    try {
      const created = await createSavedFolderOption(name);
      if (!screenActiveRef.current || generation !== loadGenerationRef.current)
        return;
      setFolders(
        current => [
          ...current.filter(folder => folder.id !== created.id),
          created,
        ],
        {replaceCountSnapshots: [created.id]},
      );
      selectFolder(created.id);
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
  }, [
    creatingFolder,
    folders,
    identityKey,
    newFolderName,
    selectFolder,
    setFolders,
  ]);

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
      let optimisticCountSnapshot: object | undefined;
      let optimisticCountApplied = false;
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
      const removeLocalRow = () => {
        const index = savedRef.current.findIndex(
          row => row.id === item.id && row.folderId === item.folderId,
        );
        if (index < 0) return index;
        const remainingRows = savedRef.current.filter(
          row => !(row.id === item.id && row.folderId === item.folderId),
        );
        savedRef.current = remainingRows;
        setSaved(remainingRows);
        return index;
      };

      try {
        boundary = await captureAccountSessionBoundary();
        if (!stillOwned()) return;
        optimisticIndex = removeLocalRow();
        if (optimisticIndex < 0) return;
        optimisticApplied = true;
        optimisticCountSnapshot = folderCountSnapshotsRef.current.get(
          item.folderId,
        );
        const count = foldersRef.current.find(
          folder => folder.id === item.folderId,
        )?.lessonsCount;
        if (count !== undefined && count > 0) {
          optimisticCountApplied = true;
          setFolders(current =>
            current.map(folder =>
              folder.id === item.folderId
                ? {...folder, lessonsCount: count - 1}
                : folder,
            ),
          );
        }

        await removeLessonFromSavedFolder(item.id, item.folderId);
        assertAccountSessionBoundary(boundary);
        // A refresh may have restored its pre-delete snapshot while the write
        // was pending. The confirmed result must also win locally, exactly once.
        if (!stillOwned()) return;
        removeLocalRow();
        if (
          folderCountSnapshotsRef.current.get(item.folderId) !==
          optimisticCountSnapshot
        ) {
          // A replacement total may have arrived before OR after the server
          // delete. Neither row absence nor a second decrement can decide that.
          setFolders(
            current =>
              current.map(folder =>
                folder.id === item.folderId
                  ? {...folder, lessonsCount: undefined}
                  : folder,
              ),
            {replaceCountSnapshots: [item.folderId]},
          );
          const reconciliationSnapshot = folderCountSnapshotsRef.current.get(
            item.folderId,
          );
          try {
            const latest = await getSavedFolderOptions({requireFresh: true});
            if (
              !stillOwned() ||
              folderCountSnapshotsRef.current.get(item.folderId) !==
                reconciliationSnapshot
            )
              return;
            const latestCount = latest.find(
              folder => folder.id === item.folderId,
            )?.lessonsCount;
            setFolders(
              current =>
                current.map(folder =>
                  folder.id === item.folderId
                    ? {...folder, lessonsCount: latestCount}
                    : folder,
                ),
              {replaceCountSnapshots: [item.folderId]},
            );
          } catch {
            if (
              stillOwned() &&
              folderCountSnapshotsRef.current.get(item.folderId) ===
                reconciliationSnapshot
            ) {
              // The delete succeeded: only its now-unknown count needs retry.
              setError('تمت إزالة المقطع\nتعذّر تحديث عدد المقاطع');
            }
          }
        }
      } catch {
        if (stillOwned()) {
          if (
            optimisticCountApplied &&
            folderCountSnapshotsRef.current.get(item.folderId) ===
              optimisticCountSnapshot
          ) {
            setFolders(current =>
              current.map(folder =>
                folder.id === item.folderId && folder.lessonsCount !== undefined
                  ? {...folder, lessonsCount: folder.lessonsCount + 1}
                  : folder,
              ),
            );
          }
          if (
            optimisticApplied &&
            !savedRef.current.some(
              row => row.id === item.id && row.folderId === item.folderId,
            )
          ) {
            const restored = [...savedRef.current];
            restored.splice(
              Math.min(Math.max(0, optimisticIndex), restored.length),
              0,
              item,
            );
            savedRef.current = restored;
            setSaved(restored);
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
    [identityKey, removingSaved, setFolders],
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
                optimisticApplied = true;

                await deleteSavedFolderOption(folder.id);
                assertAccountSessionBoundary(boundary);
                if (stillOwned()) {
                  setFolders(current =>
                    current.filter(item => item.id !== folder.id),
                  );
                  selectFolder('all');
                }
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
  }, [
    activeFolderId,
    deletingFolder,
    folders,
    identityKey,
    selectFolder,
    setFolders,
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
    selectFolder,
    serverSession,
    setNewFolderName,
    showCreateFolder,
    toggleCreateFolder,
  };
}
