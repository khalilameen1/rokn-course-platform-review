import {useFocusEffect} from '@react-navigation/native';
import {useQuery} from '@tanstack/react-query';
import {useCallback} from 'react';

import type {SavedFolderOption} from '../../components/VideoPlayer/courseLearning/savedFolderIndex';
import {isWatchLaterFolderName} from '../../components/VideoPlayer/courseLearning/savedFolderNames';
import {useAppForegroundState} from '../../hooks/useAppActiveState';
import {useAccountQueryScope} from '../../services/query/accountQueryScope';
import {savedFoldersQueryOptions} from '../../services/query/savedLibraryQueries';

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
 * The learner's saved lists for ركني, observing the same account-scoped
 * saved-folders query as the full library (`savedFoldersQueryOptions`), which
 * reads through the player sheet's folder index and its offline copy;
 * confirmed save/remove/folder changes invalidate it (`invalidateFolderList`).
 * Every focus and return to the app reads again; a failed refresh keeps the
 * cards already shown.
 */
export const useSavedPreview = (identityKey: string, enabled: boolean) => {
  const appIsActive = useAppForegroundState();
  const {scope} = useAccountQueryScope(identityKey);
  const folders = useQuery({
    ...savedFoldersQueryOptions(scope),
    select: savedCollections,
    // A manual query: ركني reads on every focus and foreground return.
    enabled: false,
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
