import {useFocusEffect} from '@react-navigation/native';
import {useQuery} from '@tanstack/react-query';
import {useCallback} from 'react';

// The folder index alone, not the player runtime behind courseLearningApi.
import {
  getSavedFolderOptions,
  type SavedFolderOption,
} from '../../components/VideoPlayer/courseLearning/savedFolderIndex';
import {isWatchLaterFolderName} from '../../components/VideoPlayer/courseLearning/savedFolderNames';
import {assertAccountSessionBoundary} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {
  captureScopedBoundary,
  useAccountQueryScope,
} from '../../services/query/accountQueryScope';
import {accountQueryKeys} from '../../services/query/queryKeys';

export type SavedPreviewStatus = 'loading' | 'ready' | 'error';

const NO_COLLECTIONS: SavedCollection[] = [];

export type SavedCollection = {
  id: string;
  name: string;
  /** Undefined when the server only has its generic folder placeholder. */
  coverUrl?: string;
  /** Undefined while a confirmed save has invalidated the cached count. */
  lessonsCount?: number;
  watchLater: boolean;
};

// SavedFolderResource covers a list with its first saved lesson's thumbnail
// and falls back to this asset. ركني draws its own folder tile instead.
const PLACEHOLDER_COVER = /\/images\/default-folder\.png(?:[?#]|$)/i;

/**
 * The saved-folders read already carries each list's name, lesson count and
 * cover, so ركني needs no per-list request. "المشاهدة لاحقًا" leads, as
 * Instagram's "All posts" does; the rest keep the server's newest-first order
 * because the API does not report when a list was last used.
 */
export const savedCollections = (
  folders: SavedFolderOption[],
): SavedCollection[] => {
  const collections = folders.map(folder => ({
    id: folder.id,
    name: folder.name,
    coverUrl:
      folder.imageUrl && !PLACEHOLDER_COVER.test(folder.imageUrl)
        ? folder.imageUrl
        : undefined,
    lessonsCount: folder.lessonsCount,
    watchLater: isWatchLaterFolderName(folder.name),
  }));
  return [
    ...collections.filter(collection => collection.watchLater),
    ...collections.filter(collection => !collection.watchLater),
  ];
};

/**
 * The learner's saved lists for ركني, as one account-scoped query. It reads
 * through the same account-scoped folder index (and its offline copy) as the
 * full library and the player sheet; confirmed save/remove/folder changes
 * invalidate it (`invalidateFolderList`). Every focus and return to the app
 * reads again; a failed refresh keeps the cards already shown.
 */
export const useSavedPreview = (identityKey: string, enabled: boolean) => {
  const appIsActive = useAppForegroundState();
  const {scope} = useAccountQueryScope(identityKey);
  const folders = useQuery({
    queryKey: accountQueryKeys(scope).savedFolders(),
    queryFn: async () => {
      const boundary = await captureScopedBoundary(scope!);
      const options = await getSavedFolderOptions();
      assertAccountSessionBoundary(boundary);
      return savedCollections(options);
    },
    // A manual query: ركني reads on every focus and foreground return.
    enabled: false,
    staleTime: 0,
  });

  const refetch = folders.refetch;
  const reload = useCallback(() => {
    if (!enabled || scope === null) return;
    void refetch({cancelRefetch: false});
  }, [enabled, refetch, scope]);

  useFocusEffect(
    useCallback(() => {
      if (appIsActive) reload();
    }, [appIsActive, reload]),
  );

  const status: SavedPreviewStatus = folders.data
    ? 'ready'
    : folders.isError && !folders.isFetching
    ? 'error'
    : 'loading';
  return {collections: folders.data ?? NO_COLLECTIONS, reload, status};
};
