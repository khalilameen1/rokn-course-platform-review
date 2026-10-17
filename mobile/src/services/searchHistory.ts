import AsyncStorage from '@react-native-async-storage/async-storage';
import {
  accountScopedStorageKey,
  assertAccountSessionBoundary,
  captureAccountSessionBoundary,
  type AccountSessionBoundary,
} from '../constants/helpers';
import {cleanUnicodeText, truncateGraphemes} from '../utils/unicodeText';
import {createKeyedAsyncQueue} from '../utils/keyedAsyncQueue';

const SEARCH_HISTORY_KEY = '@rokn/search-history/v1';
const SEARCH_HISTORY_LIMIT = 7;

const historyKey = (boundary: AccountSessionBoundary) =>
  accountScopedStorageKey(SEARCH_HISTORY_KEY, boundary);

const cleanQuery = (value: string) =>
  truncateGraphemes(cleanUnicodeText(value, false), 80);

const withHistoryLock = createKeyedAsyncQueue();

const readSearchHistory = async (
  key: string,
  boundary: AccountSessionBoundary,
): Promise<string[]> => {
  const raw = await AsyncStorage.getItem(key);
  assertAccountSessionBoundary(boundary);
  try {
    const parsed = raw ? JSON.parse(raw) : [];
    return Array.isArray(parsed)
      ? parsed
          .filter((item): item is string => typeof item === 'string')
          .map(cleanQuery)
          .filter(Boolean)
          .slice(0, SEARCH_HISTORY_LIMIT)
      : [];
  } catch {
    return [];
  }
};

export const getSearchHistory = async (): Promise<string[]> => {
  const boundary = await captureAccountSessionBoundary();
  const key = await historyKey(boundary);
  return withHistoryLock(key, () => readSearchHistory(key, boundary));
};

export const rememberSearch = async (value: string): Promise<string[]> => {
  const query = cleanQuery(value);
  if (!query) return getSearchHistory();
  const boundary = await captureAccountSessionBoundary();
  const key = await historyKey(boundary);
  return withHistoryLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    const current = await readSearchHistory(key, boundary);
    const next = [
      query,
      ...current.filter(
        item => item.toLocaleLowerCase('ar') !== query.toLocaleLowerCase('ar'),
      ),
    ].slice(0, SEARCH_HISTORY_LIMIT);
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.setItem(key, JSON.stringify(next));
    assertAccountSessionBoundary(boundary);
    return next;
  });
};

export const clearSearchHistory = async () => {
  const boundary = await captureAccountSessionBoundary();
  const key = await historyKey(boundary);
  await withHistoryLock(key, async () => {
    assertAccountSessionBoundary(boundary);
    await AsyncStorage.removeItem(key);
    assertAccountSessionBoundary(boundary);
  });
};
