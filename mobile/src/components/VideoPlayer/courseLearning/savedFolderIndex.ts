import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {hasSession} from '../../../services/roknApi';
import {settleWithin} from '../../../utils/settleWithin';
import {ownerKey, singleFlight} from './savedCollectionConcurrency';
import {acceptRemoteCacheRepair} from './savedCollectionCacheRepair';
import {valueAsString} from './shared';

type SavedFolderDto = {
  id?: unknown;
  name?: unknown;
  image?: unknown;
  lessons_count?: unknown;
};

const SAVED_FOLDERS_KEY = '@rokn/saved-folder-options/v1';
const folderListFlights = new Map<string, Promise<SavedFolderOption[]>>();
const folderListRevisions = new Map<string, number>();
const folderCacheWrites = new Map<string, Promise<void>>();

export const invalidateFolderList = (boundary: AccountSessionBoundary) => {
  const key = ownerKey(boundary);
  folderListRevisions.set(key, (folderListRevisions.get(key) ?? 0) + 1);
  // A post-mutation refresh must not join a pre-mutation network snapshot.
  folderListFlights.delete(key);
  folderListFlights.delete(`${key}:fresh`);
};

export const savedFolderRevision = (boundary: AccountSessionBoundary) =>
  folderListRevisions.get(ownerKey(boundary)) ?? 0;

const isSavedCollectionContractError = (error: unknown) =>
  error instanceof Error &&
  [
    'INVALID_SAVED_FOLDERS_RESPONSE',
    'SAVED_FOLDER_CREATE_FAILED',
    'WATCH_LATER_FOLDER_CONTRACT_INVALID',
    'SAVED_MEMBERSHIP_CONTRACT_INVALID',
  ].includes(error.message);

export type SavedFolderOption = {
  id: string;
  name: string;
  imageUrl?: string;
  lessonsCount?: number;
};

export const normalizedFolderName = (value: string) =>
  value.trim().replace(/\s+/g, ' ').toLocaleLowerCase('ar');

export const mapSavedFolder = (folder: SavedFolderDto): SavedFolderOption => ({
  id: valueAsString(folder.id),
  name: valueAsString(folder.name).trim(),
  imageUrl: folder.image ? valueAsString(folder.image) : undefined,
  lessonsCount: Number.isFinite(Number(folder.lessons_count))
    ? Math.max(0, Number(folder.lessons_count))
    : undefined,
});

export const validSavedFolderOption = (
  value: unknown,
): value is SavedFolderDto => {
  if (!value || typeof value !== 'object') return false;
  const folder = value as SavedFolderDto;
  const id = valueAsString(folder.id);
  return (
    /^\d{1,18}$/.test(id) &&
    Number(id) > 0 &&
    valueAsString(folder.name).trim().length > 0
  );
};

export const requireSavedFolderList = (value: unknown): SavedFolderDto[] => {
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
      return parsed.filter(validSavedFolderOption).map(folder => {
        const cached = folder as SavedFolderDto & {
          imageUrl?: unknown;
          lessonsCount?: unknown;
        };
        // This cache stores normalized options. Older server-shaped entries
        // remain readable without changing the remote response contract.
        return mapSavedFolder({
          ...cached,
          image: cached.imageUrl ?? cached.image,
          lessons_count: cached.lessonsCount ?? cached.lessons_count,
        });
      });
    }
  } catch {
    // A damaged local folder index should never block saving a reel.
  }
  return [];
};

const writeLocalSavedFolders = async (
  accountScope: string,
  next:
    | SavedFolderOption[]
    | ((current: SavedFolderOption[]) => SavedFolderOption[]),
  isCurrent = () => true,
) => {
  // Serialize this existing cache only: a storage write already in progress
  // must finish before the later mutation repair or fresh read replaces it.
  const previous = folderCacheWrites.get(accountScope) ?? Promise.resolve();
  const write = previous
    .catch(() => undefined)
    .then(async () => {
      if (isCurrent()) {
        const folders =
          typeof next === 'function'
            ? next(await readLocalSavedFolders(accountScope))
            : next;
        if (!isCurrent()) return;
        await AsyncStorage.setItem(
          localSavedFoldersKey(accountScope),
          JSON.stringify(folders),
        );
      }
    });
  folderCacheWrites.set(accountScope, write);
  try {
    await write;
  } finally {
    if (folderCacheWrites.get(accountScope) === write)
      folderCacheWrites.delete(accountScope);
  }
};

export const invalidateMembershipCounts = async (
  boundary: AccountSessionBoundary,
  folderId: string | null,
) => {
  invalidateFolderList(boundary);
  // A read may already include the committed write, so invalidate rather than
  // guessing a +/- delta. Enqueue before any other asynchronous cache repairs.
  await acceptRemoteCacheRepair(boundary, () =>
    writeLocalSavedFolders(
      boundary.scope,
      current =>
        current.map(folder =>
          folderId === null || folder.id === folderId
            ? {...folder, lessonsCount: undefined}
            : folder,
        ),
      () => {
        assertAccountSessionBoundary(boundary);
        return true;
      },
    ),
  );
};

export const cacheCreatedSavedFolder = (
  boundary: AccountSessionBoundary,
  created: SavedFolderOption,
) =>
  acceptRemoteCacheRepair(boundary, () =>
    writeLocalSavedFolders(boundary.scope, latest => [
      ...latest.filter(item => item.id !== created.id),
      created,
    ]),
  );

export const cacheDeletedSavedFolder = (
  boundary: AccountSessionBoundary,
  folderId: string,
) =>
  writeLocalSavedFolders(boundary.scope, current =>
    current.filter(folder => folder.id !== folderId),
  );

export const getSavedFolderOptions = async (options?: {
  requireFresh?: boolean;
}): Promise<SavedFolderOption[]> => {
  const accountBoundary = await captureAccountSessionBoundary();
  return loadSavedFolderOptions(accountBoundary, options?.requireFresh);
};

const loadSavedFolderOptions = async (
  accountBoundary: AccountSessionBoundary,
  requireFresh = false,
): Promise<SavedFolderOption[]> => {
  assertAccountSessionBoundary(accountBoundary);
  const accountScope = accountBoundary.scope;
  const key = ownerKey(accountBoundary);
  const revision = folderListRevisions.get(key) ?? 0;
  const isCurrent = () => (folderListRevisions.get(key) ?? 0) === revision;
  const flightKey = requireFresh ? `${key}:fresh` : key;
  return singleFlight(folderListFlights, flightKey, async () => {
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
      if (!isCurrent())
        return loadSavedFolderOptions(accountBoundary, requireFresh);
      await settleWithin(
        writeLocalSavedFolders(accountScope, folders, isCurrent),
        undefined,
      );
      assertAccountSessionBoundary(accountBoundary);
      if (!isCurrent())
        return loadSavedFolderOptions(accountBoundary, requireFresh);
      return folders;
    } catch (error) {
      // ACCOUNT_CHANGED_DURING_REQUEST must never fall through to the previous
      // owner's offline folder list.
      assertAccountSessionBoundary(accountBoundary);
      if (!isCurrent())
        return loadSavedFolderOptions(accountBoundary, requireFresh);
      if (requireFresh || isSavedCollectionContractError(error)) throw error;
      // Bound the whole ordered read: timing out just its queue wait would let
      // a known-stale snapshot escape before the pending repair lands.
      const cached = await settleWithin(
        (async () => {
          await folderCacheWrites.get(accountScope)?.catch(() => undefined);
          return readLocalSavedFolders(accountScope);
        })(),
        [],
      );
      assertAccountSessionBoundary(accountBoundary);
      if (!isCurrent())
        return loadSavedFolderOptions(accountBoundary, requireFresh);
      if (cached.length) return cached;
      throw error instanceof Error
        ? error
        : new Error('SAVED_FOLDERS_UNAVAILABLE');
    }
  });
};
