/** Folder names compare as the server's normalized_name does. */
export const normalizedFolderName = (value: string) =>
  value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('ar');

/** The default list one-tap save fills, like Instagram's "All posts". */
export const WATCH_LATER_FOLDER_NAME = 'المشاهدة لاحقًا';

export const isWatchLaterFolderName = (name: string) =>
  normalizedFolderName(name) === normalizedFolderName(WATCH_LATER_FOLDER_NAME);
