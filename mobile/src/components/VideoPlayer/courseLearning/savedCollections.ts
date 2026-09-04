import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {
  hasSession,
  removeSavedFolderFromCache,
  removeSavedLessonEverywhereFromCache,
  removeSavedLessonFromCache,
} from '../../../services/roknApi';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {updatePlayerStateForScope} from './persistence';
import {valueAsString} from './shared';

type SavedFolderDto = {
  id?: unknown;
  name?: unknown;
  image?: unknown;
  lessons_count?: unknown;
};

const WATCH_LATER_FOLDER_KEY = '@rokn/watch-later-folder-id/v2';
const SAVED_FOLDERS_KEY = '@rokn/saved-folder-options/v1';

const folderListFlights = new Map<string, Promise<SavedFolderOption[]>>();
const watchLaterFolderFlights = new Map<string, Promise<string | null>>();
const createFolderFlights = new Map<string, Promise<SavedFolderOption>>();
const deleteFolderFlights = new Map<string, Promise<void>>();
const saveMembershipFlights = new Map<string, Promise<boolean>>();
const removeMembershipFlights = new Map<string, Promise<void>>();
const watchLaterFlights = new Map<string, Promise<boolean>>();

const singleFlight = <T>(
  flights: Map<string, Promise<T>>,
  key: string,
  operation: () => Promise<T>,
): Promise<T> => {
  const running = flights.get(key);
  if (running) return running;
  const flight = operation();
  flights.set(key, flight);
  flight.then(
    () => {
      if (flights.get(key) === flight) flights.delete(key);
    },
    () => {
      if (flights.get(key) === flight) flights.delete(key);
    },
  );
  return flight;
};

const ownerKey = (boundary: AccountSessionBoundary) =>
  `${boundary.scope}:${boundary.epoch}`;

const responseStatus = (error: unknown) =>
  Number(
    error && typeof error === 'object'
      ? (error as {status?: unknown; response?: {status?: unknown}}).status ??
          (error as {response?: {status?: unknown}}).response?.status
      : 0,
  );

const isSavedCollectionContractError = (error: unknown) =>
  error instanceof Error &&
  [
    'INVALID_SAVED_FOLDERS_RESPONSE',
    'SAVED_FOLDER_CREATE_FAILED',
    'WATCH_LATER_FOLDER_CONTRACT_INVALID',
    'SAVED_MEMBERSHIP_CONTRACT_INVALID',
  ].includes(error.message);

const acceptRemoteCacheRepair = async (
  accountBoundary: AccountSessionBoundary,
  repair: () => Promise<unknown>,
) => {
  try {
    assertAccountSessionBoundary(accountBoundary);
    await repair();
    assertAccountSessionBoundary(accountBoundary);
  } catch (error) {
    if (
      error instanceof Error &&
      error.message === 'ACCOUNT_CHANGED_DURING_REQUEST'
    ) {
      throw error;
    }
    // The server mutation is already authoritative. The next folder/library
    // read and the feed reconciliation rebuild both caches, so no mutation
    // outbox may replay a successful write or delete.
  }
};

const deleteIdempotently = async (route: string) => {
  try {
    await publicRequest.delete(route);
  } catch (error) {
    if (responseStatus(error) !== 404) throw error;
  }
};

const ensureWatchLaterFolder = async (
  accountBoundary: AccountSessionBoundary,
  ignoreCached = false,
): Promise<string | null> =>
  singleFlight(
    watchLaterFolderFlights,
    `${ownerKey(accountBoundary)}:${ignoreCached ? 'refresh' : 'cached'}`,
    async () => {
    const expectedScope = accountBoundary.scope;
    const storageKey = `${WATCH_LATER_FOLDER_KEY}:${expectedScope}`;
    const cached = await AsyncStorage.getItem(storageKey).catch(() => null);
    assertAccountSessionBoundary(accountBoundary);
    if (
      !ignoreCached &&
      /^\d{1,18}$/.test(String(cached || '')) &&
      Number(cached) > 0
    ) {
      return String(cached);
    }
    if (cached !== null) {
      // Older builds occasionally persisted an object/stringified response here
      // instead of the folder id. It is not an entitlement; discard it and ask
      // the server for the authoritative folder on this launch.
      await AsyncStorage.removeItem(storageKey);
    }
    const sessionAvailable = await hasSession();
    assertAccountSessionBoundary(accountBoundary);
    if (!sessionAvailable) {
      return null;
    }

    const response = await publicRequest.get('saved-folders');
    assertAccountSessionBoundary(accountBoundary);
    const folderPayload = response?.data?.data;
    const folders = requireSavedFolderList(folderPayload);
    let folder = folders.find(item => {
      const name = normalizedFolderName(valueAsString(item?.name));
      return name === normalizedFolderName('المشاهدة لاحقًا');
    });
    if (!folder) {
      const created = await publicRequest.post('saved-folders', {
        name: 'المشاهدة لاحقًا',
        client_request_id: secureRandomUuid(),
      });
      assertAccountSessionBoundary(accountBoundary);
      folder = created?.data?.data;
    }
    if (!validSavedFolderOption(folder)) {
      throw new Error('WATCH_LATER_FOLDER_CONTRACT_INVALID');
    }
    const id = valueAsString(folder.id);
    await AsyncStorage.setItem(storageKey, id).catch(() => undefined);
    assertAccountSessionBoundary(accountBoundary);
    return id;
    },
  );

const saveMembershipOnServer = async (folderId: string, lessonId: string) => {
  const response = await publicRequest.post(`saved-folders/${folderId}/lessons`, {
    lesson_id: lessonId,
  });
  const payload = response?.data?.data;
  if (
    payload?.is_saved !== true ||
    valueAsString(payload?.folder_id) !== folderId ||
    valueAsString(payload?.lesson_id) !== lessonId
  ) {
    throw new Error('SAVED_MEMBERSHIP_CONTRACT_INVALID');
  }
};

export type SavedFolderOption = {
  id: string;
  name: string;
  imageUrl?: string;
  lessonsCount?: number;
};

const mapSavedFolder = (folder: SavedFolderDto): SavedFolderOption => ({
  id: valueAsString(folder.id),
  name: valueAsString(folder.name).trim(),
  imageUrl: folder.image ? valueAsString(folder.image) : undefined,
  lessonsCount: Number.isFinite(Number(folder.lessons_count))
    ? Math.max(0, Number(folder.lessons_count))
    : undefined,
});

const validSavedFolderOption = (value: unknown): value is SavedFolderDto => {
  if (!value || typeof value !== 'object') return false;
  const folder = value as SavedFolderDto;
  const id = valueAsString(folder.id);
  return (
    /^\d{1,18}$/.test(id) &&
    Number(id) > 0 &&
    valueAsString(folder.name).trim().length > 0
  );
};

const requireSavedFolderList = (value: unknown): SavedFolderDto[] => {
  if (
    !Array.isArray(value) ||
    value.some(item => !validSavedFolderOption(item))
  ) {
    throw new Error('INVALID_SAVED_FOLDERS_RESPONSE');
  }
  return value as SavedFolderDto[];
};

const localSavedFoldersKey = (accountScope: string) =>
  `${SAVED_FOLDERS_KEY}:${accountScope}`;

const readLocalSavedFolders = async (
  accountScope: string,
): Promise<SavedFolderOption[]> => {
  try {
    const raw = await AsyncStorage.getItem(localSavedFoldersKey(accountScope));
    const parsed = raw ? JSON.parse(raw) : [];
    if (Array.isArray(parsed) && parsed.length) {
      return parsed.filter(validSavedFolderOption).map(mapSavedFolder);
    }
  } catch {
    // A damaged local folder index should never block saving a reel.
  }
  return [];
};

const writeLocalSavedFolders = async (
  accountScope: string,
  folders: SavedFolderOption[],
) =>
  AsyncStorage.setItem(
    localSavedFoldersKey(accountScope),
    JSON.stringify(folders),
  );

const normalizedFolderName = (value: string) =>
  value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('ar');

export const getSavedFolderOptions = async (): Promise<SavedFolderOption[]> => {
  const accountBoundary = await captureAccountSessionBoundary();
  const accountScope = accountBoundary.scope;
  return singleFlight(folderListFlights, ownerKey(accountBoundary), async () => {
    const sessionAvailable = await hasSession();
    assertAccountSessionBoundary(accountBoundary);
    if (!sessionAvailable) {
      throw new Error('SAVED_COLLECTIONS_AUTH_REQUIRED');
    }
    try {
      const response = await publicRequest.get('saved-folders');
      const folderPayload = response?.data?.data;
      const folders = requireSavedFolderList(folderPayload).map(mapSavedFolder);
      assertAccountSessionBoundary(accountBoundary);
      await writeLocalSavedFolders(accountScope, folders).catch(
        () => undefined,
      );
      assertAccountSessionBoundary(accountBoundary);
      return folders;
    } catch (error) {
      // ACCOUNT_CHANGED_DURING_REQUEST must never fall through to the previous
      // owner's offline folder list.
      assertAccountSessionBoundary(accountBoundary);
      if (isSavedCollectionContractError(error)) throw error;
      const cached = await readLocalSavedFolders(accountScope);
      assertAccountSessionBoundary(accountBoundary);
      if (cached.length) return cached;
      throw error instanceof Error
        ? error
        : new Error('SAVED_FOLDERS_UNAVAILABLE');
    }
  });
};

export const createSavedFolderOption = async (
  rawName: string,
): Promise<SavedFolderOption> => {
  const name = rawName.trim().slice(0, 60);
  if (!name) throw new Error('FOLDER_NAME_REQUIRED');
  const accountBoundary = await captureAccountSessionBoundary();
  const accountScope = accountBoundary.scope;
  const flightKey = `${ownerKey(accountBoundary)}:${normalizedFolderName(name)}`;
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
    const payload = response?.data?.data;
    if (!validSavedFolderOption(payload)) {
      throw new Error('SAVED_FOLDER_CREATE_FAILED');
    }
    const created = mapSavedFolder(payload);
    await acceptRemoteCacheRepair(accountBoundary, async () => {
      const latest = await readLocalSavedFolders(accountScope);
      await writeLocalSavedFolders(
        accountScope,
        [
          ...latest.filter(item => item.id !== created.id),
          created,
        ],
      );
    });
    return created;
  });
};

export const deleteSavedFolderOption = async (folderId: string) => {
  const normalizedFolderId = folderId.trim();
  if (!normalizedFolderId) throw new Error('INVALID_SAVED_FOLDER_ROUTE');
  const accountBoundary = await captureAccountSessionBoundary();
  const accountScope = accountBoundary.scope;
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

      const repair = async () => {
        assertAccountSessionBoundary(accountBoundary);
        await removeSavedFolderFromCache(normalizedFolderId, accountBoundary);
        const current = await readLocalSavedFolders(accountScope);
        await writeLocalSavedFolders(
          accountScope,
          current.filter(folder => folder.id !== normalizedFolderId),
        );
        const watchLaterKey = `${WATCH_LATER_FOLDER_KEY}:${accountScope}`;
        const watchLaterFolderId = await AsyncStorage.getItem(watchLaterKey);
        if (watchLaterFolderId === normalizedFolderId) {
          await AsyncStorage.removeItem(watchLaterKey);
        }
        await updatePlayerStateForScope(
          accountScope,
          state => {
            const nextFolders = {...state.savedFolderLessons};
            delete nextFolders[normalizedFolderId];
            const stillSaved = new Set(Object.values(nextFolders).flat());
            return {
              ...state,
              savedFolderLessons: nextFolders,
              savedLessons: state.savedLessons.filter(id => stillSaved.has(id)),
            };
          },
          accountBoundary,
        );
      };
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
  const accountScope = accountBoundary.scope;
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
      const repair = () =>
        updatePlayerStateForScope(
          accountScope,
          state => ({
            ...state,
            savedLessons: Array.from(
              new Set([...state.savedLessons, normalizedLessonId]),
            ),
            savedFolderLessons: {
              ...state.savedFolderLessons,
              [normalizedFolderId]: Array.from(
                new Set([
                  ...(state.savedFolderLessons[normalizedFolderId] || []),
                  normalizedLessonId,
                ]),
              ),
            },
          }),
          accountBoundary,
        );
      await acceptRemoteCacheRepair(accountBoundary, repair);
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
  const accountScope = accountBoundary.scope;
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
          await AsyncStorage.removeItem(
            `${WATCH_LATER_FOLDER_KEY}:${accountScope}`,
          ).catch(() => undefined);
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

      const repair = () =>
        updatePlayerStateForScope(
          accountScope,
          state => ({
            ...state,
            savedLessons: nextSaved
              ? Array.from(new Set([...state.savedLessons, normalizedLessonId]))
              : state.savedLessons.filter(id => id !== normalizedLessonId),
            savedFolderLessons: nextSaved
              ? {
                  ...state.savedFolderLessons,
                  [targetFolderId as string]: Array.from(
                    new Set([
                      ...(state.savedFolderLessons[targetFolderId as string] ||
                        []),
                      normalizedLessonId,
                    ]),
                  ),
                }
              : Object.fromEntries(
                  Object.entries(state.savedFolderLessons)
                    .map(([folderId, lessons]) => [
                      folderId,
                      lessons.filter(id => id !== normalizedLessonId),
                    ])
                    .filter(([, lessons]) => lessons.length > 0),
                ),
          }),
          accountBoundary,
        );
      await acceptRemoteCacheRepair(accountBoundary, async () => {
        if (!nextSaved) {
          await removeSavedLessonEverywhereFromCache(
            normalizedLessonId,
            accountBoundary,
          );
        }
        await repair();
      });

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
  const accountScope = accountBoundary.scope;
  const sessionAvailable = await hasSession();
  assertAccountSessionBoundary(accountBoundary);
  if (!sessionAvailable) {
    return [];
  }
  const lessonIds = Array.from(
    new Set(rawLessonIds.filter(id => /^\d+$/.test(id))),
  );
  if (!lessonIds.length) return [];

  const saved = new Set<string>();
  for (let offset = 0; offset < lessonIds.length; offset += 200) {
    const chunk = lessonIds.slice(offset, offset + 200);
    const response = await publicRequest.get('saved-lessons/state', {
      params: {lesson_ids: chunk},
    });
    assertAccountSessionBoundary(accountBoundary);
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

  assertAccountSessionBoundary(accountBoundary);
  const queried = new Set(lessonIds);
  await updatePlayerStateForScope(
    accountScope,
    state => ({
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
    }),
    accountBoundary,
  );

  return Array.from(saved);
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
  const accountScope = accountBoundary.scope;
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
      const repair = () =>
        updatePlayerStateForScope(
          accountScope,
          state => {
            const nextFolders = {...state.savedFolderLessons};
            const remainingInFolder = (
              nextFolders[normalizedFolderId] || []
            ).filter(id => id !== normalizedLessonId);
            if (remainingInFolder.length) {
              nextFolders[normalizedFolderId] = remainingInFolder;
            } else {
              delete nextFolders[normalizedFolderId];
            }
            const remainsSaved = Object.values(nextFolders).some(lessons =>
              lessons.includes(normalizedLessonId),
            );
            return {
              ...state,
              savedFolderLessons: nextFolders,
              savedLessons: remainsSaved
                ? state.savedLessons
                : state.savedLessons.filter(id => id !== normalizedLessonId),
            };
          },
          accountBoundary,
        );
      await acceptRemoteCacheRepair(accountBoundary, async () => {
        await removeSavedLessonFromCache(
          normalizedFolderId,
          normalizedLessonId,
          accountBoundary,
        );
        await repair();
      });
    },
  );
};
