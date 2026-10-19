import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {updatePlayerStateForScope} from '../../../services/learningPlayerState';
import {queryClient} from '../../../services/query/queryClient';
import {
  applySavedMembershipChange,
  type SavedMembershipChange,
} from '../../../services/query/savedLibraryInvalidation';

export type {SavedMembershipChange};

/**
 * Applies an already-confirmed server mutation to the saved-lessons queries
 * and the player cache. Starts both without waiting for either. The caller
 * owns the bounded best-effort wait; timing it out must not release the
 * native write queue.
 */
export const repairSavedMembershipCache = async (
  boundary: AccountSessionBoundary,
  change: SavedMembershipChange,
): Promise<void> => {
  assertAccountSessionBoundary(boundary);
  const pageRepair = applySavedMembershipChange(
    queryClient,
    boundary.scope,
    change,
  );
  const playerRepair = updatePlayerStateForScope(
    boundary.scope,
    state => {
      if (change.kind === 'save') {
        return {
          ...state,
          savedLessons: Array.from(
            new Set([...state.savedLessons, change.lessonId]),
          ),
          savedFolderLessons: {
            ...state.savedFolderLessons,
            [change.folderId]: Array.from(
              new Set([
                ...(state.savedFolderLessons[change.folderId] || []),
                change.lessonId,
              ]),
            ),
          },
        };
      }
      if (change.kind === 'remove-everywhere') {
        return {
          ...state,
          savedLessons: state.savedLessons.filter(id => id !== change.lessonId),
          savedFolderLessons: Object.fromEntries(
            Object.entries(state.savedFolderLessons)
              .map(
                ([folderId, lessons]) =>
                  [folderId, lessons.filter(id => id !== change.lessonId)] as [
                    string,
                    string[],
                  ],
              )
              .filter(([, lessons]) => lessons.length > 0),
          ),
        };
      }
      const nextFolders = {...state.savedFolderLessons};
      if (change.kind === 'delete-folder') {
        delete nextFolders[change.folderId];
        const stillSaved = new Set(Object.values(nextFolders).flat());
        return {
          ...state,
          savedFolderLessons: nextFolders,
          savedLessons: state.savedLessons.filter(id => stillSaved.has(id)),
        };
      }
      const remainingInFolder = (nextFolders[change.folderId] || []).filter(
        id => id !== change.lessonId,
      );
      if (remainingInFolder.length)
        nextFolders[change.folderId] = remainingInFolder;
      else delete nextFolders[change.folderId];
      // The local folder map can be partial; prefer the server's answer.
      const remainsSaved =
        change.kind === 'remove' && typeof change.stillSaved === 'boolean'
          ? change.stillSaved
          : Object.values(nextFolders).some(lessons =>
              lessons.includes(change.lessonId),
            );
      return {
        ...state,
        savedFolderLessons: nextFolders,
        savedLessons: remainsSaved
          ? state.savedLessons
          : state.savedLessons.filter(id => id !== change.lessonId),
      };
    },
    boundary,
  );
  await Promise.all([pageRepair, playerRepair]);
};

/** The guard is evaluated inside the native queue, not just before enqueueing. */
export const reconcileSavedMembershipCache = (
  boundary: AccountSessionBoundary,
  queried: Set<string>,
  saved: Set<string>,
  assertCurrent: () => void,
) =>
  updatePlayerStateForScope(
    boundary.scope,
    state => {
      assertCurrent();
      return {
        ...state,
        savedLessons: Array.from(
          new Set([
            ...state.savedLessons.filter(id => !queried.has(id)),
            ...saved,
          ]),
        ),
        savedFolderLessons: Object.fromEntries(
          Object.entries(state.savedFolderLessons)
            .map(
              ([folderId, ids]) =>
                [
                  folderId,
                  ids.filter(id => !queried.has(id) || saved.has(id)),
                ] as [string, string[]],
            )
            .filter(([, ids]) => ids.length > 0),
        ),
      };
    },
    boundary,
  );
