import AsyncStorage from '@react-native-async-storage/async-storage';
import {publicRequest} from '../../../constants/api';
import {
  assertAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../../../constants/helpers';
import {hasSession} from '../../../services/roknApi';
import {secureRandomUuid} from '../../../utils/secureRandom';
import {settleWithin} from '../../../utils/settleWithin';
import {ownerKey, singleFlight} from './savedCollectionConcurrency';
import {
  invalidateFolderList,
  normalizedFolderName,
  requireSavedFolderList,
  validSavedFolderOption,
} from './savedFolderIndex';
import {valueAsString} from './shared';

const WATCH_LATER_FOLDER_KEY = '@rokn/watch-later-folder-id/v2';
const watchLaterFolderFlights = new Map<string, Promise<string | null>>();
const watchLaterHintOperations = new Map<string, Promise<void>>();

const withWatchLaterHint = <T>(
  boundary: AccountSessionBoundary,
  operation: (key: string) => Promise<T>,
): Promise<T> => {
  const key = `${WATCH_LATER_FOLDER_KEY}:${boundary.scope}`;
  const result = (watchLaterHintOperations.get(key) ?? Promise.resolve()).then(
    async () => {
      assertAccountSessionBoundary(boundary);
      const value = await operation(key);
      assertAccountSessionBoundary(boundary);
      return value;
    },
  );
  const tail = result.then(
    () => undefined,
    () => undefined,
  );
  watchLaterHintOperations.set(key, tail);
  void tail.then(() => {
    if (watchLaterHintOperations.get(key) === tail)
      watchLaterHintOperations.delete(key);
  });
  return result;
};

export const forgetWatchLaterHint = (
  boundary: AccountSessionBoundary,
  expectedId: string,
) =>
  withWatchLaterHint(boundary, async key => {
    const current = await AsyncStorage.getItem(key);
    assertAccountSessionBoundary(boundary);
    if (current === expectedId) await AsyncStorage.removeItem(key);
  });

export const ensureWatchLaterFolder = async (
  accountBoundary: AccountSessionBoundary,
  ignoreCached = false,
): Promise<string | null> =>
  singleFlight(
    watchLaterFolderFlights,
    `${ownerKey(accountBoundary)}:${ignoreCached ? 'refresh' : 'cached'}`,
    async () => {
      // This is only a destination hint. A blocked old cleanup may delay its
      // raw queue, but the server can still identify the current default list.
      const cached = ignoreCached
        ? null
        : await settleWithin(
            withWatchLaterHint(accountBoundary, key =>
              AsyncStorage.getItem(key),
            ),
            null,
          );
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
        await settleWithin(
          forgetWatchLaterHint(accountBoundary, cached),
          undefined,
        );
        assertAccountSessionBoundary(accountBoundary);
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
        invalidateFolderList(accountBoundary);
      }
      if (!validSavedFolderOption(folder)) {
        throw new Error('WATCH_LATER_FOLDER_CONTRACT_INVALID');
      }
      const id = valueAsString(folder.id);
      await settleWithin(
        withWatchLaterHint(accountBoundary, key =>
          AsyncStorage.setItem(key, id),
        ),
        undefined,
      );
      assertAccountSessionBoundary(accountBoundary);
      return id;
    },
  );
