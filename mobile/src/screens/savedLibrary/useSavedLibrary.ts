import {useFocusEffect} from '@react-navigation/native';
import {
  useInfiniteQuery,
  useIsMutating,
  useMutation,
  useMutationState,
  useQuery,
  useQueryClient,
  type QueryClient,
} from '@tanstack/react-query';
import {useCallback, useEffect, useMemo, useRef, useState} from 'react';
import {Alert} from 'react-native';
import {useSelector} from 'react-redux';
import {
  createSavedFolderOption,
  deleteSavedFolderOption,
  removeLessonFromSavedFolder,
  type SavedFolderOption,
} from '../../components/VideoPlayer/courseLearningApi';
import {sessionIdentityKey} from '../../constants/helpers';
import {learnerCopy} from '../../constants/learnerCopy';
import {friendlyNetworkMessage} from '../../services/networkExperience';
import {useAccountQueryScope} from '../../services/query/accountQueryScope';
import {accountQueryKeys} from '../../services/query/queryKeys';
import {
  ALL_SAVED_LESSONS,
  adjustFolderCount,
  insertSavedRow,
  removeSavedRows,
  savedFoldersQueryOptions,
  savedLessonRows,
  savedLessonsQueryOptions,
  savedRowKey,
  type SavedLessonsData,
} from '../../services/query/savedLibraryQueries';
import {hasSession, type SavedLesson} from '../../services/roknApi';
import type {RootState} from '../../store/store';

type SavedGroup = {
  name: string;
  items: SavedLesson[];
};

/** Where a row sat in each loaded list, for a rollback to its old place. */
type RowPositions = Array<[readonly unknown[], number]>;

type RemovalContext = {
  positions: RowPositions;
  optimisticFolders: SavedFolderOption[] | undefined;
  interrupted: InterruptedReads;
};
type FolderDeletionContext = {
  folderIndex: number;
  positions: Array<[readonly unknown[], Array<[SavedLesson, number]>]>;
  interrupted: InterruptedReads;
};
type InterruptedReads = {lists: boolean; folders: boolean};

const EMPTY_FOLDERS: SavedFolderOption[] = [];
const NO_ROWS: SavedLesson[] = [];

const isAccountChange = (error: unknown) =>
  error instanceof Error && error.message === 'ACCOUNT_CHANGED_DURING_REQUEST';

const responseStatus = (error: unknown) =>
  Number(
    (error as {status?: unknown; response?: {status?: unknown}} | null)
      ?.status ??
      (error as {response?: {status?: unknown}} | null)?.response?.status ??
      0,
  );

/**
 * Pauses the owner's list and folder reads before an optimistic edit (TanStack
 * "Optimistic Updates", via the cache): a pre-mutation response can then not
 * land over the edit. Reports which reads were interrupted so a failed
 * mutation can resume them after its rollback, as those reads used to land.
 */
const pauseReads = async (
  client: QueryClient,
  owner: string,
): Promise<InterruptedReads> => {
  const keys = accountQueryKeys(owner);
  const lists = {queryKey: keys.savedLessonLists()};
  const folders = {queryKey: keys.savedFolders()};
  const interrupted = {
    lists: client.isFetching(lists) > 0,
    folders: client.isFetching(folders) > 0,
  };
  await Promise.all([client.cancelQueries(lists), client.cancelQueries(folders)]);
  return interrupted;
};

const resumeReads = (
  client: QueryClient,
  owner: string,
  interrupted: InterruptedReads,
) => {
  const keys = accountQueryKeys(owner);
  if (interrupted.lists) {
    void client
      .refetchQueries({queryKey: keys.savedLessonLists(), type: 'active'})
      .catch(() => undefined);
  }
  if (interrupted.folders) {
    void client
      .refetchQueries({queryKey: keys.savedFolders(), type: 'active'})
      .catch(() => undefined);
  }
};

/**
 * The full saved library: one infinite query per list (all lessons or one
 * folder) plus the saved-folders index, both owned by the account scope
 * (ARCHITECTURE.md M1/M6). Removing a lesson, deleting a list and creating a
 * list edit the cache first and roll back on failure (TanStack "Optimistic
 * Updates"); the confirmed write then marks the owner's lists stale through
 * `applySavedMembershipChange` / `invalidateFolderList`, so a mounted library
 * reads the server's answer again at once. Pending mutations are read from
 * the mutation cache, so leaving and returning to the screen never loses a
 * busy state or unlocks a duplicate write.
 */
export function useSavedLibrary(initialFolderId?: string) {
  const client = useQueryClient();
  const storedUser = useSelector((state: RootState) => state.auth.userData);
  const identityKey = sessionIdentityKey(storedUser);
  const {scope, failed: scopeFailed, retry: retryScope} =
    useAccountQueryScope(identityKey);
  const scopeRef = useRef(scope);
  scopeRef.current = scope;
  const keys = useMemo(() => accountQueryKeys(scope), [scope]);

  // The requested list belongs to the account that opened the screen; any
  // later account starts from all saved lessons.
  const openingRef = useRef({
    identityKey,
    folderId: initialFolderId?.trim() || ALL_SAVED_LESSONS,
  });
  const [activeFolderId, setActiveFolderId] = useState(
    openingRef.current.folderId,
  );
  const [actionError, setActionError] = useState('');
  const [showCreateFolder, setShowCreateFolder] = useState(false);
  const [newFolderName, setNewFolderName] = useState('');
  const [folderError, setFolderError] = useState('');
  // Explicit recovery must reach the server, not clear a warning with an
  // offline index. Ordinary focus reads still keep their cache fallback.
  const requireFreshFoldersRef = useRef(false);

  useEffect(() => {
    setActiveFolderId(
      identityKey === openingRef.current.identityKey
        ? openingRef.current.folderId
        : ALL_SAVED_LESSONS,
    );
    setActionError('');
    setFolderError('');
    setNewFolderName('');
    setShowCreateFolder(false);
    requireFreshFoldersRef.current = false;
  }, [identityKey]);

  const session = useQuery({
    queryKey: keys.session(),
    queryFn: () => hasSession(),
    enabled: scope !== null,
    staleTime: 0,
    refetchOnWindowFocus: false,
  });
  const signedIn = session.data === true;
  const enabled = scope !== null && signedIn;

  const lessons = useInfiniteQuery({
    ...savedLessonsQueryOptions(scope, activeFolderId),
    enabled,
  });
  const folders = useQuery({
    ...savedFoldersQueryOptions(scope, () => {
      const requireFresh = requireFreshFoldersRef.current;
      requireFreshFoldersRef.current = false;
      return requireFresh;
    }),
    enabled,
  });

  // TanStack React Native guide, "Refresh on Screen focus": the first focus
  // is the mount read; every later focus reads page one and the index again
  // (an interrupted "عرض المزيد" is dropped, as before).
  const reloadRef = useRef<() => void>(() => undefined);
  reloadRef.current = () => {
    const owner = scope;
    if (owner === null) return;
    void session.refetch({cancelRefetch: false}).then(result => {
      if (result.data !== true || scopeRef.current !== owner) return;
      void lessons.refetch();
      void folders.refetch();
    });
  };
  const focusedBeforeRef = useRef(false);
  useFocusEffect(
    useCallback(() => {
      if (focusedBeforeRef.current) reloadRef.current();
      focusedBeforeRef.current = true;
    }, []),
  );

  const retry = useCallback(() => {
    requireFreshFoldersRef.current = true;
    if (scopeFailed) retryScope();
    reloadRef.current();
  }, [retryScope, scopeFailed]);

  const selectFolder = useCallback((folderId: string) => {
    setActiveFolderId(current => (current === folderId ? current : folderId));
  }, []);

  // The selected list is gone (deleted on another device or already removed):
  // leave the index and return to all saved lessons, as before.
  const lessonsError = lessons.error;
  useEffect(() => {
    if (
      activeFolderId === ALL_SAVED_LESSONS ||
      scope === null ||
      !lessons.isError ||
      responseStatus(lessonsError) !== 404
    ) {
      return;
    }
    client.setQueryData<SavedFolderOption[]>(keys.savedFolders(), current =>
      current?.filter(folder => folder.id !== activeFolderId),
    );
    client.removeQueries({
      queryKey: keys.savedLessons(activeFolderId),
      exact: true,
    });
    setActiveFolderId(ALL_SAVED_LESSONS);
  }, [activeFolderId, client, keys, lessons.isError, lessonsError, scope]);

  const serverSession = scope === null ? null : session.data ?? null;
  const loadMore = useCallback(async () => {
    if (!lessons.hasNextPage || lessons.isFetching || serverSession !== true) {
      return;
    }
    await lessons.fetchNextPage();
  }, [lessons, serverSession]);

  const removalKey = useMemo(
    () => [...keys.all, 'saved-lesson-removal'] as const,
    [keys],
  );
  const folderCreateKey = useMemo(
    () => [...keys.all, 'saved-folder-create'] as const,
    [keys],
  );
  const folderDeleteKey = useMemo(
    () => [...keys.all, 'saved-folder-delete'] as const,
    [keys],
  );

  const removal = useMutation({
    mutationKey: removalKey,
    mutationFn: async ({item}: {item: SavedLesson; owner: string}) => {
      await removeLessonFromSavedFolder(item.id, item.folderId);
    },
    onMutate: async ({item, owner}): Promise<RemovalContext> => {
      const ownedKeys = accountQueryKeys(owner);
      const interrupted = await pauseReads(client, owner);
      const key = savedRowKey(item);
      const positions: RowPositions = client
        .getQueriesData<SavedLessonsData>({
          queryKey: ownedKeys.savedLessonLists(),
        })
        .map(([queryKey, data]) => [
          queryKey,
          savedLessonRows(data).findIndex(row => savedRowKey(row) === key),
        ]);
      client.setQueriesData<SavedLessonsData>(
        {queryKey: ownedKeys.savedLessonLists()},
        data => data && removeSavedRows(data, row => savedRowKey(row) !== key),
      );
      client.setQueryData<SavedFolderOption[]>(
        ownedKeys.savedFolders(),
        current => current && adjustFolderCount(current, item.folderId, -1),
      );
      // The stored value (after structural sharing) is the receipt a rollback
      // compares against: only our own decrement is undone.
      const optimisticFolders = client.getQueryData<SavedFolderOption[]>(
        ownedKeys.savedFolders(),
      );
      return {positions, optimisticFolders, interrupted};
    },
    onError: (_error, {item, owner}, context) => {
      // Another account owns the screen now; its queries were removed.
      if (owner !== scopeRef.current || !context) return;
      const ownedKeys = accountQueryKeys(owner);
      context.positions.forEach(([queryKey, index]) => {
        if (index < 0) return;
        client.setQueryData<SavedLessonsData>(
          queryKey,
          data => data && insertSavedRow(data, item, index),
        );
      });
      // A newer index already supplied the total; only our own decrement
      // is undone.
      client.setQueryData<SavedFolderOption[]>(
        ownedKeys.savedFolders(),
        current =>
          current && current === context.optimisticFolders
            ? adjustFolderCount(current, item.folderId, 1)
            : current,
      );
      resumeReads(client, owner, context.interrupted);
      setActionError(`تعذّرت إزالة المقطع\n${learnerCopy.retry}`);
    },
  });

  const folderCreation = useMutation({
    mutationKey: folderCreateKey,
    mutationFn: ({name}: {name: string; owner: string}) =>
      createSavedFolderOption(name),
    onSuccess: (created, {owner}) => {
      if (owner !== scopeRef.current) return;
      client.setQueryData<SavedFolderOption[]>(
        accountQueryKeys(owner).savedFolders(),
        current => [
          ...(current ?? []).filter(folder => folder.id !== created.id),
          created,
        ],
      );
      setActiveFolderId(created.id);
      setNewFolderName('');
      setShowCreateFolder(false);
    },
    onError: (_error, {owner}) => {
      if (owner !== scopeRef.current) return;
      setFolderError(`تعذّر إنشاء القائمة\n${learnerCopy.networkRetry}`);
    },
  });

  const folderDeletion = useMutation({
    mutationKey: folderDeleteKey,
    mutationFn: async ({folder}: {folder: SavedFolderOption; owner: string}) => {
      await deleteSavedFolderOption(folder.id);
    },
    onMutate: async ({folder, owner}): Promise<FolderDeletionContext> => {
      const ownedKeys = accountQueryKeys(owner);
      const interrupted = await pauseReads(client, owner);
      const positions: FolderDeletionContext['positions'] = client
        .getQueriesData<SavedLessonsData>({
          queryKey: ownedKeys.savedLessonLists(),
        })
        .map(([queryKey, data]) => [
          queryKey,
          savedLessonRows(data).flatMap((row, index) =>
            row.folderId === folder.id ? [[row, index] as [SavedLesson, number]] : [],
          ),
        ]);
      let folderIndex = -1;
      client.setQueryData<SavedFolderOption[]>(
        ownedKeys.savedFolders(),
        current => {
          if (!current) return current;
          folderIndex = current.findIndex(item => item.id === folder.id);
          return current.filter(item => item.id !== folder.id);
        },
      );
      client.setQueriesData<SavedLessonsData>(
        {queryKey: ownedKeys.savedLessonLists()},
        data =>
          data && removeSavedRows(data, row => row.folderId !== folder.id),
      );
      return {folderIndex, positions, interrupted};
    },
    onSuccess: (_result, {owner}) => {
      if (owner === scopeRef.current) setActiveFolderId(ALL_SAVED_LESSONS);
    },
    onError: (_error, {folder, owner}, context) => {
      if (owner !== scopeRef.current || !context) return;
      const ownedKeys = accountQueryKeys(owner);
      client.setQueryData<SavedFolderOption[]>(
        ownedKeys.savedFolders(),
        current => {
          if (!current || current.some(item => item.id === folder.id))
            return current;
          const restored = [...current];
          restored.splice(
            Math.min(Math.max(0, context.folderIndex), restored.length),
            0,
            folder,
          );
          return restored;
        },
      );
      context.positions.forEach(([queryKey, rows]) => {
        rows.forEach(([row, index]) => {
          client.setQueryData<SavedLessonsData>(
            queryKey,
            data => data && insertSavedRow(data, row, index),
          );
        });
      });
      resumeReads(client, owner, context.interrupted);
      setActiveFolderId(folder.id);
      setFolderError(`تعذّر حذف القائمة\n${learnerCopy.networkRetry}`);
    },
  });

  // Busy state comes from the mutation cache, so it survives leaving the
  // screen and never unlocks a second write for the same row or list.
  const pendingRemovals = useMutationState({
    filters: {mutationKey: removalKey, status: 'pending'},
    select: mutation =>
      savedRowKey((mutation.state.variables as {item: SavedLesson}).item),
  });
  const removingSaved = useMemo(
    () => new Set(pendingRemovals),
    [pendingRemovals],
  );
  const creatingFolder = useIsMutating({mutationKey: folderCreateKey}) > 0;
  const deletingFolder = useIsMutating({mutationKey: folderDeleteKey}) > 0;

  const folderIndex = signedIn ? folders.data ?? EMPTY_FOLDERS : EMPTY_FOLDERS;
  const removeMutate = removal.mutateAsync;
  const removeSaved = useCallback(
    async (item: SavedLesson) => {
      const owner = scopeRef.current;
      if (owner === null) return;
      const key = savedRowKey(item);
      const inFlight =
        client.isMutating({
          mutationKey: removalKey,
          predicate: mutation =>
            savedRowKey(
              (mutation.state.variables as {item: SavedLesson}).item,
            ) === key,
        }) > 0;
      if (inFlight) return;
      setActionError('');
      await removeMutate({item, owner}).catch(() => undefined);
    },
    [client, removalKey, removeMutate],
  );

  const createMutate = folderCreation.mutateAsync;
  const createFolder = useCallback(async () => {
    const name = newFolderName.trim();
    const owner = scopeRef.current;
    if (
      !name ||
      owner === null ||
      client.isMutating({mutationKey: folderCreateKey}) > 0
    ) {
      return;
    }
    const existing = folderIndex.find(
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
    setFolderError('');
    await createMutate({name, owner}).catch(() => undefined);
  }, [
    client,
    createMutate,
    folderCreateKey,
    folderIndex,
    newFolderName,
    selectFolder,
  ]);

  const deleteMutate = folderDeletion.mutateAsync;
  const deleteActiveFolder = useCallback(() => {
    const folder = folderIndex.find(item => item.id === activeFolderId);
    const owner = scopeRef.current;
    if (
      !folder ||
      owner === null ||
      client.isMutating({mutationKey: folderDeleteKey}) > 0
    ) {
      return;
    }
    Alert.alert(
      'حذف القائمة',
      `سنحذف ${folder.name}\nوتبقى المحفوظات في القوائم الأخرى`,
      [
        {text: 'إلغاء', style: 'cancel'},
        {
          text: 'حذف',
          style: 'destructive',
          onPress: () => {
            if (client.isMutating({mutationKey: folderDeleteKey}) > 0) return;
            setFolderError('');
            void deleteMutate({folder, owner}).catch(() => undefined);
          },
        },
      ],
    );
  }, [activeFolderId, client, deleteMutate, folderDeleteKey, folderIndex]);

  const toggleCreateFolder = useCallback(() => {
    setShowCreateFolder(value => !value);
    setFolderError('');
  }, []);

  const rows = useMemo(
    () => (signedIn ? savedLessonRows(lessons.data) : NO_ROWS),
    [lessons.data, signedIn],
  );
  const firstPage = signedIn ? lessons.data?.pages[0] : undefined;
  const derived = useMemo(() => {
    const folderMap = new Map<string, SavedFolderOption>();
    const folderCounts = new Map<string, number>();
    folderIndex.forEach(folder => {
      folderMap.set(folder.id, folder);
      if (folder.lessonsCount !== undefined) {
        folderCounts.set(folder.id, folder.lessonsCount);
      }
    });
    if (activeFolderId !== ALL_SAVED_LESSONS && firstPage && !deletingFolder) {
      // A successful folder-page read outranks a possibly offline index: the
      // list stays selectable and its total is the server's.
      if (!folderMap.has(activeFolderId)) {
        folderMap.set(activeFolderId, {
          id: activeFolderId,
          name: firstPage.folderName ?? rows[0]?.folderName ?? '',
          lessonsCount: firstPage.total,
        });
      }
      folderCounts.set(activeFolderId, firstPage.total);
    }
    rows.forEach(item => {
      if (!folderMap.has(item.folderId)) {
        folderMap.set(item.folderId, {
          id: item.folderId,
          name: item.folderName,
        });
      }
    });
    const visible =
      activeFolderId === ALL_SAVED_LESSONS
        ? rows
        : rows.filter(item => item.folderId === activeFolderId);
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
  }, [activeFolderId, deletingFolder, firstPage, folderIndex, rows]);

  const readingFirstPage = lessons.isFetching && !lessons.isFetchingNextPage;
  const loading =
    scope === null
      ? !scopeFailed
      : !signedIn
      ? session.data === undefined && !session.isError
      : readingFirstPage || (lessons.data === undefined && !lessons.isError);
  const listFailed =
    signedIn &&
    !readingFirstPage &&
    lessons.isError &&
    !lessons.isFetchNextPageError &&
    !isAccountChange(lessonsError) &&
    responseStatus(lessonsError) !== 404;
  let error = '';
  if (scopeFailed) {
    error = 'تعذّر تحميل المحفوظات\nمكانك وكل ما حفظته موجود';
  } else if (session.isError && !session.isFetching) {
    error = `${friendlyNetworkMessage(
      session.error,
      'المحفوظات',
    )}\nمكانك وكل ما حفظته موجود`;
  } else if (listFailed) {
    error =
      rows.length && activeFolderId === ALL_SAVED_LESSONS
        ? 'نعرض آخر محفوظات متاحة\nأعد المحاولة عند عودة الاتصال'
        : `${friendlyNetworkMessage(
            lessonsError,
            'المحفوظات',
          )}\nمكانك وكل ما حفظته موجود`;
  }
  const folderLoadError =
    signedIn &&
    folders.isError &&
    !folders.isFetching &&
    !isAccountChange(folders.error)
      ? 'تعذّر تحديث القوائم\nالمحفوظات ما زالت موجودة'
      : '';
  const loadMoreError =
    signedIn &&
    lessons.isFetchNextPageError &&
    !lessons.isFetching &&
    responseStatus(lessonsError) !== 404
      ? 'تعذّر تحميل باقي المحفوظات'
      : '';

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
    identityOwned: scope !== null || scopeFailed,
    loadMore,
    loading,
    loadingMore: lessons.isFetchingNextPage,
    loadMoreError,
    newFolderName,
    nextPage:
      signedIn && lessons.hasNextPage && lessons.data
        ? lessons.data.pages.length + 1
        : null,
    removeSaved,
    removingSaved,
    retry,
    saved: rows,
    selectFolder,
    serverSession,
    setNewFolderName,
    showCreateFolder,
    toggleCreateFolder,
  };
}
