import {useFocusEffect} from '@react-navigation/native';
import {useCallback, useEffect, useRef, useState} from 'react';

// The folder index alone, not the player runtime behind courseLearningApi.
import {
  getSavedFolderOptions,
  type SavedFolderOption,
} from '../../components/VideoPlayer/courseLearning/savedFolderIndex';
import {isWatchLaterFolderName} from '../../components/VideoPlayer/courseLearning/savedFolderNames';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../constants/helpers';
import {useAppForegroundState} from '../../hooks/useAppActiveState';

export type SavedPreviewStatus = 'loading' | 'ready' | 'error';

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
 * The learner's saved lists for ركني. It reads through the same account-scoped
 * folder index and offline cache as the full library and the player sheet.
 */
export const useSavedPreview = (identityKey: string, enabled: boolean) => {
  const appIsActive = useAppForegroundState();
  const ownerRef = useRef(identityKey);
  const [collections, setCollections] = useState<SavedCollection[]>([]);
  const [status, setStatus] = useState<SavedPreviewStatus>('loading');
  const reloadRef = useRef<(() => void) | null>(null);
  const reload = useCallback(() => reloadRef.current?.(), []);

  useEffect(() => {
    ownerRef.current = identityKey;
    setCollections([]);
    setStatus('loading');
  }, [identityKey]);

  useFocusEffect(
    useCallback(() => {
      if (!enabled || !appIsActive) return undefined;
      let active = true;
      let readGeneration = 0;
      // Focus, foreground and pull to refresh share one screen-owned read.
      const refresh = () => {
        const generation = ++readGeneration;
        const owns = () =>
          active &&
          generation === readGeneration &&
          ownerRef.current === identityKey;
        void (async () => {
          try {
            const boundary = await captureAccountSessionBoundary();
            const folders = await getSavedFolderOptions();
            assertAccountSessionBoundary(boundary);
            if (!owns()) return;
            setCollections(savedCollections(folders));
            setStatus('ready');
          } catch {
            if (!owns()) return;
            // Keep cards already shown; a failed refresh does not empty them.
            setStatus(current => (current === 'ready' ? current : 'error'));
          }
        })();
      };
      reloadRef.current = refresh;
      refresh();
      return () => {
        active = false;
        if (reloadRef.current === refresh) reloadRef.current = null;
      };
    }, [appIsActive, enabled, identityKey]),
  );

  return {collections, reload, status};
};
