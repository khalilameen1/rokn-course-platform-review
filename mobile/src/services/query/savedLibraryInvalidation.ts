import type {QueryClient} from '@tanstack/react-query';
import type {SavedLesson} from '../api/savedLessons';
import {accountQueryKeys} from './queryKeys';
import {removeSavedRows, type SavedLessonsData} from './savedLibraryQueries';

/** A saved-lesson membership change the server has already confirmed. */
export type SavedMembershipChange =
  | {kind: 'save'; folderId: string; lessonId: string}
  | {
      kind: 'remove';
      folderId: string;
      lessonId: string;
      /** Server answer: another list still holds the lesson. */
      stillSaved?: boolean | null;
    }
  | {kind: 'remove-everywhere'; lessonId: string}
  | {kind: 'delete-folder'; folderId: string};

const savedRowFilter = (
  change: SavedMembershipChange,
): ((item: SavedLesson) => boolean) | null => {
  switch (change.kind) {
    case 'remove':
      return item =>
        item.folderId !== change.folderId || item.id !== change.lessonId;
    case 'remove-everywhere':
      return item => item.id !== change.lessonId;
    case 'delete-folder':
      return item => item.folderId !== change.folderId;
    default:
      return null;
  }
};

/**
 * Applies a confirmed membership change to every saved-lessons list of the
 * owner (ARCHITECTURE.md M1/M2: the write path marks the reads). The same
 * steps as the catalogue's `removeUnavailableCourse`:
 * - a list read already in flight is cancelled, so its pre-mutation response
 *   cannot bring the row back (this replaces the previous revision counters
 *   that re-issued such a GET);
 * - the row leaves every loaded list and therefore the device snapshot;
 * - the lists are marked stale and a mounted library reads again at once,
 *   while unmounted lists confirm on their next read.
 */
export const applySavedMembershipChange = async (
  client: QueryClient,
  scope: string,
  change: SavedMembershipChange,
): Promise<void> => {
  const keys = accountQueryKeys(scope);
  const filters = {queryKey: keys.savedLessonLists()};
  if (change.kind === 'delete-folder') {
    client.removeQueries({
      queryKey: keys.savedLessons(change.folderId),
      exact: true,
    });
  }
  const keep = savedRowFilter(change);
  if (keep) {
    await client.cancelQueries(filters);
    client.setQueriesData<SavedLessonsData>(
      filters,
      data => data && removeSavedRows(data, keep),
    );
  }
  await client.invalidateQueries(filters);
};
