import AsyncStorage from '@react-native-async-storage/async-storage';

/**
 * Device storage for the saved-folder index and the Watch Later destination
 * hint. Both are account-scoped caches (`${base}:${scope}`) that logout and
 * account-switch cleanup remove with every other scoped key.
 */
const SAVED_FOLDERS_KEY = '@rokn/saved-folder-options/v1';
const WATCH_LATER_FOLDER_KEY = '@rokn/watch-later-folder-id/v2';

export const readSavedFolderIndex = (accountScope: string) =>
  AsyncStorage.getItem(`${SAVED_FOLDERS_KEY}:${accountScope}`);

export const writeSavedFolderIndex = (accountScope: string, raw: string) =>
  AsyncStorage.setItem(`${SAVED_FOLDERS_KEY}:${accountScope}`, raw);

export const watchLaterHintKey = (accountScope: string) =>
  `${WATCH_LATER_FOLDER_KEY}:${accountScope}`;

export const readWatchLaterHint = (accountScope: string) =>
  AsyncStorage.getItem(watchLaterHintKey(accountScope));

export const writeWatchLaterHint = (accountScope: string, folderId: string) =>
  AsyncStorage.setItem(watchLaterHintKey(accountScope), folderId);

export const removeWatchLaterHint = (accountScope: string) =>
  AsyncStorage.removeItem(watchLaterHintKey(accountScope));
