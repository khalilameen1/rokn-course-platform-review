import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
} from '../../../constants/helpers';
import {hasSession} from '../../../services/roknApi';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {settleWithin} from '../../../utils/settleWithin';
import {
  repairSavedMembershipCache,
  reconcileSavedMembershipCache,
} from './savedMembershipCache';
import {valueAsString} from './shared';
import {ownerKey, singleFlight} from './savedCollectionConcurrency';
import {acceptRemoteCacheRepair} from './savedCollectionCacheRepair';
import {
  cacheCreatedSavedFolder,
  cacheDeletedSavedFolder,
  invalidateFolderList,
  invalidateMembershipCounts,
  mapSavedFolder,
  normalizedFolderName,
  savedFolderRevision,
  validSavedFolderOption,
  type SavedFolderOption,
} from './savedFolderIndex';
import {ensureWatchLaterFolder, forgetWatchLaterHint} from './watchLaterFolder';

export {getSavedFolderOptions} from './savedFolderIndex';
export type {SavedFolderOption} from './savedFolderIndex';

const createFolderFlights = new Map<string, Promise<SavedFolderOption>>();
const deleteFolderFlights = new Map<string, Promise<void>>();
const saveMembershipFlights = new Map<string, Promise<boolean>>();
const removeMembershipFlights = new Map<string, Promise<void>>();
const watchLaterFlights = new Map<string, Promise<boolean>>();

const responseStatus = (error: unknown) =>
  Number(
    error && typeof error === 'object'
      ? (error as {status?: unknown; response?: {status?: unknown}}).status ??
          (error as {response?: {status?: unknown}}).response?.status
      : 0,
  );

const deleteIdempotently = async (route: string) => {
  try {
    await publicRequest.delete(route);
  } catch (error) {
    if (responseStatus(error) !== 404) throw error;
  }
};

const saveMembershipOnServer = async (folderId: string, lessonId: string) => {
  const response = await publicRequest.post(
    `saved-folders/${folderId}/lessons`,
    {
      lesson_id: lessonId,
    },
  );
  const payload = response?.data?.data;
  if (
    payload?.is_saved !== true ||
    valueAsString(payload?.folder_id) !== folderId ||
    valueAsString(payload?.lesson_id) !== lessonId
  ) {
    throw new Error('SAVED_MEMBERSHIP_CONTRACT_INVALID');
  }
};

export const createSavedFolderOption = async (
  rawName: string,
): Promise<SavedFolderOption> => {
  const name = rawName.trim().slice(0, 60);
  if (!name) throw new Error('FOLDER_NAME_REQUIRED');
  const accountBoundary = await captureAccountSessionBoundary();
  const flightKey = `${ownerKey(accountBoundary)}:${normalizedFolderName(
    name,
  )}`;
  return singleFlight(createFolderFlights, flightKey, async () => {
    const sessionAvailable = await hasSession();
    assertAccountSessionBoundary(accountBoundary);
    if (!sessionAvailable) {
      throw new Error('SAVED_COLLECTIONS_AUTH_REQUIRED');
    }
    const response = await publicRequest.post('saved-folders', {
      name,
      client_request_id: secureRandomUuid(),
    });
    assertAccountSessionBoundary(accountBoundary);
    invalidateFolderList(accountBoundary);
    const payload = response?.data?.data;
    if (!validSavedFolderOption(payload)) {
      throw new Error('SAVED_FOLDER_CREATE_FAILED');
    }
    const created = mapSavedFolder(payload);
    await cacheCreatedSavedFolder(accountBoundary, created);
    return created;
  });
};

export const deleteSavedFolderOption = async (folderId: string) => {
  const normalizedFolderId = folderId.trim();
  if (!normalizedFolderId) throw new Error('INVALID_SAVED_FOLDER_ROUTE');
  const accountBoundary = await captureAccountSessionBoundary();
  return singleFlight(
    deleteFolderFlights,
    `${ownerKey(accountBoundary)}:${normalizedFolderId}`,
    async () => {
      const sessionAvailable = await hasSession();
      assertAccountSessionBoundary(accountBoundary);
      if (!sessionAvailable) {
        throw new Error('SAVED_COLLECTIONS_AUTH_REQUIRED');
      }
      if (!/^\d{1,18}$/.test(normalizedFolderId)) {
        throw new Error('INVALID_SAVED_FOLDER_ROUTE');
      }
      await deleteIdempotently(`saved-folders/${normalizedFolderId}`);
      assertAccountSessionBoundary(accountBoundary);
      invalidateFolderList(accountBoundary);

      // Independent repairs must all enter their own queues after the ACK;
      // stalled lesson storage must not leave the folder index/player untouched.
      const repair = () =>
        Promise.all([
          cacheDeletedSavedFolder(accountBoundary, normalizedFolderId),
          forgetWatchLaterHint(accountBoundary, normalizedFolderId),
          repairSavedMembershipCache(accountBoundary, {
            kind: 'delete-folder',
            folderId: normalizedFolderId,
          }),
        ]);
      await acceptRemoteCacheRepair(accountBoundary, repair);
    },
  );
};

export const saveLessonToFolder = async (
  lessonId: string,
  folder: SavedFolderOption,
) => {
  const normalizedLessonId = lessonId.trim();
  const normalizedFolderId = folder.id.trim();
  if (!normalizedLessonId || !normalizedFolderId) {
    throw new Error('INVALID_SAVED_LESSON_ROUTE');
  }
  const accountBoundary = await captureAccountSessionBoundary();
  return singleFlight(
    saveMembershipFlights,
    `${ownerKey(accountBoundary)}:${normalizedFolderId}:${normalizedLessonId}`,
    async () => {
      const sessionAvailable = await hasSession();
      assertAccountSessionBoundary(accountBoundary);
      if (!sessionAvailable) {
        throw new Error('SAVED_COLLECTIONS_AUTH_REQUIRED');
      }
      if (
        !/^\d{1,18}$/.test(normalizedFolderId) ||
        !/^\d{1,18}$/.test(normalizedLessonId)
      ) {
        throw new Error('INVALID_SAVED_LESSON_ROUTE');
      }
      // For real accounts the server is authoritative. Do not show a success
      // that disappears on another device or after the next refresh.
      await saveMembershipOnServer(normalizedFolderId, normalizedLessonId);
      assertAccountSessionBoundary(accountBoundary);
      await invalidateMembershipCounts(accountBoundary, normalizedFolderId);
      await acceptRemoteCacheRepair(accountBoundary, () =>
        repairSavedMembershipCache(accountBoundary, {
          kind: 'save',
          folderId: normalizedFolderId,
          lessonId: normalizedLessonId,
        }),
      );
      return true;
    },
  );
};

export const toggleWatchLater = async (
  lessonId: string,
  currentlySaved: boolean,
) => {
  const normalizedLessonId = lessonId.trim();
  if (!normalizedLessonId) throw new Error('INVALID_SAVED_LESSON_ROUTE');
  const accountBoundary = await captureAccountSessionBoundary();
  const nextSaved = !currentlySaved;
  return singleFlight(
    watchLaterFlights,
    `${ownerKey(accountBoundary)}:${normalizedLessonId}`,
    async () => {
      const sessionAvailable = await hasSession();
      assertAccountSessionBoundary(accountBoundary);
      if (!sessionAvailable) {
        throw new Error('SAVED_COLLECTIONS_AUTH_REQUIRED');
      }
      if (!/^\d{1,18}$/.test(normalizedLessonId)) {
        throw new Error('INVALID_SAVED_LESSON_ROUTE');
      }
      let targetFolderId: string | null = null;

      if (!nextSaved) {
        await deleteIdempotently(`saved-lessons/${normalizedLessonId}`);
      } else {
        let folderId = await ensureWatchLaterFolder(accountBoundary);
        if (!folderId) throw new Error('WATCH_LATER_FOLDER_UNAVAILABLE');
        try {
          await saveMembershipOnServer(folderId, normalizedLessonId);
        } catch (error) {
          if (responseStatus(error) !== 404) throw error;
          assertAccountSessionBoundary(accountBoundary);
          await settleWithin(
            forgetWatchLaterHint(accountBoundary, folderId),
            undefined,
          );
          assertAccountSessionBoundary(accountBoundary);
          folderId = await ensureWatchLaterFolder(accountBoundary, true);
          if (!folderId) throw new Error('WATCH_LATER_FOLDER_UNAVAILABLE');
          await saveMembershipOnServer(folderId, normalizedLessonId);
        }
        targetFolderId = folderId;
      }
      assertAccountSessionBoundary(accountBoundary);
      if (nextSaved && !targetFolderId) {
        throw new Error('WATCH_LATER_FOLDER_UNAVAILABLE');
      }
      await invalidateMembershipCounts(accountBoundary, targetFolderId);

      await acceptRemoteCacheRepair(accountBoundary, () =>
        repairSavedMembershipCache(
          accountBoundary,
          nextSaved && targetFolderId
            ? {
                kind: 'save',
                folderId: targetFolderId,
                lessonId: normalizedLessonId,
              }
            : {kind: 'remove-everywhere', lessonId: normalizedLessonId},
        ),
      );

      return nextSaved;
    },
  );
};

/**
 * Reconciles bookmark icons with the server in one bounded request per feed.
 * This removes stale device-local state after a save or delete on another device.
 */
export const reconcileServerSavedLessons = async (
  rawLessonIds: string[],
): Promise<string[]> => {
  const accountBoundary = await captureAccountSessionBoundary();
  const sessionAvailable = await hasSession();
  assertAccountSessionBoundary(accountBoundary);
  if (!sessionAvailable) {
    return [];
  }
  const lessonIds = Array.from(
    new Set(rawLessonIds.filter(id => /^\d+$/.test(id))),
  );
  if (!lessonIds.length) return [];

  const overtaken = new Error('SAVED_LESSON_STATE_CHANGED_DURING_READ');
  const reconcileSnapshot = async (mayRetry: boolean): Promise<string[]> => {
    // Membership and folder ACKs already invalidate this owner's folder index.
    // The same boundary owns its saved-state snapshot; no second ledger is needed.
    const revision = savedFolderRevision(accountBoundary);
    const assertCurrent = () => {
      assertAccountSessionBoundary(accountBoundary);
      if (savedFolderRevision(accountBoundary) !== revision) throw overtaken;
    };
    try {
      const saved = new Set<string>();
      for (let offset = 0; offset < lessonIds.length; offset += 200) {
        const chunk = lessonIds.slice(offset, offset + 200);
        const response = await publicRequest.get('saved-lessons/state', {
          params: {lesson_ids: chunk},
        });
        assertCurrent();
        const ids = response?.data?.data?.saved_lesson_ids;
        if (!Array.isArray(ids)) {
          throw new Error('SAVED_LESSON_STATE_CONTRACT_INVALID');
        }
        const requested = new Set(chunk);
        ids.forEach(id => {
          const value = valueAsString(id);
          if (!/^\d{1,18}$/.test(value) || !requested.has(value)) {
            throw new Error('SAVED_LESSON_STATE_CONTRACT_INVALID');
          }
          saved.add(value);
        });
      }

      assertCurrent();
      await reconcileSavedMembershipCache(
        accountBoundary,
        new Set(lessonIds),
        saved,
        assertCurrent,
      );
      assertCurrent();
      return Array.from(saved);
    } catch (error) {
      assertAccountSessionBoundary(accountBoundary);
      if (error === overtaken && mayRetry) return reconcileSnapshot(false);
      throw error;
    }
  };
  return reconcileSnapshot(true);
};

export const removeLessonFromSavedFolder = async (
  lessonId: string,
  folderId: string,
) => {
  const normalizedLessonId = lessonId.trim();
  const normalizedFolderId = folderId.trim();
  if (!normalizedLessonId || !normalizedFolderId) {
    throw new Error('INVALID_SAVED_LESSON_ROUTE');
  }
  const accountBoundary = await captureAccountSessionBoundary();
  return singleFlight(
    removeMembershipFlights,
    `${ownerKey(accountBoundary)}:${normalizedFolderId}:${normalizedLessonId}`,
    async () => {
      const sessionAvailable = await hasSession();
      assertAccountSessionBoundary(accountBoundary);
      if (!sessionAvailable) {
        throw new Error('SAVED_COLLECTIONS_AUTH_REQUIRED');
      }
      if (
        !/^\d{1,18}$/.test(normalizedFolderId) ||
        !/^\d{1,18}$/.test(normalizedLessonId)
      ) {
        throw new Error('INVALID_SAVED_LESSON_ROUTE');
      }
      // The server commits first. A failed delete must not disappear locally and
      // then reappear on the next refresh or another device.
      await deleteIdempotently(
        `saved-folders/${normalizedFolderId}/lessons/${normalizedLessonId}`,
      );
      assertAccountSessionBoundary(accountBoundary);
      await invalidateMembershipCounts(accountBoundary, normalizedFolderId);
      await acceptRemoteCacheRepair(accountBoundary, () =>
        repairSavedMembershipCache(accountBoundary, {
          kind: 'remove',
          folderId: normalizedFolderId,
          lessonId: normalizedLessonId,
        }),
      );
    },
  );
};
