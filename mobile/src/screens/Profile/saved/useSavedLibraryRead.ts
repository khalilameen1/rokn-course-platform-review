import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useRef, useState} from 'react';
import {getSavedFolderOptions} from '../../../components/VideoPlayer/courseLearningApi';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {friendlyNetworkMessage} from '../../../services/networkExperience';
import {
  getSavedFolderLessonsPage,
  getSavedLessonsPage,
  hasSession,
} from '../../../services/roknApi';
import type {SavedLibrarySnapshot} from './SavedLibrarySnapshot';

/** A command can finish on the same account after leaving its original page. */
export type SavedLibraryOperation = {
  isCurrent: () => boolean;
  isAccountActive: () => boolean;
};

export function useSavedLibraryRead(
  identityKey: string,
  snapshot: SavedLibrarySnapshot,
  restoreMutationActivity: () => void,
) {
  const [activeFolderId, setActiveFolderId] = useState('all');
  const [serverSession, setServerSession] = useState<boolean | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [reload, setReload] = useState(0);
  const [nextPage, setNextPage] = useState<number | null>(null);
  const [loadingMore, setLoadingMore] = useState(false);
  const [loadMoreError, setLoadMoreError] = useState('');

  const [folderLoadError, setFolderLoadError] = useState('');
  const loadGenerationRef = useRef(0);
  const loadingMoreRef = useRef(false);
  const screenActiveRef = useRef(false);
  const dataOwnerRef = useRef(identityKey);

  useEffect(() => {
    loadGenerationRef.current += 1;
    loadingMoreRef.current = false;
    snapshot.clear();
    setActiveFolderId('all');
    setServerSession(null);
    setNextPage(null);
    setError('');
    setLoadMoreError('');
    setFolderLoadError('');
    setLoading(true);
    setLoadingMore(false);
    dataOwnerRef.current = identityKey;
  }, [identityKey, snapshot]);

  const captureOperation = useCallback((): SavedLibraryOperation => {
    const generation = loadGenerationRef.current;
    const isAccountActive = () =>
      screenActiveRef.current && dataOwnerRef.current === identityKey;
    return {
      isAccountActive,
      isCurrent: () =>
        isAccountActive() && generation === loadGenerationRef.current,
    };
  }, [identityKey]);

  const selectFolder = useCallback(
    (folderId: string) => {
      if (folderId === activeFolderId) return;
      // Invalidate immediately: an earlier page must not land in the new scope.
      loadGenerationRef.current += 1;
      loadingMoreRef.current = false;
      snapshot.replaceRows([]);
      setNextPage(null);
      setLoading(true);
      setLoadingMore(false);
      setError('');
      setLoadMoreError('');
      setActiveFolderId(folderId);
    },
    [activeFolderId, snapshot],
  );

  useFocusEffect(
    useCallback(() => {
      let active = true;
      screenActiveRef.current = true;
      restoreMutationActivity();
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
            snapshot.clear();
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
            snapshot.replaceFolderIndex(
              folderResult.value,
              lessonResult.ok ? activeFolderId : undefined,
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
            snapshot.removeFolder(activeFolderId);
            selectFolder('all');
            return;
          }
          if (!lessonResult.ok) throw lessonResult.reason;
          const result = lessonResult.value;
          snapshot.replaceRows(result.lessons);
          if (activeFolderId !== 'all') {
            snapshot.replaceFolderTotal(activeFolderId, result.total);
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
    }, [
      activeFolderId,
      identityKey,
      reload,
      restoreMutationActivity,
      selectFolder,
      snapshot,
    ]),
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
      snapshot.appendRows(result.lessons);
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
          snapshot.removeFolder(activeFolderId);
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
    snapshot,
  ]);

  return {
    activeFolderId,
    captureOperation,
    error,
    folderLoadError,
    identityOwned: dataOwnerRef.current === identityKey,
    loadMore,
    loading,
    loadingMore,
    loadMoreError,
    nextPage,
    retry,
    selectFolder,
    serverSession,
    restoreFolderSelection: setActiveFolderId,
    reportReadError: setError,
  };
}
